<?php

namespace App\Core\FlowShop\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class FlowShopApiController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'module' => 'FlowShop',
            'server_base_url' => $this->serverBaseUrl(),
        ]);
    }

    private function serverBaseUrl(): ?string
    {
        $configured = env('MARKETPLACE_SERVER_BASE_URL');
        if (! is_string($configured) || $configured === '') {
            return null;
        }

        return rtrim($configured, '/');
    }
}
