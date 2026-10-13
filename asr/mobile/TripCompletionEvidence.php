<?php
namespace App\MapaMobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Called inside the locked order/activity/receipt transaction, never separately. */
final class TripCompletionEvidence
{
    public static function create($order,array $c,array $body,array $gps): array
    {
        if(!config('mapa_mobile.completion_evidence_enabled',false)) return [];
        if(!Schema::hasTable('mapa_mobile_trip_reports') || !Schema::hasTable('mapa_mobile_private_media')) MobileService::fail('Trip camera evidence requires command-center setup.',503);
        $now=(int)round(microtime(true)*1000);
        if(!EnrollmentPolicy::evidence($body,$gps,$now)) MobileService::fail('Trip end needs a note, a new camera photo and a fresh accurate GPS receipt. This draft is rejected; preserve the note and capture new evidence before retrying with a new action.',422);
        $jpeg=PrivateMedia::jpeg($body['photo_base64']??null);
        $id=(string)Str::uuid();
        $media=$c['private_media']->put($jpeg,['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid,'user_uuid'=>$c['user']->uuid,'driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']->uuid,'kind'=>'trip_end']);
        DB::table('mapa_mobile_trip_reports')->insert(['id'=>$id,'company_uuid'=>$c['company'],'order_uuid'=>$order->uuid,'user_uuid'=>$c['user']->uuid,'driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']->uuid,
            'action_id'=>$body['action_id'],'note'=>trim($body['completion_note']),'photo_media_id'=>$media,
            'latitude'=>$gps['latitude'],'longitude'=>$gps['longitude'],'accuracy_m'=>$gps['accuracy_m'],'recorded_at'=>$gps['recorded_at'],'photo_captured_at'=>$body['photo_captured_at'],'received_at'=>$now,'created_at'=>now()]);
        return ['report_id'=>$id,'photo_media_id'=>$media,'latitude'=>$gps['latitude'],'longitude'=>$gps['longitude'],'accuracy_m'=>$gps['accuracy_m'],
            'recorded_at'=>$gps['recorded_at'],'photo_captured_at'=>(int)$body['photo_captured_at'],'received_at'=>$now,'source'=>'crew_camera_and_device_gps','independently_verified'=>false];
    }
}
