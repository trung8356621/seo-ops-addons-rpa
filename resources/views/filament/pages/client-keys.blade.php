<x-filament-panels::page>
    @php
        $rows = $this->rows();
    @endphp

    <div class="space-y-6 max-w-6xl">
        <!-- Header & Action -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('social-automation::filament.client_keys.intro') }}
                </p>
            </div>
            <div>
                <button
                    wire:click="openCreateModal"
                    type="button"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-teal-600 hover:bg-teal-700 active:bg-teal-800 rounded-lg shadow-sm transition"
                >
                    <x-heroicon-m-plus class="w-4 h-4" />
                    {{ __('social-automation::filament.client_keys.create_btn') }}
                </button>
            </div>
        </div>

        <!-- Single-Time Raw Key Reveal Banner -->
        @if ($recentlyCreatedRawKey)
            <div class="p-5 rounded-xl border border-teal-500/40 bg-teal-50/70 dark:bg-teal-950/30 text-teal-900 dark:text-teal-200 shadow-sm space-y-3">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-2 font-bold text-base text-teal-800 dark:text-teal-200">
                        <x-heroicon-m-check-badge class="w-6 h-6 text-teal-600 dark:text-teal-400" />
                        <span>{{ __('social-automation::filament.client_keys.created_title') }}: <span class="underline">{{ $recentlyCreatedKeyName }}</span></span>
                    </div>
                    <button
                        wire:click="dismissRawKeyBanner"
                        type="button"
                        class="text-xs text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 font-semibold px-2 py-1"
                    >
                        ✕ {{ __('social-automation::filament.client_keys.dismiss') }}
                    </button>
                </div>

                <p class="text-xs text-amber-700 dark:text-amber-400 font-medium">
                    ⚠️ {{ __('social-automation::filament.client_keys.reveal_warning') }}
                </p>

                <div
                    x-data="{ copied: false, text: @js($recentlyCreatedRawKey) }"
                    class="flex items-center gap-2 bg-white dark:bg-gray-900 p-3 rounded-lg border border-teal-300 dark:border-teal-700 font-mono text-sm"
                >
                    <input
                        type="text"
                        readonly
                        value="{{ $recentlyCreatedRawKey }}"
                        class="w-full bg-transparent border-0 p-0 text-xs sm:text-sm font-mono text-gray-900 dark:text-gray-100 focus:ring-0 select-all"
                    />
                    <button
                        type="button"
                        @click="navigator.clipboard.writeText(text); copied = true; setTimeout(() => copied = false, 3000)"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md transition shadow-xs"
                        :class="copied ? 'bg-green-600 text-white' : 'bg-teal-600 hover:bg-teal-700 text-white'"
                    >
                        <span x-show="!copied">{{ __('social-automation::filament.client_keys.copy_btn') }}</span>
                        <span x-show="copied" style="display: none;">{{ __('social-automation::filament.client_keys.copied') }}</span>
                    </button>
                </div>
            </div>
        @endif

        <!-- Keys Table -->
        @if ($rows->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                <x-heroicon-o-key class="w-12 h-12 mx-auto text-gray-400 dark:text-gray-500 mb-2" />
                <p class="font-medium text-base mb-1">{{ __('social-automation::filament.client_keys.empty_title') }}</p>
                <p class="text-xs mb-4">{{ __('social-automation::filament.client_keys.empty_desc') }}</p>
                <button
                    wire:click="openCreateModal"
                    type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                >
                    + {{ __('social-automation::filament.client_keys.create_first') }}
                </button>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xs">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50/75 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.client_keys.col_name') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.client_keys.col_prefix') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.client_keys.col_creator') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.client_keys.col_created_at') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.client_keys.col_last_used') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.client_keys.col_status') }}</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.client_keys.col_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $row->name }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                                        {{ $row->key_prefix }}...
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                                    {{ $row->creator?->name ?? ($row->created_by ? 'ID: '.$row->created_by : 'System') }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row->created_at->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row->last_used_at ? $row->last_used_at->diffForHumans() : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row->isActive())
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                            ● {{ __('social-automation::filament.client_keys.status_active') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                            ✕ {{ __('social-automation::filament.client_keys.status_revoked') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($row->isActive())
                                        <button
                                            wire:click="revokeKey({{ $row->id }})"
                                            wire:confirm="{{ __('social-automation::filament.client_keys.revoke_confirm') }}"
                                            type="button"
                                            class="text-xs font-semibold text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 hover:underline"
                                        >
                                            {{ __('social-automation::filament.client_keys.action_revoke') }}
                                        </button>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-gray-600 italic">
                                            {{ __('social-automation::filament.client_keys.revoked') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Create Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div
                class="w-full max-w-md bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-800 p-6 space-y-4"
                @click.outside="$wire.closeCreateModal()"
            >
                <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-800 pb-3">
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">
                        {{ __('social-automation::filament.client_keys.modal_create_title') }}
                    </h3>
                    <button wire:click="closeCreateModal" type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        ✕
                    </button>
                </div>

                <div class="space-y-3">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                        {{ __('social-automation::filament.client_keys.field_name') }}
                    </label>
                    <input
                        wire:model="newKeyName"
                        type="text"
                        placeholder="VD: Client Machine Office #1"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        wire:keydown.enter="createKey"
                        autofocus
                    />
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('social-automation::filament.client_keys.field_name_hint') }}
                    </p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                    <button
                        wire:click="closeCreateModal"
                        type="button"
                        class="px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-md transition"
                    >
                        {{ __('social-automation::filament.client_keys.btn_cancel') }}
                    </button>
                    <button
                        wire:click="createKey"
                        type="button"
                        class="px-4 py-1.5 text-xs font-semibold text-white bg-teal-600 hover:bg-teal-700 rounded-md transition shadow-xs"
                    >
                        {{ __('social-automation::filament.client_keys.btn_confirm_create') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
