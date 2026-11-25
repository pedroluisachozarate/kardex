<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MobileGateway
{
    public function request(?string $token = null): PendingRequest
    {
        $url = rtrim((string) config('mobile.server_url'), '/');
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $localHttp = false;
        if ($scheme === 'http' && config('mobile.allow_local_http') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $bytes = array_map('intval', explode('.', $host));
            $localHttp = $bytes[0] === 10 || ($bytes[0] === 172 && $bytes[1] >= 16 && $bytes[1] <= 31) || ($bytes[0] === 192 && $bytes[1] === 168);
        }
        if (! $url || ! $host || isset($parts['user']) || isset($parts['pass']) || ($scheme !== 'https' && ! $localHttp)) {
            throw new \RuntimeException('Configura un servidor HTTPS válido, o una IP privada autorizada para pruebas por Wi-Fi.');
        }
        $client = Http::baseUrl($url.'/api/mobile')->acceptJson()->connectTimeout(5)->timeout(45)->withoutRedirecting();

        return $token ? $client->withToken($token) : $client;
    }
}
