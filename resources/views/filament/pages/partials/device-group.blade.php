@php
    $sessionStateLabel = static function (string $state): string {
        return match ($state) {
            'verified' => 'Đã xác minh',
            'needs_login' => 'Cần đăng nhập',
            'candidate' => 'Chưa xác minh',
            default => str_replace('_', ' ', ucfirst($state)),
        };
    };
    $sessionStateBadge = static function (string $state): string {
        return match ($state) {
            'verified' => 'sa-badge sa-badge-verified',
            'needs_login' => 'sa-badge sa-badge-needs-login',
            default => 'sa-badge sa-badge-candidate',
        };
    };
@endphp
<article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-800">
        <div class="min-w-0 space-y-1.5">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="break-words text-sm font-semibold text-gray-950 dark:text-white">{{ $device['name'] }}</h3>
                @if($device['online'])
                    <span class="sa-badge sa-badge-online"><span class="sa-status-dot bg-emerald-500"></span>Online</span>
                @else
                    <span class="sa-badge sa-badge-offline"><span class="sa-status-dot bg-gray-400"></span>Offline</span>
                @endif
            </div>
            <div class="sa-device-meta">
                <span>{{ $device['os_label'] ?? 'Chưa có thông tin hệ điều hành' }}</span>
                <span aria-hidden="true">·</span>
                <span>Hoạt động gần nhất {{ $device['last_seen'] ?? '—' }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-right text-xs text-gray-500">
            <button type="button" class="sa-action-primary" wire:click="openNotificationModal({{ $device['id'] }})">Gửi thông báo</button>
            <div>
                <strong class="block text-base tabular-nums text-gray-900 dark:text-white">{{ count($device['browsers']) }}</strong>
                Browser
            </div>
            <div>
                <strong class="block text-base tabular-nums text-emerald-600">{{ $device['available_count'] }}</strong>
                Khả dụng
            </div>
        </div>
    </header>
    @if($device['browsers'] === [])
        <div class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Máy chưa có Browser Session. Trạng thái này độc lập với kết nối thiết bị.</div>
    @else
        <div class="sa-browser-grid mt-3">
            @foreach($device['browsers'] as $browser)
                <section class="flex min-w-0 flex-col rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h4 class="break-words text-sm font-semibold leading-5 text-gray-950 dark:text-white" title="{{ $browser['account_name'] }}">{{ $browser['account_name'] }}</h4>
                            <div class="mt-1 break-all text-xs text-gray-500">{{ ucfirst($browser['platform']) }}{{ $browser['site_url'] ? ' · '.$browser['site_url'] : '' }}</div>
                        </div>
                        <span class="{{ $sessionStateBadge($browser['session_state']) }} shrink-0">{{ $sessionStateLabel($browser['session_state']) }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px]">
                        <span class="{{ !empty($browser['signal_present']) ? 'sa-badge sa-badge-signal-on' : 'sa-badge sa-badge-signal-off' }}">
                            {{ !empty($browser['signal_present']) ? 'Có tín hiệu' : 'Không có tín hiệu' }}
                        </span>
                        <span class="text-gray-500">
                            {{ $browser['dispatch_enabled'] ? 'Đang bật nhận Task' : 'Đã tắt nhận Task' }}
                            @if($browser['pause_reason']) · {{ str_replace('_', ' ', $browser['pause_reason']) }}@endif
                        </span>
                    </div>
                    @if($browser['signal_checked_at'])
                        <div class="mt-1 text-[10px] text-gray-400">Kiểm tra tín hiệu {{ $browser['signal_checked_at'] }}</div>
                    @endif
                    @if($browser['last_task_at'])
                        <div class="mt-2 text-[11px] text-gray-400">Task gần nhất: {{ $browser['last_task_at'] }}</div>
                    @endif
                    <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-2 pt-3">
                        @if($browser['dispatch_enabled'])
                            <button type="button" class="sa-action-ghost" wire:click="toggleDispatch({{ $browser['id'] }}, false)">Tắt</button>
                        @elseif($browser['dispatch_eligible'] && $browser['session_state'] !== 'needs_login')
                            <button type="button" class="sa-action-primary" wire:click="toggleDispatch({{ $browser['id'] }}, true)">Bật</button>
                        @endif
                        @if(!empty($browser['signal_present']))
                            <button type="button" class="sa-action-danger" wire:click="clearSessionSignal({{ $browser['id'] }})" title="Tắt Session Signal (đồng bộ false xuống Client)">Tắt tín hiệu</button>
                        @endif
                        @if($browser['dispatch_eligible'] && $browser['session_state'] !== 'needs_login')
                            <button type="button" class="sa-action-danger" wire:click="requireLogin({{ $browser['id'] }})">Yêu cầu đăng nhập</button>
                        @endif
                        <button type="button" class="sa-action-primary" wire:click="openNotificationModal({{ $device['id'] }}, {{ $browser['id'] }})">Gửi thông báo</button>
                    </div>
                    <details class="mt-3 text-[11px] text-gray-400">
                        <summary class="cursor-pointer">Thông tin kỹ thuật</summary>
                        <code class="mt-1 block break-all">{{ $browser['browser_id'] }}</code>
                    </details>
                </section>
            @endforeach
        </div>
    @endif
    <details class="mt-3 text-xs text-gray-400">
        <summary class="cursor-pointer">Thông tin kỹ thuật Client</summary>
        <div class="mt-1 space-y-1">
            @if (!empty($device['client_api_key_id']))
                <div>Client API Key ID</div>
                <code class="block break-all">{{ $device['client_api_key_id'] }}</code>
            @endif
            <div>Internal ref</div>
            <code class="block break-all">{{ $device['device_id'] }}</code>
        </div>
    </details>
</article>
