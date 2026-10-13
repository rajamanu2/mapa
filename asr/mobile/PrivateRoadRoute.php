<?php
namespace App\MapaMobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Optional internal road geometry. Never calls the public OSRM demo or logs coordinates. */
final class PrivateRoadRoute
{
    public const ORIGIN='http://mapa-road-router:5000';
    public const MAX_BYTES=262144;
    public const MAX_POINTS=4096;
    private $transport;
    private $resolver;
    public function __construct(?callable $transport=null,?callable $resolver=null) {
        $this->transport=$transport;$this->resolver=$resolver;
    }
    public static function between(array $from,array $to): ?array {
        $settings=(array)config('mapa_mobile.road_routing',[]);
        if(($settings['enabled']??false)!==true||($settings['origin']??'')!==self::ORIGIN||!self::point($from)||!self::point($to))return null;
        // Route geometry is reusable only for exactly the same endpoints. Keys contain
        // no raw location, patient, phone, order or capability token.
        $key='mapa:private-road:'.hash('sha256',json_encode([$from['latitude'],$from['longitude'],$to['latitude'],$to['longitude']]));
        try { return Cache::remember($key,20,fn()=>['route'=>(new self)->request($from,$to,$settings)])['route']??null; }
        catch(\Throwable $e){return null;}
    }
    public static function privateAddress(string $ip): bool {
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))return false;
        $n=unpack('N',inet_pton($ip))[1];
        return ($n&0xff000000)===0x0a000000||($n&0xfff00000)===0xac100000||($n&0xffff0000)===0xc0a80000;
    }
    private static function number(mixed $n): bool {return (is_int($n)||is_float($n))&&is_finite((float)$n);}
    public static function point(array $p): bool {
        return self::number($p['latitude']??null)&&self::number($p['longitude']??null)
            &&abs($p['latitude'])<=90&&abs($p['longitude'])<=180&&!($p['latitude']==0&&$p['longitude']==0);
    }
    private static function metres(array $a,array $b): float {
        $r=pi()/180;$lat=($b['latitude']-$a['latitude'])*$r;$lng=($b['longitude']-$a['longitude'])*$r;
        $h=sin($lat/2)**2+cos($a['latitude']*$r)*cos($b['latitude']*$r)*sin($lng/2)**2;
        return 12742000*asin(sqrt(min(1,max(0,$h))));
    }
    public static function validate(array $body,array $from,array $to,int $now): ?array {
        if(!self::point($from)||!self::point($to)||($body['code']??null)!=='Ok')return null;
        $route=$body['routes'][0]??null;$points=$route['geometry']['coordinates']??null;
        if(($route['geometry']['type']??null)!=='LineString'||!is_array($points)||!array_is_list($points)
            ||count($points)<2||count($points)>self::MAX_POINTS)return null;
        foreach($points as $p)if(!is_array($p)||!array_is_list($p)||count($p)!==2||!self::point(['longitude'=>$p[0],'latitude'=>$p[1]]))return null;
        if(self::metres($from,['longitude'=>$points[0][0],'latitude'=>$points[0][1]])>300
            ||self::metres($to,['longitude'=>$points[count($points)-1][0],'latitude'=>$points[count($points)-1][1]])>300)return null;
        $distance=$route['distance']??null;$duration=$route['duration']??null;
        if(!self::number($distance)||!self::number($duration)||$distance<0||$distance>1500000||$duration<0||$duration>172800)return null;
        return ['coordinates'=>$points,'distance_m'=>$distance,'duration_s'=>$duration,'source'=>'private_osrm','generated_at'=>$now,'expires_at'=>$now+60000];
    }
    public function request(array $from,array $to,array $settings,?int $now=null): ?array {
        if(($settings['enabled']??false)!==true||($settings['origin']??'')!==self::ORIGIN||!self::point($from)||!self::point($to))return null;
        try {
            $ips=$this->resolver?($this->resolver)('mapa-road-router'):gethostbynamel('mapa-road-router');
            if(!is_array($ips)||!count($ips))return null;
            foreach($ips as $ip)if(!is_string($ip)||!self::privateAddress($ip))return null;
            $coordinates=implode(';',array_map(fn($p)=>sprintf('%.6F,%.6F',$p['longitude'],$p['latitude']),[$from,$to]));
            $url=self::ORIGIN.'/route/v1/driving/'.$coordinates.'?alternatives=false&steps=false&geometries=geojson&overview=simplified&radiuses=100;100';
            if($this->transport)$raw=($this->transport)($url,$ips[0]);
            else {
                if(!defined('CURLOPT_RESOLVE'))return null;
                $sink=fopen('php://temp/maxmemory:262144','w+b');
                try {
                    // Streaming-mode Guzzle can select its PHP stream handler and ignore
                    // CURLOPT_RESOLVE. Explicit curl + bounded sink retain the DNS pin.
                    $response=Http::withHeaders(['Accept'=>'application/json','Accept-Encoding'=>'identity'])
                        ->withOptions(['allow_redirects'=>false,'proxy'=>'','decode_content'=>false,
                            'connect_timeout'=>0.4,'timeout'=>1.5,'sink'=>$sink,
                            'handler'=>new \GuzzleHttp\Handler\CurlHandler,
                            'on_headers'=>function($response){if((int)$response->getHeaderLine('Content-Length')>self::MAX_BYTES)throw new \RuntimeException('Road response exceeds limit.');},
                            'progress'=>function($total,$downloaded){if($total>self::MAX_BYTES||$downloaded>self::MAX_BYTES)throw new \RuntimeException('Road response exceeds limit.');},
                            'curl'=>[CURLOPT_RESOLVE=>['mapa-road-router:5000:'.$ips[0]]]])->get($url);
                    if($response->status()!==200)return null;
                    rewind($sink);$raw=fread($sink,self::MAX_BYTES+1);
                } finally {if(is_resource($sink))fclose($sink);}
            }
            if(!is_string($raw)||strlen($raw)>self::MAX_BYTES)return null;
            $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            return is_array($body)?self::validate($body,$from,$to,$now??(int)round(microtime(true)*1000)):null;
        }catch(\Throwable $e){return null;}
    }
}
