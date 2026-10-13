<?php
namespace App\MapaMobile;
/** Explicit worker invocation; installing a timer is a separate commissioning action. */
class SourceDispatchDrainCommand extends \Illuminate\Console\Command {
    protected $signature='mapa:source-dispatch-drain {--limit=20}';
    protected $description='Deliver ordered source ownership transitions from verified native activities';
    public function handle():int {
        $raw=(string)$this->option('limit');if(!preg_match('/^[1-9][0-9]{0,2}$/D',$raw)||(int)$raw>100){$this->error('Limit must be 1 to 100.');return 1;}
        try{$r=(new SourceDispatchSaga)->drain((int)$raw);$this->line(json_encode($r,JSON_THROW_ON_ERROR));return $r['held']?2:0;}
        catch(\Throwable $e){$this->error('Source dispatch delivery is unavailable; durable ownership was retained.');return 1;}
    }
}
