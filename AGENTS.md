# seo-ops-addons-rpa

Standalone repository for SEO-OPS Social Automation Addon.
Repository: `https://github.com/trung8356621/seo-ops-addons-rpa`
Integrated into SEO-OPS Client via Windows Directory Junction (`D:\work\omnichannel-addons\social-automation`).

## Frontend Asset Architecture & Boundary Rules

1. **Dedicated Addon Vite Boundary**:
   - Social Automation frontend assets MUST remain completely isolated from Core Filament Vite (`public/build`, `public/hot`) and other addons (`build-seeding`, `build-agent`).
   - Production build destination: `public/build-social-automation/` with `manifest.json`.
   - Package fallback mirror: `resources/dist/`.
   - Dev HMR hot file: `public/hot-social-automation` (port `5175`). Never write to `public/hot`.

2. **Asset Resolution via `SocialAutomationVite`**:
   - Blade templates MUST load assets via `app(\Omnichannel\Addons\SocialAutomation\Support\SocialAutomationVite::class)->tags()`.
   - NEVER inject raw inline scripts, hardcode asset hashes, or use `__DIR__` file checks.
   - `SocialAutomationVite` automatically handles stale hot-file detection (verifying dev server heartbeat) and falls back to production `manifest.json`.

3. **Filament / Livewire SPA Lifecycle**:
   - Blade shells hosting React components MUST declare `wire:ignore` and a unique `wire:key` (e.g., `wire:key="action-tags-workspace-shell"`).
   - React mounting scripts MUST attach once to `DOMContentLoaded` and `livewire:navigated` (or `livewire:load`).
   - React roots MUST be reused or unmounted cleanly before remounting (`activeRoot?.unmount()`) to prevent orphaned React trees or memory leaks.

4. **Independent Scoped CSS**:
   - React components MUST rely on scoped CSS (`resources/css/action-tags.css`) bundled through Vite.
   - Do NOT assume Filament Core Tailwind scanner scans files in standalone addon repositories.

5. **Production Deployment**:
   - Production runs 100% serverless on the Node side; NO Node/Vite dev server or runtime process required.
   - Run `npm run build` to compile assets to `public/build-social-automation/` and `resources/dist/`.
   - See detailed documentation in [docs/FRONTEND_ASSET_ARCHITECTURE.md](file:///d:/work-app/new/seo-ops-addons-rpa/docs/FRONTEND_ASSET_ARCHITECTURE.md).

## Mandatory Execution Skills
- `seo-ops-execution-guard`
- `seo-ops-token-discipline`
- `compact-coding`
