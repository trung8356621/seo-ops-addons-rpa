<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $canManage = $this->canManage();
    @endphp

    <div
        x-data
        class="space-y-6 max-w-7xl"
    >
        <!-- Header & Action -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('social-automation::filament.accounts.intro') }}
                </p>
            </div>
            @if ($canManage)
                <div>
                    <x-filament::button
                        wire:click="openCreateModal"
                        icon="heroicon-m-plus"
                        size="sm"
                        color="primary"
                    >
                        {{ __('social-automation::filament.accounts.create_btn') }}
                    </x-filament::button>
                </div>
            @endif
        </div>

        <!-- Accounts Table / Compact Empty State -->
        @if ($rows->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 sm:p-7 text-center max-w-xl mx-auto my-4">
                <div class="mx-auto mb-3 flex items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500" style="width: 48px; height: 48px;">
                    <x-heroicon-o-user-group style="width: 24px; height: 24px; max-width: 24px; max-height: 24px;" />
                </div>
                <h3 class="font-semibold text-sm text-gray-900 dark:text-gray-100 mb-1">
                    {{ __('social-automation::filament.accounts.empty_title') }}
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 max-w-md mx-auto">
                    {{ __('social-automation::filament.accounts.empty_desc') }}
                </p>
                @if ($canManage)
                    <div class="flex items-center justify-center">
                        <x-filament::button
                            wire:click="openCreateModal"
                            icon="heroicon-m-plus"
                            size="xs"
                            color="primary"
                        >
                            {{ __('social-automation::filament.accounts.create_first') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xs">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50/75 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_platform') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_name') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_email') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_password') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_note') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_last_interaction') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_creator') }}</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_created_at') }}</th>
                            @if ($canManage)
                                <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">{{ __('social-automation::filament.accounts.col_actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition">
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300">
                                        {{ $row->platform }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $row->getAccountName() }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                                    @if ($row->getDisplayEmail())
                                        <div
                                            x-data="{ copied: false, text: @js($row->getDisplayEmail()) }"
                                            class="inline-flex items-center gap-1.5"
                                        >
                                            <span class="font-mono">{{ $row->getDisplayEmail() }}</span>
                                            <button
                                                type="button"
                                                @click="navigator.clipboard.writeText(text); copied = true; setTimeout(() => copied = false, 2000)"
                                                title="{{ __('social-automation::filament.accounts.copy_email') }}"
                                                class="px-1.5 py-0.5 text-[10px] font-semibold rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                                            >
                                                <span x-show="!copied">{{ __('social-automation::filament.accounts.copy_btn') }}</span>
                                                <span x-show="copied" style="display: none;" class="text-green-600 dark:text-green-400">✓</span>
                                            </button>
                                        </div>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row->hasPassword())
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                            ● {{ __('social-automation::filament.accounts.password_saved') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                            {{ __('social-automation::filament.accounts.password_none') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-xs truncate" title="{{ $row->note }}">
                                    {{ $row->note ?: '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                                    @if ($row->last_interaction_at)
                                        <span class="font-mono">{{ $row->last_interaction_at->format('Y-m-d H:i') }}</span>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-600 italic">{{ __('social-automation::filament.accounts.interaction_none') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                                    {{ $row->getCreatorName() ?? 'System' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row->created_at ? $row->created_at->format('Y-m-d H:i') : '—' }}
                                </td>
                                @if ($canManage)
                                    <td class="px-4 py-3 text-right space-x-2">
                                        <button
                                            wire:click="openPasswordModal({{ $row->id }})"
                                            type="button"
                                            class="text-xs font-semibold text-amber-600 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300 hover:underline"
                                        >
                                            {{ $row->hasPassword() ? 'Đổi mật khẩu' : 'Thiết lập mật khẩu' }}
                                        </button>
                                        <button
                                            wire:click="openEditModal({{ $row->id }})"
                                            type="button"
                                            class="text-xs font-semibold text-teal-600 hover:text-teal-800 dark:text-teal-400 dark:hover:text-teal-300 hover:underline"
                                        >
                                            {{ __('social-automation::filament.accounts.action_edit') }}
                                        </button>
                                        <button
                                            wire:click="deleteAccount({{ $row->id }})"
                                            wire:confirm="{{ __('social-automation::filament.accounts.delete_confirm') }}"
                                            type="button"
                                            class="text-xs font-semibold text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 hover:underline"
                                        >
                                            {{ __('social-automation::filament.accounts.action_delete') }}
                                        </button>
                                    </td>
                                @endif
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
                class="w-full max-w-lg bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-800 p-6 space-y-4"
                @click.outside="$wire.closeCreateModal()"
            >
                <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-800 pb-3">
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">
                        {{ __('social-automation::filament.accounts.modal_create_title') }}
                    </h3>
                    <button wire:click="closeCreateModal" type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        ✕
                    </button>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_platform') }} *
                        </label>
                        <select
                            wire:model="platform"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        >
                            <option value="Facebook">Facebook</option>
                            <option value="Threads">Threads</option>
                            <option value="X">X (Twitter)</option>
                            <option value="Instagram">Instagram</option>
                            <option value="TikTok">TikTok</option>
                            <option value="LinkedIn">LinkedIn</option>
                            <option value="Pinterest">Pinterest</option>
                            <option value="Other">Khác</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_name') }} *
                        </label>
                        <input
                            wire:model="name"
                            type="text"
                            placeholder="VD: Fanpage SEO Brand #1"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                            autofocus
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_email') }} <span class="text-danger-600 dark:text-danger-400">*</span>
                        </label>
                        <input
                            wire:model="email"
                            type="email"
                            required
                            placeholder="Email tài khoản (bắt buộc)"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Site URL (WordPress / Tùy chỉnh)
                        </label>
                        <input
                            wire:model="site_url"
                            type="url"
                            placeholder="https://example.com hoặc https://example.com/blog"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Cấu hình URL cho WordPress hoặc nền tảng tùy chỉnh. Hỗ trợ đường dẫn con (subdirectory).
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_note') }}
                        </label>
                        <textarea
                            wire:model="note"
                            rows="2"
                            placeholder="Ghi chú về tài khoản này..."
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        ></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                    <x-filament::button
                        wire:click="closeCreateModal"
                        color="gray"
                        size="sm"
                    >
                        {{ __('social-automation::filament.accounts.btn_cancel') }}
                    </x-filament::button>
                    <x-filament::button
                        wire:click="createAccount"
                        wire:loading.attr="disabled"
                        wire:target="createAccount"
                        color="primary"
                        size="sm"
                    >
                        {{ __('social-automation::filament.accounts.btn_create') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    <!-- Edit Modal -->
    @if ($showEditModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div
                class="w-full max-w-lg bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-800 p-6 space-y-4"
                @click.outside="$wire.closeEditModal()"
            >
                <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-800 pb-3">
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">
                        {{ __('social-automation::filament.accounts.modal_edit_title') }}
                    </h3>
                    <button wire:click="closeEditModal" type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        ✕
                    </button>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_platform') }} *
                        </label>
                        <select
                            wire:model="platform"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        >
                            <option value="Facebook">Facebook</option>
                            <option value="Threads">Threads</option>
                            <option value="X">X (Twitter)</option>
                            <option value="Instagram">Instagram</option>
                            <option value="TikTok">TikTok</option>
                            <option value="LinkedIn">LinkedIn</option>
                            <option value="Pinterest">Pinterest</option>
                            <option value="Other">Khác</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_name') }} *
                        </label>
                        <input
                            wire:model="name"
                            type="text"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_email') }} <span class="text-danger-600 dark:text-danger-400">*</span>
                        </label>
                        <input
                            wire:model="email"
                            type="email"
                            required
                            placeholder="Email tài khoản (bắt buộc)"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Site URL (WordPress / Tùy chỉnh)
                        </label>
                        <input
                            wire:model="site_url"
                            type="url"
                            placeholder="https://example.com hoặc https://example.com/blog"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Cấu hình URL cho WordPress hoặc nền tảng tùy chỉnh. Hỗ trợ đường dẫn con (subdirectory).
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('social-automation::filament.accounts.field_note') }}
                        </label>
                        <textarea
                            wire:model="note"
                            rows="2"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-teal-500 focus:ring-teal-500"
                        ></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                    <x-filament::button
                        wire:click="closeEditModal"
                        color="gray"
                        size="sm"
                    >
                        {{ __('social-automation::filament.accounts.btn_cancel') }}
                    </x-filament::button>
                    <x-filament::button
                        wire:click="updateAccount"
                        wire:loading.attr="disabled"
                        wire:target="updateAccount"
                        color="primary"
                        size="sm"
                    >
                        {{ __('social-automation::filament.accounts.btn_save') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    @if ($showPasswordModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div x-data="{ showPassword: false }" class="w-full max-w-md bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-800 p-6 space-y-4" @click.outside="$wire.closePasswordModal()">
                <div class="border-b border-gray-100 dark:border-gray-800 pb-3">
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ $passwordAccountHasPassword ? 'Đổi mật khẩu' : 'Thiết lập mật khẩu' }}</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Account: {{ $passwordAccountName }} · {{ $passwordAccountHasPassword ? 'Đã lưu mật khẩu' : 'Chưa lưu mật khẩu' }}</p>
                </div>
                <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-xs text-warning-800 dark:border-warning-700 dark:bg-warning-950/40 dark:text-warning-200">
                    <strong>Cảnh báo bảo mật:</strong><br>
                    Khi lưu mật khẩu, các thiết bị RPA Client có quyền truy cập Installation này có thể sử dụng chức năng Copy Password.<br><br>
                    Chỉ lưu mật khẩu nếu bạn tin tưởng các thiết bị được kết nối.
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Mật khẩu mới</label>
                    <div class="flex gap-2">
                        <input wire:model="password" x-bind:type="showPassword ? 'text' : 'password'" autocomplete="new-password" class="flex-1 px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" />
                        <x-filament::button type="button" color="gray" size="sm" x-on:click="showPassword = !showPassword"><span x-text="showPassword ? 'Ẩn' : 'Hiện'"></span></x-filament::button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Xác nhận mật khẩu</label>
                    <input wire:model="password_confirmation" x-bind:type="showPassword ? 'text' : 'password'" autocomplete="new-password" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" />
                </div>
                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                    <x-filament::button wire:click="closePasswordModal" color="gray" size="sm">Hủy</x-filament::button>
                    <x-filament::button wire:click="updatePassword" wire:loading.attr="disabled" wire:target="updatePassword" color="primary" size="sm">Lưu mật khẩu</x-filament::button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
