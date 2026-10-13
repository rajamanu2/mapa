<?php
namespace App\MapaText;
final class SecurityPolicy {
 public static function oauth(array $claims,string $client,string $audience,string $company,int $now): bool {
  $aud=$claims['aud']??[];if(is_string($aud))$aud=[$aud];
  $scope=is_string($claims['scope']??null)?preg_split('/\s+/',trim($claims['scope'])):[];
  return ($claims['active']??false)===true&&$client!==''&&$company!==''&&$audience!==''
   &&is_string($claims['client_id']??null)&&hash_equals($client,$claims['client_id'])
   &&is_string($claims['company_uuid']??null)&&hash_equals($company,$claims['company_uuid'])
   &&is_array($aud)&&in_array($audience,$aud,true)&&in_array('mapa:text:send',$scope,true)
   &&is_int($claims['exp']??null)&&$claims['exp']>$now
   &&(!isset($claims['nbf'])||(is_int($claims['nbf'])&&$claims['nbf']<=$now));
 }
 public static function httpsEndpoint(string $url): bool {
  $u=parse_url($url);return is_array($u)&&($u['scheme']??'')==='https'&&isset($u['host'])
   &&!isset($u['user'])&&!isset($u['pass'])&&!isset($u['fragment']);
 }
 public static function signature(string $raw,string $signature,string $secret): bool {
  return strlen($secret)>=32&&preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)===1
   &&hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),$signature);
 }
}
