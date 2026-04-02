<?php

namespace App\Core\FlowShop\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Master\FlowShopManagement\Http\Controllers\Admin\FlowShopManagementController;

class FlowShopManagementServerGateway
{
    public function __construct(
        private readonly RemoteRegistryClient $remoteRegistryClient,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDownloadLink(array $payload, ?string $ip): JsonResponse
    {
        if ($this->remoteRegistryClient->isEnabled()) {
            $remoteResponse = $this->remoteRegistryClient->createDownloadLink($payload);
            if ($remoteResponse !== null) {
                return response()->json(
                    $remoteResponse->json() ?? [
                        'success' => false,
                        'message' => 'Remote registry response parse hatasi.',
                    ],
                    $remoteResponse->status()
                );
            }
        }

        $request = Request::create('/flow-shop-management/api/download-link', 'POST', $payload);
        $request->server->set('REMOTE_ADDR', is_string($ip) && $ip !== '' ? $ip : '127.0.0.1');

        return app(FlowShopManagementController::class)->createDownloadLink($request);
    }
}
