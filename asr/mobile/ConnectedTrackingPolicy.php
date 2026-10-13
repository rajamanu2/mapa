<?php
namespace App\MapaMobile;
final class ConnectedTrackingPolicy {
 public static function supervisedPilot(?object $call): bool {
  if(!$call)return false;
  $draft=json_decode((string)($call->draft??'{}'),true);
  return is_array($draft)&&($draft['supervised_pilot']??false)===true;
 }
 public static function token(string $s): bool { return preg_match('/^[a-f0-9]{64}$/D',$s)===1; }
 public static function active(?object $link,int $now): bool { return $link && $link->revoked_at===null && (int)$link->expires_at>$now; }
 public static function closed(string $status): bool { return in_array(strtolower($status),['completed','canceled','cancelled','failed','dismissed'],true); }
 public static function fix(array $b,int $now,int $previous=0): bool { return Policy::freshFix($b,$now,$previous); }
}
