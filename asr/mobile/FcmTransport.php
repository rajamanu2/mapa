<?php
namespace App\MapaMobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** FCM HTTP v1 only. Provider URLs are fixed; neither callers nor device tokens control them. */
class FcmTransport
{
    public function configured(): bool
    {
        if (!config('mapa_mobile.push.enabled', false)) return false;
        try { $this->credentials(); return true; } catch (\Throwable $e) { return false; }
    }

    protected function credentials(): array
    {
        $project = (string) config('mapa_mobile.push.project_id');
        $path = (string) config('mapa_mobile.push.credentials_file');
        if (!preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/D', $project) || !$path || !is_file($path)
            || !is_readable($path) || filesize($path) > 32768) throw new \RuntimeException('MAPA push provider is not configured.');
        $data = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (($data['type'] ?? '') !== 'service_account' || ($data['project_id'] ?? '') !== $project
            || !filter_var($data['client_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !str_ends_with($data['client_email'], '.iam.gserviceaccount.com')
            || !is_string($data['private_key'] ?? null) || !openssl_pkey_get_private($data['private_key']))
            throw new \RuntimeException('MAPA push provider credentials need administrator review.');
        return $data;
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $cacheKey = 'mapa-fcm-oauth:'.hash('sha256', $credentials['client_email'].$credentials['private_key']);
        return Cache::remember($cacheKey, 3000, function () use ($credentials) {
            $encode = fn($v) => rtrim(strtr(base64_encode(json_encode($v, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $now = time();
            $unsigned = $encode(['alg'=>'RS256','typ'=>'JWT']).'.'.$encode([
                'iss'=>$credentials['client_email'], 'scope'=>'https://www.googleapis.com/auth/firebase.messaging',
                'aud'=>'https://oauth2.googleapis.com/token', 'iat'=>$now, 'exp'=>$now + 3600]);
            if (!openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256))
                throw new \RuntimeException('MAPA push credentials could not sign a request.');
            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            try {
                $response = Http::asForm()->acceptJson()->withOptions(['allow_redirects'=>false])->connectTimeout(3)->timeout(8)
                    ->post('https://oauth2.googleapis.com/token', ['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]);
            } catch (\Throwable $e) { throw new \RuntimeException('MAPA push authorization is temporarily unavailable.'); }
            $token = $response->json('access_token');
            if (!$response->successful() || !is_string($token) || strlen($token) < 20 || strlen($token) > 8192)
                throw new \RuntimeException('MAPA push provider authorization failed.');
            return $token;
        });
    }

    public static function message(string $token, string $eventId, string $sessionHash, int $issuedAt, string $kind = 'mapa_assignments_changed'): array
    {
        if (!in_array($kind,['mapa_assignments_changed','mapa_hospital_changed'],true)) throw new \InvalidArgumentException('Invalid MAPA push kind.');
        // No patient, caller, pickup, ambulance, order ID, name or coordinates leave MAPA.
        return ['message'=>['token'=>$token, 'data'=>['kind'=>$kind, 'event_id'=>$eventId,
            'session_tag'=>$sessionHash, 'issued_at'=>(string)$issuedAt],
            'android'=>['priority'=>'HIGH','ttl'=>'120s','collapse_key'=>$kind==='mapa_hospital_changed'?'mapa_hospital':'mapa_assignments']]];
    }

    /** accepted is provider acceptance, never proof that the phone displayed or crew accepted it. */
    public function send(string $token, string $eventId, string $sessionHash, int $issuedAt): string
    {
        return $this->sendKind($token,$eventId,$sessionHash,$issuedAt,'mapa_assignments_changed');
    }
    public function sendHospital(string $token,string $eventId,string $sessionHash,int $issuedAt): string { return $this->sendKind($token,$eventId,$sessionHash,$issuedAt,'mapa_hospital_changed'); }
    protected function sendKind(string $token,string $eventId,string $sessionHash,int $issuedAt,string $kind): string
    {
        if (!$this->configured()) throw new \RuntimeException('MAPA push provider is not configured.');
        try {
            $response = Http::withToken($this->accessToken())->acceptJson()->withOptions(['allow_redirects'=>false])->connectTimeout(3)->timeout(8)
                ->post('https://fcm.googleapis.com/v1/projects/'.config('mapa_mobile.push.project_id').'/messages:send',
                    self::message($token, $eventId, $sessionHash, $issuedAt, $kind));
        } catch (\Throwable $e) { throw new \RuntimeException('MAPA push delivery is temporarily unavailable.'); }
        if ($response->successful() && is_string($response->json('name'))) return 'accepted';
        foreach ($response->json('error.details', []) as $detail)
            if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && ($detail['errorCode'] ?? '') === 'UNREGISTERED') return 'unregistered';
        // Do not persist provider bodies: they can contain device tokens or service account data.
        throw new \RuntimeException('MAPA push provider rejected delivery (HTTP '.$response->status().').');
    }
}
