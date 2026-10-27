<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Services;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaClientNotification;
final class ClientNotificationService
{
    public const TYPES = ['manual_intervention', 'login_required', 'display_message'];

    public function pending(SaAgent $agent, string $installationId): array
    {
        return SaClientNotification::query()->where('installation_id', $installationId)
            ->where('agent_id', $agent->id)->whereIn('status', ['pending', 'delivered'])
            ->with(['browserSession', 'account'])
            ->orderBy('id')->limit(50)->get()->map(function (SaClientNotification $row): array {
                if ($row->status === 'pending') { $row->update(['status'=>'delivered','delivered_at'=>now()]); }
                return ['id'=>$row->notification_uuid,'type'=>$row->type,'message'=>$row->message,
                    'browser_id'=>$row->browserSession?->session_uuid,'account_id'=>$row->account?->account_uuid,
                    'account_name'=>$row->account?->getAccountName() ?: 'Account','task_id'=>$row->job_id,
                    'created_at'=>$row->created_at?->toIso8601String()];
            })->all();
    }

    public function acknowledge(SaAgent $agent, string $installationId, string $uuid): bool
    {
        return SaClientNotification::query()->where('notification_uuid', $uuid)
            ->where('installation_id', $installationId)->where('agent_id', $agent->id)
            ->whereIn('status', ['pending','delivered'])->update(['status'=>'acked','acked_at'=>now()]) === 1;
    }

    public function create(array $data, int $actorId): SaClientNotification
    {
        if (! in_array($data['type'] ?? '', self::TYPES, true)) throw new \DomainException('notification_type_not_allowed');
        return SaClientNotification::query()->firstOrCreate([
            'installation_id'=>$data['installation_id'], 'idempotency_key'=>$data['idempotency_key'],
        ], [
            'notification_uuid'=>(string) Str::uuid(), 'agent_id'=>$data['agent_id'],
            'browser_session_id'=>$data['browser_session_id'] ?? null, 'account_id'=>$data['account_id'] ?? null,
            'job_id'=>$data['job_id'] ?? null, 'type'=>$data['type'],
            'message'=>mb_substr(trim((string) $data['message']),0,2000), 'status'=>'pending',
            'created_by_user_id'=>$actorId,
        ]);
    }

    public function requestLogin(SaBrowserSession $session, string $installationId, int $actorId, string $message): SaClientNotification
    {
        if ($session->account_id === null || ! $session->agent()->where('installation_id', $installationId)->exists()) {
            throw new \DomainException('browser_not_eligible');
        }

        return DB::connection($session->getConnectionName())->transaction(function () use ($session, $installationId, $actorId, $message): SaClientNotification {
            $capabilities = is_array($session->capabilities) ? $session->capabilities : [];
            $existingRequest = is_array($capabilities['login_request'] ?? null) ? $capabilities['login_request'] : [];
            $capabilities['login_request'] = [
                'requested_at'=>now()->toIso8601String(),
                'dispatch_was_enabled'=>$session->session_state === 'needs_login'
                    ? (bool) ($existingRequest['dispatch_was_enabled'] ?? false)
                    : (bool) $session->dispatch_enabled,
                'previous_pause_reason'=>$session->session_state === 'needs_login'
                    ? ($existingRequest['previous_pause_reason'] ?? null)
                    : $session->dispatch_pause_reason,
            ];
            $session->update([
                'session_state'=>'needs_login',
                'dispatch_enabled'=>false,
                'dispatch_pause_reason'=>'needs_login',
                'capabilities'=>$capabilities,
            ]);

            return $this->create([
                'installation_id'=>$installationId,
                'agent_id'=>$session->agent_id,
                'browser_session_id'=>$session->id,
                'account_id'=>$session->account_id,
                'job_id'=>null,
                'type'=>'login_required',
                'message'=>$message,
                'idempotency_key'=>'login-required:'.$session->id.':'.Str::uuid(),
            ], $actorId);
        });
    }

    public function confirmLogin(SaAgent $agent, string $installationId, string $browserUuid, string $accountUuid, ?string $checkedAt): array
    {
        $session = SaBrowserSession::query()
            ->where('agent_id', $agent->id)
            ->where('session_uuid', $browserUuid)
            ->whereHas('agent', fn ($query) => $query->where('installation_id', $installationId))
            ->whereHas('sessionAccounts', fn ($query) => $query->where('is_active', true)
                ->whereHas('account', fn ($accountQuery) => $accountQuery->where('account_uuid', $accountUuid)))
            ->first();
        if (! $session instanceof SaBrowserSession) return ['ok'=>false,'error'=>'browser_account_not_found'];

        return DB::connection($session->getConnectionName())->transaction(function () use ($session, $checkedAt): array {
            $session->refresh();
            $caps = is_array($session->capabilities) ? $session->capabilities : [];
            $signal = is_array($caps['session_signal'] ?? null) ? $caps['session_signal'] : [];
            $requestedAt = strtotime((string) data_get($caps, 'login_request.requested_at', ''));
            $storedCheckedAt = strtotime((string) ($signal['checked_at'] ?? ''));
            $clientCheckedAt = strtotime((string) $checkedAt);
            if (($signal['status'] ?? null) !== 'present' || $requestedAt === false || $storedCheckedAt === false
                || $storedCheckedAt <= $requestedAt || ($clientCheckedAt !== false && $storedCheckedAt < $clientCheckedAt)) {
                return ['ok'=>false,'error'=>'fresh_session_signal_required'];
            }

            if ((string) $session->session_state !== 'needs_login') {
                return ['ok'=>true,'session_state'=>(string) $session->session_state,'dispatch_enabled'=>(bool) $session->dispatch_enabled];
            }

            $manualDisabled = (string) $session->dispatch_pause_reason === 'manual_disabled';
            $restoreDispatch = ! $manualDisabled && (bool) data_get($caps, 'login_request.dispatch_was_enabled', false);
            $previousReason = data_get($caps, 'login_request.previous_pause_reason');
            if ($previousReason === 'needs_login' || $previousReason === null) {
                $previousReason = 'login_confirmed_pending_resume';
            }
            $session->update([
                'session_state'=>'candidate',
                'dispatch_enabled'=>$restoreDispatch,
                'dispatch_pause_reason'=>$restoreDispatch ? null : ($manualDisabled ? 'manual_disabled' : $previousReason),
            ]);

            return ['ok'=>true,'session_state'=>'candidate','dispatch_enabled'=>$restoreDispatch];
        });
    }
}
