<?php
namespace App\MapaVoice;
use App\MapaMobile\MobileService;
use Fleetbase\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
/** Reuse the exact native mobile membership, verification and MFA gates. */
class CrewEligibility extends MobileService {
    public function allows(object $binding,User $user): bool {
        try{$c=$this->resolve($binding,$user,false);return $c['binding']->role==='crew'&&$c['driver']&&$c['vehicle'];}
        catch(HttpExceptionInterface $e){if(in_array($e->getStatusCode(),[401,403],true))return false;throw $e;}
    }
}
