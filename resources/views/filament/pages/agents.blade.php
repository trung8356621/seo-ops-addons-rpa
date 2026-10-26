<x-filament-panels::page>
    @php $rows = $rows ?? (isset($this) && method_exists($this, 'rows') ? $this->rows() : app(\Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService::class)->listForUi()); @endphp
    <div class="space-y-4 max-w-6xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.agents.intro') }}</p>
        @if ($rows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.agents.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 shadow-sm dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2.5 text-left font-semibold">Tên thiết bị</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Device ID</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Phiên bản</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Trạng thái</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Khả dụng</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Jobs</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Last Seen</th>
                            <th class="px-3 py-2.5 text-left font-semibold">Lỗi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                        @foreach ($rows as $row)
                            @php
                                $isOnline = ($row['online_state'] ?? 'offline') === 'online';
                                $deviceId = $row['device_id'] ?? '';
                                $shortDeviceId = strlen($deviceId) > 16 ? substr($deviceId, 0, 8) . '...' . substr($deviceId, -6) : $deviceId;
                            @endphp
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">
                                <td class="px-3 py-2.5 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $row['display_name'] ?? 'Chưa đặt tên' }}
                                    <div class="text-xs text-gray-400 font-mono">{{ $row['agent_kind'] ?? 'runner' }}</div>
                                </td>
                                <td class="px-3 py-2.5 font-mono text-xs text-gray-600 dark:text-gray-400" title="{{ $deviceId }}">
                                    {{ $shortDeviceId }}
                                </td>
                                <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-400">
                                    {{ $row['app_version'] ?? '—' }}
                                </td>
                                <td class="px-3 py-2.5">
                                    @if ($isOnline)
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                            Online
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700">
                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                            Offline
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-xs">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                        {{ $row['availability'] ?? 'available' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-400">
                                    {{ ($row['active_jobs'] ?? 0).'/'.($row['max_concurrency'] ?? 1) }}
                                </td>
                                <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row['last_heartbeat_at'] ?? '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-xs">
                                    @if (! empty($row['last_error_code']) || ! empty($row['last_error_message']))
                                        <span class="text-rose-600 dark:text-rose-400 font-mono" title="{{ $row['last_error_message'] ?? '' }}">
                                            {{ $row['last_error_code'] ?? 'Error' }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
