<?php
namespace App\MapaVoice;

use App\MapaMobile\ConsoleEnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Company-scoped switch for automatic offers on newly reviewed MAPA live calls. */
final class DispatchSettingsService extends VoiceService
{
    private function consoleStaff(Request $request, bool $lock = false): array {
        $this->company();
        return (new ConsoleEnrollmentService)->context($request, $lock);
    }

    private function ready(): void {
        if (!Schema::hasTable('mapa_dispatch_settings') || !Schema::hasTable('mapa_dispatch_settings_audit'))
            self::fail('Automatic dispatch settings are not installed.', 503);
    }

    private function workerFresh(?object $row): bool {
        if (!$row?->last_tick_at) return false;
        $lastTick = \Carbon\Carbon::parse($row->last_tick_at, 'UTC');
        return $lastTick->greaterThan(now()->subSeconds(35))
            && $lastTick->lessThanOrEqualTo(now()->addSeconds(5));
    }

    private function state(string $company): array {
        $row = DB::table('mapa_dispatch_settings')->where('company_uuid', $company)->first();
        $enabled = (bool) ($row->enabled ?? false);
        $lastTick = $row?->last_tick_at ? \Carbon\Carbon::parse($row->last_tick_at, 'UTC') : null;
        $workerFresh = $this->workerFresh($row);
        $crew = DB::table('mapa_mobile_bindings')->where('company_uuid', $company)
            ->where('role', 'crew')->where('enabled', true)->whereNotNull('driver_uuid')->count();
        return ['contract' => 'mapa-dispatch-settings-v1', 'enabled' => $enabled,
            'mode' => 'all_live', 'revision' => (int) ($row->revision ?? 0),
            'server_ready' => (bool) config('mapa_voice.enabled') && (bool) config('mapa_voice.manual_enabled')
                && (bool) config('mapa_voice.automatic_dispatch_enabled')
                && Schema::hasTable('mapa_dispatch_runs') && Schema::hasTable('mapa_dispatch_offers')
                && !\App\MapaMobile\SourceDispatchSaga::enabled() && $workerFresh,
            'worker_fresh' => (bool) $workerFresh,
            'last_tick_at' => $lastTick?->toIso8601String(), 'enrolled_crews' => $crew,
            'server_time' => now()->toIso8601String()];
    }

    public function showSettings(Request $request): array {
        $this->ready();
        $staff = $this->consoleStaff($request);
        return $this->state($staff['company']);
    }

    public function saveSettings(Request $request): array {
        $this->ready();
        $body = $request->validate(['enabled' => 'required|boolean',
            'expected_revision' => 'required|integer|min:0', 'confirmed' => 'required|accepted']);
        if ($request->json('confirmed') !== true || !is_bool($request->json('enabled')))
            self::fail('Explicit confirmation is required.', 422);
        $company = $this->consoleStaff($request)['company'];
        return DB::transaction(function () use ($request, $body, $company) {
            $this->lockTenant($company);
            $staff = $this->consoleStaff($request, true);
            $row = DB::table('mapa_dispatch_settings')->where('company_uuid', $company)->lockForUpdate()->first();
            $revision = (int) ($row->revision ?? 0);
            if ($revision !== (int) $body['expected_revision'])
                self::fail('Automatic dispatch settings changed. Refresh before saving.', 409);
            $enabled = $request->json('enabled');
            if ($enabled && (!(bool) config('mapa_voice.enabled') || !(bool) config('mapa_voice.manual_enabled')
                || !(bool) config('mapa_voice.automatic_dispatch_enabled')
                || !Schema::hasTable('mapa_dispatch_runs') || !Schema::hasTable('mapa_dispatch_offers')
                || \App\MapaMobile\SourceDispatchSaga::enabled() || !$this->workerFresh($row)))
                self::fail('The server worker is not ready. Automatic dispatch remains off.', 409);
            if ($enabled && DB::table('mapa_mobile_bindings')->where('company_uuid', $company)
                ->where('role', 'crew')->where('enabled', true)->whereNotNull('driver_uuid')->count() < 1)
                self::fail('Enroll at least one MAPA crew phone before enabling automatic dispatch.', 409);
            $next = $revision + 1;
            if ($row) DB::table('mapa_dispatch_settings')->where('company_uuid', $company)->update([
                'enabled' => $enabled, 'mode' => 'all_live', 'revision' => $next,
                'updated_by' => $staff['user']->uuid, 'updated_at' => now()]);
            else DB::table('mapa_dispatch_settings')->insert([
                'company_uuid' => $company, 'enabled' => $enabled, 'mode' => 'all_live',
                'revision' => $next, 'updated_by' => $staff['user']->uuid,
                'created_at' => now(), 'updated_at' => now()]);
            if (!$enabled) {
                $ids = DB::table('mapa_dispatch_runs')->where('company_uuid', $company)
                    ->whereIn('state', ['searching', 'offering'])->pluck('call_id')->all();
                if ($ids) {
                    DB::table('mapa_dispatch_offers')->whereIn('call_id', $ids)
                        ->whereIn('state', ['offered', 'overdue'])->update([
                            'state' => 'cancelled', 'responded_at' => now(), 'updated_at' => now()]);
                    DB::table('mapa_dispatch_runs')->whereIn('call_id', $ids)->update([
                        'state' => 'stopped', 'reason' => 'settings_disabled', 'updated_at' => now()]);
                }
            }
            DB::table('mapa_dispatch_settings_audit')->insert([
                'company_uuid' => $company, 'user_uuid' => $staff['user']->uuid,
                'enabled' => $enabled, 'revision' => $next, 'created_at' => now()]);
            return $this->state($company);
        }, 3);
    }
}
