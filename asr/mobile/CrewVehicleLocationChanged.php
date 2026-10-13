<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Broadcasting\Channel;

/** Native-compatible GPS event with server-owned mobile tenant channels.
 * A mobile bearer does not establish the native console session/API credential.
 */
final class CrewVehicleLocationChanged extends VehicleLocationChanged
{
    private string $mobileCompany;
    public function __construct(Vehicle $vehicle, string $company, array $additionalData = [])
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$company)
            || $vehicle->company_uuid !== $company) throw new \InvalidArgumentException('Mobile GPS event tenant mismatch.');
        $this->mobileCompany=$company;
        parent::__construct($vehicle, $additionalData);
    }
    public function broadcastOn()
    {
        return [new Channel('company.'.$this->mobileCompany),
            new Channel('vehicle.'.$this->vehicleId),new Channel('vehicle.'.$this->vehicleUuid)];
    }
}
