<?php
namespace App\MapaVoice;
use App\MapaMobile\SourceInventoryController;
use Illuminate\Validation\ValidationException;

/** Exact source location identities; never substitutes administrative coordinates for pickup GPS. */
final class SourceIntakeDirectory {
    public static function get(string $company):array {
        $state=SourceInventoryController::verifiedState($company);
        if(time()-$state['received_at']>120)VoiceService::fail('Fetch fresh ASR locations before selecting a source location.',503);
        $datasets=$state['snapshot']['datasets'];
        return ['hospitals'=>array_map(static fn($r)=>['id'=>(string)$r['phc_id'],'name'=>(string)$r['name']],$datasets['hospitals']),
            'secretariats'=>array_map(static fn($r)=>['id'=>(string)$r['secretariat_id'],'phc_id'=>(string)$r['phc_id'],'name'=>(string)$r['name']],$datasets['secretariats']??[]),
            'villages'=>array_map(static fn($r)=>['id'=>(string)$r['village_id'],'secretariat_id'=>(string)$r['secretariat_id'],'name'=>(string)$r['name']],$datasets['villages']??[]),
            'hierarchy_available'=>isset($datasets['secretariats'],$datasets['villages'])];
    }
    public static function validate(array $values,string $company):array {
        $refs=['origin_phc_id','origin_secretariat_id','origin_village_id','destination_phc_id'];
        if(!array_filter(array_intersect_key($values,array_flip($refs))))return $values;
        $directory=self::get($company);return self::against($values,$directory);
    }
    public static function against(array $values,array $directory):array {
        $phcs=array_column($directory['hospitals'],null,'id');$secs=array_column($directory['secretariats'],null,'id');$villages=array_column($directory['villages'],null,'id');
        $phc=$values['origin_phc_id']??null;$sec=$values['origin_secretariat_id']??null;$village=$values['origin_village_id']??null;$destination=$values['destination_phc_id']??null;
        $invalid=static fn($key)=>throw ValidationException::withMessages([$key=>'Choose a current ASR location within the selected hierarchy.']);
        if($phc&&!isset($phcs[$phc]))$invalid('origin_phc_id');
        if($sec&&(!isset($secs[$sec])||$secs[$sec]['phc_id']!==$phc))$invalid('origin_secretariat_id');
        if($village&&(!isset($villages[$village])||$villages[$village]['secretariat_id']!==$sec))$invalid('origin_village_id');
        if($destination&&!isset($phcs[$destination]))$invalid('destination_phc_id');
        // Names come from the verified directory whenever an identity is selected.
        if($phc)$values['origin_phc_name']=$phcs[$phc]['name'];
        if($sec)$values['origin_secretariat_name']=$secs[$sec]['name'];
        if($village)$values['village']=$villages[$village]['name'];
        return $values;
    }
}
