<x-filament-panels::page>
    <style>
        .sa-browser-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        @media (min-width: 640px) { .sa-browser-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .sa-browser-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    </style>
@php
    $dashboard = $this->dashboard();
    $onlineDevices = collect($dashboard['devices'])->where('online', true)->values();
    $offlineDevices = collect($dashboard['devices'])->where('online', false)->values();
@endphp
<div class="w-full space-y-6" wire:poll.10s>
    <p class="text-sm text-gray-500 dark:text-gray-400">Theo dõi heartbeat của máy, trạng thái phiên đăng nhập và quyền nhận Task độc lập.</p>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['Online device identities', $dashboard['metrics']['users_online']], ['Machines online', $dashboard['metrics']['machines_online'].' / '.$dashboard['metrics']['machines_total']], ['Legacy / unlinked sessions', $dashboard['metrics']['unlinked_accounts']], ['Dispatchable sessions', $dashboard['metrics']['sessions_available']]] as [$label, $value])
            <div class="flex min-h-24 flex-col justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"><div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div><div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div></div>
        @endforeach
    </div>
    <section class="space-y-3">
        <div class="flex items-center justify-between"><h2 class="text-sm font-semibold text-gray-950 dark:text-white">Online Devices</h2><span class="text-xs text-gray-500">{{ $onlineDevices->count() }} online</span></div>
        @forelse($onlineDevices as $device)
            @include('social-automation::filament.pages.partials.device-group', ['device' => $device])
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700">Không có máy online. Session đã lưu không bị xem là mất đăng nhập chỉ vì máy offline.</div>
        @endforelse
    </section>
    <details class="group rounded-xl border border-gray-200 bg-gray-50/60 dark:border-gray-700 dark:bg-gray-900/40">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4">
            <div><h2 class="text-sm font-semibold text-gray-950 dark:text-white">Offline / Legacy Devices</h2><p class="mt-1 text-xs text-gray-500">Giữ nguyên {{ $offlineDevices->count() }} record lịch sử; không tự merge theo hostname.</p></div>
            <span class="text-xs text-gray-500 group-open:hidden">Mở danh sách</span><span class="hidden text-xs text-gray-500 group-open:inline">Thu gọn</span>
        </summary>
        <div class="space-y-3 border-t border-gray-200 p-4 dark:border-gray-700">
            @forelse($offlineDevices as $device)
                @include('social-automation::filament.pages.partials.device-group', ['device' => $device])
            @empty
                <div class="text-sm text-gray-500">Không có máy offline hoặc legacy.</div>
            @endforelse
        </div>
    </details>
</div>
@if($this->showNotificationModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
            <div class="flex items-center justify-between"><h2 class="font-semibold text-gray-950 dark:text-white">Gửi thông báo</h2><button type="button" class="text-gray-400" wire:click="closeNotificationModal">✕</button></div>
            <p class="mt-1 text-xs text-gray-500">Device #{{ $this->notificationAgentId }}. Chọn Browser nếu thông báo chỉ dành cho một Account cụ thể.</p>
            <label class="mt-4 block text-xs font-medium text-gray-600 dark:text-gray-300">Browser / Account (optional)
                <select wire:model="notificationSessionId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                    <option value="">Toàn bộ Device</option>
                    @foreach($this->notificationBrowserOptions() as $browser)<option value="{{ $browser['id'] }}">{{ $browser['account_name'] }} · {{ $browser['platform'] }}</option>@endforeach
                </select>
            </label>
            <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Mẫu thông báo
                <select wire:model="notificationTemplate" wire:change="applyNotificationTemplate" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                    <option value="">Tự nhập</option><option value="check_browser">Kiểm tra Browser</option><option value="manual_modal">Xử lý cửa sổ xác nhận</option><option value="check_account">Kiểm tra Account</option>
                </select>
            </label>
            <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Nội dung
                <textarea wire:model="notificationMessage" rows="5" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800"></textarea>
            </label>
            @error('notificationMessage')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="text-sm text-gray-500" wire:click="closeNotificationModal">Hủy</button><button type="button" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white" wire:click="sendNotification">Gửi</button></div>
        </div>
    </div>
@endif
</x-filament-panels::page>
