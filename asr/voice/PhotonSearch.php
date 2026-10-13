<?php
namespace App\MapaVoice;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Bounded, explicit online place lookup. Never receives a call record. */
class PhotonSearch
{
    public function search(string $query, string $scope='asr'): array
    {
        if(!in_array($scope,['asr','india'],true))return ['status'=>'invalid','results'=>[]];
        if(mb_strlen($query)<3||mb_strlen($query)>160||preg_match('/[\x00-\x1f@]|https?:\/\/|www\./iu',$query)||preg_match_all('/\p{N}/u',$query)>=7||!preg_match('/\p{L}/u',$query))return ['status'=>'invalid','results'=>[]];
        if(!config('mapa_voice.photon_enabled',true))return ['status'=>'disabled','results'=>[]];
        try {
            $key='mapa-photon-v4:'.$scope.':'.hash('sha256',mb_strtolower(trim($query),'UTF-8'));
            $cached=Cache::get($key);
            if(is_array($cached))return ['status'=>'cached','results'=>$cached];
            // Shared across API workers: no more than one uncached provider request / 2 seconds.
            if(!Cache::add('mapa-photon-request-window-v1',true,2))return ['status'=>'busy','results'=>[]];
            $params=['q'=>$query,'limit'=>8,'lat'=>18.07776,'lon'=>82.66037,'zoom'=>9,'lang'=>'en'];
            if($scope==='asr')$params['bbox']='81.80,17.40,83.35,18.75';
            else unset($params['lat'],$params['lon'],$params['zoom']);
            $params['countrycode']='IN';
            $response=Http::acceptJson()->withHeaders(['User-Agent'=>'MAPA-ASR-place-search/1.0 (https://mapa-asr-health.pages.dev)'])
                ->connectTimeout(2)->timeout(6)->withOptions(['allow_redirects'=>false])->get('https://photon.komoot.io/api/',$params);
            if(!$response->successful()||strlen($response->body())>500000)return ['status'=>'unavailable','results'=>[]];
            $data=$response->json();
            if(!is_array($data)||!is_array($data['features']??null))return ['status'=>'unavailable','results'=>[]];
            $items=self::candidates($data['features'],$scope);
            Cache::put($key,$items,$items?86400:300);
            return ['status'=>'ok','results'=>$items];
        } catch(\Throwable $e) {
            // Provider errors/URLs can include the query. Do not log or expose them.
            return ['status'=>'unavailable','results'=>[]];
        }
    }

    public static function candidates(array $features, string $scope='asr'): array
    {
        if(!in_array($scope,['asr','india'],true))return [];
        $items=[];$seen=[];
        foreach(array_slice($features,0,32) as $f){
            if(!is_array($f)||($f['geometry']['type']??'')!=='Point')continue;
            $coordinates=$f['geometry']['coordinates']??null;$p=$f['properties']??null;
            if(!is_array($coordinates)||count($coordinates)!==2||!is_array($p)||!is_numeric($coordinates[0])||!is_numeric($coordinates[1]))continue;
            $lat=(float)$coordinates[1];$lon=(float)$coordinates[0];
            if(!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180||strtoupper((string)($p['countrycode']??''))!=='IN')continue;
            if($scope==='asr'&&($lat<17.40||$lat>18.75||$lon<81.80||$lon>83.35))continue;
            $name=$p['name']??$p['street']??null;if(!is_string($name)||trim($name)==='')continue;
            $type=$p['osm_type']??'';$id=(string)($p['osm_id']??'');
            if(!in_array($type,['N','W','R'],true)||!preg_match('/^[0-9]{1,20}$/',$id))continue;
            $key='osm:'.$type.':'.$id;if(isset($seen[$key]))continue;$seen[$key]=true;
            $clean=fn($v)=>is_string($v)?mb_substr(preg_replace('/[\x00-\x1f]/u',' ',trim($v)),0,180):'';
            $parts=array_values(array_unique(array_filter(array_map($clean,[$name,$p['housenumber']??null,$p['street']??null,$p['district']??null,$p['city']??null,$p['county']??null,$p['state']??null,$p['postcode']??null]))));
            $address=implode(', ',$parts);
            $locality=$clean($p['city']??$p['district']??'');
            if(!$locality&&($p['osm_key']??'')==='place'&&in_array($p['osm_value']??'',['city','town','village','hamlet','suburb','neighbourhood'],true))$locality=$clean($name);
            $items[]=['id'=>$key,'label'=>$address.' · OpenStreetMap candidate','address'=>$address,'locality'=>$locality,
                'latitude'=>$lat,'longitude'=>$lon,'source'=>'OpenStreetMap via Photon','approximate'=>true];
            if(count($items)===8)break;
        }
        return $items;
    }
}
