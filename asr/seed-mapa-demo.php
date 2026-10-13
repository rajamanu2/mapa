<?php
// MAPA.DEV local presentation fixtures only. Query-builder writes deliberately bypass
// model observers: no invitations, notifications, webhooks or dispatch events.
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Fleetbase\FleetOps\Models\Vehicle;

$company = (string) config('asr.company_uuid');
if (!preg_match('/^[0-9a-f-]{36}$/i', $company) || !DB::table('companies')->where('uuid', $company)->exists()) {
    throw new RuntimeException('Configure the MAPA organization before seeding.');
}
if (!app()->environment('local', 'testing')) {
    throw new RuntimeException('MAPA demo seeding is restricted to local/testing environments.');
}
$refresh = in_array('--refresh-positions', $argv, true);
$verifyOnly = in_array('--verify', $argv, true);
$namespace = 'mapa-demo-v1';
$meta = json_encode(['mapa_demo' => true, 'simulated' => true, 'demo_dataset' => $namespace, 'notice' => 'Fictional presentation data. Not a real ambulance, person, facility or GPS feed.']);
$now = now()->toDateTimeString();
$created = 0;
$unchanged = 0;
$refreshed = 0;
$manifest = [];
$id = static function (string $key) use ($company, $namespace): string {
    $h = hash('sha256', $namespace . ':' . $company . ':' . $key);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
};
$point = static fn (float $lat, float $lng) => DB::raw("ST_GeomFromText('POINT($lng $lat)', 0)");
// Reference coordinates near towns only, not surveyed facilities or boundaries.
$locations = [
    ['Paderu', 18.083, 82.669],
    ['Araku Valley', 18.327, 82.879],
    ['Chintapalli', 17.870, 82.344],
    ['Koyyuru', 17.674, 82.229],
    ['Munchingiputtu', 18.323, 82.508],
];

// Deterministic UUIDs plus ownership checks prevent touching unrelated records.
$put = static function (string $table, string $key, array $values) use ($company, $namespace, $id, $now, $verifyOnly, &$created, &$unchanged): string {
    $uuid = $id($key);
    $existing = DB::table($table)->where('uuid', $uuid)->first();
    if ($existing) {
        $owned = $table === 'positions'
            ? $existing->_key === $namespace
            : (json_decode($existing->meta ?? '{}', true)['demo_dataset'] ?? null) === $namespace;
        if ($existing->company_uuid !== $company || !$owned || $existing->deleted_at !== null) {
            throw new RuntimeException("Refusing to overwrite unowned or retired demo record: $table/$uuid");
        }
        $unchanged++;
    } elseif ($verifyOnly) {
        throw new RuntimeException("Missing demo record: $table/$uuid");
    } else {
        DB::table($table)->insert(array_merge($values, ['uuid' => $uuid, 'company_uuid' => $company, '_key' => $namespace, 'created_at' => $now, 'updated_at' => $now]));
        $created++;
    }
    return $uuid;
};

