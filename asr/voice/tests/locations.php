<?php
// Isolated local-search contract. Deliberately no Laravel HTTP client or network fixture.
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $values){}
        public function boolean(string $key): bool{return filter_var($this->values[$key]??false,FILTER_VALIDATE_BOOLEAN);}
        public function validate(array $rules): array {
            if(isset($rules['latitude'])){
                foreach(['latitude'=>90,'longitude'=>180] as $key=>$max)if(!is_numeric($this->values[$key]??null)||abs((float)$this->values[$key])>$max)throw new \RuntimeException('validation',422);
                return $this->values;
            }
            $q=$this->values['q']??null;
            if(!is_string($q)||mb_strlen($q)<3||mb_strlen($q)>160)throw new \RuntimeException('validation',422);
            if(isset($this->values['scope'])&&!in_array($this->values['scope'],['asr','india'],true))throw new \RuntimeException('validation',422);
            return $this->values;
        }
    }
}
namespace App\MapaVoice {
    class VoiceService {public static function fail(string $message,int $status=403): never {throw new \RuntimeException($message,$status);}}
    class PhotonSearch {
        public static array $requests=[];
        public static string $status='ok';
        public function search(string $query,string $scope='asr'): array {
            self::$requests[]=[$query,$scope];
            return ['status'=>self::$status,'results'=>self::$status==='ok'?[['id'=>'osm:N:123','label'=>'TEST online landmark','address'=>'TEST online address','locality'=>'TEST locality','latitude'=>20.0,'longitude'=>80.0,'source'=>'OpenStreetMap via Photon','approximate'=>true]]:[]];
        }
    }
    function config(string $name){return $GLOBALS['search_enabled'];}
}
namespace {
    require dirname(__DIR__).'/LocationSearch.php';
    use App\MapaVoice\LocationSearch;
    use Illuminate\Http\Request;
    $GLOBALS['search_enabled']=true;$assertions=0;$search=new LocationSearch;
    function check(bool $ok,string $message):void {global $assertions;$assertions++;if(!$ok)throw new RuntimeException($message);}
    function findPlace(string $q):array {global $search;return $search->search(new Request(['q'=>$q]));}
    foreach(['Paderu','Araku','pAdErU'] as $name){
        $r=findPlace($name);check(count($r['results'])>0,'Known regional place found: '.$name);
        check(count($r['results'])<=40,'Typeahead results bounded');
        check($r['local_status']==='ok','Healthy local catalogs report availability');
        check($r['approximate']===true&&str_contains($r['notice'],'not an official district boundary'),'Approximate regional extent disclosed');
        check($r['attribution']==='GeoNames · CC BY4.0'&&$r['license_url']==='https://creativecommons.org/licenses/by/4.0/','Source and licence attributed');
        check($r['scope']==='asr'&&isset($r['results'][0]['address'],$r['results'][0]['locality']),'Default ASR search returns separate autofill fields');
        foreach($r['results'] as $row){check($row['approximate']===true&&in_array($row['source'],['GeoNames','OpenStreetMap'],true),'No exact caller location claim');check($row['latitude']>=17.40&&$row['latitude']<=18.75&&$row['longitude']>=81.80&&$row['longitude']<=83.35,'Candidate is within declared rectangle');}
    }
    foreach(['Araku railway station','Araku train station','Araku rly stn','Araku railwaystation','ARK','అరకు రైల్వే'] as $query){
        $r=findPlace($query);$station=array_values(array_filter($r['results'],fn($p)=>$p['id']==='osm:N:2523199054'));
        check(count($station)===1,'Araku station found locally without a provider: '.$query);
        check(abs($station[0]['latitude']-18.3329216)<0.000001&&abs($station[0]['longitude']-82.8652583)<0.000001,'Actual mapped station coordinates retained');
        check(str_contains($station[0]['address'],'railway station')&&str_contains($r['local_map_attribution'],'OpenStreetMap'),'Station type and OSM attribution are visible');
    }
    $rail=findPlace('railway station');check($rail['local_match_count']>$rail['local_shown_count']&&$rail['local_shown_count']===32,'Large result sets expose the true count instead of silently hiding matches');
    check(findPlace('Paderu')['local_match_count']===findPlace('Pāderu')['local_match_count'],'Latin accents do not hide a recorded village');
    check(findPlace('unknownzzzyyyregionalplace')['results']===[],'Unknown place does not invent a location');
    $itda=findPlace('itda');check(count($itda['directory_results'])===1,'ITDA landmark can be found');
    check($itda['directory_results'][0]['latitude']===null&&$itda['directory_results'][0]['longitude']===null&&$itda['directory_results'][0]['requires_pin']===true,'Official address never invents a building pin');
    check(str_contains($itda['directory_results'][0]['source_url'],'allurisitharamaraju.ap.gov.in'),'Landmark source is traceable');
    foreach(['Chintapalli','G.K.Veedhi','Munchingputtu','పాడేరు','చింతపల్లి'] as $alias)check(count(findPlace($alias)['results'])>0,'Mandal spelling or Telugu name resolves: '.$alias);
    check(count(findPlace('hospital')['directory_results'])>=2,'Hospital place names are searchable without inventing a receiving facility');
    foreach(['+91 80088 16919','919999999999','caller@example.com','https://example.com','1234','\tPaderu',str_repeat('a',161)] as $q){
        $q=str_replace('\\t',"\t",$q);$failed=false;try{findPlace($q);}catch(RuntimeException $e){$failed=$e->getCode()===422;}check($failed,'Contact, URL or invalid query rejected');
    }
    $near=$search->nearby(new Request(['latitude'=>18.088168,'longitude'=>82.667449]));
    check($near['latitude']===18.088168&&$near['longitude']===82.667449,'Nearby lookup preserves the exact operator pin');
    check(is_array($near['nearest'])&&$near['nearest']['distance_m']<=5000&&$near['nearest']['source']==='GeoNames','Only local nearby settlement context is returned');
    check($near['approximate']===true&&str_contains($near['notice'],'confirm'),'Nearby context is not a verified entrance or village assignment');
    check($search->nearby(new Request(['latitude'=>0,'longitude'=>0]))['nearest']===null,'Distant pin does not invent a nearby ASR village');
    foreach([['latitude'=>91,'longitude'=>82],['latitude'=>18,'longitude'=>'wrong']] as $point){$invalid=false;try{$search->nearby(new Request($point));}catch(RuntimeException $e){$invalid=$e->getCode()===422;}check($invalid,'Invalid pin is rejected');}
    $GLOBALS['search_enabled']=false;$disabled=false;try{findPlace('Paderu');}catch(RuntimeException $e){$disabled=$e->getCode()===503;}check($disabled,'Feature flag fails closed');
    $source=file_get_contents(dirname(__DIR__).'/LocationSearch.php');
    foreach(['Http::','curl_','https://nominatim','file_get_contents($url'] as $external)check(!str_contains($source,$external),'No external query implementation: '.$external);
    // Load the same implementation beside an isolated incomplete catalog. No source or live data changes.
    $fixture=sys_get_temp_dir().'/mapa-location-search-'.bin2hex(random_bytes(8));
    mkdir($fixture);$GLOBALS['search_enabled']=true;
    try {
        file_put_contents($fixture.'/LocationSearch.php',str_replace('class LocationSearch','class MissingCatalogSearch',$source));
        copy(dirname(__DIR__).'/asr-directory.json',$fixture.'/asr-directory.json');
        require $fixture.'/LocationSearch.php';
        $missing=new \App\MapaVoice\MissingCatalogSearch;
        set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
        try {
            $local=$missing->search(new Request(['q'=>'ITDA']));
            check($local['local_status']==='unavailable'&&count($local['directory_results'])===1,'Missing places preserve available directory results with a clear status');
            check($local['online_status']==='not_requested'&&\App\MapaVoice\PhotonSearch::$requests===[],'Local catalog failure never opts into an online query');
            foreach(['missing','malformed'] as $catalogState){
                if($catalogState==='malformed')file_put_contents($fixture.'/asr-places.json','{}');
                $online=$missing->search(new Request(['q'=>'ITDA','online'=>1,'scope'=>'india']));
                check($online['local_status']==='unavailable'&&$online['online_status']==='ok'&&count($online['results'])===1,'Explicit online search survives '.$catalogState.' local catalog');
                check($online['results'][0]['id']==='osm:N:123'&&$online['results'][0]['approximate']===true,'Online candidate survives without implying confirmation');
            }
            check(\App\MapaVoice\PhotonSearch::$requests===[['ITDA','india'],['ITDA','india']],'Only the requested place query and scope reach the online provider');
            $invalid=false;try{$missing->search(new Request(['q'=>'caller@example.com','online'=>1]));}catch(RuntimeException $e){$invalid=$e->getCode()===422;}
            check($invalid&&count(\App\MapaVoice\PhotonSearch::$requests)===2,'Privacy validation still rejects contact details before online lookup');
            \App\MapaVoice\PhotonSearch::$status='busy';
            $limited=$missing->search(new Request(['q'=>'ITDA','online'=>1]));
            check($limited['online_status']==='busy'&&$limited['results']===[],'Online rate-limit status remains visible during local outage');
        } finally {restore_error_handler();}
    } finally {
        foreach(['LocationSearch.php','asr-directory.json','asr-places.json'] as $file)if(is_file($fixture.'/'.$file))unlink($fixture.'/'.$file);
        rmdir($fixture);
    }
    echo 'Local location search: '.$assertions." assertions passed\n";
}
