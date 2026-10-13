<?php
namespace App\MapaMobile;

final class HospitalHandoverPolicy
{
    public const CONDITIONS = ['stable','urgent','critical','unknown'];
    public const RESPONSES = ['acknowledged','ready','contact_crew'];
    /** An assignment is an early heads-up, never an observed patient report. */
    public static function assignedPlaceMatches(string $company, string $place, array $order, ?array $destination): bool
    {
        return $company !== '' && $place !== ''
            && ($order['company_uuid']??null)===$company
            && ($destination['company_uuid']??null)===$company
            && ($destination['uuid']??null)===$place && empty($destination['deleted_at'])
            && !empty($order['driver_assigned_uuid']) && !empty($order['vehicle_assigned_uuid'])
            && (bool)($order['dispatched']??false) && empty($order['deleted_at'])
            && !in_array(strtolower($order['status']??''),['created','scheduled','completed','failed','cancelled','canceled'],true);
    }
    public static function callerReported(array $review): ?array
    {
        // Do not leak full intake metadata, transcripts, callbacks or operator notes.
        $facts=[];
        foreach(['reported_problem','priority','consciousness','breathing','scene_safety'] as $field)
            if(isset($review[$field]) && is_string($review[$field]) && trim($review[$field])!=='')
                $facts[$field]=mb_substr(trim($review[$field]),0,$field==='reported_problem'?2000:80);
        if(isset($review['patient_count']) && filter_var($review['patient_count'],FILTER_VALIDATE_INT)!==false
            && (int)$review['patient_count']>=1 && (int)$review['patient_count']<=99)
            $facts['patient_count']=(int)$review['patient_count'];
        return $facts ? ['source'=>'caller_reported_dispatch_intake','observed_by_crew'=>false,...$facts] : null;
    }
    public static function receivingPlaceMatches(string $company, string $place, array $report, array $order, ?array $destination): bool
    {
        return $place !== '' && ($report['company_uuid']??null)===$company
            && ($order['company_uuid']??null)===$company
            && ($report['order_uuid']??null)===($order['uuid']??'')
            && ($report['place_uuid']??null)===$place
            && ($destination['company_uuid']??null)===$company
            && ($destination['uuid']??null)===$place && empty($destination['deleted_at'])
            && ($report['driver_uuid']??null)===($order['driver_assigned_uuid']??'')
            && ($report['vehicle_uuid']??null)===($order['vehicle_assigned_uuid']??'')
            && (bool)($order['dispatched']??false)
            && empty($order['deleted_at'])
            && !in_array(strtolower($order['status']??''),['created','scheduled','completed','failed','cancelled','canceled'],true);
    }
}