DB::transaction(function () use ($locations, $put, $meta, $point, $company, $refresh, $verifyOnly, $now, $namespace, &$refreshed, &$manifest) {
    foreach ($locations as $index => [$town, $lat, $lng]) {
        $n = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
        $publicVehicleId = 'vehicle_mapa_demo_' . $n;
        $vehicle = $put('vehicles', 'vehicle-' . $n, [
            'public_id' => $publicVehicleId, 'internal_id' => 'MAPA-DEMO-' . $n,
            'name' => 'DEMO Ambulance ' . $n . ' — ' . $town,
            'description' => 'MAPA synthetic presentation ambulance. Simulated position; not operational.',
            'plate_number' => 'DEMO-' . $n, 'call_sign' => 'DEMO-' . $n,
            'location' => $point($lat, $lng), 'heading' => 0, 'speed' => 0,
            'status' => 'active', 'online' => 1, 'meta' => $meta,
            'slug' => 'mapa-demo-ambulance-' . $n,
        ]);
        $user = $put('users', 'user-' . $n, [
            'public_id' => 'user_mapa_demo_' . $n, 'name' => 'DEMO Driver ' . $n,
            'email' => null, 'phone' => null, 'password' => null,
            'type' => 'driver', 'status' => 'inactive', 'country' => 'IN',
            'timezone' => 'Asia/Kolkata', 'meta' => $meta,
        ]);
        $driver = $put('drivers', 'driver-' . $n, [
            'public_id' => 'driver_mapa_demo_' . $n, 'internal_id' => 'DEMO-DRIVER-' . $n,
            'user_uuid' => $user, 'vehicle_uuid' => $vehicle,
            'location' => $point($lat, $lng), 'latitude' => $lat, 'longitude' => $lng,
            'country' => 'IN', 'city' => $town, 'currency' => 'INR',
            'online' => 1, 'status' => 'available', 'current_status' => 'DEMO — simulated availability',
            'slug' => 'mapa-demo-driver-' . $n, 'meta' => $meta,
        ]);
        $place = $put('places', 'place-' . $n, [
            'public_id' => 'place_mapa_demo_' . $n,
            'name' => 'DEMO ' . ($index % 2 === 0 ? 'Response Base' : 'Receiving Hospital') . ' ' . $n . ' — ' . $town,
            'street1' => 'Fictional presentation location; not a real facility',
            'city' => $town, 'province' => 'Andhra Pradesh', 'district' => 'Alluri Sitharama Raju', 'country' => 'IN',
            'type' => $index % 2 === 0 ? 'depot' : 'hospital',
            'location' => $point($lat + 0.005, $lng + 0.005), 'meta' => $meta,
        ]);
        $position = $put('positions', 'position-' . $n, [
            'subject_uuid' => $vehicle, 'subject_type' => (new Vehicle)->getMorphClass(),
            'coordinates' => $point($lat, $lng), 'speed' => 0, 'heading' => 0,
        ]);
        // One-time correction of the original fictional 04/05 fixtures to the
        // current ASR operating area. All four ownership checks above must pass.
        // Exact old names prevent overwriting later operator customizations.
        $oldTown = [3 => 'Rampachodavaram', 4 => 'Chinturu'][$index] ?? null;
        if ($oldTown && !$verifyOnly && DB::table('vehicles')->where('uuid', $vehicle)
            ->where('company_uuid', $company)->where('_key', $namespace)
            ->where('name', 'DEMO Ambulance ' . $n . ' — ' . $oldTown)->exists()) {
            DB::table('vehicles')->where('uuid', $vehicle)->where('company_uuid', $company)->where('_key', $namespace)
                ->update(['name' => 'DEMO Ambulance ' . $n . ' — ' . $town, 'location' => $point($lat, $lng), 'updated_at' => $now]);
            DB::table('drivers')->where('uuid', $driver)->where('company_uuid', $company)->where('_key', $namespace)
                ->update(['city' => $town, 'location' => $point($lat, $lng), 'latitude' => $lat, 'longitude' => $lng, 'updated_at' => $now]);
            DB::table('places')->where('uuid', $place)->where('company_uuid', $company)->where('_key', $namespace)
                ->update(['name' => 'DEMO ' . ($index % 2 === 0 ? 'Response Base' : 'Receiving Hospital') . ' ' . $n . ' — ' . $town,
                    'city' => $town, 'location' => $point($lat + 0.005, $lng + 0.005), 'updated_at' => $now]);
            DB::table('positions')->where('uuid', $position)->where('company_uuid', $company)->where('_key', $namespace)
                ->update(['coordinates' => $point($lat, $lng), 'updated_at' => $now]);
        }
        if ($refresh && !$verifyOnly) {
            // Refresh the one deterministic simulated fix; do not invent GPS history.
            DB::table('positions')->where('uuid', $position)->where('company_uuid', $company)->where('_key', $namespace)
                ->update(['coordinates' => $point($lat, $lng), 'created_at' => $now, 'updated_at' => $now]);
            $refreshed++;
        }
        $loaded = Vehicle::where('company_uuid', $company)->where('uuid', $vehicle)->firstOrFail();
        $fix = $loaded->positions()->where('company_uuid', $company)->where('uuid', $position)->firstOrFail();
        if (abs($fix->latitude - $lat) > 0.000001 || abs($fix->longitude - $lng) > 0.000001) {
            throw new RuntimeException('Position read-back failed for ' . $publicVehicleId);
        }
        $manifest[] = ['id' => $publicVehicleId, 'name' => $loaded->name, 'vehicle_uuid' => $vehicle, 'driver_uuid' => $driver, 'place_uuid' => $place, 'simulated' => true];
    }
});
$counts = [];
foreach (['vehicles', 'drivers', 'users', 'places', 'positions'] as $table) {
    $counts[$table] = DB::table($table)->where('company_uuid', $company)->where('_key', $namespace)->whereNull('deleted_at')->count();
    if ($counts[$table] !== 5) { throw new RuntimeException('Unexpected demo count: ' . $table); }
}
echo json_encode(['dataset' => $namespace, 'company_uuid' => $company, 'simulated' => true, 'created' => $created, 'unchanged' => $unchanged, 'positions_refreshed' => $refreshed, 'counts' => $counts, 'vehicles' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
