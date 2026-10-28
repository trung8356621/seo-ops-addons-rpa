<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Registry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\SessionSignalState;
use Omnichannel\Addons\SocialAutomation\Enums\AccountIdentitySource;
use Omnichannel\Addons\SocialAutomation\Enums\SessionReadyStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Sync browser sessions + account metadata from Runner / RPA Client.
 * Ready ≠ verified login. Never stores cookies/secrets.
 */
final class SessionSyncService
{
    public function __construct(
        private readonly OnlineStateCalculator $online = new OnlineStateCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, sessions?: list<array<string, mixed>>, errors?: list<string>}
     */
    public function sync(string $agentUuid, array $payload, ?int $credentialId): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return ['ok' => false, 'errors' => ['agent_not_found']];
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return ['ok' => false, 'errors' => ['credential_mismatch']];
        }

        $items = $payload['sessions'] ?? null;
        if (! is_array($items)) {
            return ['ok' => false, 'errors' => ['sessions_required']];
        }

        foreach (['cookies', 'cookie', 'password', 'passwords', 'token', 'secret'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                return ['ok' => false, 'errors' => ['payload_forbidden_field:'.$forbidden]];
            }
        }
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['cookies', 'cookie', 'password', 'profile_path', 'user_data_dir'] as $forbidden) {
                if (array_key_exists($forbidden, $item)) {
                    return ['ok' => false, 'errors' => ['session_forbidden_field:'.$forbidden]];
                }
            }
        }

        try {
            $out = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agent, $items): array {
                $result = [];
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $result[] = $this->upsertSession($agent, $item);
                }

                return $result;
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['sync_failed']];
        }

        return ['ok' => true, 'sessions' => $out];
    }

    /**
     * Synchronize Browser Profiles from RPA Client machine.
     * Enforces strict secrecy: rejects cookies, passwords, tokens, profile paths.
     * Idempotent by browser_id (session_uuid).
     *
     * @param  list<array<string, mixed>>  $browsers
     * @return array{ok: bool, synced_count?: int, browsers?: list<array<string, mixed>>, errors?: list<string>}
     */
    public function syncClientBrowsers(SaAgent $agent, array $browsers, ?string $installationId = null): array
    {
        // Validate forbidden fields first (recursively or in top-level items)
        $forbiddenKeys = ['cookies', 'cookie', 'password', 'passwords', 'token', 'secret', 'profile_path', 'user_data_dir', 'cookie_value', 'c_user'];
        foreach ($browsers as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ($forbiddenKeys as $forbidden) {
                if (array_key_exists($forbidden, $item)) {
                    return ['ok' => false, 'errors' => ['session_forbidden_field:'.$forbidden]];
                }
                if (is_array($item['session_signal'] ?? null) && array_key_exists($forbidden, $item['session_signal'])) {
                    return ['ok' => false, 'errors' => ['session_forbidden_field:session_signal.'.$forbidden]];
                }
                if (is_array($item['account_binding'] ?? null) && array_key_exists($forbidden, $item['account_binding'])) {
                    return ['ok' => false, 'errors' => ['session_forbidden_field:account_binding.'.$forbidden]];
                }
            }
        }

        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        try {
            $out = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agent, $browsers, $installationId): array {
                $result = [];
                foreach ($browsers as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $browserId = trim((string) ($item['browser_id'] ?? $item['session_uuid'] ?? ''));
                    if ($browserId === '') {
                        continue;
                    }

                    $platform = strtolower(trim((string) ($item['platform'] ?? 'facebook')));
                    $domain = trim((string) ($item['domain'] ?? ($platform !== '' ? $platform.'.com' : 'facebook.com')));

                    $runtime = is_array($item['runtime'] ?? null) ? $item['runtime'] : [];
                    $status = strtolower(trim((string) ($runtime['status'] ?? $item['status'] ?? 'stopped')));
                    if (! in_array($status, ['running', 'stopped', 'error', 'crashed'], true)) {
                        $status = 'stopped';
                    }

                    $session = SaBrowserSession::query()->firstOrNew(['session_uuid' => $browserId]);
                    if ($session->exists && (int) $session->agent_id !== (int) $agent->id) {
                        throw new \DomainException('browser_device_mismatch');
                    }
                    $existingCaps = is_array($session->capabilities) ? $session->capabilities : [];

                    // Account ID is the business identity of a Browser. Name is not accepted as identity.
                    $rawBinding = is_array($item['account_binding'] ?? null) ? $item['account_binding'] : null;
                    $targetAccountId = $rawBinding ? trim((string) ($rawBinding['account_id'] ?? '')) : '';
                    if ($targetAccountId === '') {
                        throw new \DomainException('account_id_required');
                    }

                    $rawSignal = is_array($item['session_signal'] ?? null) ? $item['session_signal'] : [];
                    $incomingSignalStatus = strtolower((string) ($rawSignal['status'] ?? 'unknown'));
                    // New browsers still require a present session signal before first upload.
                    if (! $session->exists && $incomingSignalStatus !== 'present') {
                        continue;
                    }

                    $accountModel = SaAccount::query()
                        ->where('account_uuid', $targetAccountId)
                        ->orWhere('id', $targetAccountId)
                        ->first();

                    if (! $accountModel instanceof SaAccount) {
                        throw new \DomainException('account_not_found:'.$targetAccountId);
                    }

                    // Enforce Installation isolation: Client Key Installation MUST match Account Installation!
                    if ($installationId !== null && $installationId !== '' && $accountModel->installation_id !== null && $accountModel->installation_id !== '') {
                        if ($accountModel->installation_id !== $installationId) {
                            throw new \DomainException('account_installation_mismatch');
                        }
                    }

                    // Enforce platform compatibility
                    if (strtolower((string) $accountModel->platform) !== strtolower($platform)) {
                        throw new \DomainException('account_platform_mismatch');
                    }

                    if ($session->exists && $session->account_id !== null && (int) $session->account_id !== (int) $accountModel->id) {
                        throw new \DomainException('browser_account_binding_immutable');
                    }
                    if (SaBrowserSession::query()->where('agent_id', $agent->id)
                        ->where('account_id', $accountModel->id)->where('session_uuid', '!=', $browserId)->exists()) {
                        throw new \DomainException('account_browser_duplicate_on_device');
                    }

                    $accountDisplayName = (string) ($accountModel->name ?? $accountModel->label ?? $accountModel->username ?? 'Account');
                    $accountBinding = [
                        'account_id' => (string) $accountModel->account_uuid,
                        'account_name' => $accountDisplayName,
                        'platform' => strtolower((string) $accountModel->platform),
                        'bound_at' => $rawBinding['bound_at'] ?? now()->toIso8601String(),
                    ];

                    // E5.3: Execution Confirmation with account switch reset rule
                    $prevAccountId = $existingCaps['account_binding']['account_id'] ?? null;
                    $newAccountId = $accountBinding['account_id'];

                    if ($prevAccountId !== $newAccountId) {
                        // Reset execution confirmation when switching account
                        $executionConfirmation = [
                            'status' => 'not_run',
                            'first_run_at' => null,
                            'last_success_at' => null,
                            'last_run_id' => null,
                            'screenshot_ref' => null,
                        ];
                    } else {
                        $rawConfirmation = is_array($item['execution_confirmation'] ?? null) ? $item['execution_confirmation'] : null;
                        $executionConfirmation = $rawConfirmation ?: ($existingCaps['execution_confirmation'] ?? [
                            'status' => 'not_run',
                            'first_run_at' => null,
                            'last_success_at' => null,
                            'last_run_id' => null,
                            'screenshot_ref' => null,
                        ]);
                    }

                    // Session Signal: two-way boolean, last-writer-wins by checked_at.
                    // Independent of session_state / task verification / notification ACK.
                    $existingSignal = is_array($existingCaps['session_signal'] ?? null) ? $existingCaps['session_signal'] : null;
                    if (array_key_exists('present', $rawSignal) || array_key_exists('status', $rawSignal)) {
                        $incomingSignal = $rawSignal;
                        if (! array_key_exists('method', $incomingSignal) || $incomingSignal['method'] === null || $incomingSignal['method'] === '') {
                            $incomingSignal['method'] = $platform === 'facebook' ? 'facebook_c_user_cookie' : ($existingSignal['method'] ?? null);
                        }
                        $sessionSignal = SessionSignalState::mergeLastWriteWins($existingSignal, $incomingSignal);
                    } else {
                        $sessionSignal = SessionSignalState::normalize($existingSignal);
                    }

                    $mergedCaps = array_merge($existingCaps, [
                        // Display label is Account Name — browser name is not a business field.
                        'name' => $accountDisplayName,
                        'status' => $status,
                        'browser_id' => $browserId,
                        'platform' => $platform,
                        'runtime' => ['status' => $status],
                        'account_binding' => $accountBinding,
                        'session_signal' => $sessionSignal,
                        'execution_confirmation' => $executionConfirmation,
                        'last_reported_at' => now()->toIso8601String(),
                    ]);

                    $readyStatus = SessionReadyStatus::Ready->value;
                    $sessionState = $session->exists && (string) $session->session_state === 'verified'
                        ? 'verified'
                        : ($session->exists && (string) $session->session_state === 'needs_login' ? 'needs_login' : 'candidate');

                    $session->fill([
                        'agent_id' => $agent->id,
                        'account_id' => $accountModel->id,
                        'platform' => $platform,
                        'domain' => $domain,
                        'ready_status' => $readyStatus,
                        'session_state' => $sessionState,
                        'dispatch_enabled' => $session->exists ? (bool) $session->dispatch_enabled : true,
                        'dispatch_pause_reason' => $session->dispatch_pause_reason,
                        'last_confirmed_at' => now(),
                        'capabilities' => $mergedCaps,
                    ])->save();

                    SaSessionAccount::query()
                        ->where('browser_session_id', $session->id)
                        ->update(['is_active' => false]);

                    SaSessionAccount::query()->updateOrCreate(
                        [
                            'browser_session_id' => $session->id,
                            'account_id' => $accountModel->id,
                            'domain' => $domain,
                        ],
                        [
                            'is_active' => true,
                            'bound_at' => now(),
                        ]
                    );

                    $result[] = [
                        'browser_id' => $session->session_uuid,
                        'account_name' => $accountDisplayName,
                        'platform' => $platform,
                        'status' => $status,
                        'runtime' => ['status' => $status],
                        'account_binding' => $accountBinding,
                        'session_signal' => $sessionSignal,
                        'execution_confirmation' => $executionConfirmation,
                        'ready_status' => $readyStatus,
                        'session_state' => $sessionState,
                        'last_reported_at' => $mergedCaps['last_reported_at'],
                    ];
                }

                return $result;
            });
        } catch (\DomainException $de) {
            return ['ok' => false, 'errors' => [$de->getMessage()]];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'errors' => ['sync_failed']];
        }

        return [
            'ok' => true,
            'synced_count' => count($out),
            'browsers' => $out,
            // Authoritative per-browser signal snapshot for SEO-OPS → Client LWW apply.
            'session_signals' => $this->listSignalsForAgent($agent),
        ];
    }

    /**
     * Delete a single browser session for a client.
     */
    public function deleteClientBrowser(SaAgent $agent, string $browserId): bool
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return false;
        }

        return (bool) SaBrowserSession::query()
            ->where('agent_id', $agent->id)
            ->where('session_uuid', $browserId)
            ->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUi(): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return [];
        }

        return SaBrowserSession::query()->with('agent')->orderByDesc('updated_at')->limit(200)->get()
            ->map(function (SaBrowserSession $s): array {
                $capabilities = is_array($s->capabilities) ? $s->capabilities : [];
                $name = (string) ($capabilities['name'] ?? $s->session_uuid);
                $reportedStatus = (string) ($capabilities['status'] ?? ($s->ready_status === 'ready' ? 'running' : 'stopped'));

                $agent = $s->agent;
                $deviceOnline = $agent instanceof SaAgent && $this->online->isOnline($agent->last_heartbeat_at);

                // Section 6: When Device is Offline, SEO-OPS must show Browser Unknown/Stale
                $effectiveStatus = ! $deviceOnline
                    ? 'unknown_stale'
                    : (in_array($reportedStatus, ['running', 'stopped', 'error', 'crashed'], true) ? $reportedStatus : 'stopped');

                return [
                    'session_uuid' => $s->session_uuid,
                    'browser_id' => $s->session_uuid,
                    'name' => $name,
                    'agent_uuid' => $agent?->agent_uuid,
                    'device_name' => $agent?->display_name ?? '—',
                    'device_id' => $agent?->device_id ?? '—',
                    'device_online' => $deviceOnline,
                    'platform' => $s->platform ?? 'facebook',
                    'domain' => $s->domain ?? 'facebook.com',
                    'reported_status' => $reportedStatus,
                    'effective_status' => $effectiveStatus,
                    'status' => $effectiveStatus,
                    'ready_status' => $s->ready_status,
                    'busy' => $s->locked_job_id !== null
                        && ($s->lock_expires_at === null || $s->lock_expires_at->isFuture()),
                    'account_binding' => $capabilities['account_binding'] ?? ['account_id' => null, 'bound_at' => null],
                    'account_name' => $capabilities['account_binding']['account_name'] ?? null,
                    'session_signal' => $capabilities['session_signal'] ?? ['status' => 'unknown', 'checked_at' => null, 'method' => null],
                    'execution_confirmation' => $capabilities['execution_confirmation'] ?? ['status' => 'not_run'],
                    'last_confirmed_at' => $s->last_confirmed_at?->toIso8601String(),
                    'last_seen_at' => $capabilities['last_reported_at'] ?? $s->last_confirmed_at?->toIso8601String(),
                ];
            })->all();
    }

    /** @return array{metrics: array<string,int>, devices:list<array<string,mixed>>} */
    public function clientDashboard(): array
    {
        if (! SocialAutomationSchema::agentsReady()) return ['metrics'=>['users_online'=>0,'machines_online'=>0,'machines_total'=>0,'unlinked_accounts'=>0,'sessions_available'=>0],'devices'=>[]];
        $agents = SaAgent::query()->where('agent_kind', 'client')->with('browserSessions')->orderByDesc('last_heartbeat_at')->get();
        $tz = (string) config('app.timezone', 'UTC');
        $devices = $agents->map(function (SaAgent $agent) use ($tz): array {
            $online = $this->online->isOnline($agent->last_heartbeat_at);
            $browsers = $agent->browserSessions->map(function (SaBrowserSession $session) use ($tz): array {
                $account = $session->account_id ? SaAccount::query()->find($session->account_id) : null;
                $signalCheckedAt = data_get($session->capabilities, 'session_signal.checked_at');
                return ['id'=>(int)$session->id,'browser_id'=>(string)$session->session_uuid,
                    'account_name'=>$account?->getAccountName() ?: 'Chưa liên kết Account','platform'=>(string)($account?->platform ?? $session->platform ?? 'unknown'),
                    'site_url'=>$account?->getSiteUrl(),'session_state'=>(string)($session->session_state ?? 'candidate'),
                    'dispatch_enabled'=>(bool)($session->dispatch_enabled ?? true),'pause_reason'=>$session->dispatch_pause_reason,
                    'dispatch_eligible'=>$session->account_id !== null && in_array((string)($session->session_state ?? 'candidate'),['candidate','verified'],true),
                    'signal_present'=>SessionSignalState::isPresent(is_array($session->capabilities) ? ($session->capabilities['session_signal'] ?? null) : null),
                    'signal_status'=>SessionSignalState::normalize(is_array($session->capabilities) ? ($session->capabilities['session_signal'] ?? null) : null)['status'],
                    'signal_checked_at'=>is_string($signalCheckedAt) && $signalCheckedAt !== ''
                        ? \Illuminate\Support\Carbon::parse($signalCheckedAt)->timezone($tz)->format('d/m/Y H:i')
                        : null,
                    'signal_method'=>data_get($session->capabilities, 'session_signal.method'),
                    'last_task_at'=>$session->last_task_at?->timezone($tz)->format('d/m/Y H:i')];
            })->all();
            $osLabel = trim((string) ($agent->os_label ?? ''));

            return ['id'=>(int)$agent->id,'name'=>$agent->display_name ?: 'Máy Client',
                'client_api_key_id'=>$agent->client_api_key_id,
                'device_id'=>$agent->device_id,
                'os_family'=>$agent->os_family,'os_label'=>$osLabel !== '' ? $osLabel : null,
                'online'=>$online,
                'last_seen'=>$agent->last_heartbeat_at?->timezone($tz)->format('d/m/Y H:i'),
                'browsers'=>$browsers,
                'available_count'=>count(array_filter($browsers, fn($b)=>$b['dispatch_eligible'] && $b['dispatch_enabled']))];
        })->all();
        $sessions = $agents->flatMap(fn(SaAgent $a)=>$a->browserSessions);
        return ['metrics'=>['users_online'=>$agents->filter(fn(SaAgent $a)=>$this->online->isOnline($a->last_heartbeat_at))->pluck('device_id')->unique()->count(),
            'machines_online'=>$agents->filter(fn(SaAgent $a)=>$this->online->isOnline($a->last_heartbeat_at))->count(),'machines_total'=>$agents->count(),
            'unlinked_accounts'=>$sessions->whereNull('account_id')->count(),
            'sessions_available'=>$sessions->filter(fn(SaBrowserSession $s)=>$s->account_id !== null && (bool)($s->dispatch_enabled ?? true) && in_array((string)($s->session_state ?? 'candidate'),['candidate','verified'],true))->count()],
            'devices'=>$devices];
    }

    public function setDispatch(int $sessionId, bool $enabled): bool
    {
        $query = SaBrowserSession::query()->whereKey($sessionId);
        if ($enabled) $query->whereNotNull('account_id')->whereIn('session_state',['candidate','verified']);
        return $query->update(['dispatch_enabled'=>$enabled,
            'dispatch_pause_reason'=>$enabled ? null : 'manual_disabled']) === 1;
    }

    /**
     * Admin/client-facing write of the Session Signal boolean (last writer).
     * Does not change session_state, dispatch, or task verification.
     */
    public function setSessionSignalPresent(int $sessionId, bool $present, ?string $checkedAt = null): bool
    {
        $session = SaBrowserSession::query()->whereKey($sessionId)->first();
        if (! $session instanceof SaBrowserSession) {
            return false;
        }
        $caps = is_array($session->capabilities) ? $session->capabilities : [];
        $caps['session_signal'] = SessionSignalState::normalize([
            'present' => $present,
            'checked_at' => $checkedAt ?: now()->toIso8601String(),
            'method' => data_get($caps, 'session_signal.method'),
        ]);
        $session->capabilities = $caps;
        $session->save();

        return true;
    }

    /**
     * @return list<array{browser_id: string, present: bool, status: string, checked_at: ?string, method: ?string}>
     */
    public function listSignalsForAgent(SaAgent $agent): array
    {
        return SaBrowserSession::query()
            ->where('agent_id', $agent->id)
            ->orderBy('id')
            ->get()
            ->map(function (SaBrowserSession $session): array {
                $signal = SessionSignalState::normalize(
                    is_array($session->capabilities) ? ($session->capabilities['session_signal'] ?? null) : null
                );

                return [
                    'browser_id' => (string) $session->session_uuid,
                    'present' => $signal['present'],
                    'status' => $signal['status'],
                    'checked_at' => $signal['checked_at'],
                    'method' => $signal['method'],
                ];
            })
            ->all();
    }

    public function requireLogin(int $sessionId): bool
    {
        return SaBrowserSession::query()->whereKey($sessionId)->whereNotNull('account_id')->update(['session_state'=>'needs_login',
            'dispatch_enabled'=>false,'dispatch_pause_reason'=>'needs_login']) === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccountsForUi(): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return [];
        }

        return SaAccount::query()->orderByDesc('updated_at')->limit(200)->get()
            ->map(static function (SaAccount $a): array {
                return [
                    'account_uuid' => $a->account_uuid,
                    'platform' => $a->platform,
                    'domain' => $a->domain,
                    'label' => $a->label,
                    'platform_account_id' => $a->platform_account_id,
                    'identity_source' => $a->identity_source,
                    'verified' => (bool) $a->verified,
                    'declared_username' => $a->declared_username,
                    'last_confirmed_at' => $a->last_confirmed_at?->toIso8601String(),
                ];
            })->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function upsertSession(SaAgent $agent, array $item): array
    {
        $sessionUuid = trim((string) ($item['session_uuid'] ?? ''));
        if ($sessionUuid === '') {
            $sessionUuid = (string) Str::uuid();
        }

        $ready = strtolower(trim((string) ($item['ready_status'] ?? SessionReadyStatus::Pending->value)));
        if (! in_array($ready, array_column(SessionReadyStatus::cases(), 'value'), true)) {
            $ready = SessionReadyStatus::Pending->value;
        }

        $session = SaBrowserSession::query()->firstOrNew(['session_uuid' => $sessionUuid]);
        $session->fill([
            'agent_id' => $agent->id,
            'platform' => $item['platform'] ?? $session->platform,
            'domain' => $item['domain'] ?? $session->domain,
            'ready_status' => $ready,
            'last_confirmed_at' => ! empty($item['confirmed']) ? now() : ($session->last_confirmed_at ?? now()),
            'capabilities' => is_array($item['capabilities'] ?? null) ? $item['capabilities'] : ($session->capabilities ?? []),
        ])->save();

        $accounts = is_array($item['accounts'] ?? null) ? $item['accounts'] : [];
        foreach ($accounts as $acc) {
            if (! is_array($acc)) {
                continue;
            }
            $this->bindAccount($session, $acc);
        }

        return [
            'session_uuid' => $session->session_uuid,
            'ready_status' => $session->ready_status,
            'platform' => $session->platform,
            'domain' => $session->domain,
            // Explicit: ready is operator-confirmed readiness, not verified auth.
            'login_verified' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $acc
     */
    private function bindAccount(SaBrowserSession $session, array $acc): void
    {
        $platform = strtolower(trim((string) ($acc['platform'] ?? $session->platform ?? '')));
        $domain = trim((string) ($acc['domain'] ?? $session->domain ?? $platform));
        if ($platform === '' || $domain === '') {
            return;
        }

        $platformAccountId = trim((string) ($acc['platform_account_id'] ?? ''));
        $verified = (bool) ($acc['verified'] ?? false);
        // Declared email/username never force verified=true.
        if ($verified && $platformAccountId === '') {
            $verified = false;
        }

        $source = strtolower(trim((string) ($acc['identity_source'] ?? AccountIdentitySource::Declared->value)));
        if (! in_array($source, array_column(AccountIdentitySource::cases(), 'value'), true)) {
            $source = AccountIdentitySource::Declared->value;
        }

        $query = SaAccount::query()->where('platform', $platform);
        if ($platformAccountId !== '') {
            $query->where('platform_account_id', $platformAccountId);
        } else {
            $query->whereNull('platform_account_id')
                ->where('declared_username', trim((string) ($acc['declared_username'] ?? '')));
        }
        $account = $query->first();
        if (! $account instanceof SaAccount) {
            $account = SaAccount::query()->create([
                'account_uuid' => (string) Str::uuid(),
                'platform' => $platform,
                'domain' => $domain,
                'label' => $acc['label'] ?? null,
                'declared_username' => $acc['declared_username'] ?? null,
                'declared_email' => $acc['declared_email'] ?? null,
                'platform_account_id' => $platformAccountId !== '' ? $platformAccountId : null,
                'identity_source' => $source,
                'verified' => $verified,
                'last_confirmed_at' => now(),
            ]);
        } else {
            $account->fill([
                'label' => $acc['label'] ?? $account->label,
                'declared_username' => $acc['declared_username'] ?? $account->declared_username,
                'declared_email' => $acc['declared_email'] ?? $account->declared_email,
                'identity_source' => $source,
                'verified' => $verified,
                'last_confirmed_at' => now(),
            ])->save();
        }

        $isActive = (bool) ($acc['is_active'] ?? true);
        if ($isActive) {
            SaSessionAccount::query()
                ->where('browser_session_id', $session->id)
                ->where('domain', $domain)
                ->update(['is_active' => false]);
        }

        SaSessionAccount::query()->updateOrCreate(
            [
                'browser_session_id' => $session->id,
                'account_id' => $account->id,
                'domain' => $domain,
            ],
            [
                'is_active' => $isActive,
                'bound_at' => now(),
            ]
        );
    }
}
