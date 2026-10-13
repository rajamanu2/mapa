<?php
/** Root-reviewed resource-only CLI apply. Source drivers/users are deliberately never written. */
require '/fleetbase/api/app/AsrImport/ImportPlan.php';
require '/fleetbase/api/app/AsrImport/ImportApply.php';
use ASR\Import\ImportPlan;
try {
    if(!isset($asrImportInput) || !is_array($asrImportInput))throw new RuntimeException('Explicit reviewed input is required.');
    $plan=ImportPlan::unpack($asrImportInput);
    $reviewedDigest=$asrImportInput['expected_plan_sha256'];
    require '/fleetbase/api/vendor/autoload.php';
    $app=require '/fleetbase/api/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $result=\ASR\Import\ImportApply::apply($plan,$reviewedDigest);
    echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,'Resource import receipt unconfirmed: '.get_class($error).PHP_EOL);exit(1);}
