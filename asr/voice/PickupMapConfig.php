<?php
namespace App\MapaVoice;

/** Browser Maps key only, returned after VoiceService::staff authenticates the operator. */
class PickupMapConfig
{
    public static function get(): array
    {
        // Share the native console's existing admin-managed Maps configuration.
        $key = trim((string) config('services.google_maps.api_key', ''));
        $ready = preg_match('/^AIza[0-9A-Za-z_-]{35}$/', $key) === 1;
        return ['provider' => $ready ? 'google' : 'local', 'google_maps_api_key' => $ready ? $key : '',
            'google_maps_configured' => $ready];
    }
}
