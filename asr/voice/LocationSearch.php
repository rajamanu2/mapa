<?php
namespace App\MapaVoice;
use Illuminate\Http\Request;

/** Local typeahead; explicit online=1 adds a bounded public place lookup. */
class LocationSearch
{
    private const NOTICE='Approximate place centres in an ASR-area search rectangle, not an official district boundary or a verified caller location. Confirm village, landmark, access route and pickup pin with the caller.';
    private static ?array $places=null;
    private static ?array $directory=null;
    private static ?array $landmarks=null;

    /** Local context only: never send a caller's selected pin to an external geocoder. */
    public function nearby(Request $r): array
    {
        if(!config('mapa_voice.geocoding_enabled'))VoiceService::fail('Place lookup is unavailable. Keep the pin and confirm the pickup with the caller.',503);
        $v=$r->validate(['latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180']);
        $lat=(float)$v['latitude'];$lon=(float)$v['longitude'];
        if(!is_finite($lat)||!is_finite($lon))VoiceService::fail('A valid pickup pin is required.',422);
        $nearest=null;$distance=5000;
        foreach(self::places() as $p){
            // Administrative centres and terrain must not be presented as a caller's village.
            if($p['kind']!=='settlement'||!in_array($p['feature_code']??'',['PPL','PPLA','PPLA2','PPLA3','PPLA4','PPLC','PPLX'],true))continue;
            $a=sin(deg2rad($p['latitude']-$lat)/2)**2+cos(deg2rad($lat))*cos(deg2rad($p['latitude']))*sin(deg2rad($p['longitude']-$lon)/2)**2;
            $meters=6371000*2*asin(sqrt(min(1,max(0,$a))));
            if($meters<$distance){$distance=$meters;$nearest=$p;}
        }
        return ['latitude'=>$lat,'longitude'=>$lon,'nearest'=>$nearest?['name'=>$nearest['name'],'distance_m'=>(int)round($distance),'source'=>'GeoNames']:null,
            'approximate'=>true,'notice'=>'Nearby settlement context only. The selected coordinates remain the pickup pin; confirm the village and entrance with the caller.'];
    }

