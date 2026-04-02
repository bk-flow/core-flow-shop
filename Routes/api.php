<?php

use App\Core\FlowShop\Http\Controllers\Api\FlowShopApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/marketplace-client')->middleware(['throttle:60,1'])->group(function (): void {
    Route::get('/status', [FlowShopApiController::class, 'status'])->name('api.marketplace-client.status');
});
