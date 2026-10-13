<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceCommandController.php';
require_once __DIR__.'/SourceWorkflowClient.php';
class SourceWorkflowController extends SourceCommandController {
    protected function protocol():string{return \MapaSource\SourceWorkflowCommands::class;}
    protected function configured():bool{return (bool)config('mapa_mobile.source_sync_enabled',false)
        &&(bool)config('mapa_mobile.source_workflows_enabled',false)&&$this->client()->configured();}
    protected function client():SourceCommandClient{return new SourceWorkflowClient(storage_path('app/mapa-source-sync/workflow.json'));}
}
