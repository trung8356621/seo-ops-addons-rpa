<x-filament-panels::page>
@php
    $dashboard = $this->dashboard();
    $onlineDevices = collect($dashboard['devices'])->where('online', true)->values();
    $offlineDevices = collect($dashboard['devices'])->where('online', false)->values();
@endphp
<div class="w-full space-y-6">
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
</x-filament-panels::page>
