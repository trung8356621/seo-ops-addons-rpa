<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Workspace\WorkspaceDestination;
use App\Core\Workspace\WorkspaceDestinationRegistry;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Omnichannel\Addons\SocialAutomation\Console\SocialAutomationScheduleTickCommand;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobStateMachine;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminRoleGate;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminAuthController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminScriptSyncController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiKeyManagementController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptReleaseMediaController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationBootstrapController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationHealthController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaAdminUserApi;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\RenderRpaAdminRateLimitJson;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptCatalogService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseMediaService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRemoveService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptTombstoneService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetInvalidationService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetWorkflowComposerService;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleDispatcher;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Services\SocialAutomationDatabaseConnectionService;
use Omnichannel\Addons\SocialAutomation\Support\DashboardMetrics;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationDatabaseHealth;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use Throwable;

final class SocialAutomationServiceProvider extends ServiceProvider
{
    public const SLUG = 'social-automation';

    public function register(): void
    {
        $this->app->singleton(SocialAutomationServiceResolver::class);
        $this->app->singleton(SocialAutomationAccess::class);
        $this->app->singleton(SocialAutomationInstallationResolver::class);
        $this->app->singleton(SocialAutomationDatabaseConnectionService::class);
        $this->app->singleton(SocialAutomationDatabaseHealth::class);
        $this->app->singleton(SocialAutomationServiceHealth::class);
        $this->app->singleton(PackagePublishValidator::class);
        $this->app->singleton(WidgetInvalidationService::class);
        $this->app->singleton(ScriptCatalogService::class);
        $this->app->singleton(ScriptTombstoneService::class);
        $this->app->singleton(ScriptReleaseMediaService::class);
        $this->app->singleton(ScriptRemoveService::class);
        $this->app->singleton(ScriptRegistryUiService::class);
        $this->app->singleton(ScriptSyncPlanner::class);
        $this->app->singleton(WidgetWorkflowComposerService::class);
        $this->app->singleton(PackageRegistryService::class);
        $this->app->singleton(RpaAdminRoleGate::class);
        $this->app->singleton(RpaAdminSessionService::class);
        $this->app->singleton(OnlineStateCalculator::class);
        $this->app->singleton(EligibilitySelector::class);
        $this->app->singleton(JobStateMachine::class);
        $this->app->singleton(AgentRegistryService::class);
        $this->app->singleton(SessionSyncService::class);
        $this->app->singleton(\Omnichannel\Addons\SocialAutomation\Services\ClientNotificationService::class);
        $this->app->singleton(JobOrchestrator::class);
        $this->app->singleton(ScheduleDispatcher::class);
        $this->app->singleton(DashboardMetrics::class);
        $this->app->singleton(RpaClientApiKeyGenerator::class);
        $this->app->singleton(RpaClientApiKeyService::class);
        $this->app->singleton(EnsureRpaClientApiKey::class);
        $this->app->singleton(
            \Omnichannel\Addons\SocialAutomation\Contracts\SaAccountRepositoryContract::class,
            \Omnichannel\Addons\SocialAutomation\Repositories\EloquentSaAccountRepository::class
        );
        $this->app->singleton(\Omnichannel\Addons\SocialAutomation\Services\SaAccountService::class);

        try {
            $this->registerCapabilities();
        } catch (Throwable) {
            // Capability registration failure must stay isolated to this addon.
        }
    }