    public function search(Request $r): array
    {
        if(!config('mapa_voice.geocoding_enabled'))VoiceService::fail('Place search is unavailable. Use the map and verify the address with the caller.',503);
        $v=$r->validate(['q'=>'required|string|min:3|max:160','online'=>'sometimes|boolean','scope'=>'sometimes|in:asr,india']);$q=trim($v['q']);$scope=$v['scope']??'asr';
        if(mb_strlen($q)<3||preg_match('/[\x00-\x1f@]|https?:\/\/|www\./iu',$v['q'])||preg_match_all('/\p{N}/u',$q)>=7||!preg_match('/\p{L}/u',$q))VoiceService::fail('Search for a village, mandal or landmark only; do not enter contact or patient details.',422);
        $query=self::normalize($q);$terms=preg_split('/\s+/u',$query,-1,PREG_SPLIT_NO_EMPTY);
        if(!$terms)VoiceService::fail('Enter a village, mandal or landmark name.',422);
        // An optional local catalog must not prevent an explicitly requested online lookup.
        $localStatus='ok';$localPlaces=[];$directoryPlaces=[];
        foreach(['landmarks','places'] as $catalog){
            try{$localPlaces=[...$localPlaces,...self::$catalog()];}
            catch(\Throwable $e){$localStatus='unavailable';}
        }
        try{$directoryPlaces=self::directory()['places']??[];}
        catch(\Throwable $e){$localStatus='unavailable';}
        $matches=[];
        foreach($localPlaces as $place){
            $score=null;
            foreach($place['search_aliases'] as $name){
                $all=true;
                foreach($terms as $term)if(mb_strpos($name,$term)===false){$all=false;break;}
                if(!$all)continue;
                $rank=$name===$query?0:(str_starts_with($name,$query)?1:2);
                $score=$score===null?$rank:min($score,$rank);
            }
            if($score===null)continue;
            $osm=($place['source']??'')==='OpenStreetMap';
            $matches[]=['score'=>$score,'kind_rank'=>in_array($place['kind'],['terrain','water feature','vegetation','area'],true)?2:($place['kind']==='settlement'?1:0),
                'row'=>['id'=>$osm?$place['id']:'geonames:'.$place['id'],
                'label'=>$place['name'].' · '.$place['kind'].' · '.($osm?'mapped candidate':'approximate centre'),
                'address'=>$place['address']??$place['name'],'locality'=>$place['locality']??($place['kind']==='settlement'?$place['name']:''),
                'latitude'=>$place['latitude'],'longitude'=>$place['longitude'],'source'=>$osm?'OpenStreetMap':'GeoNames','approximate'=>true]];
        }
        usort($matches,fn($a,$b)=>($a['score']<=>$b['score'])?:($a['kind_rank']<=>$b['kind_rank'])?:strnatcasecmp($a['row']['label'],$b['row']['label']));
        $directory=[];
        foreach($directoryPlaces as $p){
            $haystack=self::normalize(implode(' ',[$p['name'],$p['address'],...$p['aliases']]));
            $all=true;foreach($terms as $term)if(mb_strpos($haystack,$term)===false){$all=false;break;}
            if($all)$directory[]=['id'=>'asr-directory:'.$p['id'],'name'=>$p['name'],'address'=>$p['address'],
                'source'=>'ASR District Administration','source_url'=>$p['source_url'],'latitude'=>null,'longitude'=>null,'requires_pin'=>true];
        }
        $online=$r->boolean('online')?(new PhotonSearch)->search($q,$scope):['status'=>'not_requested','results'=>[]];
        $results=[];$seen=[];
        foreach([...array_column(array_slice($matches,0,32),'row'),...$online['results']] as $row){
            if(isset($seen[$row['id']]))continue;$seen[$row['id']]=true;$results[]=$row;
        }
        $hasOsm=(bool)array_filter($results,fn($row)=>str_starts_with($row['source'],'OpenStreetMap'));
        return ['results'=>$results,'local_status'=>$localStatus,'local_match_count'=>count($matches),'local_shown_count'=>min(count($matches),32),'directory_results'=>array_slice($directory,0,8),
            'local_map_attribution'=>$hasOsm?'© OpenStreetMap contributors · ODbL · regional map directory':null,
            'scope'=>$scope,'online_status'=>$online['status'],'online_attribution'=>$online['results']?'© OpenStreetMap contributors · ODbL · search by Photon':null,
            'online_license_url'=>$online['results']?'https://www.openstreetmap.org/copyright':null,
            'directory_attribution'=>$directory?'Place names/localities: ASR District Administration. Exact pins not supplied.':null,
            'attribution'=>'GeoNames · CC BY4.0',
            'attribution_url'=>'https://www.geonames.org/','license_url'=>'https://creativecommons.org/licenses/by/4.0/','approximate'=>true,'notice'=>self::NOTICE];
    }
    private static function normalize(string $value): string
    {
        $value=mb_strtolower($value,'UTF-8');
        // Fold Latin accents without removing vowel marks from Telugu names.
        $value=strtr($value,['ā'=>'a','á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ă'=>'a','ī'=>'i','í'=>'i','ï'=>'i','ū'=>'u','ú'=>'u','ü'=>'u','ē'=>'e','é'=>'e','è'=>'e','ō'=>'o','ó'=>'o','ö'=>'o','ñ'=>'n','ṇ'=>'n','ś'=>'s','ṣ'=>'s','ṭ'=>'t','ḍ'=>'d','ḷ'=>'l']);
        $value=trim(preg_replace('/[^\p{L}\p{M}\p{N}]+/u',' ',$value));
        return preg_replace(['/\brailwaystation\b/u','/\b(train|rly)\b/u','/\bstn\b/u','/\bstations\b/u','/\bhospitals\b/u'],['railway station','railway','station','station','hospital'],$value);
    }
    private static function landmarks(): array
    {
        if(self::$landmarks!==null)return self::$landmarks;
        $path=__DIR__.'/asr-landmarks.json';
        if(!is_file($path))return self::$landmarks=[];
        $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($data['schema']??'')!=='mapa-osm-local-v1'||!is_array($data['places']??null))throw new \RuntimeException('Unsupported map directory');
        $valid=[];
        foreach($data['places'] as $p){
            if(!is_array($p)||!preg_match('/^osm:[NWR]:[0-9]+$/',(string)($p['id']??''))||!is_string($p['name']??null)||!is_string($p['kind']??null)||!is_array($p['aliases']??null)||!is_numeric($p['latitude']??null)||!is_numeric($p['longitude']??null))continue;
            $lat=(float)$p['latitude'];$lon=(float)$p['longitude'];
            if(!is_finite($lat)||!is_finite($lon)||$lat<17.40||$lat>18.75||$lon<81.80||$lon>83.35)continue;
            $p['latitude']=$lat;$p['longitude']=$lon;$p['source']='OpenStreetMap';
            $p['search_aliases']=array_map([self::class,'normalize'],array_filter($p['aliases'],fn($a)=>is_string($a)&&$a!==''));
            $valid[]=$p;
        }
        return self::$landmarks=$valid;
    }
    private static function directory(): array
    {
        if(self::$directory!==null)return self::$directory;
        $data=json_decode(file_get_contents(__DIR__.'/asr-directory.json'),true,512,JSON_THROW_ON_ERROR);
        if(($data['schema']??'')!=='mapa-asr-address-directory-v1')throw new \RuntimeException('Unsupported directory');
        return self::$directory=$data;
    }
    private static function places(): array
    {
        if(self::$places!==null)return self::$places;
        $path=__DIR__.'/asr-places.json';
        try{$data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
        catch(\Throwable $e){VoiceService::fail('The local place list is unavailable. Use the map and verify the address with the caller.',503);}
        if(!is_array($data)||($data['schema']??'')!=='mapa-geonames-local-v1'||!is_array($data['places']??null))VoiceService::fail('The local place list is unavailable.',503);
        $valid=[];
        foreach($data['places'] as $p){
            if(!is_array($p)||!is_numeric($p['latitude']??null)||!is_numeric($p['longitude']??null)||!is_string($p['name']??null)||!is_array($p['aliases']??null)||!is_string($p['kind']??null)||!preg_match('/^\d+$/',(string)($p['id']??'')))continue;
            $lat=(float)$p['latitude'];$lon=(float)$p['longitude'];if(!is_finite($lat)||!is_finite($lon)||$lat<17.40||$lat>18.75||$lon<81.80||$lon>83.35)continue;
            $p['aliases']=array_values(array_filter([...$p['aliases'],...(self::directory()['mandal_aliases'][$p['id']]??[])],fn($a)=>is_string($a)&&$a!==''));$p['latitude']=$lat;$p['longitude']=$lon;$valid[]=$p;
            $valid[array_key_last($valid)]['search_aliases']=array_map([self::class,'normalize'],$p['aliases']);
        }
        return self::$places=$valid;
    }
}
