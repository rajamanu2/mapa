<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/../PhotonSearch.php';
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\MapaVoice\PhotonSearch;
$checks=0;
function verify($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function resetFixture($body,$status=200){
    Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore));
    Http::swap(new Illuminate\Http\Client\Factory);Http::preventStrayRequests();
    Http::fake(['https://photon.komoot.io/*'=>Http::response($body,$status)]);
}
config(['mapa_voice.photon_enabled'=>true]);$search=new PhotonSearch;
$point=['geometry'=>['type'=>'Point','coordinates'=>[82.6674492,18.0881677]],'properties'=>['name'=>'TEST ITDA','city'=>'TEST locality','osm_type'=>'N','osm_id'=>123,'countrycode'=>'IN']];
resetFixture(['features'=>[$point]]);
$r=$search->search('ITDA Paderu');verify($r['status']==='ok'&&count($r['results'])===1,'Named place resolves');
verify($r['results'][0]['latitude']===18.0881677&&$r['results'][0]['longitude']===82.6674492,'Longitude/latitude order is correct');
verify($r['results'][0]['approximate']===true&&str_contains($r['results'][0]['source'],'OpenStreetMap'),'Map candidate is attributed, not marked confirmed');
verify($r['results'][0]['address']==='TEST ITDA, TEST locality'&&$r['results'][0]['locality']==='TEST locality','Clean address and provider locality available for autofill');
$sent=Http::recorded()[0][0];
verify(str_starts_with($sent->url(),'https://photon.komoot.io/api/?'),'Only fixed HTTPS destination');
parse_str(parse_url($sent->url(),PHP_URL_QUERY),$params);
verify(array_keys($params)===['q','limit','lat','lon','zoom','lang','bbox','countrycode'],'Only bounded public place-search parameters leave MAPA');
verify($params['bbox']==='81.80,17.40,83.35,18.75'&&$params['limit']==='8'&&$params['countrycode']==='IN','ASR rectangle, country and limit enforced');
verify($search->search('ITDA Paderu')['status']==='cached'&&count(Http::recorded())===1,'Repeated lookup is served without another provider request');
verify($search->search('Another landmark')['status']==='busy'&&count(Http::recorded())===1,'Shared limiter prevents rapid uncached provider requests');
foreach(['+91 80088 16919','caller@example.com','https://example.com','x'.str_repeat('y',160),"Paderu\ncontact"] as $invalid)verify($search->search($invalid)['status']==='invalid','Unsafe or overlong query rejected before network');
verify(count(Http::recorded())===1,'Rejected queries did not leave MAPA');
$foreign=$point;$foreign['properties']['countrycode']='US';$outside=$point;$outside['geometry']['coordinates']=[80,20];$invalid=$point;$invalid['geometry']['coordinates']=['bad',18];
verify(count(PhotonSearch::candidates([$point,$point,$foreign,$outside,$invalid]))===1,'Duplicates, foreign/outside coordinates and malformed points rejected');
$outside['properties']['osm_id']=456;
verify(count(PhotonSearch::candidates([$point,$outside,$foreign,$invalid],'india'))===2,'India search admits Indian places outside the ASR rectangle but rejects foreign or malformed places');
verify(PhotonSearch::candidates([$point],'invalid')===[],'Unknown scope never broadens the search');
resetFixture(['features'=>[$outside]]);
$india=$search->search('TEST town','india');
parse_str(parse_url(Http::recorded()[0][0]->url(),PHP_URL_QUERY),$params);
verify($india['status']==='ok'&&count($india['results'])===1&&!isset($params['bbox'])&&$params['countrycode']==='IN','India lookup removes the district boundary while keeping the country filter');
verify(!isset($params['lat'])&&!isset($params['lon'])&&!isset($params['zoom']),'India search does not prefer ASR businesses over a named distant town');
verify($search->search('TEST town')['status']==='busy','Results cached for India are not returned for an ASR query');
verify($search->search('TEST town','invalid')['status']==='invalid','Unknown scope rejected before network');
foreach([['features'=>null],['unexpected'=>[]]] as $body){resetFixture($body);verify($search->search('ITDA Paderu')['status']==='unavailable','Malformed provider data has a controlled fallback');}
resetFixture('provider error',503);verify($search->search('ITDA Paderu')['status']==='unavailable','HTTP failure does not leak its body');
resetFixture(['features'=>[]]);verify($search->search('Unknown landmark')['results']===[],'No result never fabricates a point');
config(['mapa_voice.photon_enabled'=>false]);verify($search->search('ITDA Paderu')['status']==='disabled','Provider can be disabled independently');
echo "Photon search: $checks checks passed (mock HTTP and process-local cache; no live data changed).\n";
