<?php
namespace App\MapaText;
final class DeliveryPolicy {
 public static function next(string $current,string $incoming): string {
  $rank=['queued'=>0,'sending'=>1,'unknown'=>1,'failed'=>1,'sent'=>2,'delivered'=>3,'read'=>4];
  if(!isset($rank[$incoming])||!isset($rank[$current]))return $current;
  if($incoming==='failed')return in_array($current,['delivered','read'],true)?$current:'failed';
  return $rank[$incoming]>$rank[$current]?$incoming:$current;
 }
 public static function outcome(int $http,array $body): array {
  $id=$body['messages'][0]['id']??null;
  if($http>=200&&$http<300&&is_string($id)&&strlen($id)<=200)return ['sent',$id];
  if($http>=400&&$http<500&&isset($body['error']))return ['failed',null];
  return ['unknown',null];
 }
}
