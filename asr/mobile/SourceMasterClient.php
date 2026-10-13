<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceCommandClient.php';
require_once __DIR__.'/SourceMasterCommands.php';
class SourceMasterClient extends SourceCommandClient {
    public const URL='https://asrhealth.in/mapa-source-master.php';
    protected function protocol():string{return \MapaSource\SourceMasterCommands::class;}
    protected function validateReceipt(array $receipt,array $command):void {
        if(str_ends_with($command['action'],'.create')){
            if(!is_string($receipt['created_id']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$receipt['created_id']))throw new SourceCommandUncertain('Invalid created source identity.');
            unset($receipt['created_id']);
        }
        parent::validateReceipt($receipt,$command);
    }
}
