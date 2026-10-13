<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class SourceConnectionController
{
    public function index(Request $r){return $this->guard($r,function($c)use($r){
        if(array_diff(array_keys($r->query()),['dataset','search','page','options','native_uuid']))MobileService::fail('Unknown connection filter.',422);
        $d=SourceConnections::dataset($r->query('dataset'));$search=$r->query('search','');$page=$r->query('page','1');$native=$r->query('native_uuid');
        if(!is_string($search)||mb_strlen($search)>100||!is_string($page)||!preg_match('/^[1-9][0-9]{0,4}$/D',$page)||($native!==null&&!SourceConnections::uuid($native)))MobileService::fail('Invalid connection filter.',422);
        if($r->query('options')!==null&&!in_array($r->query('options'),['0','1'],true))MobileService::fail('Invalid options filter.',422);
        $service=new SourceConnections;
        return $r->query('options')==='1'?$service->options($c,$d,$search,(int)$page):$service->listing($c,$d,$search,(int)$page,$native);
    });}
    public function store(Request $r){return $this->guard($r,function($c)use($r){if($r->query())MobileService::fail('This action takes no query.',422);return (new SourceConnections)->change($r,$r->json()->all());});}
    public function projection(Request $r,string $dataset,string $id){return $this->guard($r,function($c)use($r,$dataset,$id){
        if($r->query()||!SourceConnections::uuid($id))MobileService::fail('Invalid resource projection.',422);
        return (new SourceConnections)->projection($c,SourceConnections::dataset($dataset),$id);
    });}
    private function guard(Request $r,callable $fn){
        $company=null;try{$c=(new ConsoleEnrollmentService(new PrivateMedia))->context($r);$company=$c['company'];SourceConnections::permit($c,'read');$body=$fn($c);$status=200;}
        catch(HttpExceptionInterface $e){$body=['error'=>$e->getMessage()];$status=$e->getStatusCode();}catch(\Throwable $e){$body=['error'=>'Source connections are unavailable.'];$status=503;}
        return response()->json(['contract'=>SourceConnections::CONTRACT,'company_id'=>$company]+$body,$status)->header('Cache-Control','no-store, private')->header('X-Content-Type-Options','nosniff');
    }
}
