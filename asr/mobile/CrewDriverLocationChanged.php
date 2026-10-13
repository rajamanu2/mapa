<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Events\DriverLocationChanged;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Broadcasting\Channel;

/** Native-compatible GPS event with server-owned mobile tenant channels.
 * A mobile bearer does not establish the native console session/API credential.
 */
final class CrewDriverLocationChanged extends DriverLocationChanged
{
    private string $mobileCompany;
    public function __construct(Driver $driver, string $company)
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$company)
            || $driver->company_uuid !== $company) throw new \InvalidArgumentException('Mobile GPS event tenant mismatch.');
        $this->mobileCompany=$company;
        parent::__construct($driver);
    }
    public function broadcastOn()
    {
        return [new Channel('company.'.$this->mobileCompany),
            new Channel('driver.'.$this->driverId),new Channel('driver.'.$this->driverUuid)];
    }
}
