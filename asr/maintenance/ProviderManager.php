<?php
namespace App\MapaMaintenance;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Cache;

/** Extend the existing manager so every existing AI task uses the same selected model. */
class ProviderManager extends AiProviderManager
{
    public function metadata(): array {
        $metadata = parent::metadata(); $config = Setting::system('ai', []);
        foreach ($metadata['providers'] as &$provider) {
            $name = $provider['value']; if ($name === 'local') continue;
            $extra = Cache::get(ModelCatalog::cacheKey($config, $name), []);
            // Preserve an administrator-selected model across a catalog cache expiry.
            // Its ID is display metadata only; the provider still validates actual availability.
            if (($config['provider'] ?? null) === $name && is_string($config['default_model'] ?? null) && preg_match('/^[a-zA-Z0-9._:-]{1,160}$/D', $config['default_model'])) {
                $extra[] = ['label' => $config['default_model'], 'value' => $config['default_model']];
            }
            $provider['models'] = collect(array_merge($provider['models'], $extra))->unique('value')->values()->all();
        }
        return $metadata;
    }
}