    public function boot(): void
    {
        $root = dirname(__DIR__);
        $this->loadViewsFrom($root.'/resources/views', 'social-automation');
        $this->loadTranslationsFrom($root.'/resources/lang', 'social-automation');

        try {
            app(SocialAutomationServiceResolver::class)->ensureCatalogRow();
        } catch (Throwable) {
            // Keep other addons bootable; the Social Automation health UI reports the failure.
        }

        try {
            app(SocialAutomationDatabaseConnectionService::class)->bootstrap();
        } catch (Throwable) {
            // health endpoints report status
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                SocialAutomationScheduleTickCommand::class,
                \Omnichannel\Addons\SocialAutomation\Console\BackfillScriptRequestsCommand::class,
            ]);
        }

        try {
            $this->registerWorkspaceDestination();
        } catch (Throwable) {
            // Workspace registration is optional and must not break unrelated panels.
        }
        $this->registerRoutes();
        $this->registerSeoPanelTopLevelNav();
    }

    private function registerWorkspaceDestination(): void
    {
        if (! $this->app->bound(WorkspaceDestinationRegistry::class)) {
            return;
        }

        /** @var WorkspaceDestinationRegistry $registry */
        $registry = $this->app->make(WorkspaceDestinationRegistry::class);
        if ($registry->has('social-automation')) {
            return;
        }

        $registry->register(new WorkspaceDestination(
            key: 'social-automation',
            label: 'Social Automation',
            url: url('/social-automation'),
            sort: 25,
            description: 'RPA Social Automation control plane',
            icon: 'heroicon-o-rectangle-group',
            panelId: 'social-automation',
            canAccess: static function (\App\Models\User $user): bool {
                if (! $user->canAccessPanel(\Filament\Facades\Filament::getPanel('social-automation'))) {
                    return false;
                }

                return app(SocialAutomationAccess::class)->canAccess($user);
            },
        ));
    }

    private function registerSeoPanelTopLevelNav(): void
    {
        Filament::serving(function (): void {
            $panelId = Filament::getCurrentPanel()?->getId();
            if (! in_array($panelId, ['seo', 'seo-main'], true)) {
                return;
            }

            try {
                if (! app(SocialAutomationAccess::class)->canAccess()) {
                    return;
                }
            } catch (Throwable) {
                return;
            }

            Filament::registerNavigationItems([
                NavigationItem::make('Social Automation')
                    ->icon('heroicon-o-rectangle-group')
                    ->url(url('/social-automation'))
                    ->sort(56)
                    ->isActiveWhen(static fn (): bool => request()->is('social-automation') || request()->is('social-automation/*')),
            ]);
        });
    }

    private function registerRoutes(): void
    {
        $middleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            SubstituteBindings::class,
        ];

        Route::middleware($middleware)
            ->prefix('api/social-automation')
            ->group(function (): void {
                Route::get('/bootstrap', SocialAutomationBootstrapController::class)
                    ->name('social-automation.bootstrap');
                Route::get('/health', SocialAutomationHealthController::class)
                    ->name('social-automation.health');
            });

        Route::middleware($middleware)
            ->prefix('social-automation')
            ->group(function (): void {
                Route::get('/script-media/{mediaId}', [ScriptReleaseMediaController::class, 'show'])
                    ->whereNumber('mediaId')
                    ->name('social-automation.script-media.show');

                Route::prefix('api/action-registry')->group(function (): void {
                    Route::get('/data', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'data'])
                        ->name('social-automation.action-registry.data');
                    Route::post('/sites', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'storeSite'])
                        ->name('social-automation.action-registry.sites.store');
                    Route::put('/sites/{id}', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'updateSite'])
                        ->whereNumber('id')
                        ->name('social-automation.action-registry.sites.update');
                    Route::post('/paths', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'storePath'])
                        ->name('social-automation.action-registry.paths.store');
                    Route::put('/paths/{id}', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'updatePath'])
                        ->whereNumber('id')
                        ->name('social-automation.action-registry.paths.update');
                    Route::post('/tags', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'storeTag'])
                        ->name('social-automation.action-registry.tags.store');
                    Route::post('/tags/{id}/requests', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'createRequest'])
                        ->whereNumber('id')
                        ->name('social-automation.action-registry.tags.requests.create');
                    Route::delete('/tags/{id}', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController::class, 'destroyTag'])
                        ->whereNumber('id')
                        ->name('social-automation.action-registry.tags.destroy');
                });

                // Client API Key Management for SEO-OPS UI / Staff session
                Route::prefix('api/client-keys')->group(function (): void {
                    Route::get('/', [RpaClientApiKeyManagementController::class, 'index'])
                        ->name('social-automation.client-keys.index');
                    Route::get('/active-or-candidate', [RpaClientApiKeyManagementController::class, 'activeOrCandidate'])
                        ->name('social-automation.client-keys.active-or-candidate');
                    Route::post('/activate-candidate', [RpaClientApiKeyManagementController::class, 'activateCandidate'])
                        ->name('social-automation.client-keys.activate-candidate');
                    Route::post('/', [RpaClientApiKeyManagementController::class, 'store'])
                        ->name('social-automation.client-keys.store');
                    Route::post('/{id}/revoke', [RpaClientApiKeyManagementController::class, 'revoke'])
                        ->whereNumber('id')
                        ->name('social-automation.client-keys.revoke');
                });

                // Account Inventory Management for SEO-OPS UI / API
                Route::prefix('api/accounts')->group(function (): void {
                    Route::get('/', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationAccountManagementController::class, 'index'])
                        ->name('social-automation.accounts.index');
                    Route::post('/', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationAccountManagementController::class, 'store'])
                        ->name('social-automation.accounts.store');
                    Route::put('/{id}', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationAccountManagementController::class, 'update'])
                        ->whereNumber('id')
                        ->name('social-automation.accounts.update');
                    Route::delete('/{id}', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationAccountManagementController::class, 'destroy'])
                        ->whereNumber('id')
                        ->name('social-automation.accounts.destroy');
                });
                Route::post('/api/clients/sessions/{sessionId}/notifications', [\Omnichannel\Addons\SocialAutomation\Http\Controllers\ClientManagementController::class, 'notify'])
                    ->whereNumber('sessionId')->name('social-automation.clients.notifications.store');
            });

        // RPA Client Key Endpoints (Bearer <client_api_key>)
        Route::prefix('api/v1/social-automation/client')
            ->middleware([EnsureRpaClientApiKey::class])
            ->group(function (): void {
                Route::get('/validate', [RpaClientApiController::class, 'validateKey'])
                    ->name('social-automation.client.validate');
                Route::post('/register', [RpaClientApiController::class, 'registerDevice'])
                    ->name('social-automation.client.register');
                Route::post('/disconnect', [RpaClientApiController::class, 'disconnect'])
                    ->name('social-automation.client.disconnect');
                Route::post('/heartbeat', [RpaClientApiController::class, 'heartbeat'])
                    ->name('social-automation.client.heartbeat');
                Route::post('/agents/{agentUuid}/heartbeat', [RpaClientApiController::class, 'heartbeatWithParam'])
                    ->where('agentUuid', '[A-Za-z0-9-]+')
                    ->name('social-automation.client.agent-heartbeat');

                // E4: Browser Profile Sync & Management
                Route::post('/browser-sync', [RpaClientApiController::class, 'syncBrowserSessions'])
                    ->name('social-automation.client.browser-sync');
                Route::post('/browser-delete', [RpaClientApiController::class, 'deleteBrowserSession'])
                    ->name('social-automation.client.browser-delete');
                Route::delete('/browsers/{browserId}', [RpaClientApiController::class, 'deleteBrowserSession'])
                    ->name('social-automation.client.browser-delete-param');
                Route::get('/browser-sessions', [RpaClientApiController::class, 'listBrowserSessions'])
                    ->name('social-automation.client.browser-sessions');
                // E5: List bindable accounts for client
                Route::get('/accounts', [RpaClientApiController::class, 'listBindableAccounts'])
                    ->name('social-automation.client.accounts');
                Route::get('/accounts/{accountId}', [RpaClientApiController::class, 'getAccount'])
                    ->name('social-automation.client.account');
                Route::get('/accounts/{accountId}/password', [RpaClientApiController::class, 'getAccountPassword'])
                    ->name('social-automation.client.account-password');
                Route::get('/notifications', [RpaClientApiController::class, 'notifications']);
                Route::post('/notifications/{notificationId}/ack', [RpaClientApiController::class, 'acknowledgeNotification'])
                    ->whereUuid('notificationId');
                Route::post('/session-confirm-login', [RpaClientApiController::class, 'confirmLogin'])
                    ->name('social-automation.client.session-confirm-login');
            });

        // RPA Admin user auth + Script sync (not Runner service_api_credentials).
        Route::prefix('api/v1/social-automation/rpa-admin')
            ->middleware([RenderRpaAdminRateLimitJson::class, 'throttle:60,1'])
            ->group(function (): void {
                Route::post('/login', [RpaAdminAuthController::class, 'login'])
                    ->name('social-automation.rpa-admin.login');

                Route::middleware([EnsureRpaAdminUserApi::class])->group(function (): void {
                    Route::get('/session', [RpaAdminAuthController::class, 'session'])
                        ->name('social-automation.rpa-admin.session');
                    Route::post('/logout', [RpaAdminAuthController::class, 'logout'])
                        ->name('social-automation.rpa-admin.logout');
                    Route::get('/scripts', [RpaAdminScriptSyncController::class, 'catalog'])
                        ->name('social-automation.rpa-admin.scripts.catalog');
                    Route::post('/scripts/sync/plan', [RpaAdminScriptSyncController::class, 'plan'])
                        ->name('social-automation.rpa-admin.scripts.sync.plan');
                    Route::post('/scripts/publish', [RpaAdminScriptSyncController::class, 'publish'])
                        ->name('social-automation.rpa-admin.scripts.publish');
                    Route::post('/scripts/pull', [RpaAdminScriptSyncController::class, 'pull'])
                        ->name('social-automation.rpa-admin.scripts.pull');
                    Route::post('/scripts/video-demo', [RpaAdminScriptSyncController::class, 'attachVideoDemo'])
                        ->name('social-automation.rpa-admin.scripts.video-demo');
                    Route::get('/scripts/video-demo', [RpaAdminScriptSyncController::class, 'getVideoDemo'])
                        ->name('social-automation.rpa-admin.scripts.video-demo.get');
                    Route::post('/scripts/asset', [RpaAdminScriptSyncController::class, 'attachAsset']);
                    Route::get('/scripts/asset', [RpaAdminScriptSyncController::class, 'getAsset'])
                        ->name('social-automation.rpa-admin.scripts.asset');
                    Route::get('/active-scripts', [RpaAdminScriptSyncController::class, 'activeScripts'])
                        ->name('social-automation.rpa-admin.active-scripts');
                    Route::post('/backup', [RpaAdminScriptSyncController::class, 'backup']);
                    Route::get('/backup', [RpaAdminScriptSyncController::class, 'backupInfo']);
                    Route::post('/backup/download', [RpaAdminScriptSyncController::class, 'backupDownload']);
                    Route::get('/execution-reports', [RpaAdminScriptSyncController::class, 'reports']);
                    Route::post('/execution-reports', [RpaAdminScriptSyncController::class, 'storeReport']);
                    Route::get('/action-tags', [RpaAdminScriptSyncController::class, 'actionTags'])
                        ->name('social-automation.rpa-admin.action-tags');
                    Route::get('/script-requests', [RpaAdminScriptSyncController::class, 'scriptRequests'])
                        ->name('social-automation.rpa-admin.script-requests');
                    Route::post('/script-requests/{requestId}/claim', [RpaAdminScriptSyncController::class, 'claimScriptRequest'])
                        ->where('requestId', '[A-Za-z0-9._-]+')
                        ->name('social-automation.rpa-admin.script-requests.claim');
                });
            });
    }

    private function registerCapabilities(): void
    {
        if (! $this->app->bound(CapabilityRegistry::class)) {
            return;
        }

        /** @var CapabilityRegistry $caps */
        $caps = $this->app->make(CapabilityRegistry::class);
        foreach ($this->providedCapabilityIds() as $id) {
            if ($caps->has($id)) {
                continue;
            }
            $caps->register($id, new SocialAutomationCapabilityMarker($id, self::SLUG), self::SLUG);
        }
    }

    /** @return list<string> */
    private function providedCapabilityIds(): array
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'addon.json';
        if (! is_file($path)) {
            return [];
        }

        $meta = json_decode((string) file_get_contents($path), true);
        if (! is_array($meta) || ! is_array($meta['provides'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $meta['provides'])));
    }
}

final class SocialAutomationCapabilityMarker
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerSlug,
    ) {}
}
