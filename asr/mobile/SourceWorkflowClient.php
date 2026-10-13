<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceCommandClient.php';
require_once __DIR__.'/SourceWorkflowCommands.php';
class SourceWorkflowClient extends SourceCommandClient {
    public const URL='https://asrhealth.in/mapa-source-workflow.php';
    protected function protocol():string{return \MapaSource\SourceWorkflowCommands::class;}
    protected function validateReceipt(array $r,array $command):void {
        $extra=match($command['action']){'session.start','session.close'=>['daily_status_id'],
            'repair.complete'=>['repair_id','daily_status_id','source_status','operational_readiness_changed'],
            'trip.record'=>['daily_status_id','trip_ids','rows_created','distance_kms','closing_odo'],default=>[]};
        $base=array_diff_key($r,array_flip($extra));
        if(!is_string($base['applied_at']??null)||!preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\+00:00|\+05:30)$/D',$base['applied_at']))throw new SourceCommandUncertain('Invalid workflow timestamp.');
        $base['applied_at']=(new \DateTimeImmutable($base['applied_at']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);parent::validateReceipt($base,$command);
        $actual=array_keys($r);sort($actual);$expected=array_merge(array_keys($base),$extra);sort($expected);
        if($actual!==$expected)throw new SourceCommandUncertain('Unexpected workflow receipt.');
        foreach(['daily_status_id','repair_id'] as $key)if(isset($r[$key])&&(!is_string($r[$key])||!preg_match('/^[1-9][0-9]{0,9}$/D',$r[$key])))throw new SourceCommandUncertain('Invalid workflow identity.');
        if($command['action']==='repair.complete'&&($r['source_status']!=='On Road'||$r['operational_readiness_changed']!==false))throw new SourceCommandUncertain('Invalid repair receipt.');
        if($command['action']==='trip.record'){
            if(!is_array($r['trip_ids'])||!array_is_list($r['trip_ids'])||count($r['trip_ids'])<1||count($r['trip_ids'])>10||$r['rows_created']!==count($r['trip_ids']))throw new SourceCommandUncertain('Invalid trip receipt.');
            foreach($r['trip_ids'] as $id)if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id))throw new SourceCommandUncertain('Invalid trip identity.');
            foreach(['distance_kms','closing_odo'] as $key)if(!is_string($r[$key])||!preg_match('/^[0-9]{1,8}\.[0-9]{2}$/D',$r[$key]))throw new SourceCommandUncertain('Invalid distance receipt.');
        }
    }
}
