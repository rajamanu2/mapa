<?php
namespace App\MapaMobile;

/** Display provenance from a verified snapshot; never infers a native dispatch. */
final class SourceAssignmentProjection
{
    public static function active(array $snapshot): array
    {
        if (!in_array($snapshot['contract'] ?? null,[\MapaSource\SourceSync::CONTRACT_V2,\MapaSource\SourceSync::CONTRACT_V3],true)) return [];
        $states = ['dispatched','en_route','arrived_scene','transporting'];
        $incidents = array_column($snapshot['datasets']['cad_incidents'], null, 'id');
        $vehicles = array_column($snapshot['datasets']['ambulances'], null, 'id');
        $drivers = array_column($snapshot['datasets']['drivers'], null, 'id');
        return array_values(array_filter($snapshot['datasets']['cad_dispatches'], static function ($row) use ($states, $incidents, $vehicles, $drivers) {
            $incident = $incidents[$row['incident_id']] ?? null;
            return $incident && in_array($incident['status'], $states, true)
                && in_array($row['status'], $states, true)
                && isset($vehicles[$row['ambulance_id']], $drivers[$row['driver_id']]);
        }));
    }

    public static function label(string $dataset, array $row, array $assignments): ?string
    {
        foreach ($assignments as $assignment) {
            $field = ['cad_incidents'=>'incident_id','cad_dispatches'=>'id','ambulances'=>'ambulance_id','drivers'=>'driver_id'][$dataset] ?? null;
            if ($field && (string)$assignment[$field] === (string)$row['id']) return 'Assigned through asrhealth.in';
        }
        return null;
    }
}
