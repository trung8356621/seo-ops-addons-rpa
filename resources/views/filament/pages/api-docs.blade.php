<x-filament-panels::page>
    <div class="space-y-4 max-w-3xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Social Automation is API-first. Admin Studio UI lives in RPA Admin.
            Use Swagger UI for contract inspection (SEO-OPS admin/owner session required).
        </p>
        <div class="flex flex-wrap gap-3">
            <x-filament::button tag="a" :href="$this->getDocsUrl()" target="_blank" color="primary" size="sm">
                Open Swagger UI
            </x-filament::button>
            <x-filament::button tag="a" :href="$this->getOpenApiYamlUrl()" target="_blank" color="gray" size="sm">
                OpenAPI YAML
            </x-filament::button>
            <x-filament::button tag="a" :href="$this->getOpenApiJsonUrl()" target="_blank" color="gray" size="sm">
                OpenAPI JSON
            </x-filament::button>
        </div>
        <ul class="text-sm text-gray-600 dark:text-gray-300 list-disc pl-5 space-y-1">
            <li>RPA Admin: <code>/api/v1/social-automation/rpa-admin/*</code></li>
            <li>RPA Client: <code>/api/v1/social-automation/client/*</code></li>
            <li>Service API: <code>/api/v1/services/{service}/social-automation/*</code></li>
        </ul>
    </div>
</x-filament-panels::page>
