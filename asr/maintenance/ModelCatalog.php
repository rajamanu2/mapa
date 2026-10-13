<?php
namespace App\MapaMaintenance;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ModelCatalog
{
    public static function cacheKey(array $config, string $provider): string {
        return 'mapa:ai:models:'.hash('sha256', $provider.'|'.($config['providers'][$provider]['api_key'] ?? '').'|'.($config['providers'][$provider]['base_url'] ?? ''));
    }
    public function discover(array $config): array {
        $provider = $config['provider'] ?? 'local';
        abort_unless(in_array($provider, ['openai', 'anthropic'], true), 422, 'Connect OpenAI or Claude in AI Provider Settings first.');
        $key = $config['providers'][$provider]['api_key'] ?? '';
        abort_unless(is_string($key) && $key !== '' && $key !== '********', 422, 'Save a provider credential first.');
        $base = $provider === 'openai' ? 'https://api.openai.com/v1' : 'https://api.anthropic.com/v1';
        abort_unless(rtrim($config['providers'][$provider]['base_url'] ?? $base, '/') === $base, 422, 'Model discovery requires the canonical provider address.');
        $ids = []; $after = null;
        for ($page = 0; $page < 10; $page++) {
            $request = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false])->acceptJson();
            $request = $provider === 'openai' ? $request->withToken($key) : $request->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01']);
            try { $response = $request->get($base.'/models', $provider === 'anthropic' ? array_filter(['limit' => 100, 'after_id' => $after]) : []); }
            catch (\Throwable $e) { abort(502, 'Model discovery could not reach the provider.'); }
            abort_unless($response->successful() && strlen($response->body()) <= 1048576, 502, 'Provider model discovery failed. Check the saved connection.');
            $body = $response->json(); abort_unless(is_array($body['data'] ?? null), 502, 'Invalid provider model catalog.');
            foreach ($body['data'] as $model) {
                $id = $model['id'] ?? null;
                if (is_string($id) && preg_match('/^[a-zA-Z0-9._:-]{1,160}$/D', $id)) $ids[$id] = ['label' => $id, 'value' => $id];
            }
            if ($provider === 'openai' || empty($body['has_more'])) break;
            $next = $body['last_id'] ?? null;
            abort_unless(is_string($next) && $next !== $after && $page < 9, 502, 'Incomplete provider model catalog.'); $after = $next;
        }
        ksort($ids); $models = array_values($ids);
        Cache::put(self::cacheKey($config, $provider), $models, 3600);
        return ['provider' => $provider, 'models' => $models, 'note' => 'Availability is not text-generation compatibility. Test the selected model before use. Model IDs are not ranked by recency or quality.'];
    }
}
