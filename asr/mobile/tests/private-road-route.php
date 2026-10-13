<?php
require_once __DIR__.'/../PrivateRoadRoute.php';
use App\MapaMobile\PrivateRoadRoute as R;
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$from=['latitude'=>17.70,'longitude'=>83.20];$to=['latitude'=>17.71,'longitude'=>83.21];
$body=['code'=>'Ok','routes'=>[['geometry'=>['type'=>'LineString','coordinates'=>[[83.20,17.70],[83.205,17.705],[83.21,17.71]]],'distance'=>1600,'duration'=>300]]];
$settings=['enabled'=>true,'origin'=>R::ORIGIN];$calls=0;$lookups=0;$seen=[];
$route=new R(function($url,$ip)use(&$calls,&$seen,$body){$calls++;$seen=[$url,$ip];return json_encode($body);},function($host)use(&$lookups){$lookups++;check($host==='mapa-road-router','fixed DNS alias');return ['172.20.0.4'];});
foreach([[],['enabled'=>false,'origin'=>R::ORIGIN],['enabled'=>'true','origin'=>R::ORIGIN]] as $s)check($route->request($from,$to,$s)===null,'disabled config');
foreach(['https://router.project-osrm.org','http://127.0.0.1:5000','http://mapa-road-router:5000/','http://user@mapa-road-router:5000','http://mapa-road-router:5000?x=1'] as $origin)check($route->request($from,$to,['enabled'=>true,'origin'=>$origin])===null,'unapproved origin');
check($calls===0&&$lookups===0,'disabled/unapproved never resolve or request');
foreach(['10.0.0.1','172.16.0.1','172.31.255.254','192.168.1.1'] as $ip)check(R::privateAddress($ip),'RFC1918');
foreach(['127.0.0.1','169.254.169.254','8.8.8.8','172.15.0.1','172.32.0.1','::1','fd00::1','2130706433','10.0.0.1.evil','0.0.0.0'] as $ip)check(!R::privateAddress($ip),'not RFC1918');
$valid=$route->request($from,$to,$settings,100000);
check($valid['coordinates']===$body['routes'][0]['geometry']['coordinates'],'coordinates preserved');
check($valid['generated_at']===100000&&$valid['expires_at']===160000,'bounded freshness');
check($valid['source']==='private_osrm'&&$valid['distance_m']===1600&&$valid['duration_s']===300,'source and estimates');
check($seen[1]==='172.20.0.4'&&str_starts_with($seen[0],R::ORIGIN.'/route/v1/driving/83.200000,17.700000;83.210000,17.710000?'),'fixed path and pin');
check(str_contains($seen[0],'alternatives=false')&&str_contains($seen[0],'radiuses=100;100'),'bounded route options');
foreach([false,[],['172.20.0.4','8.8.8.8'],['127.0.0.1'],['169.254.169.254'],['::1']] as $ips){$called=false;$r=new R(function()use(&$called){$called=true;return '{}';},fn()=>$ips);check($r->request($from,$to,$settings)===null&&!$called,'DNS fails closed before transport');}
foreach([['latitude'=>'17.7','longitude'=>83.2],['latitude'=>NAN,'longitude'=>83.2],['latitude'=>INF,'longitude'=>83.2],['latitude'=>91,'longitude'=>83.2],['latitude'=>0,'longitude'=>0]] as $p)check($route->request($p,$to,$settings)===null,'invalid input');
$invalid=[];
$b=$body;$b['code']='NoRoute';$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['type']='Polygon';$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['coordinates']=[[83.2,17.7]];$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['coordinates']=array_fill(0,4097,[83.2,17.7]);$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['coordinates'][0]=[80,10];$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['coordinates'][2]=[80,10];$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['coordinates'][1]=[181,17.7];$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['coordinates'][1]=[83.2,17.7,1];$invalid[]=$b;
$b=$body;$b['routes'][0]['geometry']['coordinates'][1]=['83.2',17.7];$invalid[]=$b;
foreach(['distance'=>[-1,1500001,'1600',INF],'duration'=>[-1,172801,'300',NAN]] as $key=>$values)foreach($values as $value){$b=$body;$b['routes'][0][$key]=$value;$invalid[]=$b;}
foreach($invalid as $b)check(R::validate($b,$from,$to,100000)===null,'invalid response geometry or measures');
foreach(['not json','null',str_repeat('x',R::MAX_BYTES+1)] as $raw){$r=new R(fn()=>$raw,fn()=>['10.1.2.3']);check($r->request($from,$to,$settings)===null,'invalid or oversized wire body');}
$r=new R(fn()=>throw new RuntimeException('synthetic timeout'),fn()=>['10.1.2.3']);check($r->request($from,$to,$settings)===null,'transport failure closed');
$r=new R(fn()=>'{}',fn()=>throw new RuntimeException('synthetic DNS failure'));check($r->request($from,$to,$settings)===null,'resolver failure closed');
echo "PASS private road route: $checks checks (synthetic, network-free)\n";
