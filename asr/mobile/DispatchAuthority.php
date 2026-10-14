<?php
declare(strict_types=1);
namespace App\MapaMobile;

/** Explicit ownership cutover; source provenance and phone readiness are separate. */
final class DispatchAuthority
{
    public static function mapaControls(): bool
    {
        return function_exists('config') && config('mapa_mobile.dispatch_authority', 'asr') === 'mapa';
    }

    /** Cutover never releases unresolved ownership or silently discards an outbox. */
    public static function assertNoResidualSourceWork(string $company): void
    {
        if (!self::mapaControls()) return;
        try {
            $pending = \Illuminate\Support\Facades\DB::table('mapa_source_journeys')
                ->where('company_uuid', $company)->whereNull('terminal_at')->exists()
                || \Illuminate\Support\Facades\DB::table('mapa_source_dispatch_outbox')
                    ->where('company_uuid', $company)->where('state', '<>', 'confirmed')->exists();
        } catch (\Throwable $e) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(503, 'Dispatch ownership could not be verified. Contact control.');
        }
        if ($pending) throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'Unresolved source journey ownership must be reconciled before MAPA dispatch.');
    }
}
