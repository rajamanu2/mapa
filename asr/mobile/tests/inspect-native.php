<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['users','companies','company_users','drivers','vehicles','places','orders','payloads','order_configs','tracking_numbers','tracking_statuses','positions'] as $table) {
    echo "$table required columns:\n";
    foreach (Illuminate\Support\Facades\DB::select("SHOW COLUMNS FROM `$table`") as $col)
        if ($col->Null === 'NO' && $col->Default === null && !str_contains($col->Extra,'auto_increment')) echo $col->Field.' '.$col->Type."\n";
}
