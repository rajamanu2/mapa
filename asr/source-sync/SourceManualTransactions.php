<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceCoordinationPolicy.php';
require_once __DIR__.'/SourceRepairPhotos.php';

/** Executes the reviewed original form action body unchanged within one durable retry boundary. */
final class SourceManualTransactions {
    public const ACTIONS=[
        'ambulance_running.php'=>['save_daily_status','save_trip','close_session','reopen_session','revert_offroad','delete_trip','edit_trip','edit_odo'],
        'ambulance_analytics.php'=>['reopen_session'],
        'off_road_ambulances.php'=>['init_repair','upload_photos','update_place','update_odo','add_component','delete_component','complete_repair'],
    ];
    public static function fields(array &$session):string {
        if(!is_string($session['mapa_form_csrf']??null)||strlen($session['mapa_form_csrf'])!==64)$session['mapa_form_csrf']=bin2hex(random_bytes(32));
        $id=bin2hex(random_bytes(16));
        return '<input type="hidden" name="mapa_form_csrf" value="'.htmlspecialchars($session['mapa_form_csrf'],ENT_QUOTES,'UTF-8').'"><input type="hidden" name="mapa_request_id" value="'.$id.'">';
    }
    /** Original ASR action, with local form protection and atomicity only. No MAPA reservations or schema gates. */
    public static function run(\PDO $db,string $file,array $post,array $session,callable $original,?SourceRepairPhotos $photos=null):array {
        $action=$post['action']??null;$actor=$session['user_id']??null;$csrf=$post['mapa_form_csrf']??null;
        if(!is_string($action)||!in_array($action,self::ACTIONS[$file]??[],true))throw new CommandRejected(422,'Unsupported manual action.');
        if((!is_string($actor)&&!is_int($actor))||(string)$actor==='')throw new CommandRejected(403,'Authenticated source user required.');
        if(!is_string($csrf)||!is_string($session['mapa_form_csrf']??null)||strlen($csrf)!==64||!hash_equals($session['mapa_form_csrf'],$csrf))throw new CommandRejected(403,'The form session expired. Refresh the page before saving.');
        $owns=!$db->inTransaction();$mode=$db->getAttribute(\PDO::ATTR_ERRMODE);$db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$commitAttempted=false;
        if($owns)$db->beginTransaction();if($photos)$photos->bind($csrf,hash('sha256',serialize([$file,(string)$actor,$post,$photos->fingerprints()])));
        try {$result=$original();if(!is_array($result)||!empty($result['error']))throw new CommandRejected(422,is_array($result)?(string)$result['error']:'The source form was not saved.');
            if($owns){$commitAttempted=true;$db->commit();}return $result;
        }catch(\Throwable $e){$rolledBack=false;if($owns&&$db->inTransaction()){$db->rollBack();$rolledBack=true;}if($photos&&(!$commitAttempted||$rolledBack))$photos->rollback();throw $e;
        }finally{$db->setAttribute(\PDO::ATTR_ERRMODE,$mode);}
    }
}
