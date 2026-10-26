<x-filament-panels::page>
    @php
        $rows = $this->rows();
    @endphp

    <div
        x-data
        x-on:copy-key-to-clipboard.window="navigator.clipboard.writeText($event.detail.text)"
        class="space-y-6 max-w-6xl"
    >
        <!-- Header & Action -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('social-automation::filament.client_keys.intro') }}
                </p>
            </div>
            <div>
                <x-filament::button
                    wire:click="openCreateModal"
                    icon="heroicon-m-plus"
                    size="sm"
                    color="primary"
                >
                    {{ __('social-automation::filament.client_keys.create_btn') }}
                </x-filament::button>
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
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md transition shadow-xs text-white"
                        :style="copied ? 'background-color: #16a34a;' : 'background-color: #0d9488;'"
                    >
                        <span x-show="!copied">{{ __('social-automation::filament.client_keys.copy_btn') }}</span>
                        <span x-show="copied" style="display: none;">{{ __('social-automation::filament.client_keys.copied') }}</span>
                    </button>
                </div>
            </div>
        @endif

        <!-- Keys Table -->
        @if ($rows->isEmpty())
            <div class="max-w-xl mx-auto my-4 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 sm:p-7 text-center">
                <div class="w-12 h-12 flex items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500 mx-auto mb-3" style="width: 48px; height: 48px;">
                    <x-heroicon-o-key class="w-6 h-6" style="width: 24px; height: 24px; max-width: 24px; max-height: 24px;" />
                </div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                    {{ __('social-automation::filament.client_keys.empty_title') }}
                </h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 max-w-sm mx-auto">
                    {{ __('social-automation::filament.client_keys.empty_desc') }}
                </p>
                <x-filament::button
                    wire:click="openCreateModal"
                    icon="heroicon-m-plus"
                    size="xs"
                    color="primary"
                >
                    {{ __('social-automation::filament.client_keys.create_first') }}
                </x-filament::button>
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
                                <td class="px-4 py-3 text-right space-x-3">
                                    @if ($row->isActive())
                                        @if ($row->getDecryptedRawKey())
                                            <button
                                                wire:click="copyKey({{ $row->id }})"
                                                type="button"
                                                class="text-xs font-semibold text-teal-600 hover:text-teal-800 dark:text-teal-400 dark:hover:text-teal-300 hover:underline"
                                            >
                                                {{ __('social-automation::filament.client_keys.copy_btn') }}
                                            </button>
                                        @endif
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
                    <x-filament::button
                        wire:click="closeCreateModal"
                        color="gray"
                        size="sm"
                    >
                        {{ __('social-automation::filament.client_keys.btn_cancel') }}
                    </x-filament::button>
                    <x-filament::button
                        wire:click="createKey"
                        color="primary"
                        size="sm"
                        wire:loading.attr="disabled"
                        wire:target="createKey"
                    >
                        {{ __('social-automation::filament.client_keys.btn_confirm_create') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
