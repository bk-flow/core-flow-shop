<?php

namespace App\Core\FlowShop\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class RemoteRegistryClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function isEnabled(): bool
    {
        return $this->baseUrl() !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDownloadLink(array $payload): ?Response
    {
        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            return null;
        }

        return $this->request()
            ->post($baseUrl.'/flow-shop-management/api/download-link', $payload);
    }

    private function baseUrl(): ?string
    {
        $configured = env('MARKETPLACE_SERVER_BASE_URL');
        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return rtrim($configured, '/');
    }

    private function request(): PendingRequest
    {
        $token = env('MARKETPLACE_SERVER_TOKEN');

        $client = $this->http->timeout(30)->retry(2, 200);
        if (is_string($token) && trim($token) !== '') {
            return $client->withHeaders([
                'X-Marketplace-Client-Token' => trim($token),
            ]);
        }

        return $client;
    }
}
