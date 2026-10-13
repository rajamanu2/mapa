<?php
namespace App\MapaVoice;
class AutomaticDispatchCommand extends \Illuminate\Console\Command {
    protected $signature='mapa:automatic-dispatch-tick';
    protected $description='Expire native MAPA offers and offer the next road-ranked eligible crew';
    public function handle(): int {
        try {$this->line(json_encode(['advanced'=>(new AutomaticDispatch)->tick()],JSON_THROW_ON_ERROR));return 0;}
        catch(\Throwable $e){$this->error('Automatic dispatch requires operator attention. No source operation was performed.');return 1;}
    }
}
