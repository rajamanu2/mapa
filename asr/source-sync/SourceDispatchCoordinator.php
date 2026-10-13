<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceCommands.php';
/** Compatibility for original ASR handlers. MAPA neither locks nor owns ASR trips. */
final class SourceDispatchCoordinator {
 public static function acquire(\PDO $db):void {}
 public static function release(\PDO $db):void {}
 public static function withLock(\PDO $db,callable $fn):mixed {return $fn();}
 public static function assertAvailable(\PDO $db,string $vehicle,?string $driver=null):void {}
 public static function assertNoMapaReservation(\PDO $db,?string $vehicle,?string $driver=null):void {}
 public static function reserve(\PDO $db,string $company,string $journey,string $vehicle,string $driver):array {throw new CommandRejected(503,'ASR integration is display-only. Dispatch in ASR Health.');}
 public static function transition(\PDO $db,string $company,string $journey,int $version,string $next):array {throw new CommandRejected(503,'ASR integration is display-only. Update the trip in ASR Health.');}
}
