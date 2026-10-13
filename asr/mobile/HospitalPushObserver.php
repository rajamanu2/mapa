<?php
namespace App\MapaMobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
/** Optional after-commit hint. Report persistence and hospital acknowledgement stay authoritative. */
class HospitalPushObserver {
 public function created(object $row): void {
  try {
   if (!(new PushService)->hospitalAvailable()) return;
   $job=new DeliverHospitalPush($row->id,$row->company_uuid,$row->place_uuid,hash('sha256','hospital:'.$row->id.':'.$row->revision.':'.$row->place_uuid),time(),(int)$row->revision);
   DB::afterCommit(function()use($job){try{Bus::dispatch($job);}catch(\Throwable $e){$this->warn();}});
  } catch(\Throwable $e){$this->warn();}
 }
 private function warn(): void {try{Log::warning('MAPA hospital alert could not be queued; control must verify hospital acknowledgement.');}catch(\Throwable $ignored){}}
}
