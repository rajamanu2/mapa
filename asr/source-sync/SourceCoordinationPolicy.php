<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceDispatchCoordinator.php';

/** Private commissioning and migration gate, never set from a request parameter. */
final class SourceCoordinationPolicy {
    public const TABLES=['ambulances','amb_drivers','amb_daily_status','amb_trips','amb_repairs','amb_repair_items','type_ambulance','cad_incidents','cad_dispatch_log','cad_status_updates','mapa_dispatch_reservations','mapa_manual_commands'];
    public static function validate(array $c):void {
        if(!is_bool($c['enabled']??null)||!is_bool($c['maintenance_mode']??null)||($c['enabled']&&$c['maintenance_mode'])||!is_string($c['schema_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$c['schema_sha256']))throw new \RuntimeException('Explicit coordination policy and reviewed schema receipt required.');
    }
    public static function load():array {
        $path=getenv('MAPA_SOURCE_COORDINATION_CONFIG');$actual=is_string($path)?realpath($path):false;$web=realpath($_SERVER['DOCUMENT_ROOT']??'');
        if(!$actual||!is_file($actual)||filesize($actual)>65536||($web&&($actual===$web||str_starts_with($actual,$web.DIRECTORY_SEPARATOR))))throw new \RuntimeException('Private coordination policy unavailable.');
        $c=str_ends_with($actual,'.php')?(static fn($p)=>require $p)($actual):json_decode(file_get_contents($actual),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($c))throw new \RuntimeException('Invalid coordination policy.');self::validate($c);return $c;
    }
    /** Stable metadata fingerprint only; never reads patient/account contents. */
    public static function schemaReceipt(\PDO $db):string {
        $marks=implode(',',array_fill(0,count(self::TABLES),'?'));
        $q=$db->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks) ORDER BY TABLE_NAME");$q->execute(self::TABLES);$tables=$q->fetchAll(\PDO::FETCH_ASSOC);
        if(count($tables)!==count(self::TABLES))throw new \RuntimeException('Required commissioned source tables missing.');foreach($tables as $t)if(strtoupper($t['ENGINE']??'')!=='INNODB')throw new \RuntimeException('Transactional source tables required.');
        $q=$db->prepare("SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks) ORDER BY TABLE_NAME,ORDINAL_POSITION");$q->execute(self::TABLES);$columns=$q->fetchAll(\PDO::FETCH_ASSOC);
        $q=$db->prepare("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX");$q->execute(self::TABLES);$indexes=$q->fetchAll(\PDO::FETCH_ASSOC);
        // Canonical text prevents PDO emulation/numeric type differences from invalidating a metadata receipt.
        $normalize=static function(array $rows):array{foreach($rows as &$row)foreach($row as &$v)$v=$v===null?null:(string)$v;return $rows;};
        return hash('sha256',SourceSync::json([$normalize($tables),$normalize($columns),$normalize($indexes)]));
    }
    public static function verifySchema(\PDO $db,array $c,string $role='original'):void {
        self::validate($c);if(!in_array($role,['original','command','workflow','dispatch'],true))throw new \RuntimeException('Unknown schema receipt role.');
        // Column-restricted SQL accounts see different metadata. Attest each actual account without granting patient SELECT.
        $key=$role==='original'?'schema_sha256':$role.'_schema_sha256';$expected=$c[$key]??null;
        if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($expected,self::schemaReceipt($db)))throw new \RuntimeException('Source schema or account permissions changed; maintenance review required.');
    }
    public static function operation(\PDO $db,string $role,callable $action):mixed {
        return SourceDispatchCoordinator::withLock($db,static function()use($db,$role,$action){$c=self::load();if(!$c['enabled']||$c['maintenance_mode'])throw new \RuntimeException('Source operation paused for maintenance.');self::verifySchema($db,$c,$role);return $action();});
    }
    public static function denyWebReconciliation():void { /* Display-only MAPA does not gate original ASR maintenance logic. */ }
    /** Returns true only for an explicit offline CLI maintenance invocation. Included web bootstrap becomes a verified no-op. */
    public static function mayRunBootstrap(\PDO $db,array $c,bool $direct,string $sapi,bool $maintenanceAncestor=false):bool {
        self::validate($c);
        if($c['enabled']){if($direct)throw new \RuntimeException('Bootstrap is disabled during coordinated operation.');self::verifySchema($db,$c);return false;}
        if(!$c['maintenance_mode']||$sapi!=='cli'||(!$direct&&!$maintenanceAncestor))throw new \RuntimeException('Bootstrap requires explicit standalone CLI maintenance with bridge disabled.');
        $q=$db->query('SELECT COUNT(*) FROM mapa_dispatch_reservations WHERE terminal_at IS NULL');if((int)$q->fetchColumn()>0)throw new \RuntimeException('Active MAPA reservations must be reconciled before maintenance.');
        $q=$db->query('SELECT COUNT(*) FROM amb_trips WHERE drop_date IS NULL OR drop_time IS NULL');if((int)$q->fetchColumn()>0)throw new \RuntimeException('Active source journeys must end before maintenance.');
        return true;
    }
}
