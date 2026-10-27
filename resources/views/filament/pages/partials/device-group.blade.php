<article class="rounded-xl border {{ $device['available_count'] ? 'border-gray-200 dark:border-gray-700' : 'border-amber-200 dark:border-amber-900' }} bg-white p-4 dark:bg-gray-900">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-800">
        <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h3 class="break-words font-semibold text-gray-950 dark:text-white">{{ $device['name'] }}</h3><span class="text-xs font-medium {{ $device['online'] ? 'text-emerald-600' : 'text-gray-400' }}">{{ $device['online'] ? '● Online' : '○ Offline' }}</span></div><div class="mt-1 text-xs text-gray-500">Last seen {{ $device['last_seen'] ?? '—' }}</div></div>
        <div class="flex items-center gap-4 text-right text-xs text-gray-500"><button type="button" class="text-xs font-medium text-primary-600" wire:click="openNotificationModal({{ $device['id'] }})">Gửi thông báo</button><div><strong class="block text-base text-gray-900 dark:text-white">{{ count($device['browsers']) }}</strong>Browser</div><div><strong class="block text-base text-emerald-600">{{ $device['available_count'] }}</strong>Khả dụng</div></div>
    </header>
    @if($device['browsers'] === [])
        <div class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Máy chưa có Browser Session. Trạng thái này độc lập với kết nối thiết bị.</div>
    @else
        <div class="sa-browser-grid mt-3">
            @foreach($device['browsers'] as $browser)
                <section class="flex min-w-0 flex-col rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-start justify-between gap-2"><div class="min-w-0"><h4 class="break-words text-sm font-semibold leading-5 text-gray-950 dark:text-white" title="{{ $browser['account_name'] }}">{{ $browser['account_name'] }}</h4><div class="mt-1 break-all text-xs text-gray-500">{{ ucfirst($browser['platform']) }}{{ $browser['site_url'] ? ' · '.$browser['site_url'] : '' }}</div></div><span class="shrink-0 text-[11px] font-medium {{ $browser['session_state']==='verified' ? 'text-emerald-600' : ($browser['session_state']==='needs_login' ? 'text-rose-600' : 'text-amber-600') }}">{{ str_replace('_',' ',ucfirst($browser['session_state'])) }}</span></div>
                    <div class="mt-3 text-xs {{ $browser['dispatch_enabled'] ? 'text-emerald-600' : 'text-gray-400' }}">Dispatch {{ $browser['dispatch_enabled'] ? 'Enabled' : 'Disabled' }}@if($browser['pause_reason']) · {{ str_replace('_', ' ', $browser['pause_reason']) }}@endif</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-[11px]"><span class="{{ $browser['signal_status']==='present' ? 'text-emerald-600' : ($browser['signal_status']==='absent' ? 'text-rose-600' : 'text-gray-400') }}">Signal: {{ ucfirst($browser['signal_status']) }}</span><span class="text-gray-400">Verification: {{ str_replace('_', ' ', ucfirst($browser['session_state'])) }}</span></div>
                    @if($browser['signal_checked_at'])<div class="mt-1 text-[10px] text-gray-400">Signal checked {{ $browser['signal_checked_at'] }}</div>@endif
                    @if($browser['last_task_at'])<div class="mt-2 text-[11px] text-gray-400">Task gần nhất: {{ $browser['last_task_at'] }}</div>@endif
                    <div class="mt-auto flex flex-wrap gap-2 pt-3">
                        @if($browser['dispatch_enabled'])<button class="text-xs text-gray-600 dark:text-gray-300" wire:click="toggleDispatch({{ $browser['id'] }}, false)">Disable</button>
                        @elseif($browser['dispatch_eligible'] && $browser['session_state'] !== 'needs_login')<button class="text-xs text-primary-600" wire:click="toggleDispatch({{ $browser['id'] }}, true)">Enable</button>@endif
                        @if($browser['dispatch_eligible'] && $browser['session_state'] !== 'needs_login')<button class="text-xs text-rose-600" wire:click="requireLogin({{ $browser['id'] }})">Yêu cầu đăng nhập</button>@endif
                        <button type="button" class="text-xs text-primary-600" wire:click="openNotificationModal({{ $device['id'] }}, {{ $browser['id'] }})">Gửi thông báo</button>
                    </div>
                    <details class="mt-3 text-[11px] text-gray-400"><summary class="cursor-pointer">Technical details</summary><code class="mt-1 block break-all">{{ $browser['browser_id'] }}</code></details>
                </section>
            @endforeach
        </div>
    @endif
    <details class="mt-3 text-xs text-gray-400"><summary class="cursor-pointer">Device technical details</summary><code class="mt-1 block break-all">{{ $device['device_id'] }}</code></details>
</article>
