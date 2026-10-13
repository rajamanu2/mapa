<?php
// Source inspection only. Do not bootstrap Laravel, read .env or open a database.
require '/fleetbase/api/vendor/autoload.php';
$classes = [
    'Fleetbase\\FleetOps\\Models\\Driver'=>['getNameAttribute','getPhoneAttribute','getEmailAttribute'],
    'Fleetbase\\Models\\User'=>['setPasswordAttribute'],
    'Fleetbase\\FleetOps\\Models\\Vehicle'=>[],
    'Fleetbase\\FleetOps\\Models\\Place'=>[],
];
foreach ($classes as $class=>$methods) {
    $reflection=new ReflectionClass($class);$defaults=$reflection->getDefaultProperties();
    echo json_encode(['model'=>$class,'source'=>$reflection->getFileName(),'table'=>$defaults['table']??null,'fillable'=>$defaults['fillable']??[],
        'casts'=>$defaults['casts']??[],'appends'=>$defaults['appends']??[]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    foreach($methods as $method){if(!$reflection->hasMethod($method))continue;$m=$reflection->getMethod($method);$lines=file($m->getFileName());
        echo 'METHOD '.$class.'::'.$method.PHP_EOL.implode('',array_slice($lines,$m->getStartLine()-1,$m->getEndLine()-$m->getStartLine()+1)).PHP_EOL;}
}
foreach(['Fleetbase\\FleetOps\\Scopes\\DriverScope','Fleetbase\\FleetOps\\Casts\\Point'] as $class){$r=new ReflectionClass($class);echo 'SOURCE '.$r->getFileName().PHP_EOL.file_get_contents($r->getFileName()).PHP_EOL;}
foreach(array_merge(glob('/fleetbase/api/vendor/fleetbase/*/server/database/migrations/*.php'),glob('/fleetbase/api/vendor/fleetbase/*/server/migrations/*.php'),glob('/fleetbase/api/vendor/fleetbase/*/migrations/*.php')) as $filename){
    $source=file_get_contents($filename);
    if(preg_match('/Schema::create\([\'\"](?:drivers|vehicles|places|users)[\'\"]/', $source)) echo 'SCHEMA '.$filename.PHP_EOL.$source.PHP_EOL;
}
