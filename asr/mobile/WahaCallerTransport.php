<?php
namespace App\MapaMobile;

use App\MapaText\TrackerMessage;
use Illuminate\Support\Facades\Http;

/** Sends an explicitly consented MAPA tracker to the reviewed caller number. */
final class WahaCallerTransport
{
    public static function configReady(): bool
    {
        $url = (string) config('mapa_text.waha_url');
        $parts = parse_url($url);
        $sender = (string) config('mapa_text.default_sender');
        return (bool) config('mapa_mobile.whatsapp.waha_caller_enabled')
            && is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['waha', '127.0.0.1'], true)
            && ($parts['port'] ?? 3000) === 3000
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('/^\+[1-9][0-9]{7,14}$/D', $sender) === 1
            && hash_equals(substr($sender, 1), (string) config('mapa_text.phone_id'))
            && strlen((string) config('mapa_text.waha_api_key')) >= 32;
    }

    public function configured(): bool
    {
        if (!self::configReady()) return false;
        try {
            $response = Http::connectTimeout(3)->timeout(7)->withoutRedirecting()
                ->withHeaders(['X-Api-Key' => config('mapa_text.waha_api_key')])
                ->get(rtrim((string) config('mapa_text.waha_url'), '/') . '/api/sessions/default');
            if (!$response->successful()) return false;
            $session = $response->json();
            if (!is_array($session) || ($session['status'] ?? '') !== 'WORKING') return false;
            $actual = preg_replace('/[^0-9].*$/', '', (string) ($session['me']['id'] ?? ''));
            return $actual !== '' && hash_equals((string) config('mapa_text.phone_id'), $actual);
        } catch (\Throwable $error) { return false; }
    }

    public static function message(string $phone, string $url): array
    {
        $recipient = CallerPhone::normalize($phone);
        $parts = parse_url($url);
        if ($recipient === '' || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== config('mapa_text.tracking_host')
            || ($parts['path'] ?? '') !== '/track/'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query'])
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($parts['fragment'] ?? '')) !== 1) {
            throw new \InvalidArgumentException('A valid caller and private MAPA tracker link are required.');
        }
        return ['session' => 'default', 'chatId' => substr($recipient, 1) . '@c.us',
            'text' => TrackerMessage::withLink($url), 'linkPreview' => false];
    }

    public static function outcome(int $status, ?array $body): array
    {
        $id = $body['_data']['id']['_serialized'] ?? $body['id'] ?? null;
        if ($status >= 200 && $status < 300 && is_string($id) && $id !== '' && strlen($id) <= 255)
            return ['status' => 'accepted', 'message_id' => $id];
        return ['status' => $status >= 400 && $status < 500 && $status !== 408 ? 'rejected' : 'unknown', 'message_id' => null];
    }

    public function send(string $phone, string $url, ?string $callback = null): array
    {
        $payload = self::message($phone, $url);
        if (!$this->configured()) return ['status' => 'unknown', 'message_id' => null];
        try {
            $response = Http::connectTimeout(5)->timeout(35)->withoutRedirecting()
                ->withHeaders(['X-Api-Key' => config('mapa_text.waha_api_key')])
                ->post(rtrim((string) config('mapa_text.waha_url'), '/') . '/api/sendText', $payload);
            $body = $response->json();
            return self::outcome($response->status(), is_array($body) ? $body : null);
        } catch (\Throwable $error) { return ['status' => 'unknown', 'message_id' => null]; }
    }
}
