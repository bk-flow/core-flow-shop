<?php

namespace App\Core\FlowShop;

use App\Core\System\Support\LoadsModuleMiddleware;
use App\Providers\AdminCmsRouteStack;
use Illuminate\Support\ServiceProvider;

class FlowShopServiceProvider extends ServiceProvider
{
    use LoadsModuleMiddleware;

    public function register(): void {}

    public function boot(): void
    {
        $this->loadMiddlewareFrom(__DIR__.'/config/middleware.php');

        if (is_dir(__DIR__.'/resources/lang')) {
            $this->loadTranslationsFrom(__DIR__.'/resources/lang', null);
        }

        if (is_dir(__DIR__.'/resources/views')) {
            $this->loadViewsFrom(__DIR__.'/resources/views', 'marketplace-client');
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        AdminCmsRouteStack::withLocale(function (): void {
            AdminCmsRouteStack::withAdminAuth(function (): void {
                $this->requireRouteFile(__DIR__.'/Routes/admin.php');
            });
        });

        $this->requireRouteFile(__DIR__.'/Routes/api.php');
    }

    private function requireRouteFile(string $absolutePath): void
    {
        if (! is_file($absolutePath)) {
            return;
        }

        require $absolutePath;
    }
}
