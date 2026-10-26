<x-filament-panels::page>
    @if ($isStaff)
        {{-- STAFF VIEW: Centered max-w-[900px], Section A (API Key Card) & Section B (16:9 Video Guide) --}}
        <div class="mx-auto w-full max-w-[900px] space-y-6">
            {{-- SECTION A — RPA Client API Key --}}
            <div
                x-data="{ copied: false }"
                x-on:copy-key-to-clipboard.window="navigator.clipboard.writeText($event.detail.text); copied = true; setTimeout(() => copied = false, 3000)"
                class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-6 shadow-xs space-y-5"
            >
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-800 pb-3">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-key class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            RPA Client API Key
                        </h2>
                    </div>
                    <div>
                        @if ($isKeyActivated)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                Đã kích hoạt
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                Sẵn sàng kích hoạt
                            </span>
                        @endif
                    </div>
                </div>

                <div class="space-y-4">
                    {{-- Key Field & Copy Button --}}
                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                            Client API Key
                        </label>
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <input
                                    type="text"
                                    readonly
                                    value="{{ $clientApiKey }}"
                                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 px-3 py-2.5 font-mono text-xs text-gray-800 dark:text-gray-200 focus:ring-0 select-all"
                                />
                            </div>
                            <button
                                type="button"
                                wire:click="copyAndActivateKey"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 px-5 py-2.5 text-xs font-semibold rounded-lg transition shadow-xs whitespace-nowrap"
                                :class="copied ? 'bg-green-600 text-white' : 'bg-teal-600 hover:bg-teal-700 text-white'"
                            >
                                <x-heroicon-o-clipboard-document class="w-4 h-4" />
                                <span wire:loading.remove wire:target="copyAndActivateKey" x-show="!copied">Sao chép Key</span>
                                <span wire:loading wire:target="copyAndActivateKey">Đang xử lý...</span>
                                <span x-show="copied" style="display: none;">Đã sao chép!</span>
                            </button>
                        </div>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">
                            Nhấn <strong>Sao chép Key</strong> để sao chép mã kết nối vào clipboard và sử dụng trên ứng dụng RPA Client của bạn.
                        </p>
                    </div>
                </div>
            </div>

            {{-- SECTION B — Video Hướng Dẫn (16:9 responsive frame) --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-6 shadow-xs space-y-4">
                <div class="flex items-center gap-2 border-b border-gray-100 dark:border-gray-800 pb-3">
                    <x-heroicon-o-play-circle class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Video Hướng Dẫn
                    </h2>
                </div>

                {{-- 16:9 Responsive Container ready for iframe / video tag --}}
                <div class="relative w-full aspect-video rounded-lg overflow-hidden border border-gray-200 dark:border-gray-800 bg-gray-900 flex flex-col items-center justify-center text-center p-6 text-gray-400 shadow-inner">
                    {{-- Placeholder State --}}
                    <div class="flex flex-col items-center justify-center space-y-3">
                        <div class="w-14 h-14 rounded-full bg-gray-800/80 border border-gray-700/60 flex items-center justify-center text-gray-400">
                            <x-heroicon-o-film class="w-7 h-7" />
                        </div>
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-gray-200">
                                Video hướng dẫn sẽ được cập nhật
                            </p>
                            <p class="text-xs text-gray-500">
                                Hướng dẫn chi tiết cách tải, cấu hình và vận hành RPA Client sẽ sớm có mặt tại đây.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- OWNER / ADMIN VIEW: Full dashboard intact --}}
        @php
            $status = $this->statusPayload();
            $metrics = $this->metrics();
            $db = is_array($status['database'] ?? null) ? $status['database'] : [];
        @endphp

        <div class="space-y-6 max-w-4xl">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('social-automation::filament.dashboard.intro') }}
            </p>

            <!-- Compact RPA Client Connection Card -->
            <div
                x-data="{ copied: false }"
                x-on:copy-key-to-clipboard.window="navigator.clipboard.writeText($event.detail.text); copied = true; setTimeout(() => copied = false, 3000)"
                class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-xs space-y-4"
            >
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-800 pb-3">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-signal class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            RPA Client Connection
                        </h3>
                    </div>
                    <div>
                        @if ($isKeyActivated)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                Đã kích hoạt
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                Chưa kích hoạt
                            </span>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Field: SEO-OPS Endpoint -->
                    <div class="space-y-1">
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                            SEO-OPS Endpoint
                        </label>
                        <div class="flex items-center rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 px-3 py-2 font-mono text-xs text-gray-800 dark:text-gray-200">
                            <span class="select-all truncate">{{ $clientEndpoint }}</span>
                        </div>
                    </div>

                    <!-- Field: Client API Key & Copy Button -->
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Client API Key
                            </label>
                            <a
                                href="{{ url('/social-automation/client-keys') }}"
                                class="text-xs text-teal-600 dark:text-teal-400 hover:underline"
                            >
                                Quản lý nâng cao →
                            </a>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <input
                                    type="text"
                                    readonly
                                    value="{{ $clientApiKey }}"
                                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 px-3 py-2 font-mono text-xs text-gray-800 dark:text-gray-200 focus:ring-0 select-all"
                                />
                            </div>
                            <button
                                type="button"
                                wire:click="copyAndActivateKey"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg transition shadow-xs whitespace-nowrap"
                                :class="copied ? 'bg-green-600 text-white' : 'bg-teal-600 hover:bg-teal-700 text-white'"
                            >
                                <span wire:loading.remove wire:target="copyAndActivateKey" x-show="!copied">Sao chép Key</span>
                                <span wire:loading wire:target="copyAndActivateKey">Đang xử lý...</span>
                                <span x-show="copied" style="display: none;">Đã sao chép!</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Instructions & App Status -->
                <div class="pt-3 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <div class="space-y-1">
                        <p class="font-medium text-gray-700 dark:text-gray-300">Hướng dẫn kết nối máy RPA Client:</p>
                        <p>1. Cài đặt và mở ứng dụng RPA Client trên máy Runner.</p>
                        <p>2. Nhập <strong>SEO-OPS Endpoint</strong> và dán <strong>Client API Key</strong> để xác thực với hệ thống.</p>
                    </div>
                    <div class="sm:text-right shrink-0">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700">
                            Ứng dụng RPA Client: Sắp ra mắt (Coming soon)
                        </span>
                    </div>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">Service</dt>
                    <dd class="mt-1 font-medium">{{ !empty($status['active']) ? 'Active' : 'Inactive' }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">Agents online</dt>
                    <dd class="mt-1 font-medium">{{ (int) ($metrics['agents_online'] ?? 0) }} / {{ (int) ($metrics['agents_total'] ?? 0) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">Sessions ready</dt>
                    <dd class="mt-1 font-medium">{{ (int) ($metrics['sessions_ready'] ?? 0) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">Jobs running</dt>
                    <dd class="mt-1 font-medium">{{ (int) ($metrics['jobs_running'] ?? 0) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">Packages</dt>
                    <dd class="mt-1 font-medium">{{ (int) ($metrics['packages'] ?? 0) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">Tasks active</dt>
                    <dd class="mt-1 font-medium">{{ (int) ($metrics['tasks_active'] ?? 0) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">Jobs completed</dt>
                    <dd class="mt-1 font-medium">{{ (int) ($metrics['jobs_completed'] ?? 0) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-xs uppercase text-gray-500">DB plane</dt>
                    <dd class="mt-1 font-medium text-xs">
                        {{ $db['connection'] ?? 'omi_social_automation' }}
                        @if (!empty($db['reachable'])) · reachable @elseif (!empty($db['configured'])) · configured @else · not configured @endif
                    </dd>
                </div>
            </dl>

            @if (!empty($metrics['empty']))
                <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600">
                    {{ __('social-automation::filament.dashboard.empty_schema') }}
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
