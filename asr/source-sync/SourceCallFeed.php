<?php
declare(strict_types=1);
namespace MapaSource;

/** Separate, explicitly enabled caller feed; the fleet export stays non-clinical. */
final class SourceCallFeed
{
    public const CONTRACT='mapa-asr-caller-feed-v1';
    public const MAX_BYTES=4194304;
    public const FIELDS=['id','incident_number','caller_name','caller_phone','patient_name','patient_age','patient_gender','incident_type','priority','from_phc_id','from_secretariat_id','from_village_id','pickup_address','to_phc_id','destination_facility','notes','status','created_at','updated_at'];
    public static function signature(string $secret,string $timestamp,string $nonce):string {return hash_hmac('sha256',"GET\n".self::CONTRACT."\n".$timestamp."\n".$nonce,$secret);}
    public static function responseSignature(string $secret,string $nonce,string $body):string {return hash_hmac('sha256',self::CONTRACT."\n".$nonce."\n".$body,$secret);}
    public static function authorized(array $server,string $secret,int $now):bool {
        $ts=$server['HTTP_X_MAPA_TIMESTAMP']??'';$nonce=$server['HTTP_X_MAPA_NONCE']??'';$sig=$server['HTTP_X_MAPA_SIGNATURE']??'';
        return strlen($secret)>=32&&($server['REQUEST_METHOD']??'')==='GET'&&($server['QUERY_STRING']??'')===''
            &&preg_match('/^[0-9]{10}$/D',$ts)&&abs($now-(int)$ts)<=30&&preg_match('/^[a-f0-9]{32}$/D',$nonce)
            &&preg_match('/^[a-f0-9]{64}$/D',$sig)&&hash_equals(self::signature($secret,$ts,$nonce),$sig);
    }
    public static function validate(array $feed,string $company,int $now):void {
        if(($feed['contract']??null)!==self::CONTRACT||($feed['company_id']??null)!==$company||($feed['source']??null)!=='https://asrhealth.in'
            ||!is_int($feed['generated_at']??null)||abs($feed['generated_at']-$now)>120||!is_bool($feed['truncated']??null)
            ||!is_array($feed['calls']??null)||!array_is_list($feed['calls'])||count($feed['calls'])>500)throw new \RuntimeException('Invalid caller feed.');
        $seen=[];$expected=self::FIELDS;sort($expected);
        foreach($feed['calls'] as $row){
            if(!is_array($row))throw new \RuntimeException('Invalid caller row.');$keys=array_keys($row);sort($keys);
            if($keys!==$expected||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$row['id'])||isset($seen[$row['id']]))throw new \RuntimeException('Invalid caller identity.');$seen[$row['id']]=true;
            foreach($row as $key=>$value)if($value!==null&&(!is_scalar($value)||is_bool($value)||strlen((string)$value)>4000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',(string)$value)))throw new \RuntimeException('Invalid caller field.');
            if(in_array($row['status'],['completed','cancelled'],true))throw new \RuntimeException('Closed incident in active feed.');
        }
    }
    public static function revision(array $row):string {return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    public static function export(\PDO $db,string $company):array {
        $db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
        try{$rows=$db->query('SELECT '.implode(',',self::FIELDS)." FROM cad_incidents WHERE status NOT IN ('completed','cancelled') ORDER BY id DESC LIMIT 501")->fetchAll(\PDO::FETCH_ASSOC);
            $feed=['contract'=>self::CONTRACT,'company_id'=>$company,'source'=>'https://asrhealth.in','generated_at'=>time(),'truncated'=>count($rows)>500,'calls'=>array_slice($rows,0,500)];self::validate($feed,$company,time());return $feed;
        }finally{$db->rollBack();}
    }
}
