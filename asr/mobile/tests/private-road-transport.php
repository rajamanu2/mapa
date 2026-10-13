<?php
// Run only in a disposable container on a fresh Docker --internal network.
if(getenv('MAPA_ROAD_QA_ISOLATED')!=='1')throw new RuntimeException('Isolated QA required');
require '/fleetbase/api/vendor/autoload.php';
require_once __DIR__.'/../PrivateRoadRoute.php';
use App\MapaMobile\PrivateRoadRoute as R;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Http\Client\Factory;
$app=new Container;$app->instance(Factory::class,new Factory);Facade::setFacadeApplication($app);
$ip=gethostbyname(gethostname());if(!R::privateAddress($ip))throw new RuntimeException('Private test network required');
$from=['latitude'=>17.7,'longitude'=>83.2];$to=['latitude'=>17.71,'longitude'=>83.21];
$body=json_encode(['code'=>'Ok','routes'=>[['geometry'=>['type'=>'LineString','coordinates'=>[[83.2,17.7],[83.21,17.71]]],'distance'=>1600,'duration'=>300]]]);
$checks=0;
foreach(['valid','redirect','oversized-header','oversized-chunked'] as $case){
    $server=stream_socket_server('tcp://0.0.0.0:5000',$errno,$error);if(!$server)throw new RuntimeException($error);
    $pid=pcntl_fork();if($pid<0)throw new RuntimeException('fork unavailable');
    if($pid===0){
        $socket=stream_socket_accept($server,5);if(!$socket)exit(2);
        while(($line=fgets($socket))!==false&&trim($line)!==''){}
        if($case==='valid')$wire="HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
        elseif($case==='redirect')$wire="HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:9/forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        elseif($case==='oversized-header')$wire="HTTP/1.1 200 OK\r\nContent-Length: 999999\r\nConnection: close\r\n\r\n";
        else{$big=str_repeat('x',R::MAX_BYTES+20000);$wire="HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n".dechex(strlen($big))."\r\n".$big."\r\n0\r\n\r\n";}
        @fwrite($socket,$wire);fclose($socket);fclose($server);exit(0);
    }
    fclose($server);
    $route=(new R(null,fn()=>[$ip]))->request($from,$to,['enabled'=>true,'origin'=>R::ORIGIN],100000);
    pcntl_waitpid($pid,$status);
    if(($case==='valid'&&($route['source']??null)!=='private_osrm')||($case!=='valid'&&$route!==null))throw new RuntimeException('Transport case failed: '.$case);
    if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('Synthetic server failed');
    $checks++;
}
echo "PASS private road transport: $checks actual cURL/sink cases; synthetic coordinates, internal network only\n";
