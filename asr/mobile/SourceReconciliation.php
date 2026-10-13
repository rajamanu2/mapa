<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceJournal.php';
use Illuminate\Support\Facades\DB;
use MapaSource\SourceJournal;

/** Durable admin review queue. No native assignment, source writeback or notification delivery. */
final class SourceReconciliation
{
    public function ingest(string $company): array {
        return DB::transaction(function()use($company){
            DB::table('mapa_source_reconciliation_cursors')->insertOrIgnore(['company_uuid'=>$company,'head'=>null,'updated_at'=>now()]);
            $cursor=DB::table('mapa_source_reconciliation_cursors')->where('company_uuid',$company)->lockForUpdate()->first();
            // Read after the lock so concurrent imports cannot regress the cursor.
            $state=SourceInventoryController::verifiedState($company);$head=$state['reconciliation_head']??null;
            $entries=SourceJournal::pending(storage_path('app/mapa-source-sync/state.json'),$head,$cursor->head,$company);
            foreach($entries as $entry) {
                $document=$entry['document'];
                foreach($document['events'] as $event) {
                    if(!in_array($event['dataset']??null,['drivers','ambulances'],true)
                        ||!is_string($event['source_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$event['source_id'])
                        ||!in_array($event['kind']??null,['added','changed','transfer_observed','removed_at_source'],true))
                        throw new \RuntimeException('Invalid reconciliation observation.');
                    $id=hash('sha256',implode('|',[$company,$entry['head'],$event['dataset'],$event['source_id'],$event['kind']]));
                    DB::table('mapa_source_reconciliation_events')->insertOrIgnore([
                        'id'=>$id,'company_uuid'=>$company,'generation'=>$entry['head'],'dataset'=>$event['dataset'],
                        'source_id'=>$event['source_id'],'kind'=>$event['kind'],'observation'=>json_encode($event,JSON_THROW_ON_ERROR),
                        'source_generated_at'=>$document['generated_at'],'observed_at'=>$document['observed_at'],'created_at'=>now()]);
                }
            }
            DB::table('mapa_source_reconciliation_cursors')->where('company_uuid',$company)->update(['head'=>$head,'updated_at'=>now()]);
            return ['journal_available'=>$head!==null,'received_at'=>$state['received_at'],'stale'=>time()-(int)$state['received_at']>120];
        },3);
    }
    public function listing(array $context,string $status,int $page): array {
        // The authenticated console may inspect source observations even when
        // native identity linking and review acknowledgements are disabled.
        // Those writes retain their separate SourceConnections permission gate.
        $reviewEnabled=(bool)config('mapa_mobile.source_connections_enabled',false);
        if($reviewEnabled)SourceConnections::permit($context,'read');
        $state=$this->ingest($context['company']);
        $base=DB::table('mapa_source_reconciliation_events')->where('company_uuid',$context['company']);
        $pending=(clone $base)->whereNull('acknowledged_at')->count();
        if($status==='pending')$base->whereNull('acknowledged_at');
        elseif($status==='acknowledged')$base->whereNotNull('acknowledged_at');
        elseif($status!=='all')MobileService::fail('Invalid review status.',422);
        $total=(clone $base)->count();
        $rows=$base->orderByDesc('observed_at')->orderBy('id')->offset(($page-1)*50)->limit(50)->get()->map(function($row){
            $out=(array)$row;$out['observation']=json_decode($row->observation,true,32,JSON_THROW_ON_ERROR);return $out;
        })->all();
        return $state+['contract'=>'mapa-asr-reconciliation-v1','rows'=>$rows,'pending_count'=>$pending,'total'=>$total,'page'=>$page,'page_size'=>50,'has_more'=>$page*50<$total,
            'review_only'=>true,'review_allowed'=>$reviewEnabled,'source_action_time_available'=>false];
    }
    public function acknowledge(array $context,string $event): array {
        SourceConnections::permit($context,'review');
        if(!preg_match('/^[a-f0-9]{64}$/D',$event))MobileService::fail('Invalid review event.',422);
        return DB::transaction(function()use($context,$event){
            $q=DB::table('mapa_source_reconciliation_events')->where(['company_uuid'=>$context['company'],'id'=>$event]);
            $row=$q->lockForUpdate()->first();if(!$row)MobileService::fail('Review event not found.',404);
            $replayed=$row->acknowledged_at!==null;
            if(!$replayed)$q->update(['acknowledged_by'=>$context['user']->uuid,'acknowledged_at'=>now()]);
            $row=$q->first();
            return ['id'=>$event,'acknowledged_at'=>$row->acknowledged_at,'acknowledged_by'=>$row->acknowledged_by,'replayed'=>$replayed,'review_only'=>true];
        },3);
    }
}
