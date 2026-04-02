<?php

namespace App\Core\FlowShop\Http\Controllers\Admin;

use App\Core\FlowShop\Http\Requests\Admin\DownloadLinkRequest;
use App\Core\FlowShop\Http\Requests\Admin\DownloadModuleRequest;
use App\Core\FlowShop\Http\Requests\Admin\DownloadProviderRequest;
use App\Core\FlowShop\Services\FlowShopManagementServerGateway;
use App\Core\Integrations\IntegrationService;
use App\Core\Integrations\Models\IntegrationBinding;
use App\Core\Integrations\Models\IntegrationCategory;
use App\Core\Integrations\Models\IntegrationPackage;
use App\Core\Integrations\Models\IntegrationProvider;
use App\Core\Integrations\Support\IntegrationProviderPackagePath;
use App\Core\ModuleManagement\Models\ModuleInstallation;
use App\Core\ModuleManagement\Models\ModulePackage;
use App\Core\ModuleManagement\Services\ModulePackageInstaller;
use App\Core\ModuleManagement\Services\Release\ArtifactSignatureService;
use App\Core\System\Services\Module\ModuleRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Master\FlowShopManagement\Models\FlowShopManagementArtifact;
use Modules\Master\FlowShopManagement\Models\FlowShopManagementModule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class FlowShopController extends Controller
{
    /**
     * @var array<int, string>|null
     */
    private ?array $availableProviderKeysCache = null;

    public function __construct(
        private readonly IntegrationService $integrationService,
        private readonly ModulePackageInstaller $modulePackageInstaller,
        private readonly FlowShopManagementServerGateway $flowShopManagementServerGateway,
        private readonly ArtifactSignatureService $artifactSignatureService,
    ) {}

    public function index(): View
    {
        return $this->renderPage('integrations');
    }

    public function publishedModules(): View
    {
        return $this->renderPage('modules');
    }

    public function publishedIntegrations(): View
    {
        return $this->renderPage('integrations');
    }

    private function renderPage(string $page): View
    {
        $this->cleanupStaleInstalledProviders();

        $catalogModules = $this->buildCatalogModules();
        $catalogProviders = $this->buildCatalogProviders();

        $serverDiagnostics = [
            'has_server_base_url' => $this->serverBaseUrl() !== null,
            'local_feed_mode' => true,
        ];

        $updatesModuleCount = $catalogModules->filter(
            static fn (object $m): bool => ($m->install_status ?? '') === 'installed' && ($m->has_update ?? false)
        )->count();
        $updatesProviderCount = $catalogProviders->filter(
            static fn (object $p): bool => ($p->install_status ?? '') === 'installed' && ($p->has_update ?? false)
        )->count();

        return view('marketplace-client::cms.admin.flow-shop.index', [
            'serverBaseUrl' => $this->serverBaseUrl(),
            'catalogModules' => $catalogModules,
            'catalogProviders' => $catalogProviders,
            'serverDiagnostics' => $serverDiagnostics,
            'page' => $page,
            'updatesAvailableTotal' => $updatesModuleCount + $updatesProviderCount,
        ]);
    }

    public function catalogSync(Request $request): JsonResponse
    {
        $this->cleanupStaleInstalledProviders();
        $catalogData = $this->localCatalogData();
        $this->syncCatalogToPackages($catalogData);

        return response()->json([
            'success' => true,
            'message' => __('admin.marketplace_client.messages.sync_ok'),
            'data' => $catalogData,
        ]);
    }

    public function downloadProvider(DownloadProviderRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $panelId = is_string($payload['panel_id'] ?? null) && $payload['panel_id'] !== ''
            ? $payload['panel_id']
            : $this->stringEnv('MARKETPLACE_CLIENT_PANEL_ID');

        $customerId = isset($payload['customer_id']) && is_int($payload['customer_id'])
            ? $payload['customer_id']
            : $this->intEnv('MARKETPLACE_CLIENT_CUSTOMER_ID');

        $downloadPayload = [];
        if ($panelId !== '' && $customerId > 0) {
            $downloadRequest = [
                'panel_id' => $panelId,
                'customer_id' => $customerId,
                'artifact_key' => (string) $payload['provider_key'],
                'version' => (string) $payload['version'],
            ];

            $response = $this->createLocalDownloadLink($downloadRequest, $request->ip());
            $downloadPayload = $response->getData(true);
            if (! $response->isSuccessful() || ! ((bool) ($downloadPayload['success'] ?? false))) {
                return response()->json([
                    'success' => false,
                    'message' => __('admin.marketplace_client.messages.download_failed'),
                    'status_code' => $response->getStatusCode(),
                    'response' => $downloadPayload,
                ], 422);
            }
        }

        try {
            $this->downloadVerifyExtractAndInstallProvider(
                providerKey: (string) $payload['provider_key'],
                version: (string) $payload['version'],
                downloadPayload: $downloadPayload,
                panelId: $panelId !== '' ? $panelId : null,
                customerId: $customerId > 0 ? $customerId : null
            );
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => __('admin.marketplace_client.messages.install_failed').': '.$exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('admin.marketplace_client.messages.install_ok'),
            'data' => $downloadPayload,
        ]);
    }

    public function downloadModule(DownloadModuleRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $panelId = is_string($payload['panel_id'] ?? null) && $payload['panel_id'] !== ''
            ? $payload['panel_id']
            : $this->stringEnv('MARKETPLACE_CLIENT_PANEL_ID');

        $customerId = isset($payload['customer_id']) && is_int($payload['customer_id'])
            ? $payload['customer_id']
            : $this->intEnv('MARKETPLACE_CLIENT_CUSTOMER_ID');

        $downloadPayload = [];
        if ($panelId !== '' && $customerId > 0) {
            $downloadRequest = [
                'panel_id' => $panelId,
                'customer_id' => $customerId,
                'artifact_key' => (string) $payload['module_key'],
                'version' => (string) $payload['version'],
            ];
            $response = $this->createLocalDownloadLink($downloadRequest, $request->ip());
            $downloadPayload = $response->getData(true);
            if (! $response->isSuccessful() || ! ((bool) ($downloadPayload['success'] ?? false))) {
                return response()->json([
                    'success' => false,
                    'message' => __('admin.marketplace_client.messages.download_failed'),
                    'status_code' => $response->getStatusCode(),
                    'response' => $downloadPayload,
                ], 422);
            }
        }

        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $action = is_string($payload['action'] ?? null) && trim((string) $payload['action']) !== ''
            ? trim((string) $payload['action'])
            : 'install';
        try {
            $installReport = $this->downloadVerifyAndInstallModule(
                family: (string) $payload['family'],
                moduleKey: (string) $payload['module_key'],
                version: (string) $payload['version'],
                downloadPayload: $downloadPayload,
                steps: $steps,
                action: $action
            );
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => __('admin.marketplace_client.messages.module_install_failed').': '.$exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $action === 'update'
                ? __('admin.marketplace_client.messages.module_update_ok')
                : __('admin.marketplace_client.messages.module_install_ok'),
            'data' => [
                'download' => $downloadPayload,
                'report' => $installReport,
                'action' => $action,
            ],
        ]);
    }

    public function downloadModuleStream(DownloadModuleRequest $request): StreamedResponse
    {
        return new StreamedResponse(function () use ($request): void {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $send = static function (array $payload): void {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                @flush();
            };

            $payload = $request->validated();

            $panelId = is_string($payload['panel_id'] ?? null) && $payload['panel_id'] !== ''
                ? $payload['panel_id']
                : $this->stringEnv('MARKETPLACE_CLIENT_PANEL_ID');

            $customerId = isset($payload['customer_id']) && is_int($payload['customer_id'])
                ? $payload['customer_id']
                : $this->intEnv('MARKETPLACE_CLIENT_CUSTOMER_ID');

            $downloadPayload = [];
            if ($panelId !== '' && $customerId > 0) {
                $downloadRequest = [
                    'panel_id' => $panelId,
                    'customer_id' => $customerId,
                    'artifact_key' => (string) $payload['module_key'],
                    'version' => (string) $payload['version'],
                ];
                $response = $this->createLocalDownloadLink($downloadRequest, $request->ip());
                $downloadPayload = $response->getData(true);
                if (! $response->isSuccessful() || ! ((bool) ($downloadPayload['success'] ?? false))) {
                    $send([
                        'type' => 'error',
                        'success' => false,
                        'message' => __('admin.marketplace_client.messages.download_failed'),
                        'response' => $downloadPayload,
                    ]);

                    return;
                }
            }

            $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
            $action = is_string($payload['action'] ?? null) && trim((string) $payload['action']) !== ''
                ? trim((string) $payload['action'])
                : 'install';

            $maxPercent = 0;
            $progress = function (string $phase, string $message, array $context = []) use ($send, &$maxPercent): void {
                $pct = isset($context['percent']) && is_int($context['percent'])
                    ? min(100, max(0, $context['percent']))
                    : $this->installPhaseToPercent($phase);
                $maxPercent = max($maxPercent, $pct);

                if (str_ends_with($phase, '_output')) {
                    $send([
                        'type' => 'terminal',
                        'phase' => $phase,
                        'percent' => $maxPercent,
                        'text' => $message,
                        'argv' => $context['argv'] ?? null,
                    ]);

                    return;
                }

                $line = [
                    'type' => 'log',
                    'phase' => $phase,
                    'percent' => $maxPercent,
                    'message' => $message,
                ];
                if (isset($context['argv']) && is_array($context['argv'])) {
                    $line['argv'] = $context['argv'];
                }
                if (isset($context['downloaded'], $context['total'])) {
                    $line['downloaded'] = $context['downloaded'];
                    $line['total'] = $context['total'];
                }
                if (isset($context['target'])) {
                    $line['target'] = $context['target'];
                }
                $send($line);
            };

            try {
                $send(['type' => 'start', 'message' => __('admin.marketplace_client.stream.install_started')]);
                $installReport = $this->downloadVerifyAndInstallModule(
                    family: (string) $payload['family'],
                    moduleKey: (string) $payload['module_key'],
                    version: (string) $payload['version'],
                    downloadPayload: $downloadPayload,
                    steps: $steps,
                    action: $action,
                    progress: $progress
                );
                $send([
                    'type' => 'done',
                    'success' => true,
                    'message' => $action === 'update'
                        ? __('admin.marketplace_client.messages.module_update_ok')
                        : __('admin.marketplace_client.messages.module_install_ok'),
                    'report' => $installReport,
                    'action' => $action,
                    'percent' => 100,
                ]);
            } catch (Throwable $exception) {
                $send([
                    'type' => 'error',
                    'success' => false,
                    'message' => __('admin.marketplace_client.messages.module_install_failed').': '.$exception->getMessage(),
                    'percent' => $maxPercent,
                ]);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function downloadLink(DownloadLinkRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $response = $this->createLocalDownloadLink($payload, $request->ip());
        $downloadPayload = $response->getData(true);
        if (! $response->isSuccessful() || ! ((bool) ($downloadPayload['success'] ?? false))) {
            return response()->json([
                'success' => false,
                'message' => __('admin.marketplace_client.messages.download_link_failed'),
                'status_code' => $response->getStatusCode(),
                'response' => $downloadPayload,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('admin.marketplace_client.messages.download_link_ok'),
            'data' => $downloadPayload,
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

    private function syncCatalogToPackages(array $catalogData): void
    {
        /** @var list<array<string, mixed>> $providers */
        $providers = is_array($catalogData['providers'] ?? null) ? $catalogData['providers'] : [];
        if ($providers === []) {
            return;
        }

        $availableModules = $this->integrationService
            ->getAvailableModules()
            ->keyBy(fn (array $item): string => (string) ($item['key'] ?? ''));

        foreach ($providers as $provider) {
            $providerKey = (string) ($provider['provider_key'] ?? '');
            if ($providerKey === '') {
                continue;
            }

            $version = (string) ($provider['package_version'] ?? '1.0.0');
            $moduleConfig = $availableModules->get($providerKey);
            $metadata = is_array($provider['metadata'] ?? null) ? $provider['metadata'] : [];
            $publishedTitle = $this->publishedProviderTitle($providerKey);
            $publishedName = is_string($publishedTitle) && $publishedTitle !== ''
                ? ['tr' => $publishedTitle, 'en' => $publishedTitle]
                : null;
            $existingName = is_array($metadata['name'] ?? null) ? $metadata['name'] : null;
            $effectiveName = is_array($publishedName) ? $publishedName : (is_array($existingName) ? $existingName : null);

            IntegrationPackage::query()->updateOrCreate(
                [
                    'provider_key' => $providerKey,
                    'package_version' => $version,
                ],
                [
                    'category' => (string) ($provider['category'] ?? $moduleConfig['category'] ?? ''),
                    'manifest_version' => (string) ($provider['manifest_version'] ?? $moduleConfig['manifest_version'] ?? '1.0'),
                    'source_type' => 'flow_shop_management_server',
                    'source_ref' => (string) ($provider['source_ref'] ?? ''),
                    'discovery_status' => 'catalog_synced',
                    'install_status' => $this->currentInstallStatusForProvider($providerKey),
                    'last_seen_at' => now(),
                    'metadata' => array_merge($metadata, [
                        'name' => $effectiveName ?? ($moduleConfig['name'] ?? null),
                        'description' => $moduleConfig['description'] ?? null,
                        'icon' => $moduleConfig['icon'] ?? null,
                        'publisher' => 'Bikare',
                        'catalog_synced_at' => now()->toIso8601String(),
                    ]),
                ]
            );
        }
    }

    /**
     * @return array{modules: array<int, array<string, mixed>>, providers: array<int, array<string, mixed>>}
     */
    private function localCatalogData(): array
    {
        $moduleQuery = FlowShopManagementModule::query()
            ->where('is_active', true)
            ->where('family', '!=', 'master');
        if (Schema::hasColumn('shop_management_modules', 'is_publishable')) {
            $moduleQuery->where('is_publishable', true);
        }

        /** @var array<int, array<string, mixed>> $modules */
        $activeModules = $moduleQuery->orderBy('name')->get();
        $modules = $activeModules->toArray();
        $activePublishedKeys = $this->activePublishedProviderKeysFromModules($activeModules);
        /** @var array<int, array<string, mixed>> $providers */
        $providers = IntegrationPackage::query()
            ->where('discovery_status', 'valid')
            ->whereIn('provider_key', $activePublishedKeys)
            ->orderBy('provider_key')
            ->get()
            ->toArray();

        return [
            'modules' => $modules,
            'providers' => $providers,
        ];
    }

    /**
     * @param  array<string, mixed>  $downloadPayload
     */
    private function downloadVerifyExtractAndInstallProvider(
        string $providerKey,
        string $version,
        array $downloadPayload,
        ?string $panelId,
        ?int $customerId
    ): void {
        $package = $this->resolvePackageForInstall($providerKey, $version);
        if (! $package) {
            throw new RuntimeException('Integration package bulunamadi.');
        }

        $resolvedProviderKey = (string) $package->provider_key;
        $resolvedVersion = (string) $package->package_version;

        $downloaded = $this->downloadProviderArtifactZip($resolvedProviderKey, $resolvedVersion, $package, $downloadPayload);
        $validated = $this->validateProviderZip($downloaded['zip_path'], $resolvedProviderKey);
        $providerDirectory = $this->resolveProviderDirectoryName($validated['manifest'], $providerKey);
        $targetDir = base_path('integration-providers/'.$providerDirectory);
        $this->extractProviderZipToTarget(
            zipPath: $downloaded['zip_path'],
            moduleRootPrefix: $validated['module_root_prefix'],
            targetDir: $targetDir
        );
        app(ModuleRegistry::class)->clearCache();
        $this->integrationService->getAvailableModules();

        $this->createOrUpdateProviderAndBinding(
            manifest: $validated['manifest'],
            providerKey: $resolvedProviderKey,
            version: $resolvedVersion
        );

        $metadata = is_array($package?->metadata) ? $package->metadata : [];
        $metadata['download'] = [
            'download_url' => is_string($downloadPayload['download_url'] ?? null) ? $downloadPayload['download_url'] : null,
            'expires_at' => is_string($downloadPayload['expires_at'] ?? null) ? $downloadPayload['expires_at'] : null,
            'panel_id' => $panelId,
            'customer_id' => $customerId,
            'downloaded_at' => now()->toIso8601String(),
            'checksum_sha256' => $downloaded['checksum_sha256'] ?? null,
            'zip_path' => $downloaded['zip_path'],
            'provider_directory' => $providerDirectory,
        ];
        $metadata['installed_at'] = now()->toIso8601String();

        IntegrationPackage::query()->updateOrCreate(
            [
                'provider_key' => $resolvedProviderKey,
                'package_version' => $resolvedVersion,
            ],
            [
                'category' => (string) ($package?->category ?? ''),
                'manifest_version' => (string) ($package?->manifest_version ?? '1.0'),
                'source_type' => 'flow_shop_management_server',
                'source_ref' => (string) ($package?->source_ref ?? ''),
                'discovery_status' => 'valid',
                'install_status' => 'installed',
                'last_seen_at' => now(),
                'metadata' => $metadata,
            ]
        );
    }

    private function resolvePackageForInstall(string $providerKey, string $version): ?IntegrationPackage
    {
        $normalizedVersion = $this->normalizeVersion($version);
        $keyCandidates = array_values(array_unique(array_filter([
            trim($providerKey),
            str_replace('-', '_', trim($providerKey)),
            str_replace('_', '-', trim($providerKey)),
            Str::after(trim($providerKey), 'integration-provider-'),
            Str::after(trim($providerKey), 'integration_provider_'),
        ], fn (string $item): bool => $item !== '')));

        if ($keyCandidates === []) {
            return null;
        }

        $versionCandidates = array_values(array_unique(array_filter([
            trim($version),
            $normalizedVersion,
            'v'.$normalizedVersion,
        ], fn (string $item): bool => $item !== '')));

        $package = IntegrationPackage::query()
            ->whereIn('provider_key', $keyCandidates)
            ->whereIn('package_version', $versionCandidates)
            ->orderByDesc('updated_at')
            ->first();

        if ($package instanceof IntegrationPackage) {
            return $package;
        }

        $latestByKey = IntegrationPackage::query()
            ->whereIn('provider_key', $keyCandidates)
            ->orderByDesc('updated_at')
            ->first();
        if ($latestByKey instanceof IntegrationPackage) {
            return $latestByKey;
        }

        return $this->hydratePackageFromFlowShopManagementArtifact($keyCandidates, $versionCandidates);
    }

    /**
     * @param  array<int, string>  $keyCandidates
     * @param  array<int, string>  $versionCandidates
     */
    private function hydratePackageFromFlowShopManagementArtifact(array $keyCandidates, array $versionCandidates): ?IntegrationPackage
    {
        if (! Schema::hasTable('shop_management_artifacts')) {
            return null;
        }

        $artifact = FlowShopManagementArtifact::query()
            ->where('artifact_family', 'integration_provider')
            ->where(function ($query) use ($keyCandidates): void {
                $query->whereIn('artifact_key', $keyCandidates);
                foreach ($keyCandidates as $candidate) {
                    $query->orWhere('artifact_key', str_replace('-', '_', $candidate))
                        ->orWhere('artifact_key', str_replace('_', '-', $candidate));
                }
            })
            ->where(function ($query) use ($versionCandidates): void {
                $query->whereIn('version', $versionCandidates);
                foreach ($versionCandidates as $candidate) {
                    $normalized = $this->normalizeVersion($candidate);
                    $query->orWhere('version', $normalized)
                        ->orWhere('version', 'v'.$normalized);
                }
            })
            ->whereNull('deleted_at')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();

        if (! $artifact instanceof FlowShopManagementArtifact) {
            return null;
        }

        $module = null;
        if (Schema::hasTable('shop_management_modules')) {
            $module = FlowShopManagementModule::query()
                ->find($artifact->shop_management_module_id);
        }

        $providerKey = is_string($artifact->artifact_key) && trim($artifact->artifact_key) !== ''
            ? trim($artifact->artifact_key)
            : (string) ($keyCandidates[0] ?? '');
        if ($providerKey === '') {
            return null;
        }

        $version = is_string($artifact->version) && trim($artifact->version) !== ''
            ? trim($artifact->version)
            : (string) ($versionCandidates[0] ?? '1.0.0');

        $metadata = [
            'artifact_path' => is_string($artifact->artifact_path) ? $artifact->artifact_path : null,
            'checksum_sha256' => is_string($artifact->checksum_sha256) ? $artifact->checksum_sha256 : null,
            'publisher' => 'Bikare',
            'name' => [
                'tr' => is_string($module?->name) ? $module->name : Str::headline($providerKey),
                'en' => is_string($module?->name) ? $module->name : Str::headline($providerKey),
            ],
            'catalog_synced_at' => now()->toIso8601String(),
        ];

        return IntegrationPackage::query()->updateOrCreate(
            [
                'provider_key' => $providerKey,
                'package_version' => $version,
            ],
            [
                'category' => 'devops',
                'manifest_version' => '1.0',
                'source_type' => 'flow_shop_management_server',
                'source_ref' => is_string($module?->repo_url) ? $module->repo_url : '',
                'discovery_status' => 'catalog_synced',
                'install_status' => 'catalog_synced',
                'last_seen_at' => now(),
                'metadata' => $metadata,
            ]
        );
    }

    private function normalizeVersion(string $version): string
    {
        $value = trim($version);
        if (str_starts_with(strtolower($value), 'v')) {
            return ltrim(substr($value, 1), ' ');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $downloadPayload
     * @param  array<string, mixed>  $steps
     * @param  (callable(string, string, array<string, mixed>): void)|null  $progress
     */
    private function downloadVerifyAndInstallModule(
        string $family,
        string $moduleKey,
        string $version,
        array $downloadPayload,
        array $steps,
        string $action = 'install',
        ?callable $progress = null
    ): array {
        $emit = function (string $phase, string $message, array $extra = []) use ($progress): void {
            if ($progress !== null) {
                $progress($phase, $message, $extra);
            }
        };

        $resolvedFamily = $this->normalizeFamily($family);
        $emit('resolve', __('admin.marketplace_client.stream.resolving_package'));
        $package = $this->resolveModulePackageForInstall($resolvedFamily, $moduleKey, $version);
        if (! $package) {
            throw new RuntimeException('Module package bulunamadi.');
        }

        $resolvedVersion = (string) $package->package_version;
        $downloaded = $this->downloadModuleArtifactZip($resolvedFamily, $moduleKey, $resolvedVersion, $package, $downloadPayload, $progress);

        $installedVersion = $this->installedVersionForModule($resolvedFamily, $moduleKey);
        $isInstalled = $this->currentInstallStatusForModule($resolvedFamily, $moduleKey) === 'installed';
        if ($action === 'update') {
            if (! $isInstalled) {
                throw new RuntimeException(__('admin.marketplace_client.messages.module_update_requires_installed'));
            }
            if (! is_string($installedVersion) || ! $this->isNewerVersion($resolvedVersion, $installedVersion)) {
                throw new RuntimeException(__('admin.marketplace_client.messages.module_update_no_newer_version'));
            }
        }

        $emit('install_zip', __('admin.marketplace_client.stream.installing_from_zip'));
        $result = $this->modulePackageInstaller->installFromZipPath($downloaded['zip_path'], [
            'allow_non_toggleable' => true,
            'allow_reinstall_same_version' => true,
            'run_dump_autoload' => (bool) ($steps['dump_autoload'] ?? true),
            'run_migrations' => (bool) ($steps['migrate'] ?? false),
            'run_seeders' => (bool) ($steps['seed'] ?? false),
            'dependency_operation' => $action === 'update' ? 'upgrade' : 'install',
            'installed_version_override' => $resolvedVersion,
            'on_progress' => $progress !== null ? function (string $phase, string $message, array $ctx = []) use ($emit): void {
                $emit($phase, $message, $ctx);
            } : null,
        ]);

        $emit('health_check', __('admin.marketplace_client.stream.health_check'));
        $postCheckPassed = $this->postUpgradeHealthCheck($resolvedFamily, $moduleKey);
        $rollbackTriggered = false;
        if ($action === 'update' && ! $postCheckPassed && (bool) config('module_contract.auto_rollback_on_postcheck_fail', true)) {
            $previousVersion = is_string($installedVersion) ? $installedVersion : null;
            if (is_string($previousVersion) && $previousVersion !== '') {
                $this->rollbackModuleToVersion($resolvedFamily, $moduleKey, $previousVersion);
                $rollbackTriggered = true;
            }
        }
        if ($action === 'update' && ! $postCheckPassed && ! $rollbackTriggered) {
            throw new RuntimeException('Update post-check başarısız; rollback tamamlanamadı.');
        }

        if ($action === 'update' && $rollbackTriggered) {
            throw new RuntimeException(__('admin.marketplace_client.messages.module_update_rolled_back'));
        }

        return [
            'family' => $resolvedFamily,
            'module_key' => $moduleKey,
            'version' => $resolvedVersion,
            'installed_version_before' => $installedVersion,
            'installed_version_after' => is_string($result['version'] ?? null) ? (string) $result['version'] : $resolvedVersion,
            'action' => $action,
            'steps' => [
                'dump_autoload' => (bool) ($steps['dump_autoload'] ?? true),
                'migrate' => (bool) ($steps['migrate'] ?? false),
                'seed' => (bool) ($steps['seed'] ?? false),
            ],
            'post_check_passed' => $postCheckPassed,
            'rollback_triggered' => $rollbackTriggered,
        ];
    }

    private function postUpgradeHealthCheck(string $family, string $moduleKey): bool
    {
        $keyCandidates = $this->moduleKeyCandidates($moduleKey);
        if ($keyCandidates === []) {
            return false;
        }
        $lowerCandidates = array_values(array_unique(array_map('mb_strtolower', $keyCandidates)));

        $installation = ModuleInstallation::query()
            ->whereRaw('LOWER(family) = ?', [strtolower($family)])
            ->where(function ($query) use ($keyCandidates, $lowerCandidates): void {
                $query->whereIn('module_key', $keyCandidates)
                    ->orWhereIn(\DB::raw('LOWER(module_key)'), $lowerCandidates);
            })
            ->where('is_installed', true)
            ->where('is_active', true)
            ->first();

        if (! $installation instanceof ModuleInstallation) {
            return false;
        }

        $activeModule = collect(app(ModuleRegistry::class)->activeModules())
            ->first(function (array $module) use ($family, $moduleKey): bool {
                return mb_strtolower((string) ($module['family'] ?? '')) === mb_strtolower($family)
                    && mb_strtolower((string) ($module['key'] ?? '')) === mb_strtolower($moduleKey);
            });
        if (! is_array($activeModule)) {
            return false;
        }

        /** Sadece bu modülün manifest sözleşmesi; tüm paneldeki contract_fail sayısını kullanma (yanlış rollback). */
        if (! (bool) ($activeModule['contract_ok'] ?? false)) {
            return false;
        }

        return $this->hasModuleRoutesRegistered($family, $moduleKey, $activeModule);
    }

    /**
     * Namespace, Str::studly('cms') => Cms hatası yerine module.json `namespace` / provider üzerinden çözülür.
     *
     * @param  array<string, mixed>  $activeModule
     */
    private function hasModuleRoutesRegistered(string $family, string $moduleKey, ?array $activeModule = null): bool
    {
        if (! is_array($activeModule)) {
            $activeModule = collect(app(ModuleRegistry::class)->all())->first(function (array $m) use ($family, $moduleKey): bool {
                return mb_strtolower((string) ($m['family'] ?? '')) === mb_strtolower($family)
                    && mb_strtolower((string) ($m['key'] ?? '')) === mb_strtolower($moduleKey);
            });
        }
        if (! is_array($activeModule)) {
            return false;
        }

        $hasAdmin = (bool) ($activeModule['has_admin_routes'] ?? false);
        $hasApi = (bool) ($activeModule['has_api_routes'] ?? false);
        if (! $hasAdmin && ! $hasApi) {
            return true;
        }

        $config = is_array($activeModule['config'] ?? null) ? $activeModule['config'] : [];
        $namespace = is_string($config['namespace'] ?? null) ? trim((string) $config['namespace'], '\\') : '';
        if ($namespace === '') {
            $provider = (string) ($activeModule['provider'] ?? '');
            if ($provider !== '' && preg_match('/^(.+)\\\\([^\\\\]+ServiceProvider)$/', $provider, $m)) {
                $namespace = $m[1];
            }
        }
        if ($namespace === '') {
            return false;
        }

        $actionPrefix = $namespace.'\\';
        foreach (Route::getRoutes() as $route) {
            $action = (string) $route->getActionName();
            if ($action !== '' && str_contains($action, $actionPrefix)) {
                return true;
            }
        }

        return false;
    }

    private function rollbackModuleToVersion(string $family, string $moduleKey, string $targetVersion): void
    {
        $package = ModulePackage::query()
            ->where('family', $family)
            ->where('module_key', $moduleKey)
            ->where('package_version', $targetVersion)
            ->whereNotNull('artifact_path')
            ->orderByDesc('updated_at')
            ->first();
        if (! $package instanceof ModulePackage || ! is_string($package->artifact_path) || ! is_file($package->artifact_path)) {
            throw new RuntimeException("Rollback artifact bulunamadi: {$family}/{$moduleKey}@{$targetVersion}");
        }

        $this->modulePackageInstaller->installFromZipPath($package->artifact_path, [
            'allow_non_toggleable' => true,
            'allow_reinstall_same_version' => true,
            'run_dump_autoload' => true,
            'run_migrations' => false,
            'run_seeders' => false,
            'dependency_operation' => 'upgrade',
            'installed_version_override' => $targetVersion,
        ]);
    }

    private function normalizeFamily(string $family): string
    {
        return strtolower(trim($family));
    }

    private function resolveModulePackageForInstall(string $family, string $moduleKey, string $version): ?ModulePackage
    {
        $keyCandidates = $this->moduleKeyCandidates($moduleKey);
        if ($keyCandidates === []) {
            return null;
        }
        $lowerCandidates = array_values(array_unique(array_map('mb_strtolower', $keyCandidates)));
        $normalizedVersion = $this->normalizeVersion($version);
        $versionCandidates = array_values(array_unique(array_filter([
            trim($version),
            $normalizedVersion,
            'v'.$normalizedVersion,
        ], fn (string $item): bool => $item !== '')));

        $package = ModulePackage::query()
            ->where('family', $family)
            ->where(function ($query) use ($keyCandidates, $lowerCandidates): void {
                $query->whereIn('module_key', $keyCandidates)
                    ->orWhereIn(\DB::raw('LOWER(module_key)'), $lowerCandidates);
            })
            ->whereIn('package_version', $versionCandidates)
            ->orderByDesc('updated_at')
            ->first();
        if ($package instanceof ModulePackage) {
            return $package;
        }

        $latest = ModulePackage::query()
            ->where('family', $family)
            ->where(function ($query) use ($keyCandidates, $lowerCandidates): void {
                $query->whereIn('module_key', $keyCandidates)
                    ->orWhereIn(\DB::raw('LOWER(module_key)'), $lowerCandidates);
            })
            ->orderByDesc('updated_at')
            ->first();
        if ($latest instanceof ModulePackage) {
            return $latest;
        }

        return $this->hydrateModulePackageFromFlowShopManagementArtifact($family, $moduleKey, $versionCandidates, $keyCandidates);
    }

    /**
     * @param  array<int, string>  $versionCandidates
     */
    private function hydrateModulePackageFromFlowShopManagementArtifact(string $family, string $moduleKey, array $versionCandidates, array $keyCandidates): ?ModulePackage
    {
        if (! Schema::hasTable('shop_management_artifacts')) {
            return null;
        }

        $lowerCandidates = array_values(array_unique(array_map('mb_strtolower', $keyCandidates)));

        $artifact = FlowShopManagementArtifact::query()
            ->where('artifact_family', $family)
            ->where(function ($query) use ($keyCandidates, $lowerCandidates): void {
                $query->whereIn('artifact_key', $keyCandidates)
                    ->orWhereIn(\DB::raw('LOWER(artifact_key)'), $lowerCandidates);
            })
            ->where(function ($query) use ($versionCandidates): void {
                $query->whereIn('version', $versionCandidates);
                foreach ($versionCandidates as $candidate) {
                    $normalized = $this->normalizeVersion($candidate);
                    $query->orWhere('version', $normalized)
                        ->orWhere('version', 'v'.$normalized);
                }
            })
            ->whereNull('deleted_at')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();

        if (! $artifact instanceof FlowShopManagementArtifact) {
            return null;
        }

        $resolvedModuleKey = is_string($artifact->artifact_key) && trim((string) $artifact->artifact_key) !== ''
            ? trim((string) $artifact->artifact_key)
            : $moduleKey;

        $version = is_string($artifact->version) && trim($artifact->version) !== ''
            ? trim($artifact->version)
            : (string) ($versionCandidates[0] ?? '1.0.0');

        $artifactMeta = is_array($artifact->metadata) ? $artifact->metadata : [];
        $checksum = is_string($artifact->checksum_sha256) ? trim($artifact->checksum_sha256) : '';
        $artifactPath = is_string($artifact->artifact_path) ? $artifact->artifact_path : '';

        $signature = is_string($artifactMeta['signature'] ?? null) ? trim((string) $artifactMeta['signature']) : '';
        $signatureKeyId = is_string($artifactMeta['signature_key_id'] ?? null) ? trim((string) $artifactMeta['signature_key_id']) : '';
        $publicKey = is_string($artifactMeta['signature_public_key'] ?? null) ? trim((string) $artifactMeta['signature_public_key']) : '';
        $provenance = is_string($artifactMeta['provenance'] ?? null) ? trim((string) $artifactMeta['provenance']) : '';

        if (($signature === '' || $publicKey === '') && $checksum !== '' && $artifactPath !== '' && is_file($artifactPath)) {
            try {
                $signed = $this->artifactSignatureService->signChecksum($checksum);
                $signature = $signed['signature'];
                $signatureKeyId = $signed['signature_key_id'];
                $publicKey = $signed['public_key'];
            } catch (Throwable) {
                // İmza üretilemezse FlowShopManagement kaydında eksik kalır; MODULE_SIGNATURE_ENFORCEMENT=report veya yeniden fetch denenebilir.
            }
        }

        if ($provenance === '') {
            $provenance = 'flow_shop_management_artifact:'.(string) $artifact->id;
        }

        $notesFromZip = $artifactPath !== '' && is_file($artifactPath)
            ? $this->readManifestReleaseNotesFromZipPath($artifactPath)
            : null;
        $artifactReleaseNotes = is_string($artifactMeta['release_notes'] ?? null) ? trim((string) $artifactMeta['release_notes']) : '';

        return ModulePackage::query()->updateOrCreate(
            [
                'family' => $family,
                'module_key' => $resolvedModuleKey,
                'package_version' => $version,
            ],
            [
                'release_channel' => 'stable',
                'source_type' => 'flow_shop_management_server',
                'source_ref' => is_string($artifact->source_ref) ? $artifact->source_ref : '',
                'artifact_path' => $artifactPath !== '' ? $artifactPath : null,
                'checksum_sha256' => $checksum !== '' ? $checksum : null,
                'signature' => $signature !== '' ? $signature : null,
                'signature_key_id' => $signatureKeyId !== '' ? $signatureKeyId : null,
                'metadata' => [
                    'hydrated_from_artifact_id' => $artifact->id,
                    'catalog_synced_at' => now()->toIso8601String(),
                    'signature_public_key' => $publicKey !== '' ? $publicKey : null,
                    'provenance' => $provenance,
                    'signature' => $signature !== '' ? $signature : null,
                    'signature_key_id' => $signatureKeyId !== '' ? $signatureKeyId : null,
                    'release_notes' => $notesFromZip ?? ($artifactReleaseNotes !== '' ? $artifactReleaseNotes : null),
                ],
                'published_at' => now(),
            ]
        );
    }

    /**
     * @return array<int, string>
     */
    private function moduleKeyCandidates(string $moduleKey): array
    {
        $base = trim($moduleKey);
        if ($base === '') {
            return [];
        }

        $spaced = preg_replace('/[^a-z0-9]+/i', ' ', $base) ?? $base;
        $studly = Str::studly($spaced);
        $camel = Str::camel($spaced);
        $snake = Str::snake($spaced);
        $kebab = Str::kebab($spaced);

        return array_values(array_unique(array_filter([
            $base,
            $studly,
            $camel,
            $snake,
            $kebab,
            str_replace('-', '_', $base),
            str_replace('_', '-', $base),
        ], fn (string $item): bool => trim($item) !== '')));
    }

    /**
     * @param  array<string, mixed>  $downloadPayload
     * @param  (callable(string, string, array<string, mixed>): void)|null  $progress
     * @return array{zip_path:string,checksum_sha256:?string,signature:?string,signature_key_id:?string,signature_public_key:?string}
     */
    private function downloadModuleArtifactZip(
        string $family,
        string $moduleKey,
        string $version,
        ModulePackage $package,
        array $downloadPayload,
        ?callable $progress = null
    ): array {
        $emit = function (string $phase, string $message, array $extra = []) use ($progress): void {
            if ($progress !== null) {
                $progress($phase, $message, $extra);
            }
        };

        $metadata = is_array($package->metadata) ? $package->metadata : [];
        $serverArtifactPath = is_string($package->artifact_path) && trim($package->artifact_path) !== ''
            ? trim($package->artifact_path)
            : (is_string($metadata['artifact_path'] ?? null) ? (string) $metadata['artifact_path'] : '');
        $knownChecksum = is_string($package->checksum_sha256) && trim((string) $package->checksum_sha256) !== ''
            ? (string) $package->checksum_sha256
            : (is_string($metadata['checksum_sha256'] ?? null) ? (string) $metadata['checksum_sha256'] : null);
        $knownSignature = is_string($package->signature) && trim((string) $package->signature) !== ''
            ? (string) $package->signature
            : (is_string($metadata['signature'] ?? null) ? (string) $metadata['signature'] : null);
        $knownSignatureKeyId = is_string($package->signature_key_id) && trim((string) $package->signature_key_id) !== ''
            ? (string) $package->signature_key_id
            : (is_string($metadata['signature_key_id'] ?? null) ? (string) $metadata['signature_key_id'] : null);
        $knownPublicKey = is_string($metadata['signature_public_key'] ?? null) ? (string) $metadata['signature_public_key'] : null;

        $downloadDir = storage_path('app/private/marketplace/client-artifacts/module/'.$family.'/'.$moduleKey.'/'.$version);
        File::ensureDirectoryExists($downloadDir, 0775, true);
        $zipPath = $downloadDir.'/'.$family.'-'.$moduleKey.'-'.$version.'.zip';

        if ($serverArtifactPath !== '' && is_file($serverArtifactPath)) {
            $bytes = @filesize($serverArtifactPath);
            $emit('download_local', __('admin.marketplace_client.stream.copy_local_zip'), [
                'percent' => 10,
                'path' => $serverArtifactPath,
                'bytes' => is_int($bytes) ? $bytes : null,
            ]);
            if (! @copy($serverArtifactPath, $zipPath)) {
                throw new RuntimeException('Module artifact kopyalanamadi.');
            }
            $emit('verify', __('admin.marketplace_client.stream.verify_checksum'), ['percent' => 35]);
            $this->verifyChecksumIfProvided($zipPath, $knownChecksum);
            $this->verifySignatureIfRequired($zipPath, $knownSignature, $knownPublicKey);

            return [
                'zip_path' => $zipPath,
                'checksum_sha256' => $knownChecksum,
                'signature' => $knownSignature,
                'signature_key_id' => $knownSignatureKeyId,
                'signature_public_key' => $knownPublicKey,
            ];
        }

        $downloadUrl = is_string($downloadPayload['download_url'] ?? null) ? (string) $downloadPayload['download_url'] : '';
        if ($downloadUrl === '') {
            throw new RuntimeException('Yerel module artifact bulunamadi. Lisansli download-link icin panel_id/customer_id gerekli.');
        }

        $emit('download_remote', __('admin.marketplace_client.stream.download_remote_start'), ['percent' => 5]);

        $response = Http::timeout(120)
            ->retry(2, 300)
            ->sink($zipPath)
            ->withOptions([
                'allow_redirects' => true,
                'progress' => function ($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes) use ($emit): void {
                    if ($downloadTotal > 0) {
                        $pct = 5 + (int) min(30, (30 * $downloadedBytes) / $downloadTotal);
                        $emit('download', __('admin.marketplace_client.stream.download_progress', [
                            'current' => $this->formatBytes((int) $downloadedBytes),
                            'total' => $this->formatBytes((int) $downloadTotal),
                        ]), [
                            'percent' => $pct,
                            'downloaded' => (int) $downloadedBytes,
                            'total' => (int) $downloadTotal,
                        ]);
                    }
                },
            ])
            ->get($downloadUrl);
        if (! $response->successful()) {
            throw new RuntimeException('Module ZIP indirilemedi. HTTP: '.$response->status());
        }

        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            throw new RuntimeException('Module ZIP response bos.');
        }

        $emit('verify', __('admin.marketplace_client.stream.verify_checksum'), ['percent' => 35]);

        $headerChecksum = $response->header('X-Checksum-SHA256');
        $checksum = is_string($headerChecksum) && trim($headerChecksum) !== ''
            ? trim($headerChecksum)
            : $knownChecksum;
        $headerSignature = $response->header('X-Signature');
        $headerSignatureKeyId = $response->header('X-Signature-Key-Id');
        $headerPublicKey = $response->header('X-Signature-Public-Key');
        $signature = is_string($headerSignature) && trim($headerSignature) !== '' ? trim($headerSignature) : $knownSignature;
        $signatureKeyId = is_string($headerSignatureKeyId) && trim($headerSignatureKeyId) !== '' ? trim($headerSignatureKeyId) : $knownSignatureKeyId;
        $publicKey = is_string($headerPublicKey) && trim($headerPublicKey) !== '' ? trim($headerPublicKey) : $knownPublicKey;
        $this->verifyChecksumIfProvided($zipPath, $checksum);
        $this->verifySignatureIfRequired($zipPath, $signature, $publicKey);

        return [
            'zip_path' => $zipPath,
            'checksum_sha256' => $checksum,
            'signature' => $signature,
            'signature_key_id' => $signatureKeyId,
            'signature_public_key' => $publicKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $downloadPayload
     * @param  array<string, mixed>  $manifest
     */
    private function createOrUpdateProviderAndBinding(array $manifest, string $providerKey, string $version): void
    {
        $categoryKey = (string) ($manifest['category'] ?? 'devops');
        $manifestName = is_array($manifest['name'] ?? null)
            ? $manifest['name']
            : ['tr' => (string) ($manifest['name'] ?? $providerKey), 'en' => (string) ($manifest['name'] ?? $providerKey)];
        $manifestDescription = is_array($manifest['description'] ?? null)
            ? $manifest['description']
            : ['tr' => (string) ($manifest['description'] ?? ''), 'en' => (string) ($manifest['description'] ?? '')];

        $category = IntegrationCategory::query()->firstOrCreate(
            ['key' => $categoryKey],
            [
                'slug' => $categoryKey,
                'name' => ['tr' => Str::headline($categoryKey), 'en' => Str::headline($categoryKey)],
                'description' => ['tr' => '', 'en' => ''],
                'icon' => is_string($manifest['icon'] ?? null) ? (string) $manifest['icon'] : null,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        $provider = IntegrationProvider::query()->firstOrNew(['key' => $providerKey]);
        $provider->integration_category_id = $category->id;
        $provider->name = $manifestName;
        $provider->description = $manifestDescription;
        $provider->provider_class = (string) ($manifest['provider_class'] ?? $provider->provider_class ?? '');
        $provider->icon = is_string($manifest['icon'] ?? null) ? (string) $manifest['icon'] : $provider->icon;
        $provider->documentation = [
            'fields' => is_array($manifest['installation']['fields'] ?? null) ? $manifest['installation']['fields'] : [],
            'manifest_version' => (string) ($manifest['manifest_version'] ?? $manifest['schema_version'] ?? '1.0'),
            'installed_version' => $version,
        ];
        $provider->is_active = true;
        $provider->priority = (int) ($manifest['installation']['default_priority'] ?? 0);
        $provider->test_mode = (bool) ($manifest['installation']['default_test_mode'] ?? false);
        $provider->save();

        IntegrationBinding::query()->updateOrCreate(
            [
                'provider_key' => $providerKey,
                'environment' => 'production',
                'project_id' => null,
                'tenant_id' => null,
            ],
            [
                'binding_status' => 'configured',
                'config_profile_key' => null,
                'mode' => $provider->test_mode ? 'sandbox' : 'live',
                'metadata' => [
                    'installed_via' => 'marketplace_client',
                    'installed_at' => now()->toIso8601String(),
                ],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $downloadPayload
     * @param  array<string, mixed>  $packageMetadata
     * @return array{zip_path:string,checksum_sha256:?string,signature:?string,signature_key_id:?string,signature_public_key:?string}
     */
    private function downloadProviderArtifactZip(
        string $providerKey,
        string $version,
        IntegrationPackage $package,
        array $downloadPayload
    ): array {
        $packageMetadata = is_array($package->metadata) ? $package->metadata : [];
        $serverArtifactPath = is_string($packageMetadata['artifact_path'] ?? null) ? (string) $packageMetadata['artifact_path'] : '';
        $knownChecksum = is_string($packageMetadata['checksum_sha256'] ?? null) ? (string) $packageMetadata['checksum_sha256'] : null;
        $knownSignature = is_string($packageMetadata['signature'] ?? null) ? (string) $packageMetadata['signature'] : null;
        $knownSignatureKeyId = is_string($packageMetadata['signature_key_id'] ?? null) ? (string) $packageMetadata['signature_key_id'] : null;
        $knownPublicKey = is_string($packageMetadata['signature_public_key'] ?? null) ? (string) $packageMetadata['signature_public_key'] : null;

        $downloadDir = storage_path('app/private/marketplace/client-artifacts/integration_provider/'.$providerKey.'/'.$version);
        File::ensureDirectoryExists($downloadDir, 0775, true);
        $zipPath = $downloadDir.'/'.$providerKey.'-'.$version.'.zip';

        if ($serverArtifactPath !== '' && is_file($serverArtifactPath)) {
            if (! @copy($serverArtifactPath, $zipPath)) {
                throw new RuntimeException('Server artifact kopyalanamadi.');
            }
            $this->verifyChecksumIfProvided($zipPath, $knownChecksum);
            $this->verifySignatureIfRequired($zipPath, $knownSignature, $knownPublicKey);

            return [
                'zip_path' => $zipPath,
                'checksum_sha256' => $knownChecksum,
                'signature' => $knownSignature,
                'signature_key_id' => $knownSignatureKeyId,
                'signature_public_key' => $knownPublicKey,
            ];
        }

        $downloadUrl = is_string($downloadPayload['download_url'] ?? null) ? (string) $downloadPayload['download_url'] : '';
        if ($downloadUrl === '') {
            throw new RuntimeException('Yerel artifact bulunamadi. Lisansli download-link icin panel_id/customer_id gerekli.');
        }

        $response = Http::timeout(120)
            ->retry(2, 300)
            ->withOptions(['allow_redirects' => true])
            ->get($downloadUrl);

        if (! $response->successful()) {
            throw new RuntimeException('ZIP indirilemedi. HTTP: '.$response->status());
        }

        $body = $response->body();
        if (! is_string($body) || $body === '') {
            throw new RuntimeException('ZIP response bos.');
        }
        file_put_contents($zipPath, $body);

        $headerChecksum = $response->header('X-Checksum-SHA256');
        $checksum = is_string($headerChecksum) && trim($headerChecksum) !== ''
            ? trim($headerChecksum)
            : $knownChecksum;
        $headerSignature = $response->header('X-Signature');
        $headerSignatureKeyId = $response->header('X-Signature-Key-Id');
        $headerPublicKey = $response->header('X-Signature-Public-Key');
        $signature = is_string($headerSignature) && trim($headerSignature) !== '' ? trim($headerSignature) : $knownSignature;
        $signatureKeyId = is_string($headerSignatureKeyId) && trim($headerSignatureKeyId) !== '' ? trim($headerSignatureKeyId) : $knownSignatureKeyId;
        $publicKey = is_string($headerPublicKey) && trim($headerPublicKey) !== '' ? trim($headerPublicKey) : $knownPublicKey;
        $this->verifyChecksumIfProvided($zipPath, $checksum);
        $this->verifySignatureIfRequired($zipPath, $signature, $publicKey);

        return [
            'zip_path' => $zipPath,
            'checksum_sha256' => $checksum,
            'signature' => $signature,
            'signature_key_id' => $signatureKeyId,
            'signature_public_key' => $publicKey,
        ];
    }

    private function verifyChecksumIfProvided(string $zipPath, ?string $checksum): void
    {
        $mode = strtolower((string) config('module_contract.signature_enforcement', 'report'));
        if (! is_string($checksum) || trim($checksum) === '') {
            if ($mode === 'strict') {
                throw new RuntimeException('ZIP checksum zorunlu fakat eksik.');
            }

            return;
        }
        $calculated = hash_file('sha256', $zipPath);
        if (! is_string($calculated) || ! hash_equals(strtolower(trim($checksum)), strtolower($calculated))) {
            throw new RuntimeException('ZIP checksum dogrulamasi basarisiz.');
        }
    }

    private function verifySignatureIfRequired(string $zipPath, ?string $signatureBase64, ?string $publicKeyBase64): void
    {
        $mode = strtolower((string) config('module_contract.signature_enforcement', 'report'));
        $signature = is_string($signatureBase64) ? trim($signatureBase64) : '';
        $publicKey = is_string($publicKeyBase64) ? trim($publicKeyBase64) : '';

        if ($signature === '' || $publicKey === '') {
            if ($mode === 'strict') {
                throw new RuntimeException('ZIP signature zorunlu fakat eksik.');
            }

            return;
        }

        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            if ($mode === 'strict') {
                throw new RuntimeException('Libsodium olmadan signature verify yapılamaz.');
            }

            return;
        }

        $checksum = hash_file('sha256', $zipPath);
        if (! is_string($checksum) || $checksum === '') {
            throw new RuntimeException('ZIP checksum üretilemedi (signature verify).');
        }
        $signatureRaw = base64_decode($signature, true);
        $publicKeyRaw = base64_decode($publicKey, true);
        if (! is_string($signatureRaw) || ! is_string($publicKeyRaw) || $signatureRaw === '' || $publicKeyRaw === '') {
            throw new RuntimeException('ZIP signature/public key decode hatası.');
        }
        if (! sodium_crypto_sign_verify_detached($signatureRaw, $checksum, $publicKeyRaw)) {
            throw new RuntimeException('ZIP signature doğrulaması başarısız.');
        }
    }

    /**
     * @return array{manifest:array<string,mixed>,module_root_prefix:string}
     */
    private function validateProviderZip(string $zipPath, string $expectedProviderKey): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP acilamadi.');
        }

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name)) {
                    $entries[] = $name;
                }
            }

            $moduleJsonEntry = $this->findEntryEnding($entries, 'module.json');
            if ($moduleJsonEntry === null) {
                throw new RuntimeException('ZIP icinde module.json bulunamadi.');
            }

            $manifestRaw = $zip->getFromName($moduleJsonEntry);
            if (! is_string($manifestRaw) || trim($manifestRaw) === '') {
                throw new RuntimeException('module.json okunamadi.');
            }

            $manifest = json_decode($manifestRaw, true);
            if (! is_array($manifest)) {
                throw new RuntimeException('module.json gecersiz JSON.');
            }
            $family = (string) ($manifest['family'] ?? '');
            if ($family !== 'integration_provider') {
                throw new RuntimeException('Yalniz integration_provider ZIP kurulumu desteklenir.');
            }
            $manifestKey = (string) ($manifest['key'] ?? '');
            if ($manifestKey === '' || $manifestKey !== $expectedProviderKey) {
                throw new RuntimeException("Provider key uyusmuyor. Beklenen: {$expectedProviderKey}, gelen: {$manifestKey}");
            }

            $providerClass = (string) ($manifest['provider_class'] ?? '');
            if ($providerClass === '') {
                throw new RuntimeException('module.json provider_class zorunlu.');
            }

            $entryPath = str_replace('\\', '/', $providerClass).'.php';
            $entryFile = $this->findEntryEnding($entries, $entryPath) ?? $this->findEntryEnding($entries, basename($entryPath));
            if ($entryFile === null) {
                throw new RuntimeException("Entry dosyasi bulunamadi: {$entryPath}");
            }

            $rootPrefix = dirname($moduleJsonEntry);
            $rootPrefix = $rootPrefix === '.' ? '' : trim($rootPrefix, '/');

            return [
                'manifest' => $manifest,
                'module_root_prefix' => $rootPrefix,
            ];
        } finally {
            $zip->close();
        }
    }

    private function resolveProviderDirectoryName(array $manifest, string $providerKey): string
    {
        $providerClass = (string) ($manifest['provider_class'] ?? '');
        $fromClass = IntegrationProviderPackagePath::relativeDirectoryFromProviderClass($providerClass);
        if ($fromClass !== null) {
            return $fromClass;
        }

        return Str::studly(preg_replace('/[^a-z0-9]+/i', ' ', $providerKey) ?? $providerKey);
    }

    private function extractProviderZipToTarget(string $zipPath, string $moduleRootPrefix, string $targetDir): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP acilamadi (extract).');
        }

        try {
            if (is_dir($targetDir)) {
                File::deleteDirectory($targetDir);
            }
            File::ensureDirectoryExists($targetDir, 0775, true);

            $prefix = trim($moduleRootPrefix, '/');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (! is_string($entry) || $entry === '' || str_ends_with($entry, '/')) {
                    continue;
                }

                $entry = str_replace('\\', '/', $entry);
                if ($prefix !== '') {
                    $prefixWithSlash = $prefix.'/';
                    if (! str_starts_with($entry, $prefixWithSlash)) {
                        continue;
                    }
                    $relativePath = substr($entry, strlen($prefixWithSlash));
                } else {
                    $relativePath = $entry;
                }

                if (! is_string($relativePath) || $relativePath === '') {
                    continue;
                }
                if (str_contains($relativePath, '../') || str_starts_with($relativePath, '/')) {
                    throw new RuntimeException('ZIP icinde gecersiz path bulundu.');
                }

                $destination = $targetDir.'/'.$relativePath;
                File::ensureDirectoryExists(dirname($destination), 0775, true);
                $contents = $zip->getFromIndex($i);
                if (! is_string($contents)) {
                    continue;
                }
                file_put_contents($destination, $contents);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<int, string>  $entries
     */
    private function findEntryEnding(array $entries, string $suffix): ?string
    {
        $suffix = ltrim($suffix, '/');
        $needle = '/'.$suffix;
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if ($entry === $suffix || str_ends_with($entry, $needle)) {
                return $entry;
            }
        }

        return null;
    }

    private function currentInstallStatusForProvider(string $providerKey): string
    {
        $availableProviderKeys = $this->availableProviderKeys();
        $installed = Schema::hasTable('integration_providers')
            && in_array($providerKey, $availableProviderKeys, true)
            && IntegrationProvider::query()->where('key', $providerKey)->exists();

        if ($installed) {
            return 'installed';
        }

        $existing = IntegrationPackage::query()
            ->where('provider_key', $providerKey)
            ->orderByDesc('updated_at')
            ->first();

        if ($existing && in_array($existing->install_status, ['downloaded', 'installed'], true)) {
            $metadata = is_array($existing->metadata) ? $existing->metadata : [];
            $download = is_array($metadata['download'] ?? null) ? $metadata['download'] : [];
            $zipPath = is_string($download['zip_path'] ?? null) ? (string) $download['zip_path'] : '';
            $downloadStillValid = $zipPath !== '' && is_file($zipPath);

            if ($existing->install_status === 'downloaded' && $downloadStillValid) {
                return 'downloaded';
            }

            if ($existing->install_status === 'installed' && $installed) {
                return 'installed';
            }
        }

        return 'catalog_synced';
    }

    /**
     * @return array<int, string>
     */
    private function availableProviderKeys(): array
    {
        if (is_array($this->availableProviderKeysCache)) {
            return $this->availableProviderKeysCache;
        }

        $keys = $this->integrationService
            ->getAvailableModules()
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (mixed $key): string => (string) $key)
            ->unique()
            ->values()
            ->all();

        $this->availableProviderKeysCache = $keys;

        return $keys;
    }

    private function cleanupStaleInstalledProviders(): void
    {
        $availableKeys = $this->availableProviderKeys();
        if ($availableKeys === []) {
            return;
        }
        $staleKeys = IntegrationProvider::query()
            ->when($availableKeys !== [], function ($query) use ($availableKeys): void {
                $query->whereNotIn('key', $availableKeys);
            })
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();

        if ($staleKeys === []) {
            return;
        }

        IntegrationProvider::query()
            ->whereIn('key', $staleKeys)
            ->delete();

        IntegrationBinding::query()
            ->whereIn('provider_key', $staleKeys)
            ->update(['binding_status' => 'disabled']);

        IntegrationPackage::query()
            ->whereIn('provider_key', $staleKeys)
            ->where('install_status', 'installed')
            ->update([
                'install_status' => 'catalog_synced',
                'last_seen_at' => now(),
            ]);
    }

    private function publishedProviderTitle(string $providerKey): ?string
    {
        if (! Schema::hasTable('shop_management_modules')) {
            return null;
        }

        $candidates = [
            'integration-provider-'.$providerKey,
            'integration_provider_'.$providerKey,
            $providerKey,
        ];

        $module = FlowShopManagementModule::query()
            ->where('family', 'integration_provider')
            ->whereIn('slug', $candidates)
            ->first();

        if (! $module && Schema::hasColumn('shop_management_modules', 'repo_name')) {
            $module = FlowShopManagementModule::query()
                ->where('family', 'integration_provider')
                ->where(function ($query) use ($providerKey): void {
                    $query->where('repo_name', 'integration-'.$providerKey)
                        ->orWhere('repo_name', $providerKey);
                })
                ->first();
        }

        return $module && is_string($module->name) && $module->name !== '' ? $module->name : null;
    }

    /**
     * @return array<int, string>
     */
    private function activePublishedProviderKeys(): array
    {
        if (! Schema::hasTable('shop_management_modules')) {
            return [];
        }

        $query = FlowShopManagementModule::query()
            ->where('family', 'integration_provider')
            ->where('is_active', true);
        if (Schema::hasColumn('shop_management_modules', 'is_publishable')) {
            $query->where('is_publishable', true);
        }

        return $this->activePublishedProviderKeysFromModules($query->get());
    }

    /**
     * @return Collection<int, object>
     */
    private function buildCatalogModules(): Collection
    {
        if (! Schema::hasTable('shop_management_modules')) {
            return collect();
        }

        $moduleQuery = FlowShopManagementModule::query()
            ->where('family', '!=', 'integration_provider')
            ->where('family', '!=', 'master')
            ->where('is_active', true)
            ->orderBy('name');
        if (Schema::hasColumn('shop_management_modules', 'is_publishable')) {
            $moduleQuery->where('is_publishable', true);
        }

        $select = ['id', 'name', 'slug', 'family', 'latest_version', 'repo_name'];
        if (Schema::hasColumn('shop_management_modules', 'name_i18n')) {
            $select[] = 'name_i18n';
        }

        $modules = $moduleQuery->get($select);
        if ($modules->isEmpty()) {
            return collect();
        }

        return $modules->map(function (FlowShopManagementModule $module): object {
            $family = $this->normalizeFamily((string) ($module->family ?? ''));
            $moduleKeyFromSlug = $this->resolveModuleKeyFromFlowShopManagementModule($module);
            $managementLatest = is_string($module->latest_version) && trim((string) $module->latest_version) !== ''
                ? trim((string) $module->latest_version)
                : null;
            /** Çözümleyici için ipucu: FlowShop yönetimindeki sürüm; yoksa güvenli varsayılan */
            $resolveHintVersion = $managementLatest ?? '1.0.0';

            $package = $this->resolveModulePackageForInstall($family, $moduleKeyFromSlug, $resolveHintVersion);
            $moduleKey = $package instanceof ModulePackage
                ? (string) $package->module_key
                : $moduleKeyFromSlug;

            $installStatus = $this->currentInstallStatusForModule($family, $moduleKey);
            $installedVersion = $this->installedVersionForModule($family, $moduleKey);
            $metadata = is_array($package?->metadata) ? $package->metadata : [];
            $moduleNameI18n = is_array($module->name_i18n ?? null) ? $module->name_i18n : null;
            $metadata['name'] = is_array($moduleNameI18n) && $moduleNameI18n !== []
                ? $moduleNameI18n
                : ['tr' => (string) $module->name, 'en' => (string) $module->name];
            $metadata['publisher'] = is_string($metadata['publisher'] ?? null) && trim((string) $metadata['publisher']) !== ''
                ? $metadata['publisher']
                : 'Bikare';
            $resolvedPackageVersion = $package instanceof ModulePackage
                ? trim((string) ($package->package_version ?? ''))
                : '';
            /**
             * Katalogda gösterilecek "güncel" sürüm: FlowShop yönetim kaydı (latest_version) öncelikli.
             * module_packages satırı bazen henüz yeni sürüm için yoktur; çözümleyici eski pakete düşer
             * ve package_version 1.0.2 kalır — UI yanlış olur.
             */
            $advertisedVersion = $managementLatest ?? ($resolvedPackageVersion !== '' ? $resolvedPackageVersion : '1.0.0');
            $hasUpdate = $installedVersion !== null
                && $this->isNewerVersion($advertisedVersion, $installedVersion);
            $releaseNotes = $this->resolveCatalogReleaseNotes($metadata);

            return (object) [
                'family' => $family,
                'module_key' => $moduleKey,
                'package_version' => $advertisedVersion,
                'install_status' => $installStatus,
                'installed_version' => $installedVersion,
                'has_update' => $hasUpdate,
                'release_notes' => $releaseNotes,
                'metadata' => $metadata,
            ];
        })->values();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveCatalogReleaseNotes(array $metadata): ?string
    {
        if (isset($metadata['release_notes']) && is_string($metadata['release_notes']) && trim($metadata['release_notes']) !== '') {
            return trim($metadata['release_notes']);
        }
        if (isset($metadata['release_notes']) && is_array($metadata['release_notes'])) {
            /** @var array<string, mixed> $rn */
            $rn = $metadata['release_notes'];
            $loc = app()->getLocale();
            $text = $rn[$loc] ?? $rn['tr'] ?? $rn['en'] ?? null;

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        }

        return null;
    }

    private function resolveModuleKeyFromFlowShopManagementModule(FlowShopManagementModule $module): string
    {
        $slug = trim((string) ($module->slug ?? ''));
        $family = $this->normalizeFamily((string) ($module->family ?? ''));
        if ($slug === '') {
            return '';
        }

        $familyPrefixDashed = $family !== '' ? $family.'-' : '';
        $familyPrefixUnderscore = $family !== '' ? $family.'_' : '';
        if ($familyPrefixDashed !== '' && str_starts_with($slug, $familyPrefixDashed)) {
            return trim(Str::after($slug, $familyPrefixDashed));
        }
        if ($familyPrefixUnderscore !== '' && str_starts_with($slug, $familyPrefixUnderscore)) {
            return trim(Str::after($slug, $familyPrefixUnderscore));
        }

        return $slug;
    }

    private function currentInstallStatusForModule(string $family, string $moduleKey): string
    {
        $keyCandidates = $this->moduleKeyCandidates($moduleKey);
        $lowerCandidates = array_values(array_unique(array_map('mb_strtolower', $keyCandidates)));
        $installed = ModuleInstallation::query()
            ->whereRaw('LOWER(family) = ?', [strtolower($family)])
            ->where(function ($query) use ($keyCandidates, $lowerCandidates): void {
                $query->whereIn('module_key', $keyCandidates)
                    ->orWhereIn(\DB::raw('LOWER(module_key)'), $lowerCandidates);
            })
            ->where('is_installed', true)
            ->where('is_active', true)
            ->exists();
        if ($installed) {
            return 'installed';
        }

        $latest = ModulePackage::query()
            ->where('family', $family)
            ->where(function ($query) use ($keyCandidates, $lowerCandidates): void {
                $query->whereIn('module_key', $keyCandidates)
                    ->orWhereIn(\DB::raw('LOWER(module_key)'), $lowerCandidates);
            })
            ->orderByDesc('updated_at')
            ->first();
        if ($latest instanceof ModulePackage) {
            $path = is_string($latest->artifact_path) ? $latest->artifact_path : '';
            if ($path !== '' && is_file($path)) {
                return 'downloaded';
            }
            $metadata = is_array($latest->metadata) ? $latest->metadata : [];
            $artifactPath = is_string($metadata['artifact_path'] ?? null) ? (string) $metadata['artifact_path'] : '';
            if ($artifactPath !== '' && is_file($artifactPath)) {
                return 'downloaded';
            }
        }

        return 'catalog_synced';
    }

    private function installedVersionForModule(string $family, string $moduleKey): ?string
    {
        $keyCandidates = $this->moduleKeyCandidates($moduleKey);
        if ($keyCandidates === []) {
            return null;
        }
        $lowerCandidates = array_values(array_unique(array_map('mb_strtolower', $keyCandidates)));

        $installation = ModuleInstallation::query()
            ->whereRaw('LOWER(family) = ?', [strtolower($family)])
            ->where(function ($query) use ($keyCandidates, $lowerCandidates): void {
                $query->whereIn('module_key', $keyCandidates)
                    ->orWhereIn(\DB::raw('LOWER(module_key)'), $lowerCandidates);
            })
            ->where('is_installed', true)
            ->orderByDesc('updated_at')
            ->first();

        if (! $installation instanceof ModuleInstallation) {
            return null;
        }

        $version = is_string($installation->installed_version) ? trim($installation->installed_version) : '';

        return $version !== '' ? $version : null;
    }

    private function isNewerVersion(string $candidate, string $current): bool
    {
        $candidateNormalized = $this->normalizeVersion($candidate);
        $currentNormalized = $this->normalizeVersion($current);
        if ($candidateNormalized === '' || $currentNormalized === '') {
            return false;
        }

        return version_compare($candidateNormalized, $currentNormalized, '>');
    }

    /**
     * @param  Collection<int, FlowShopManagementModule>  $modules
     * @return array<int, string>
     */
    private function activePublishedProviderKeysFromModules($modules): array
    {
        $keys = [];
        foreach ($modules as $module) {
            if ((string) $module->family !== 'integration_provider') {
                continue;
            }

            $candidates = [
                $module->slug,
                Str::after((string) $module->slug, 'integration-provider-'),
                Str::after((string) $module->slug, 'integration_provider_'),
                is_string($module->repo_name) ? Str::after((string) $module->repo_name, 'integration-') : '',
            ];

            foreach ($candidates as $candidate) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    $keys[$value] = $value;
                    $keys[str_replace('-', '_', $value)] = str_replace('-', '_', $value);
                    $keys[str_replace('_', '-', $value)] = str_replace('_', '-', $value);
                }
            }
        }

        return array_values($keys);
    }

    /**
     * Server tarafinda aktif+yayinda olan tum integration provider'lari listeler.
     * Kurulu olanlar da ayni listede kalir.
     *
     * @return Collection<int, object>
     */
    private function buildCatalogProviders(): Collection
    {
        if (! Schema::hasTable('shop_management_modules') || ! Schema::hasTable('integration_packages')) {
            return collect();
        }

        $moduleQuery = FlowShopManagementModule::query()
            ->where('family', 'integration_provider')
            ->where('is_active', true)
            ->orderBy('name');
        if (Schema::hasColumn('shop_management_modules', 'is_publishable')) {
            $moduleQuery->where('is_publishable', true);
        }

        $select = ['id', 'name', 'slug', 'repo_name', 'latest_version'];
        if (Schema::hasColumn('shop_management_modules', 'name_i18n')) {
            $select[] = 'name_i18n';
        }

        $modules = $moduleQuery->get($select);
        if ($modules->isEmpty()) {
            return collect();
        }

        $packages = IntegrationPackage::query()
            ->whereIn('provider_key', $this->activePublishedProviderKeysFromModules($modules))
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('provider_key');

        return $modules->map(function (FlowShopManagementModule $module) use ($packages): object {
            $candidates = [
                (string) $module->slug,
                Str::after((string) $module->slug, 'integration-provider-'),
                Str::after((string) $module->slug, 'integration_provider_'),
                is_string($module->repo_name) ? Str::after((string) $module->repo_name, 'integration-') : '',
            ];
            $normalizedCandidates = [];
            foreach ($candidates as $candidate) {
                $value = trim((string) $candidate);
                if ($value === '') {
                    continue;
                }
                $normalizedCandidates[] = $value;
                $normalizedCandidates[] = str_replace('-', '_', $value);
                $normalizedCandidates[] = str_replace('_', '-', $value);
            }

            $package = null;
            foreach (array_values(array_unique($normalizedCandidates)) as $candidateKey) {
                $package = $packages->get($candidateKey)?->first();
                if ($package instanceof IntegrationPackage) {
                    break;
                }
            }

            $providerKey = $package instanceof IntegrationPackage
                ? (string) $package->provider_key
                : (Str::contains((string) $module->slug, '-') ? str_replace('-', '_', Str::after((string) $module->slug, 'integration-provider-')) : (string) $module->slug);

            $metadata = $package instanceof IntegrationPackage && is_array($package->metadata)
                ? $package->metadata
                : [];

            $moduleNameI18n = is_array($module->name_i18n ?? null) ? $module->name_i18n : null;
            $metadata['name'] = is_array($moduleNameI18n) && $moduleNameI18n !== []
                ? $moduleNameI18n
                : ['tr' => (string) $module->name, 'en' => (string) $module->name];
            if (! isset($metadata['publisher']) || ! is_string($metadata['publisher']) || trim($metadata['publisher']) === '') {
                $metadata['publisher'] = 'Bikare';
            }

            $catalogVersion = is_string($module->latest_version) && trim((string) $module->latest_version) !== ''
                ? trim((string) $module->latest_version)
                : ($package instanceof IntegrationPackage
                    ? (string) ($package->package_version ?? '1.0.0')
                    : '1.0.0');

            $installStatus = $this->currentInstallStatusForProvider($providerKey);
            $installedVersion = $installStatus === 'installed' && $package instanceof IntegrationPackage
                ? (is_string($package->package_version) && trim($package->package_version) !== ''
                    ? trim((string) $package->package_version)
                    : null)
                : null;

            $hasUpdate = $installedVersion !== null
                && $this->isNewerVersion($catalogVersion, $installedVersion);
            $releaseNotes = $this->resolveCatalogReleaseNotes($metadata);

            $card = (object) [
                'provider_key' => $providerKey,
                'package_version' => $package instanceof IntegrationPackage
                    ? (string) ($package->package_version ?? $catalogVersion)
                    : $catalogVersion,
                'catalog_version' => $catalogVersion,
                'install_status' => $installStatus,
                'installed_version' => $installedVersion,
                'has_update' => $hasUpdate,
                'release_notes' => $releaseNotes,
                'metadata' => $metadata,
            ];

            return $card;
        })->values();
    }

    private function installPhaseToPercent(string $phase): int
    {
        if (str_ends_with($phase, '_output')) {
            return match ($phase) {
                'migrate_output' => 78,
                'seed_output' => 92,
                'dump_autoload_output' => 66,
                default => 72,
            };
        }

        return match ($phase) {
            'resolve' => 2,
            'download_local' => 10,
            'download_remote' => 5,
            'download' => 22,
            'verify' => 35,
            'install_zip' => 36,
            'zip_open' => 40,
            'zip_extract' => 44,
            'manifest' => 48,
            'backup' => 52,
            'deploy' => 56,
            'dump_autoload' => 62,
            'migrate' => 70,
            'seed' => 88,
            'install_complete' => 94,
            'health_check' => 97,
            default => 50,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 / 1024, 2).' MB';
    }

    private function stringEnv(string $key): string
    {
        $value = env($key);

        return is_string($value) ? trim($value) : '';
    }

    private function intEnv(string $key): int
    {
        $value = env($key);
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function readManifestReleaseNotesFromZipPath(string $zipPath): ?string
    {
        if (! is_file($zipPath)) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_contains($name, '..')) {
                    continue;
                }
                $lower = strtolower($name);
                if ($lower !== 'module.json' && ! str_ends_with($lower, '/module.json')) {
                    continue;
                }
                $raw = $zip->getFromIndex($i);
                if (! is_string($raw) || $raw === '') {
                    return null;
                }
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    return null;
                }

                return $this->resolveLocalizedReleaseNotesFromManifest($decoded);
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function resolveLocalizedReleaseNotesFromManifest(array $manifest): ?string
    {
        $loc = app()->getLocale();
        if (isset($manifest['release_notes']) && is_array($manifest['release_notes'])) {
            /** @var array<string, mixed> $rn */
            $rn = $manifest['release_notes'];
            $text = $rn[$loc] ?? $rn['tr'] ?? $rn['en'] ?? null;

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        }
        if (isset($manifest['release_notes']) && is_string($manifest['release_notes'])) {
            return trim($manifest['release_notes']) !== '' ? trim($manifest['release_notes']) : null;
        }
        $release = $manifest['release'] ?? null;
        if (is_array($release) && isset($release['notes']) && is_array($release['notes'])) {
            /** @var array<string, mixed> $notes */
            $notes = $release['notes'];
            $text = $notes[$loc] ?? $notes['tr'] ?? $notes['en'] ?? null;

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        }

        return null;
    }

    /**
     * @param  array{panel_id:string,customer_id:int,artifact_key:string,version:string}  $payload
     */
    private function createLocalDownloadLink(array $payload, ?string $ip): JsonResponse
    {
        return $this->flowShopManagementServerGateway->createDownloadLink($payload, $ip);
    }
}
