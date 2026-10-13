<?php
namespace App\MapaVoice {
    function config(string $name,$default=null){return $GLOBALS['maps_key']??$default;}
}
namespace {
    require dirname(__DIR__).'/PickupMapConfig.php';
    foreach(['', 'unrelated-token', 'AIza'.str_repeat('x',34)] as $key){
        $GLOBALS['maps_key']=$key;$r=\App\MapaVoice\PickupMapConfig::get();
        if($r['provider']!=='local'||$r['google_maps_api_key']!==''||$r['google_maps_configured']!==false)throw new \RuntimeException('Unusable credential was exposed');
    }
    $GLOBALS['maps_key']='AIza'.str_repeat('x',35);$r=\App\MapaVoice\PickupMapConfig::get();
    if($r['provider']!=='google'||$r['google_maps_api_key']!==$GLOBALS['maps_key']||$r['google_maps_configured']!==true)throw new \RuntimeException('Browser key contract failed');
    echo "Pickup map config: 4 checks passed; no provider request made.\n";
}
