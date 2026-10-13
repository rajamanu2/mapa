<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceDispatchCoordinator.php';

/** Hook called after original login/permission checks, before its first fleet read/write. */
final class SourceOriginalWriterGuard {
    private static function one(\PDO $db,string $sql,array $args):?array {$q=$db->prepare($sql);$q->execute($args);return $q->fetch(\PDO::FETCH_ASSOC)?:null;}
    public static function check(\PDO $db,string $file,array $post):void {
        if($file==='cad_incident_list.php'&&($post['action']??null)==='delete_incident'){
            $incident=self::one($db,'SELECT status FROM cad_incidents WHERE id=?',[$post['delete_id']??null]);
            if(!$incident||!in_array($incident['status'],['pending','cancelled'],true)||self::one($db,'SELECT id FROM cad_dispatch_log WHERE incident_id=? LIMIT 1',[$post['delete_id']??null]))throw new CommandRejected(409,'Dispatched incident history is retained. Complete or cancel its journey; do not delete its provenance.');
        }
        $vehicle=isset($post['ambulance_id'])?(string)$post['ambulance_id']:null;$driver=isset($post['driver_id'])?(string)$post['driver_id']:null;
        $resolveVehicle=static function(string $resolved)use(&$vehicle):void{if($vehicle!==null&&$vehicle!==''&&$vehicle!==$resolved)throw new CommandRejected(422,'Source vehicle references do not match.');$vehicle=$resolved;};
        if(!empty($post['daily_status_id'])){$s=self::one($db,'SELECT ambulance_id FROM amb_daily_status WHERE id=?',[$post['daily_status_id']]);if($s)$resolveVehicle((string)$s['ambulance_id']);}
        if(!empty($post['trip_id'])){$s=self::one($db,'SELECT d.ambulance_id,t.driver_id FROM amb_trips t JOIN amb_daily_status d ON d.id=t.daily_status_id WHERE t.id=?',[$post['trip_id']]);if($s){$resolveVehicle((string)$s['ambulance_id']);if($driver!==null&&$driver!==''&&$driver!==(string)$s['driver_id'])throw new CommandRejected(422,'Source driver references do not match.');$driver=(string)$s['driver_id'];}}
        if(!empty($post['repair_id'])){$s=self::one($db,'SELECT ambulance_id FROM amb_repairs WHERE id=?',[$post['repair_id']]);if($s)$resolveVehicle((string)$s['ambulance_id']);}
        if($file==='off_road_ambulances.php'&&($post['action']??null)==='delete_component'){$item=self::one($db,'SELECT repair_id FROM amb_repair_items WHERE id=?',[$post['item_id']??null]);if(!$item||(string)$item['repair_id']!==(string)($post['repair_id']??''))throw new CommandRejected(422,'Repair component does not belong to the reviewed repair.');}
        if($file==='ambulance_index.php'&&isset($post['id']))$vehicle=(string)$post['id'];
        if($file==='drivers.php'&&isset($post['id']))$driver=(string)$post['id'];
        SourceDispatchCoordinator::assertNoMapaReservation($db,$vehicle,$driver);
        if($file==='cad_save_dispatch.php'){
            $vehicle=(string)($post['ambulance_id']??'');$driver=(string)($post['driver_id']??'');
            $s=self::one($db,"SELECT d.ambulance_id FROM amb_daily_status d JOIN ambulances a ON a.id=d.ambulance_id JOIN type_ambulance t ON t.id=a.type_id JOIN amb_drivers dr ON dr.id=? WHERE d.id=? AND d.ambulance_id=? AND d.status='On Road' AND d.is_closed=0 AND d.record_date=CURRENT_DATE() AND d.phc_id=a.phc_id AND dr.phc_id=a.phc_id AND dr.vehicle_type=t.type",[$driver,$post['daily_status_id']??null,$vehicle]);
            if(!$s||self::one($db,"SELECT id FROM amb_repairs WHERE ambulance_id=? AND status<>'Completed' LIMIT 1",[$vehicle]))throw new CommandRejected(409,'Current vehicle session, driver posting or repair does not permit dispatch.');
            SourceDispatchCoordinator::assertAvailable($db,$vehicle,$driver);
        }
        if($file==='cad_complete_trip.php'){
            $s=self::one($db,'SELECT t.drop_date,t.drop_time,l.status FROM amb_trips t JOIN cad_dispatch_log l ON l.trip_id=t.id WHERE t.id=?',[$post['trip_id']??null]);
            if(!$s||$s['drop_date']!==null||$s['drop_time']!==null||in_array($s['status'],['completed','cancelled'],true))throw new CommandRejected(409,'Trip already ended or is unavailable; mileage was not applied again.');
        }
        if($file==='cad_update_status.php'){
            $s=self::one($db,'SELECT incident_id,status FROM cad_dispatch_log WHERE id=?',[$post['dispatch_id']??null]);
            if(!$s||(string)$s['incident_id']!==(string)($post['incident_id']??'')||in_array($s['status'],['completed','cancelled'],true))throw new CommandRejected(409,'Dispatch identity changed or journey already ended.');
            if(($post['new_status']??null)==='completed')throw new CommandRejected(409,'Use the existing complete-trip form to save drop time and mileage before completion.');
        }
        // Original manual forms cannot change a source CAD journey while it is live.
        if(!str_starts_with($file,'cad_')&&($vehicle!==null||$driver!==null))SourceDispatchCoordinator::assertAvailable($db,$vehicle??'0',$driver);
    }
}
