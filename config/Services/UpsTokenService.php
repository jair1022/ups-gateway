<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Cache\LockProvider;

class UpsTokenService
{
    private string $clientId;
    private string $clientSecret;
    private string $oauthPath;
    private string $baseUrl;

    public function __construct()
    {
        $this->clientId     = config('services.ups.client_id');
        $this->clientSecret = config('services.ups.client_secret');
        $this->oauthPath    = env('UPS_OAUTH_PATH', '/security/v1/oauth/token');
        $this->baseUrl      = env('UPS_ENV') === 'prod'
            ? env('UPS_BASE_URL_PROD')
            : env('UPS_BASE_URL_SANDBOX');
    }

    public function getAccessToken(): string
    {
        // 1) Intentar usar token guardado en cache (encriptado)
        if ($enc = Cache::get('ups_access_token_enc')) {
            try {
                return Crypt::decryptString($enc);
            } catch (\Throwable $e) {
                Cache::forget('ups_access_token_enc'); // si hay error, lo borra
            }
        }

        // 2) Usar lock para que no se generen múltiples tokens simultáneamente
        $store = Cache::getStore();
        if ($store instanceof LockProvider) {
            return Cache::lock('ups_token_lock', 10)->block(10, function () {
                if ($enc = Cache::get('ups_access_token_enc')) {
                    return Crypt::decryptString($enc);
                }
                return $this->refreshAndCacheToken();
            });
        }

        // 3) Si no hay soporte de lock (ej. file cache), genera directamente
        return $this->refreshAndCacheToken();
    }

    private function refreshAndCacheToken(): string
    {
        $url = rtrim($this->baseUrl, '/') . $this->oauthPath;

        $resp = Http::asForm()
            ->withHeaders([
                'Accept'        => 'application/json',
                'x-merchant-id' => $this->clientId,
            ])
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->retry(3, 200, throw: false)   // intenta 3 veces en caso de error
            ->timeout(15)
            ->post($url, ['grant_type' => 'client_credentials']);

        if (!$resp->successful()) {
            throw new \RuntimeException('UPS OAuth error: '.$resp->status().' '.$resp->body());
        }

        $data  = $resp->json();
        $token = $data['access_token'] ?? null;
        $ttl   = max(300, (int)($data['expires_in'] ?? 3600) - 60); // resta 60s de seguridad

        if (!$token) {
            throw new \RuntimeException('UPS OAuth: token vacío.');
        }

        // Guardar el token ENCRIPTADO en cache
        Cache::put('ups_access_token_enc', Crypt::encryptString($token), $ttl);

        return $token;
    }
}
