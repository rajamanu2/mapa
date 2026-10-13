<?php
namespace App\MapaVoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact, fail-closed scope for the first live automatic-dispatch pilot. */
final class AutomaticDispatchScope
{
    /** @return array{call_id:string,driver_uuids:list<string>}|null */
    public static function parse(mixed $callId, mixed $driverUuids): ?array
    {
        if (!is_string($callId) || !Policy::uuid($callId) || !is_string($driverUuids)) return null;
        $parts = explode(',', $driverUuids);
        if (count($parts) < 1 || count($parts) > AutomaticDispatchPolicy::MAX_OFFERS) return null;
        $drivers = [];
        foreach ($parts as $part) {
            $uuid = strtolower(trim($part));
            if (!Policy::uuid($uuid) || in_array($uuid, $drivers, true)) return null;
            $drivers[] = $uuid;
        }
        return ['call_id' => strtolower($callId), 'driver_uuids' => $drivers];
    }

    /** @return array{mode?:string,call_id:string,driver_uuids:list<string>}|null */
    public static function configured(): ?array
    {
        if (class_exists(Schema::class) && Schema::hasTable('mapa_dispatch_settings')) {
            $company = (string) config('mapa.company_uuid');
            if (!Policy::uuid($company)) return null;
            $setting = DB::table('mapa_dispatch_settings')->where('company_uuid', $company)->first();
            return $setting && (bool) $setting->enabled && $setting->mode === 'all_live'
                ? ['mode' => 'all_live', 'call_id' => '', 'driver_uuids' => []] : null;
        }
        return self::parse(config('mapa_voice.automatic_dispatch_pilot_call_id'),
            config('mapa_voice.automatic_dispatch_pilot_driver_uuids'));
    }

    /** The environment scope is an exception for one marked drill while all-live stays enabled. */
    private static function supervisedPilotScope(mixed $callId): ?array
    {
        $pilot = self::parse(config('mapa_voice.automatic_dispatch_pilot_call_id'),
            config('mapa_voice.automatic_dispatch_pilot_driver_uuids'));
        if (!$pilot || count($pilot['driver_uuids']) !== 2 || !is_string($callId)
            || !hash_equals($pilot['call_id'], strtolower($callId))) return null;
        $call = DB::table('mapa_voice_calls')->where('company_uuid', config('mapa.company_uuid'))
            ->where('id', $pilot['call_id'])->first(['provider', 'demo', 'draft']);
        return $call && $call->provider === 'manual' && !(bool) $call->demo
            && (Policy::meta($call->draft)['supervised_pilot'] ?? false) === true ? $pilot : null;
    }

    public static function modeForCallId(mixed $callId): ?string
    {
        $scope = self::configured();
        if ($scope === null) return null;
        if (($scope['mode'] ?? null) !== 'all_live') return 'pilot';
        return self::supervisedPilotScope($callId) ? 'pilot' : 'all_live';
    }

    public static function allowsCallId(mixed $callId): bool
    {
        $scope = self::configured();
        if (($scope['mode'] ?? null) === 'all_live') {
            if (!is_string($callId) || !Policy::uuid($callId)) return false;
            $call = DB::table('mapa_voice_calls')->where('company_uuid', config('mapa.company_uuid'))
                ->where('id', strtolower($callId))->first(['provider', 'demo', 'draft']);
            if (!$call || $call->provider !== 'manual' || (bool) $call->demo) return false;
            return (Policy::meta($call->draft)['supervised_pilot'] ?? false) === true
                ? self::supervisedPilotScope($callId) !== null : true;
        }
        return $scope !== null && is_string($callId) && hash_equals($scope['call_id'], strtolower($callId));
    }

    public static function allowsDriverUuid(mixed $driverUuid, mixed $callId = null): bool
    {
        $scope = self::configured();
        if (($scope['mode'] ?? null) === 'all_live') {
            if ($callId !== null && self::modeForCallId($callId) === 'pilot')
                return is_string($driverUuid) && Policy::uuid($driverUuid)
                    && in_array(strtolower($driverUuid), self::driverUuids($callId), true)
                    && DB::table('mapa_mobile_bindings')->where('company_uuid', config('mapa.company_uuid'))
                        ->where('driver_uuid', strtolower($driverUuid))->where('role', 'crew')->where('enabled', true)->exists();
            return is_string($driverUuid) && Policy::uuid($driverUuid)
                && DB::table('mapa_mobile_bindings')->where('company_uuid', config('mapa.company_uuid'))
                    ->where('driver_uuid', strtolower($driverUuid))->where('role', 'crew')->where('enabled', true)->exists();
        }
        return $scope !== null && is_string($driverUuid)
            && in_array(strtolower($driverUuid), $scope['driver_uuids'], true);
    }

    /** @return list<string> */
    public static function driverUuids(mixed $callId = null): array
    {
        if ($callId !== null && (self::configured()['mode'] ?? null) === 'all_live')
            return self::supervisedPilotScope($callId)['driver_uuids'] ?? [];
        return self::configured()['driver_uuids'] ?? [];
    }
}
