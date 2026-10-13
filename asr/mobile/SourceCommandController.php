<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceCommandClient.php';
require_once __DIR__.'/SourceReceiver.php';
use Illuminate\Http\Request;
use MapaSource\SourceCommands;
use MapaSource\SourceSync;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Approved native admins may request narrowly scoped edits in the authoritative source. */
class SourceCommandController
{
    protected function protocol():string{return SourceCommands::class;}
    public function index(Request $request)
    {
        try {
            $protocol=$this->protocol();$context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            $enabled=$context['company']===$this->authoritativeCompany() && $this->configured();
            $actions=$enabled?$this->allowedActions((string)$context['user']->uuid):[];
            $enabled=$enabled&&count($actions)>0;
            return $this->response(['contract'=>$protocol::CONTRACT,'company_id'=>$context['company'],
                'enabled'=>$enabled,'actions'=>$actions,
                'source'=>'https://asrhealth.in','source_authoritative'=>true,'conflict_check_required'=>true]);
        }catch(HttpExceptionInterface $e){return $this->response(['error'=>$e->getMessage()],$e->getStatusCode());}
        catch(\Throwable $e){return $this->response(['error'=>'ASR Health editing is unavailable.'],503);}
    }
    public function store(Request $request)
    {
        $started=false;$id=null;
        try {
            $protocol=$this->protocol();$context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            if($context['company']!==$this->authoritativeCompany())MobileService::fail('ASR source organization mismatch.',403);
            if(!$this->configured())throw new SourceCommandUnavailable('ASR Health editing is not configured.');
            if($request->query() || !$request->isJson() || strlen($request->getContent())>$protocol::MAX_BYTES)
                MobileService::fail('A bounded JSON command without query parameters is required.',422);
            try{$input=json_decode($request->getContent(),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){MobileService::fail('Invalid command JSON.',422);}
            if(!is_array($input))MobileService::fail('Invalid command.',422);
            $keys=array_keys($input);sort($keys);$allowed=['request_id','action','record_id','expected_revision','values'];sort($allowed);
            if($keys!==$allowed)MobileService::fail('Unexpected or missing command fields.',422);
            // Identity always comes from the authenticated native membership, never the browser.
            $command=['contract'=>$protocol::CONTRACT,'company_id'=>$context['company'],
                'actor_id'=>(string)$context['user']->uuid]+$input;
            // The shape check uses the fixed protocol company. The real client separately validates the
            // actual envelope, while isolated tests can override authoritativeCompany without real data.
            $protocol::validate(array_replace($command,['company_id'=>SourceSync::COMPANY]));
            $id=$command['request_id'];$started=true;
            $receipt=$this->send($command);
            // A command may have committed while native access was revoked. Do not expose the receipt
            // after revocation and do not tell the client the write failed.
            $current=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            if($current['company']!==$context['company'] || $current['user']->uuid!==$context['user']->uuid)
                MobileService::fail('The native session changed.',401);
            $refresh='pending';try{$this->refreshSource();$refresh='complete';}catch(\Throwable $e){}
            $current=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            if($current['company']!==$context['company'] || $current['user']->uuid!==$context['user']->uuid)
                MobileService::fail('The native session changed.',401);
            return $this->response($receipt+['outcome'=>'applied','refresh_status'=>$refresh,
                'message'=>$refresh==='complete'?'Saved in ASR Health and refreshed.':'Saved in ASR Health. Fetch latest data to refresh the local view.']);
        } catch(\MapaSource\CommandRejected $e) {
            return $this->response(['error'=>'Invalid source command.','outcome'=>'not_sent'],$e->status);
        } catch(SourceCommandRefused $e) {
            return $this->response(['error'=>$e->getMessage(),'outcome'=>'rejected','request_id'=>$id],$e->status);
        } catch(SourceCommandUnavailable $e) {
            return $this->response(['error'=>'ASR Health editing is not configured.','outcome'=>'not_sent'],503);
        } catch(HttpExceptionInterface $e) {
            return $this->response(['error'=>$e->getMessage(),'outcome'=>$started?'unknown':'not_sent','request_id'=>$id],$e->getStatusCode());
        } catch(\Throwable $e) {
            return $this->response(['error'=>$started?'The save outcome is not confirmed. Retry the same request ID and unchanged values; do not create another edit.':'ASR Health editing is unavailable.',
                'outcome'=>$started?'unknown':'not_sent','request_id'=>$id],503);
        }
    }
    protected function authoritativeCompany():string{return SourceSync::COMPANY;}
    protected function configured():bool{return (bool)config('mapa_mobile.source_sync_enabled',false)
        && (bool)config('mapa_mobile.source_commands_enabled',false) && $this->client()->configured();}
    protected function client():SourceCommandClient{return new SourceCommandClient(storage_path('app/mapa-source-sync/command.json'));}
    protected function allowedActions(string $actor):array{return $this->client()->allowedActions($actor);}
    protected function send(array $command):array{return $this->client()->send($command);}
    protected function refreshSource():void{$dir=storage_path('app/mapa-source-sync');(new \MapaSource\SourceReceiver)->run($dir.'/receiver.json',$dir);}
    private function response(array $data,int $status=200){return response()->json($data,$status)->header('Cache-Control','private, no-store');}
}
