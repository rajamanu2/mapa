<?php
namespace App\MapaVoice;

/** Road ranking follows native eligibility checks; it never manufactures an ETA. */
final class RecommendationService
{
    public function rank(array $directory,array $review,callable $resource,?callable $road=null,bool $radiusFirst=false): array
    {
        $unavailable=$directory['unavailable']??[];$eligible=$directory['candidates']??[];$routed=[];
        if(!Policy::coordinates($review['latitude']??null,$review['longitude']??null))
            return [...$directory,'candidates'=>[],'ranked'=>[],'routing_status'=>'pickup_unconfirmed','ranking_basis'=>'private_road_travel_time'];
        // Bound routing requests independently of directory size. Proximity selects the shortlist only.
        usort($eligible,static fn($a,$b)=>(($a['distance_km']??INF)<=>($b['distance_km']??INF))?:strcmp($a['id'],$b['id']));
        $route=$road??static function($from,$to){$r=\App\MapaMobile\PrivateRoadRoute::between($from,$to);return $r===null?null:$r['duration_s'];};
        $shortlist=[];
        foreach($eligible as $candidate){
            $position=$resource($candidate);$reason=null;
            if(!$position)$reason=['resource_missing','Ambulance or driver record is unavailable.'];
            elseif($position['source_owned']??false)$reason=['source_owned','ASR manages this ambulance; MAPA shows its trip and caller tracking only.'];
            elseif($position['source_trip_block']??null)$reason=[$position['source_trip_block']['code'],$position['source_trip_block']['message']];
            elseif($position['pending_offer']??false)$reason=['offered','An offer is already awaiting this crew’s response.'];
            elseif($position['location_conflict']??false)$reason=['crew_vehicle_apart','Crew phone and ambulance GPS are more than 500 m apart. Control must verify the assignment before dispatch.'];
            elseif(!Policy::coordinates($position['latitude']??null,$position['longitude']??null))$reason=['gps_missing','No valid current driver location.'];
            if($reason){$unavailable[]=[...$candidate,'code'=>$reason[0],'message'=>$reason[1],'reason_code'=>$reason[0],'reason'=>$reason[1]];continue;}
            // The resource fix may have changed since directory enumeration. Use
            // this same fix for radius selection, routing and the persisted offer.
            if($radiusFirst)$candidate['distance_km']=Policy::distance((float)$review['latitude'],(float)$review['longitude'],(float)$position['latitude'],(float)$position['longitude']);
            $shortlist[]=[...$candidate,'_position'=>$position];
        }
        if($radiusFirst)usort($shortlist,static fn($a,$b)=>($a['distance_km']<=>$b['distance_km'])?:strcmp($a['id'],$b['id']));
        $shortlisted=count($shortlist);$shortlist=array_slice($shortlist,0,10);
        foreach($shortlist as $candidate){
            $position=$candidate['_position'];unset($candidate['_position']);
            $seconds=$route(['latitude'=>(float)$position['latitude'],'longitude'=>(float)$position['longitude'],'fix_recorded_at'=>$position['recorded_at']??$candidate['fix_recorded_at']??null,'driver_id'=>$candidate['id']],
                ['latitude'=>(float)$review['latitude'],'longitude'=>(float)$review['longitude']]);
            if(!is_numeric($seconds)||!is_finite((float)$seconds)||$seconds<0||$seconds>172800){
                $message='Road travel time is unavailable. No road ETA has been estimated.';
                $unavailable[]=[...$candidate,'code'=>'road_routing_unavailable','message'=>$message,'reason_code'=>'road_routing_unavailable','reason'=>$message];continue;
            }
            $routed[]=[...$candidate,'road_seconds'=>(int)ceil($seconds),'road_minutes'=>(int)ceil($seconds/60),
                'radius_band_m'=>AutomaticDispatchPolicy::radiusBand($candidate['distance_km']??null),
                'ranking_basis'=>$radiusFirst?'pickup_radius_then_private_road_travel_time':'private_road_travel_time',
                'latitude'=>(float)$position['latitude'],'longitude'=>(float)$position['longitude'],
                'location_recorded_at'=>$position['recorded_at']??$candidate['fix_recorded_at']??null,
                'location_source'=>$position['source']??'driver_phone_gps',
                'crew_phone_location'=>$position['crew_phone_location']??null,
                'ambulance_gps_location'=>$position['ambulance_gps_location']??null];
        }
        $routed=$radiusFirst?AutomaticDispatchPolicy::rankByRadius($routed):AutomaticDispatchPolicy::rank($routed);
        foreach($routed as $index=>&$candidate)$candidate['rank']=$index+1;unset($candidate);
        return [...$directory,'candidates'=>$routed,'ranked'=>$routed,'unavailable'=>$unavailable,'routing_status'=>$routed?'ready':($shortlisted?'unavailable':'no_eligible_crew'),
            'ranking_basis'=>$radiusFirst?'pickup_radius_then_private_road_travel_time':'private_road_travel_time','shortlist_limit'=>10,'eligible_before_routing'=>$shortlisted,'shortlist_truncated'=>$shortlisted>10];
    }
}
