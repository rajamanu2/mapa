<?php
require '/fleetbase/api/app/AsrImport/ImportPlan.php';
require '/fleetbase/api/app/AsrImport/SupplementPlan.php';
require '/fleetbase/api/app/AsrImport/SupplementApply.php';
try{
    $plan=\ASR\Import\SupplementPlan::unpack($asrSupplementInput??[]);
    require '/fleetbase/api/vendor/autoload.php';$app=require '/fleetbase/api/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $result=\ASR\Import\SupplementApply::apply($plan,$asrSupplementInput['expected_plan_sha256']);
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(\Throwable $error){fwrite(STDERR,'Reviewed hospital supplement failed: '.get_class($error).PHP_EOL);exit(1);}
