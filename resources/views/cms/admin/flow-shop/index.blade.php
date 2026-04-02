@extends('cms.admin.layouts.root')

@section('title', __('admin.marketplace_client.title'))

@section('content')
    @php
        $activePage = (string) ($page ?? 'integrations');
        $showModules = $activePage === 'modules';
        $showIntegrations = $activePage === 'integrations';
    @endphp
    <div class="grid gap-5 lg:gap-7.5">
        <div class="pb-2">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h1 class="font-medium text-lg text-mono">{{ __('admin.marketplace_client.title') }}</h1>
                <div class="flex items-center gap-2 flex-wrap justify-end">
                    <a href="{{ route('cms.admin.flow-shop.published-modules') }}" class="kt-btn kt-btn-sm {{ $showModules ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                        {{ __('admin.marketplace_client.menu.published_modules') }}
                    </a>
                    <a href="{{ route('cms.admin.flow-shop.published-integrations') }}" class="kt-btn kt-btn-sm {{ $showIntegrations ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                        {{ __('admin.marketplace_client.menu.published_integrations') }}
                    </a>
                    @if (($updatesAvailableTotal ?? 0) > 0)
                        <button type="button"
                                class="kt-btn kt-btn-outline kt-btn-sm"
                                id="flowshop-filter-updates-btn"
                                data-active="0"
                                aria-pressed="false"
                                title="{{ __('admin.marketplace_client.actions.updates_filter_only') }}">
                            <i class="ki-filled ki-notification"></i>
                            <span class="flowshop-updates-btn-label">{{ __('admin.marketplace_client.actions.updates_with_count', ['count' => $updatesAvailableTotal]) }}</span>
                        </button>
                    @endif
                    <button type="button" class="kt-btn kt-btn-outline kt-btn-sm" id="marketplace-client-sync-btn">
                        <i class="ki-filled ki-arrows-circle"></i>
                        {{ __('admin.marketplace_client.actions.sync_catalog') }}
                    </button>
                </div>
            </div>
            <p class="text-sm text-secondary-foreground mt-2">
                {{ __('admin.marketplace_client.intro') }}
            </p>
            <p class="text-xs text-muted-foreground mt-1">
                {{ __('admin.marketplace_client.server_label') }}:
                <span class="font-mono">{{ $serverBaseUrl ?? 'N/A' }}</span>
            </p>
            @if (($serverDiagnostics['local_feed_mode'] ?? false))
                <div class="kt-alert kt-alert-primary mt-3">
                    {{ __('admin.marketplace_client.messages.local_feed_mode_enabled') }}
                </div>
            @endif
        </div>

        @if ($showModules)
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">{{ __('admin.marketplace_client.sections.catalog_modules') }}</h3>
                </div>
                <div class="kt-card-content p-5 lg:p-7.5">
                    @if (($catalogModules ?? collect())->isEmpty())
                        <div class="text-sm text-muted-foreground">{{ __('admin.marketplace_client.empty_catalog_modules') }}</div>
                    @else
                        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-5 lg:gap-7.5">
                            @foreach ($catalogModules as $module)
                                @php
                                    $meta = is_array($module->metadata) ? $module->metadata : [];
                                    $name = is_array($meta['name'] ?? null)
                                        ? ($meta['name'][app()->getLocale()] ?? $meta['name']['tr'] ?? $meta['name']['en'] ?? $module->module_key)
                                        : (is_string($meta['name'] ?? null) ? $meta['name'] : $module->module_key);
                                    $publisher = is_string($meta['publisher'] ?? null) && trim($meta['publisher']) !== ''
                                        ? trim($meta['publisher'])
                                        : 'Bikare';
                                    $statusLabel = __('admin.marketplace_client.status.'.$module->install_status);
                                    $hasUpdateCard = $module->install_status === 'installed' && ($module->has_update ?? false);
                                    $moduleReleaseNotes = is_string($module->release_notes ?? null) ? $module->release_notes : null;
                                    $catalogV = $module->package_version ?? '-';
                                    $installedV = $module->installed_version ?? null;
                                    if ($module->install_status === 'installed' && $hasUpdateCard) {
                                        $catalogVersionInfo = __('admin.marketplace_client.catalog_version_info_update', [
                                            'installed' => $installedV ?? '-',
                                            'latest' => $catalogV,
                                        ]);
                                    } elseif ($module->install_status === 'installed') {
                                        $catalogVersionInfo = __('admin.marketplace_client.catalog_version_info_installed', [
                                            'version' => $installedV ?? $catalogV,
                                        ]);
                                    } else {
                                        $catalogVersionInfo = __('admin.marketplace_client.catalog_version_info_catalog', [
                                            'version' => $catalogV,
                                        ]);
                                    }
                                    $pkgVerRaw = (string) ($module->package_version ?? '');
                                    $wizardTargetVersion = $module->install_status === 'installed'
                                        ? (is_string($installedV ?? null) && trim((string) $installedV) !== ''
                                            ? trim((string) $installedV)
                                            : $pkgVerRaw)
                                        : $pkgVerRaw;
                                @endphp
                                <div class="kt-card flowshop-catalog-card"
                                     data-has-update="{{ $hasUpdateCard ? '1' : '0' }}">
                                    <div class="kt-card-content p-5 lg:p-7.5">
                                        <div class="flex items-center justify-between mb-3 lg:mb-5">
                                            <div class="inline-flex size-10 items-center justify-center rounded-full bg-accent/60 border border-border">
                                                <i class="ki-filled ki-cube-3 text-base text-primary"></i>
                                            </div>
                                            <span class="kt-badge kt-badge-outline {{ $module->install_status === 'installed' ? 'kt-badge-success' : ($module->install_status === 'downloaded' ? 'kt-badge-warning' : 'kt-badge-secondary') }}">
                                                {{ $statusLabel !== 'admin.marketplace_client.status.'.$module->install_status ? $statusLabel : $module->install_status }}
                                            </span>
                                        </div>
                                        <div class="flex flex-col gap-1 lg:gap-2.5">
                                            <div class="text-base font-semibold text-mono">{{ $name }}</div>
                                            <span class="text-sm text-secondary-foreground">
                                                {{ __('admin.marketplace_client.catalog_module_meta', ['family' => strtoupper((string) ($module->family ?? '-')), 'publisher' => $publisher, 'version_info' => $catalogVersionInfo]) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="kt-card-footer justify-between items-center py-3.5">
                                        <div class="flex items-center gap-2">
                                            @if ($module->install_status === 'installed' && ($module->has_update ?? false))
                                                <button type="button"
                                                        class="kt-btn kt-btn-outline js-module-download-btn"
                                                        data-action="update"
                                                        data-family="{{ $module->family }}"
                                                        data-module-key="{{ $module->module_key }}"
                                                        data-version="{{ $module->package_version }}"
                                                        data-module-name="{{ $name }}"
                                                        data-installed-version="{{ $module->installed_version ?? '' }}"
                                                        data-release-notes="{{ $moduleReleaseNotes !== null ? e($moduleReleaseNotes) : '' }}">
                                                    <i class="ki-filled ki-arrows-circle"></i>
                                                    {{ __('admin.marketplace_client.actions.update') }}
                                                </button>
                                            @endif
                                            <button type="button"
                                                    class="kt-btn kt-btn-outline js-module-download-btn"
                                                    data-action="{{ $module->install_status === 'installed' ? 'reinstall' : 'install' }}"
                                                    data-family="{{ $module->family }}"
                                                    data-module-key="{{ $module->module_key }}"
                                                    data-version="{{ $wizardTargetVersion }}"
                                                    data-module-name="{{ $name }}"
                                                    data-installed-version="{{ $module->installed_version ?? '' }}"
                                                    data-release-notes="{{ $moduleReleaseNotes !== null ? e($moduleReleaseNotes) : '' }}">
                                                <i class="ki-filled ki-cloud-download"></i>
                                                {{ $module->install_status === 'installed' ? __('admin.marketplace_client.actions.reinstall') : __('admin.marketplace_client.actions.download_module') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($showIntegrations)
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">{{ __('admin.marketplace_client.sections.catalog_providers') }}</h3>
                </div>
                <div class="kt-card-content p-5 lg:p-7.5">
                    @if (($catalogProviders ?? collect())->isEmpty())
                        <div class="text-sm text-muted-foreground">{{ __('admin.marketplace_client.empty_catalog_providers') }}</div>
                    @else
                        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-5 lg:gap-7.5">
                            @foreach ($catalogProviders as $package)
                                @php($meta = is_array($package->metadata) ? $package->metadata : [])
                                @php($name = is_array($meta['name'] ?? null) ? ($meta['name'][app()->getLocale()] ?? $meta['name']['tr'] ?? $meta['name']['en'] ?? $package->provider_key) : (is_string($meta['name'] ?? null) ? $meta['name'] : $package->provider_key))
                                @php($publisher = is_string($meta['publisher'] ?? null) && trim($meta['publisher']) !== '' ? trim($meta['publisher']) : 'Bikare')
                                @php($statusLabel = __('admin.marketplace_client.status.'.$package->install_status))
                                @php($hasUpdateProvider = $package->install_status === 'installed' && ($package->has_update ?? false))
                                <div class="kt-card flowshop-catalog-card"
                                     data-has-update="{{ $hasUpdateProvider ? '1' : '0' }}">
                                    <div class="kt-card-content p-5 lg:p-7.5">
                                        <div class="flex items-center justify-between mb-3 lg:mb-5">
                                            <div class="inline-flex size-10 items-center justify-center rounded-full bg-accent/60 border border-border">
                                                <i class="{{ is_string($meta['icon'] ?? null) && $meta['icon'] !== '' ? $meta['icon'] : 'ki-filled ki-plug' }} text-base text-primary"></i>
                                            </div>
                                            <span class="kt-badge kt-badge-outline {{ $package->install_status === 'installed' ? 'kt-badge-success' : ($package->install_status === 'downloaded' ? 'kt-badge-warning' : 'kt-badge-secondary') }}">
                                                {{ $statusLabel !== 'admin.marketplace_client.status.'.$package->install_status ? $statusLabel : $package->install_status }}
                                            </span>
                                        </div>
                                        <div class="flex flex-col gap-1 lg:gap-2.5">
                                            <div class="text-base font-semibold text-mono">{{ $name }}</div>
                                            <span class="text-sm text-secondary-foreground">
                                                {{ __('admin.marketplace_client.provider_meta', ['publisher' => $publisher, 'version' => $package->catalog_version ?? $package->package_version ?? '-']) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="kt-card-footer justify-between items-center py-3.5">
                                        <button type="button"
                                                class="kt-btn kt-btn-outline js-provider-download-btn"
                                                data-provider-key="{{ $package->provider_key }}"
                                                data-version="{{ $package->catalog_version ?? $package->package_version }}">
                                            <i class="ki-filled ki-cloud-download"></i>
                                            {{ $package->install_status === 'installed' ? __('admin.marketplace_client.actions.reinstall') : __('admin.marketplace_client.actions.download_provider') }}
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="kt-modal kt-modal-center" data-kt-modal="true" id="module-install-wizard-modal" tabindex="-1" aria-hidden="true">
        <div class="kt-modal-content max-w-2xl">
            <div class="kt-modal-header">
                <h2 class="kt-modal-title">{{ __('admin.marketplace_client.wizard.title') }}</h2>
                <button type="button" class="kt-modal-close" aria-label="Close" data-kt-modal-dismiss="#module-install-wizard-modal">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>
            <div class="kt-modal-body">
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title text-sm">{{ __('admin.marketplace_client.wizard.summary') }}</h3>
                    </div>
                    <div class="kt-card-content p-4 text-sm text-secondary-foreground space-y-2">
                        <div><strong>{{ __('admin.marketplace_client.wizard.module') }}:</strong> <span id="wizard-module-name">-</span></div>
                        <div><strong>{{ __('admin.marketplace_client.wizard.family') }}:</strong> <span id="wizard-module-family">-</span></div>
                        <div><strong>{{ __('admin.marketplace_client.wizard.version') }}:</strong> <span id="wizard-module-version">-</span></div>
                        <div><strong>{{ __('admin.marketplace_client.wizard.action') }}:</strong> <span id="wizard-module-action">-</span></div>
                        <div><strong>{{ __('admin.marketplace_client.wizard.target') }}:</strong> <span id="wizard-module-target">-</span></div>
                    </div>
                </div>
                <div id="wizard-release-notes-wrap" class="kt-card mt-4 hidden">
                    <div class="kt-card-header py-3">
                        <h3 class="kt-card-title text-sm">{{ __('admin.marketplace_client.wizard.release_notes_title') }}</h3>
                    </div>
                    <div class="kt-card-content p-4">
                        <p id="wizard-release-notes-body" class="text-sm text-secondary-foreground whitespace-pre-wrap m-0"></p>
                    </div>
                </div>
                <div class="kt-card mt-4">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title text-sm">{{ __('admin.marketplace_client.wizard.steps') }}</h3>
                    </div>
                    <div class="kt-card-content p-4 space-y-3">
                        <label class="flex items-center gap-2">
                            <input class="kt-checkbox" type="checkbox" id="wizard-step-dump" checked>
                            <span class="text-sm">{{ __('admin.marketplace_client.wizard.dump_autoload') }}</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input class="kt-checkbox" type="checkbox" id="wizard-step-migrate" checked>
                            <span class="text-sm">{{ __('admin.marketplace_client.wizard.migrate') }}</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input class="kt-checkbox" type="checkbox" id="wizard-step-seed" checked>
                            <span class="text-sm">{{ __('admin.marketplace_client.wizard.seed') }}</span>
                        </label>
                    </div>
                </div>
                <div id="wizard-install-monitor" class="mt-4 hidden space-y-3">
                    <div class="kt-card">
                        <div class="kt-card-header py-3">
                            <h3 class="kt-card-title text-sm">{{ __('admin.marketplace_client.wizard.progress_title') }}</h3>
                        </div>
                        <div class="kt-card-content p-4 space-y-2">
                            <div class="flex items-center justify-between text-xs text-muted-foreground gap-2">
                                <span id="wizard-progress-label" class="truncate flex-1">—</span>
                                <span id="wizard-progress-percent" class="shrink-0 tabular-nums">0%</span>
                            </div>
                            <div class="h-2 rounded-full bg-muted overflow-hidden">
                                <div id="wizard-progress-bar" class="h-full bg-primary transition-[width] duration-300 ease-out" style="width:0%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="kt-card">
                        <div class="kt-card-header py-3 flex flex-row items-center justify-between gap-2">
                            <h3 class="kt-card-title text-sm">{{ __('admin.marketplace_client.wizard.terminal_title') }}</h3>
                            <button type="button"
                                    class="kt-btn kt-btn-outline kt-btn-xs shrink-0"
                                    id="wizard-terminal-toggle-btn"
                                    aria-expanded="false"
                                    data-label-show="{{ e(__('admin.marketplace_client.wizard.show_terminal')) }}"
                                    data-label-hide="{{ e(__('admin.marketplace_client.wizard.hide_terminal')) }}">
                                {{ __('admin.marketplace_client.wizard.show_terminal') }}
                            </button>
                        </div>
                        <div id="wizard-terminal-body" class="kt-card-content p-4 hidden">
                            <p class="text-xs text-muted-foreground mb-2">{{ __('admin.marketplace_client.wizard.terminal_hint') }}</p>
                            <pre id="wizard-install-terminal" class="font-mono text-xs leading-relaxed p-3 rounded-lg bg-zinc-950 text-emerald-200 border border-border max-h-72 overflow-y-auto whitespace-pre-wrap break-words m-0"></pre>
                        </div>
                    </div>
                </div>
            </div>
            <div class="kt-modal-footer">
                <button type="button" class="kt-btn kt-btn-light" data-kt-modal-dismiss="#module-install-wizard-modal">{{ __('admin.overall.cancel') }}</button>
                <button type="button" class="kt-btn kt-btn-primary" id="wizard-run-btn">{{ __('admin.marketplace_client.actions.continue_install') }}</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function notify(message, variant = 'success') {
        if (window.KTToast && typeof window.KTToast.show === 'function') {
            const normalizedVariant = ['warning', 'destructive', 'success', 'primary', 'secondary', 'info', 'mono']
                .includes(variant)
                ? variant
                : 'primary';
            const appearance = (normalizedVariant === 'warning' || normalizedVariant === 'destructive') ? 'light' : 'solid';
            window.KTToast.show({
                message: message || 'İşlem tamamlandı',
                variant: normalizedVariant,
                appearance,
                duration: 3200,
            });
            return;
        }
        if (window.toastr) {
            if (variant === 'destructive' || variant === 'warning') {
                window.toastr.error(message || 'İşlem başarısız');
                return;
            }
            window.toastr.success(message || 'İşlem başarılı');
            return;
        }
        window.alert(message || 'İşlem tamamlandı');
    }

    (function initFlowShopUpdatesFilter() {
        const btn = document.getElementById('flowshop-filter-updates-btn');
        if (!btn) {
            return;
        }
        const showAll = @json(__('admin.marketplace_client.actions.updates_filter_all'));
        const filterOnly = @json(__('admin.marketplace_client.actions.updates_filter_only'));

        function cardSelector() {
            return document.querySelectorAll('.flowshop-catalog-card');
        }

        function setActive(active) {
            btn.dataset.active = active ? '1' : '0';
            btn.classList.toggle('kt-btn-primary', active);
            btn.classList.toggle('kt-btn-outline', !active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            btn.title = active ? showAll : filterOnly;
            cardSelector().forEach((card) => {
                const needs = card.getAttribute('data-has-update') === '1';
                const show = !active || needs;
                card.classList.toggle('hidden', !show);
            });
        }

        btn.addEventListener('click', function () {
            setActive(btn.dataset.active !== '1');
        });
    })();

    (function initWizardTerminalToggle() {
        const toggleBtn = document.getElementById('wizard-terminal-toggle-btn');
        const body = document.getElementById('wizard-terminal-body');
        if (!toggleBtn || !body) {
            return;
        }
        const labelShow = toggleBtn.dataset.labelShow || '';
        const labelHide = toggleBtn.dataset.labelHide || '';

        function collapseTerminal() {
            body.classList.add('hidden');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.textContent = labelShow;
        }

        function expandTerminal() {
            body.classList.remove('hidden');
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.textContent = labelHide;
        }

        toggleBtn.addEventListener('click', function () {
            if (toggleBtn.getAttribute('aria-expanded') === 'true') {
                collapseTerminal();
            } else {
                expandTerminal();
            }
        });

        window.flowShopWizardResetTerminal = collapseTerminal;
    })();

    document.getElementById('marketplace-client-sync-btn')?.addEventListener('click', function () {
        fetch(@json(route('cms.admin.flow-shop.catalog-sync')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
                'Accept': 'application/json',
            },
            body: JSON.stringify({}),
        }).then(async (response) => {
            const payload = await response.json();
            notify(payload.message || (payload.success ? 'Catalog synced' : 'Sync failed'), payload.success ? 'success' : 'destructive');
        }).catch(() => {
            notify('Marketplace catalog sync failed', 'destructive');
        });
    });

    document.querySelectorAll('.js-provider-download-btn').forEach((button) => {
        button.addEventListener('click', function () {
            const providerKey = this.getAttribute('data-provider-key');
            const version = this.getAttribute('data-version');
            if (!providerKey || !version) {
                return;
            }

            this.disabled = true;
            fetch(@json(route('cms.admin.flow-shop.providers.download')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    provider_key: providerKey,
                    version: version,
                }),
            }).then(async (response) => {
                const payload = await response.json();
                if (payload.success) {
                    notify(payload.message || 'Provider downloaded', 'success');
                    window.setTimeout(() => window.location.reload(), 600);
                } else {
                    notify(payload.message || 'Provider download failed', 'destructive');
                }
            }).catch(() => {
                notify('Provider download failed', 'destructive');
            }).finally(() => {
                this.disabled = false;
            });
        });
    });

    document.querySelectorAll('.js-module-download-btn').forEach((button) => {
        button.addEventListener('click', function () {
            const wizardModalElement = document.getElementById('module-install-wizard-modal');
            if (!wizardModalElement || typeof window.KTModal === 'undefined') {
                notify('Wizard modal bulunamadi', 'destructive');
                return;
            }

            const family = this.getAttribute('data-family') || '';
            const moduleKey = this.getAttribute('data-module-key') || '';
            const version = this.getAttribute('data-version') || '';
            const action = this.getAttribute('data-action') || 'install';
            const moduleName = this.getAttribute('data-module-name') || moduleKey;
            const installedVersion = this.getAttribute('data-installed-version') || '';
            if (!family || !moduleKey || !version) return;

            const actionTextMap = {
                install: @json(__('admin.marketplace_client.actions.download_module')),
                reinstall: @json(__('admin.marketplace_client.actions.reinstall')),
                update: @json(__('admin.marketplace_client.actions.update')),
            };
            document.getElementById('wizard-module-name').textContent = moduleName;
            document.getElementById('wizard-module-family').textContent = String(family).toUpperCase();
            document.getElementById('wizard-module-version').textContent = installedVersion && action === 'update'
                ? `${installedVersion} -> ${version}`
                : (action === 'reinstall' && installedVersion ? installedVersion : version);
            document.getElementById('wizard-module-action').textContent = actionTextMap[action] || action;
            document.getElementById('wizard-module-target').textContent = String(family).toLowerCase() === 'core'
                ? `app/Core/${moduleKey}`
                : `Modules/${String(family).toUpperCase()}/${moduleKey}`;
            const notesRaw = this.getAttribute('data-release-notes') || '';
            const notesWrap = document.getElementById('wizard-release-notes-wrap');
            const notesBody = document.getElementById('wizard-release-notes-body');
            if (notesWrap && notesBody) {
                if (notesRaw.trim() !== '') {
                    notesBody.textContent = notesRaw;
                    notesWrap.classList.remove('hidden');
                } else {
                    notesBody.textContent = '';
                    notesWrap.classList.add('hidden');
                }
            }
            const wizardStepDump = document.getElementById('wizard-step-dump');
            const wizardStepMigrate = document.getElementById('wizard-step-migrate');
            const wizardStepSeed = document.getElementById('wizard-step-seed');
            if (wizardStepDump) wizardStepDump.checked = true;
            if (wizardStepMigrate) wizardStepMigrate.checked = true;
            if (wizardStepSeed) wizardStepSeed.checked = true;

            document.getElementById('wizard-install-monitor')?.classList.add('hidden');
            const pb = document.getElementById('wizard-progress-bar');
            if (pb) pb.style.width = '0%';
            document.getElementById('wizard-progress-percent') && (document.getElementById('wizard-progress-percent').textContent = '0%');
            document.getElementById('wizard-progress-label') && (document.getElementById('wizard-progress-label').textContent = '—');
            const termReset = document.getElementById('wizard-install-terminal');
            if (termReset) termReset.textContent = '';
            if (typeof window.flowShopWizardResetTerminal === 'function') {
                window.flowShopWizardResetTerminal();
            }

            const runBtn = document.getElementById('wizard-run-btn');
            const installMonitor = document.getElementById('wizard-install-monitor');
            const progressBar = document.getElementById('wizard-progress-bar');
            const progressLabel = document.getElementById('wizard-progress-label');
            const progressPercent = document.getElementById('wizard-progress-percent');
            const installTerminal = document.getElementById('wizard-install-terminal');

            function setWizardProgress(percent, label) {
                const p = Math.max(0, Math.min(100, Number(percent) || 0));
                if (progressBar) progressBar.style.width = p + '%';
                if (progressPercent) progressPercent.textContent = p + '%';
                if (progressLabel && label) progressLabel.textContent = label;
            }

            function appendInstallTerminal(chunk) {
                if (!installTerminal || !chunk) return;
                installTerminal.textContent += (installTerminal.textContent ? '\n' : '') + chunk;
                installTerminal.scrollTop = installTerminal.scrollHeight;
            }

            runBtn.onclick = async function () {
                runBtn.disabled = true;
                runBtn.setAttribute('data-kt-indicator', 'on');
                if (typeof window.flowShopWizardResetTerminal === 'function') {
                    window.flowShopWizardResetTerminal();
                }
                if (installMonitor) {
                    installMonitor.classList.remove('hidden');
                }
                if (installTerminal) installTerminal.textContent = '';
                setWizardProgress(0, @json(__('admin.marketplace_client.stream.install_started')));

                const payloadBody = {
                    family: family,
                    module_key: moduleKey,
                    version: version,
                    action: action,
                    steps: {
                        dump_autoload: !!document.getElementById('wizard-step-dump')?.checked,
                        migrate: !!document.getElementById('wizard-step-migrate')?.checked,
                        seed: !!document.getElementById('wizard-step-seed')?.checked,
                    },
                };

                try {
                    const response = await fetch(@json(route('cms.admin.flow-shop.modules.download-stream')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                            'Accept': 'application/x-ndjson, application/json',
                        },
                        body: JSON.stringify(payloadBody),
                    });

                    if (!response.ok) {
                        const errPayload = await response.json().catch(() => ({}));
                        notify(errPayload.message || ('HTTP ' + response.status), 'destructive');
                        return;
                    }

                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let finishedOk = false;

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        buffer += decoder.decode(value, { stream: true });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';
                        for (const line of lines) {
                            if (!line.trim()) continue;
                            let evt;
                            try {
                                evt = JSON.parse(line);
                            } catch (e) {
                                continue;
                            }
                            if (evt.type === 'start' && evt.message) {
                                setWizardProgress(0, evt.message);
                            }
                            if (evt.type === 'log') {
                                setWizardProgress(evt.percent ?? 0, evt.message || '');
                            }
                            if (evt.type === 'terminal' && evt.text) {
                                const prefix = evt.argv && evt.argv.length
                                    ? '$ php artisan ' + evt.argv.join(' ') + '\n'
                                    : '';
                                appendInstallTerminal(prefix + evt.text);
                            }
                            if (evt.type === 'done' && evt.success) {
                                finishedOk = true;
                                setWizardProgress(100, evt.message || '');
                                notify(evt.message || 'OK', 'success');
                            }
                            if (evt.type === 'error') {
                                notify(evt.message || 'Error', 'destructive');
                            }
                        }
                    }
                    if (buffer.trim()) {
                        try {
                            const evt = JSON.parse(buffer);
                            if (evt.type === 'done' && evt.success) {
                                finishedOk = true;
                                setWizardProgress(100, evt.message || '');
                                notify(evt.message || 'OK', 'success');
                            }
                        } catch (e) { /* ignore */ }
                    }

                    if (finishedOk) {
                        const wizardModal = window.KTModal.getInstance(wizardModalElement) || new window.KTModal(wizardModalElement);
                        wizardModal.hide();
                        window.setTimeout(() => window.location.reload(), 800);
                    }
                } catch (e) {
                    notify('Module install stream failed', 'destructive');
                } finally {
                    runBtn.removeAttribute('data-kt-indicator');
                    runBtn.disabled = false;
                }
            };

            const wizardModal = window.KTModal.getInstance(wizardModalElement) || new window.KTModal(wizardModalElement);
            wizardModal.show();
        });
    });
</script>
@endpush
