<x-filament-panels::page>
    @php
        $rows = $rows ?? (isset($this) && method_exists($this, 'rows') ? $this->rows() : app(\Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService::class)->listForUi());
    @endphp
    <div class="space-y-4 max-w-6xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.sessions.intro') }}</p>
        @if ($rows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.sessions.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 shadow-sm dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2.5 text-left font-semibold">Tên Browser</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Browser ID</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Thiết bị</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Nền tảng</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Trạng thái Browser</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Device Online</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                        @foreach ($rows as $row)
                            @php
                                $status = $row['effective_status'] ?? ($row['status'] ?? 'stopped');
                                $browserId = $row['browser_id'] ?? ($row['session_uuid'] ?? '');
                                $shortBrowserId = strlen($browserId) > 16 ? substr($browserId, 0, 8) . '...' . substr($browserId, -6) : $browserId;
                                $isDeviceOnline = (bool) ($row['device_online'] ?? false);
                            @endphp
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">
                                <td class="px-3 py-2.5 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $row['name'] ?? ($row['session_uuid'] ?? 'Browser Profile') }}
                                </td>
                                <td class="px-3 py-2.5 font-mono text-xs text-gray-600 dark:text-gray-400" title="{{ $browserId }}">
                                    {{ $shortBrowserId }}
                                </td>
                                <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-400">
                                    <div class="font-medium text-gray-800 dark:text-gray-200">{{ $row['device_name'] ?? '—' }}</div>
                                    <div class="font-mono text-[11px] text-gray-400">{{ substr((string)($row['device_id'] ?? ''), 0, 14) }}</div>
                                </td>
                                <td class="px-3 py-2.5 text-xs">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200 dark:bg-blue-950/40 dark:text-blue-400 dark:border-blue-800">
                                        {{ $row['platform'] ?? 'facebook' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5">
                                    @if ($status === 'running')
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                            Running
                                        </span>
                                    @elseif ($status === 'unknown_stale')
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800" title="Thiết bị đang Offline; trạng thái không thể xác thực">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                                            Unknown / Stale
                                        </span>
                                    @elseif ($status === 'error' || $status === 'crashed')
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/40 dark:text-rose-400 dark:border-rose-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                            Error
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700">
                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                            Stopped
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-xs">
                                    @if ($isDeviceOnline)
                                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">Online</span>
                                    @else
                                        <span class="text-gray-400">Offline</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-mono">
                                    {{ $row['last_seen_at'] ?? ($row['last_confirmed_at'] ?? '—') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
