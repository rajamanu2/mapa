<?php
namespace App\MapaMobile;

/** Pure invariants shared by the HTTP module and isolated contract tests. */
final class Policy
{
    public const CONTRACT = 'mapa-native-mobile-v1';
    public const ROLES = ['crew', 'hospital', 'operator', 'admin', 'superadmin'];
    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        return array_map([self::class, 'canonical'], $value);
    }
    public static function fingerprint(string $operation, array $body): string
    {
        return hash('sha256', $operation . ':' . json_encode(self::canonical($body), JSON_THROW_ON_ERROR));
    }
    public static function capabilities(string $role, bool $vehicle): array
    {
        $crew = $role === 'crew';
        return ['duty' => $crew && $vehicle, 'gps' => $crew && $vehicle,
            'orders' => in_array($role, ['crew', 'admin', 'superadmin'], true),
            'acknowledge' => $crew && $vehicle, 'activities' => false,
            'photo_upload' => false, 'hospital_response' => false, 'admin_writes' => false];
    }
    public static function validAction(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $id) === 1;
    }
    public static function scopeHash(string $company, string $user, string $role, ?string $driver, ?string $vehicle, ?string $place): string
    {
        return hash('sha256',json_encode([$company,$user,$role,$driver,$vehicle,$place],JSON_THROW_ON_ERROR));
    }
    public static function bindingMatches(array $binding, array $session, string $company): bool
    {
        return ($binding['enabled'] ?? false) && in_array($binding['role'] ?? '', self::ROLES, true)
            && ($binding['company_uuid'] ?? null) === $company
            && ($session['company_uuid'] ?? null) === $company
            && ($binding['user_uuid'] ?? null) === ($session['user_uuid'] ?? '')
            && (int) ($binding['revision'] ?? 0) === (int) ($session['binding_revision'] ?? -1);
    }
    public static function ownsOrder(string $company, string $driver, array $order): bool
    {
        return ($order['company_uuid'] ?? null) === $company && ($order['driver_assigned_uuid'] ?? null) === $driver;
    }
    public static function canAcknowledge(string $company, string $driver, ?string $vehicle, array $order): bool
    {
        return $vehicle !== null && self::ownsOrder($company, $driver, $order)
            && (bool) ($order['dispatched'] ?? false)
            && !in_array(strtolower((string) ($order['status'] ?? '')), ['created','scheduled','completed','canceled','cancelled','failed'], true)
            && (!( $order['vehicle_assigned_uuid'] ?? null) || $order['vehicle_assigned_uuid'] === $vehicle);
    }
    public static function freshFix(array $fix, int $now, int $previous): bool
    {
        foreach (['latitude','longitude','recorded_at','accuracy_m'] as $key)
            if (!isset($fix[$key]) || !is_numeric($fix[$key]) || !is_finite((float) $fix[$key])) return false;
        return abs((float) $fix['latitude']) <= 90 && abs((float) $fix['longitude']) <= 180
            && !((float) $fix['latitude'] === 0.0 && (float) $fix['longitude'] === 0.0)
            && (float) $fix['accuracy_m'] >= 0 && (float) $fix['accuracy_m'] <= 100
            && (int) $fix['recorded_at'] >= $now - 300000 && (int) $fix['recorded_at'] <= $now + 30000
            && (int) $fix['recorded_at'] > $previous;
    }
}
