<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Support\Facades\DB;

/** Dial targets only. Callers must authorize this exact order/destination snapshot. */
final class TripContacts
{
    public static function empty(): array { return ['driver'=>null,'caller'=>null,'hospital'=>null]; }
    public static function contact(mixed $name,mixed $phone,string $fallback): ?array {
        try { $number=CallerPhone::normalize($phone); } catch (\InvalidArgumentException $e) { return null; }
        if($number==='')return null;
        $label=is_string($name)?trim(strip_tags($name)):'';
        $label=preg_replace('/[\x00-\x1f\x7f]/u','',$label)??'';
        return ['name'=>$label!==''?mb_substr($label,0,160):$fallback,'phone'=>$number];
    }
    public static function forOrder(?Order $order,string $company,?Place $destination,bool $generic=false): array {
        $out=self::empty();
        if(!$order||$order->company_uuid!==$company||$order->deleted_at
            ||!$order->dispatched||!in_array($order->status,['dispatched','started','enroute'],true))return $out;
        // Driver.phone is an accessor to User.phone. Avoid its withTrashed relation,
        // scope both stored rows, and never substitute a newly assigned order.
        $driver=$order->driver_assigned_uuid?DB::table('drivers as d')->join('users as u','u.uuid','=','d.user_uuid')
            ->where('d.uuid',$order->driver_assigned_uuid)->where('d.company_uuid',$company)->where('u.company_uuid',$company)
            ->whereNull('d.deleted_at')->whereNull('u.deleted_at')->select(['u.name','u.phone'])->first():null;
        if($driver)$out['driver']=self::contact($generic?'Ambulance driver':$driver->name,$driver->phone,'Ambulance driver');
        $meta=is_object($order->meta)?(array)$order->meta:(is_array($order->meta)?$order->meta:[]);
        $review=$meta['mapa_dispatch_review']??[];$review=is_object($review)?(array)$review:(is_array($review)?$review:[]);
        $out['caller']=self::contact($generic?'Callback number':($review['caller_name']??null),CallerPhone::dispatchDetails($meta)['callback_number']??null,'Callback number');
        if($destination&&$destination->company_uuid===$company&&!$destination->deleted_at)
            $out['hospital']=self::contact($destination->name,$destination->phone,'Receiving hospital');
        return $out;
    }
}
