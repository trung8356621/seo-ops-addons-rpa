@use('Omnichannel\Addons\SocialAutomation\Support\SocialAutomationVite')

<x-filament-panels::page>
    @php
        $initial = $this->initialData();
        $apiBase = url('/social-automation/api/action-registry');
    @endphp

    <div class="action-tags-workspace" wire:key="action-tags-workspace-shell">
        <div
            wire:ignore
            id="action-registry-root"
            data-api-base="{{ $apiBase }}"
            data-csrf="{{ csrf_token() }}"
            data-initial-data='@json($initial, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)'
            class="w-full"
        >
            <div class="py-12 text-center text-sm text-gray-400">
                Đang nạp Action Registry...
            </div>
        </div>

        {!! app(SocialAutomationVite::class)->tags() !!}
    </div>
</x-filament-panels::page>
