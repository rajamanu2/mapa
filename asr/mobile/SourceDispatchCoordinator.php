<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceCommands.php';

/** Source database mutex and durable MAPA ownership; no TTL-based automatic release. */
final class SourceDispatchCoordinator {
    private static array $depth=[];
    private static function key(\PDO $db):string{return 'mapa-asr:'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);}
    public static function acquire(\PDO $db):void {
        $id=spl_object_id($db);if(isset(self::$depth[$id])){self::$depth[$id]++;return;}
        $q=$db->prepare('SELECT GET_LOCK(?,5)');$q->execute([self::key($db)]);if((int)$q->fetchColumn()!==1)throw new CommandRejected(503,'Fleet coordination busy. Retry the same request.');self::$depth[$id]=1;
    }
    public static function release(\PDO $db):void {$id=spl_object_id($db);if(!isset(self::$depth[$id]))return;if(--self::$depth[$id]>0)return;unset(self::$depth[$id]);$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([self::key($db)]);}
    public static function withLock(\PDO $db,callable $fn):mixed {self::acquire($db);try{return $fn();}finally{self::release($db);}}
    private static function locked(\PDO $db):void {if(!isset(self::$depth[spl_object_id($db)]))throw new \LogicException('Shared source writer lock required.');}
    private static function rows(\PDO $db,string $sql,array $args=[]):array {$q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    public static function assertAvailable(\PDO $db,string $vehicle,?string $driver=null):void {
        self::locked($db);
        if(self::rows($db,"SELECT journey_id FROM mapa_dispatch_reservations WHERE terminal_at IS NULL AND (ambulance_id=? OR driver_id=?) LIMIT 1",[$vehicle,$driver]))throw new CommandRejected(409,'Ambulance or driver is reserved by a MAPA journey.');
        if(self::rows($db,'SELECT t.id FROM amb_trips t JOIN amb_daily_status d ON d.id=t.daily_status_id WHERE (d.ambulance_id=? OR t.driver_id=?) AND (t.drop_date IS NULL OR t.drop_time IS NULL) LIMIT 1',[$vehicle,$driver]))throw new CommandRejected(409,'Ambulance or driver has an unfinished source/CAD trip.');
    }
    public static function assertNoMapaReservation(\PDO $db,?string $vehicle,?string $driver=null):void {self::locked($db);if(self::rows($db,'SELECT journey_id FROM mapa_dispatch_reservations WHERE terminal_at IS NULL AND (ambulance_id=? OR driver_id=?) LIMIT 1',[$vehicle,$driver]))throw new CommandRejected(409,'MAPA owns the current journey; finish it before changing its vehicle, driver or daily records.');}
    /** Caller must already authenticate/map company and restrict vehicle/driver grants. */
    public static function reserve(\PDO $db,string $company,string $journey,string $vehicle,string $driver):array {
        if($company!==SourceSync::COMPANY||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$journey)||!preg_match('/^[1-9][0-9]{0,9}$/D',$vehicle)||!preg_match('/^[1-9][0-9]{0,9}$/D',$driver))throw new CommandRejected(422,'Invalid reservation identity.');
        return self::withLock($db,function()use($db,$company,$journey,$vehicle,$driver){
            $old=self::rows($db,'SELECT * FROM mapa_dispatch_reservations WHERE company_id=? AND journey_id=?',[$company,$journey]);
            if($old){$r=$old[0];if((string)$r['ambulance_id']!==$vehicle||(string)$r['driver_id']!==$driver)throw new CommandRejected(409,'Journey already has another reservation.');return $r;}
            self::assertAvailable($db,$vehicle,$driver);
            $current=self::rows($db,"SELECT d.id FROM amb_daily_status d JOIN ambulances a ON a.id=d.ambulance_id JOIN type_ambulance t ON t.id=a.type_id JOIN amb_drivers dr ON dr.id=? WHERE a.id=? AND d.record_date=CURRENT_DATE() AND d.status='On Road' AND d.is_closed=0 AND d.phc_id=a.phc_id AND dr.phc_id=a.phc_id AND dr.vehicle_type=t.type LIMIT 1",[$driver,$vehicle]);
            if(!$current||self::rows($db,"SELECT id FROM amb_repairs WHERE ambulance_id=? AND status<>'Completed' LIMIT 1",[$vehicle]))throw new CommandRejected(409,'Source session, posting, category or repair prevents dispatch.');
            $q=$db->prepare("INSERT INTO mapa_dispatch_reservations(company_id,journey_id,ambulance_id,driver_id,status,version,created_at) VALUES(?,?,?,?,'reserved',1,UTC_TIMESTAMP(6))");$q->execute([$company,$journey,$vehicle,$driver]);
            return self::rows($db,'SELECT * FROM mapa_dispatch_reservations WHERE company_id=? AND journey_id=?',[$company,$journey])[0];
        });
    }
    public static function transition(\PDO $db,string $company,string $journey,int $version,string $next):array {
        if($company!==SourceSync::COMPANY||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$journey)||$version<1)throw new CommandRejected(422,'Invalid reservation identity or version.');
        return self::withLock($db,function()use($db,$company,$journey,$version,$next){
            $rows=self::rows($db,'SELECT * FROM mapa_dispatch_reservations WHERE company_id=? AND journey_id=?',[$company,$journey]);if(!$rows)throw new CommandRejected(404,'Journey reservation missing.');$r=$rows[0];
            // An exact retry of an accepted next-state transition is safe, but cannot resurrect terminal ownership.
            if((int)$r['version']===$version+1&&$r['status']===$next)return $r;
            if((int)$r['version']!==$version||$r['terminal_at']!==null)throw new CommandRejected(409,'Journey ownership changed.');
            $allowed=['reserved'=>['en_route','cancelled'],'en_route'=>['arrived_scene','cancelled'],'arrived_scene'=>['transporting','cancelled'],'transporting'=>['completed']];
            if(!in_array($next,$allowed[$r['status']]??[],true))throw new CommandRejected(409,'Invalid journey ownership transition.');
            $terminal=in_array($next,['completed','cancelled'],true);$q=$db->prepare('UPDATE mapa_dispatch_reservations SET status=?,version=version+1,terminal_at='.($terminal?'UTC_TIMESTAMP(6)':'NULL').' WHERE company_id=? AND journey_id=? AND version=?');$q->execute([$next,$company,$journey,$version]);
            return self::rows($db,'SELECT * FROM mapa_dispatch_reservations WHERE company_id=? AND journey_id=?',[$company,$journey])[0];
        });
    }
}
