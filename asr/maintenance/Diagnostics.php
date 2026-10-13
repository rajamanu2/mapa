<?php
namespace App\MapaMaintenance;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Diagnostics
{
    public function collect(string $company): array
    {
        $checks = [];
        $probe = function (string $name, callable $fn) use (&$checks) {
            try { $checks[$name] = $fn(); }
            catch (\Throwable $e) { $checks[$name] = ['status' => 'unavailable', 'next' => 'Check this service in deployment monitoring.']; }
        };
        $probe('database', function () { DB::select('SELECT 1'); return ['status' => 'ok']; });
        $probe('cache', function () {
            $key = 'mapa:maintenance:probe:'.Str::uuid();
            try { Cache::put($key, 'ok', 30); return ['status' => Cache::get($key) === 'ok' ? 'ok' : 'failed']; }
            finally { Cache::forget($key); }
        });
        foreach (['mapa_mobile_sessions', 'mapa_voice_calls', 'ai_tasks', 'mapa_maintenance_runs'] as $table) {
            $probe('schema:'.$table, fn () => ['status' => Schema::hasTable($table) ? 'ok' : 'missing', 'next' => 'Apply pending release migrations through deployment.']);
        }
        $probe('ai_connection', function () {
            $config = Setting::system('ai', []); $provider = $config['provider'] ?? 'local';
            $configured = !empty($config['enabled']) && in_array($provider, ['openai', 'anthropic'], true) && !empty($config['providers'][$provider]['api_key']);
            return ['status' => $configured ? 'configured_not_tested' : 'not_configured', 'provider' => $provider,
                'model' => $config['default_model'] ?? null, 'next' => 'Use AI Provider Settings to connect and test a model.'];
        });
        $probe('stuck_ai_tasks', fn () => ['status' => 'checked', 'count' => DB::table('ai_tasks')->where('company_uuid', $company)->where('status', 'running')->where('updated_at', '<', now()->subMinutes(15))->whereNull('deleted_at')->count(), 'repair' => 'recover_stuck_ai_tasks']);
        $probe('expired_mobile_sessions', fn () => ['status' => 'checked', 'count' => DB::table('mapa_mobile_sessions')->where('company_uuid', $company)->whereNull('revoked_at')->where('expires_at', '<=', (int) floor(microtime(true)*1000))->count(), 'repair' => 'revoke_expired_sessions']);
        return ['contract' => 'mapa-maintenance-v1', 'checked_at' => now()->toIso8601String(), 'checks' => $checks,
            'limits' => ['No caller records, transcripts, location coordinates, credentials or raw logs are included.',
                'Database and cache probes do not prove phone, GPS, dispatch, queue worker or provider availability.',
                'Code defects require a tested software release; this capability cannot execute code, shell commands or model-generated SQL.']];
    }
}
