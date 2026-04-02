<?php

use App\Core\FlowShop\Http\Controllers\Admin\FlowShopController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings/flow-shop')
    ->middleware(['cms_permission:marketplace_client_read'])
    ->group(function (): void {
        Route::get('/', [FlowShopController::class, 'index'])->name('cms.admin.flow-shop.index');
        Route::get('/modules', [FlowShopController::class, 'publishedModules'])->name('cms.admin.flow-shop.published-modules');
        Route::get('/integrations', [FlowShopController::class, 'publishedIntegrations'])->name('cms.admin.flow-shop.published-integrations');
        Route::post('/api/catalog-sync', [FlowShopController::class, 'catalogSync'])
            ->name('cms.admin.flow-shop.catalog-sync');
        Route::post('/api/download-link', [FlowShopController::class, 'downloadLink'])
            ->middleware(['cms_permission:marketplace_client_edit'])
            ->name('cms.admin.flow-shop.download-link');
        Route::post('/api/providers/download', [FlowShopController::class, 'downloadProvider'])
            ->middleware(['cms_permission:marketplace_client_edit'])
            ->name('cms.admin.flow-shop.providers.download');
        Route::post('/api/modules/download', [FlowShopController::class, 'downloadModule'])
            ->middleware(['cms_permission:marketplace_client_edit'])
            ->name('cms.admin.flow-shop.modules.download');
        Route::post('/api/modules/download-stream', [FlowShopController::class, 'downloadModuleStream'])
            ->middleware(['cms_permission:marketplace_client_edit'])
            ->name('cms.admin.flow-shop.modules.download-stream');
    });
