<?php
$path = '/fleetbase/api/config/app.php';
$content = file_get_contents($path);
$anchor = 'App\\Providers\\AppServiceProvider::class,';
$provider = 'App\\Providers\\MapaServiceProvider::class,';
$content = str_replace('App\\Providers\\AsrFleetbaseServiceProvider::class,', '', $content);
if (!str_contains($content, $anchor)) {
    fwrite(STDERR, "MAPA upstream provider registration changed; inspect config/app.php.\n");
    exit(1);
}
if (!str_contains($content, $provider)) {
    $content = str_replace($anchor, $anchor . "\n        " . $provider, $content);
}
file_put_contents($path, $content);

