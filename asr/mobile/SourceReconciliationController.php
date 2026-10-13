<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SourceReconciliationController
{
    public function index(Request $request) {
        return $this->respond(function()use($request){
            $context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            $page=$request->query('page','1');$status=$request->query('status','pending');
            if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,4}$/D',$page)||!is_string($status)||!in_array($status,['pending','acknowledged','all'],true)
                ||array_diff(array_keys($request->query()),['page','status']))MobileService::fail('Invalid review filters.',422);
            return ['contract'=>'mapa-asr-reconciliation-v1','company_id'=>$context['company']]+(new SourceReconciliation)->listing($context,$status,(int)$page);
        });
    }
    public function acknowledge(Request $request,string $event) {
        return $this->respond(function()use($request,$event){
            return DB::transaction(function()use($request,$event){
                $context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request,true);
                if($request->query()||strlen($request->getContent())>1024)MobileService::fail('Invalid acknowledgement.',422);
                try{$body=json_decode($request->getContent(),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable $e){MobileService::fail('Invalid acknowledgement.',422);}
                if($body!==['reviewed'=>true])MobileService::fail('Confirm that this source change was reviewed.',422);
                return ['contract'=>'mapa-asr-reconciliation-v1','company_id'=>$context['company']]+(new SourceReconciliation)->acknowledge($context,$event);
            },3);
        });
    }
    private function respond(callable $fn) {
        try{$body=$fn();$status=200;}
        catch(HttpExceptionInterface $e){$body=['error'=>$e->getMessage()];$status=$e->getStatusCode();}
        catch(\Throwable $e){$body=['error'=>'Source review queue is unavailable. Existing acknowledgements have been kept.'];$status=503;}
        return response()->json($body,$status)->header('Cache-Control','no-store, private')->header('X-Content-Type-Options','nosniff');
    }
}
