<x-filament-panels::page>
    <style>
        .sa-browser-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        @media (min-width: 640px) { .sa-browser-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .sa-browser-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .sa-kpi { display: flex; min-height: 5.5rem; flex-direction: column; justify-content: space-between; border-radius: .75rem; border: 1px solid rgb(229 231 235); background: #fff; padding: 1rem; }
        .dark .sa-kpi { border-color: rgb(55 65 81); background: rgb(17 24 39); }
        .sa-status-dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 9999px; flex-shrink: 0; }
        .sa-badge { display: inline-flex; align-items: center; gap: .35rem; border-radius: .375rem; padding: .125rem .5rem; font-size: .6875rem; font-weight: 600; line-height: 1.25rem; }
        .sa-badge-online { background: rgb(236 253 245); color: rgb(5 150 105); }
        .dark .sa-badge-online { background: rgb(6 78 59 / 0.35); color: rgb(52 211 153); }
        .sa-badge-offline { background: rgb(243 244 246); color: rgb(107 114 128); }
        .dark .sa-badge-offline { background: rgb(31 41 55); color: rgb(156 163 175); }
        .sa-badge-signal-on { background: rgb(236 253 245); color: rgb(5 150 105); }
        .dark .sa-badge-signal-on { background: rgb(6 78 59 / 0.35); color: rgb(52 211 153); }
        .sa-badge-signal-off { background: rgb(254 252 232); color: rgb(161 98 7); }
        .dark .sa-badge-signal-off { background: rgb(66 32 6 / 0.4); color: rgb(250 204 21); }
        .sa-badge-needs-login { background: rgb(255 247 237); color: rgb(194 65 12); }
        .dark .sa-badge-needs-login { background: rgb(67 20 7 / 0.4); color: rgb(251 146 60); }
        .sa-badge-verified { background: rgb(236 253 245); color: rgb(5 150 105); }
        .dark .sa-badge-verified { background: rgb(6 78 59 / 0.35); color: rgb(52 211 153); }
        .sa-badge-candidate { background: rgb(243 244 246); color: rgb(75 85 99); }
        .dark .sa-badge-candidate { background: rgb(31 41 55); color: rgb(156 163 175); }
        .sa-action-primary { font-size: .75rem; font-weight: 600; color: rgb(79 70 229); }
        .sa-action-ghost { font-size: .75rem; color: rgb(107 114 128); }
        .sa-action-danger { font-size: .75rem; color: rgb(225 29 72); }
        .sa-device-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; font-size: .75rem; color: rgb(107 114 128); }
    </style>
@php
    $dashboard = $this->dashboard();
    $onlineDevices = collect($dashboard['devices'])->where('online', true)->values();
    $offlineDevices = collect($dashboard['devices'])->where('online', false)->values();
    $kpiCards = [
        ['Thiết bị đang online', $dashboard['metrics']['users_online']],
        ['Máy đang online', $dashboard['metrics']['machines_online'].' / '.$dashboard['metrics']['machines_total']],
        ['Browser chưa liên kết', $dashboard['metrics']['unlinked_accounts']],
        ['Phiên sẵn sàng nhận Task', $dashboard['metrics']['sessions_available']],
    ];
@endphp
<div class="w-full space-y-5" wire:poll.10s>
    <p class="text-sm text-gray-500 dark:text-gray-400">Theo dõi heartbeat của máy, trạng thái phiên đăng nhập và quyền nhận Task độc lập.</p>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($kpiCards as [$label, $value])
            <div class="sa-kpi">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $value }}</div>
            </div>
        @endforeach
    </div>
    <section class="space-y-3">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Máy trực tuyến</h2>
            <span class="text-xs text-gray-500">{{ $onlineDevices->count() }} máy online</span>
        </div>
        @forelse($onlineDevices as $device)
            @include('social-automation::filament.pages.partials.device-group', ['device' => $device])
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700">Không có máy online. Session đã lưu không bị xem là mất đăng nhập chỉ vì máy offline.</div>
        @endforelse
    </section>
    <details class="group rounded-xl border border-gray-200 bg-gray-50/60 dark:border-gray-700 dark:bg-gray-900/40">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4">
            <div>
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Máy ngoại tuyến / Dữ liệu cũ</h2>
                <p class="mt-1 text-xs text-gray-500">Giữ nguyên {{ $offlineDevices->count() }} bản ghi lịch sử; không tự gộp theo hostname.</p>
            </div>
            <span class="text-xs text-gray-500 group-open:hidden">Mở danh sách</span>
            <span class="hidden text-xs text-gray-500 group-open:inline">Thu gọn</span>
        </summary>
        <div class="space-y-3 border-t border-gray-200 p-4 dark:border-gray-700">
            @forelse($offlineDevices as $device)
                @include('social-automation::filament.pages.partials.device-group', ['device' => $device])
            @empty
                <div class="text-sm text-gray-500">Không có máy offline hoặc dữ liệu cũ.</div>
            @endforelse
        </div>
    </details>
</div>
@if($this->showNotificationModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-gray-950 dark:text-white">Gửi thông báo</h2>
                <button type="button" class="text-gray-400" wire:click="closeNotificationModal">✕</button>
            </div>
            <p class="mt-1 text-xs text-gray-500">Device #{{ $this->notificationAgentId }}. Chọn Account nếu thông báo chỉ dành cho một tài khoản cụ thể.</p>
            <label class="mt-4 block text-xs font-medium text-gray-600 dark:text-gray-300">Account (tuỳ chọn)
                <select wire:model="notificationSessionId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                    <option value="">Toàn bộ Device</option>
                    @foreach($this->notificationBrowserOptions() as $browser)
                        <option value="{{ $browser['id'] }}">{{ $browser['account_name'] }} · {{ $browser['platform'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Mẫu thông báo
                <select wire:model="notificationTemplate" wire:change="applyNotificationTemplate" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                    <option value="">Tự nhập</option>
                    <option value="check_browser">Kiểm tra Browser</option>
                    <option value="manual_modal">Xử lý cửa sổ xác nhận</option>
                    <option value="check_account">Kiểm tra Account</option>
                </select>
            </label>
            <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">Nội dung
                <textarea wire:model="notificationMessage" rows="5" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800"></textarea>
            </label>
            @error('notificationMessage')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            <div class="mt-4 flex justify-end gap-3">
                <button type="button" class="sa-action-ghost" wire:click="closeNotificationModal">Hủy</button>
                <button type="button" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white" wire:click="sendNotification">Gửi</button>
            </div>
        </div>
    </div>
@endif
</x-filament-panels::page>
