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

## Global UI & Component Conventions

All UI development within this addon must strictly adhere to the following 12 rules:

1. **SaaS compact, data-first style**: Design interfaces for density and efficiency; maximize data visibility without bloated white space.
2. **Single primary action per zone**: Exactly one primary action button per header, modal, or functional region.
3. **Guaranteed button contrast**: Use native Filament components (`<x-filament::button color="primary">`, `color="gray"`, etc.) that leverage theme CSS variables. Avoid ad-hoc utility classes (`bg-teal-600`) uncompiled by the host Vite build. Buttons must provide distinct hover, focus, disabled, and loading states without blending into the background.
4. **Visible modal actions**: Modals must always display explicit Submit (`color="primary"`) and Cancel (`color="gray"`) buttons with clear hierarchy and proper wire targets (`wire:loading.attr="disabled"`).
5. **Constrained icon & SVG sizing**: Never rely on uncompiled Tailwind dimensions alone (`w-12 h-12`). Constrain all inline SVGs and Heroicons via explicit width/height inline styles or wrapper containers (`max-w-[56px]`, `max-h-[56px]`) to prevent SVGs from stretching to full container width.
6. **Compact empty states**:
   - Container padding: 24–32px (`p-6` to `p-7`), centered max width (`max-w-xl`).
   - Icon size: 40–56px circle with a 20–24px SVG centered inside.
   - Concise title (1 line) and brief description (1–2 lines).
   - Single compact CTA button (`size="xs"` or `size="sm"`).
   - Do not render towering empty state containers spanning hundreds of pixels.
7. **Data-first tables**: Optimize row height and cell padding (`px-4 py-3`); avoid excessive cell padding that forces vertical scrolling.
8. **Flat hierarchy**: Avoid nesting card-inside-card unless visually separating structurally distinct contexts.
9. **Scoped selectors only**: Never use overly broad global CSS selectors that can leak into or override Filament host components.
10. **Filament component reuse**: Always reuse Filament primitives (`<x-filament-panels::page>`, `<x-filament::button>`, `<x-filament::input>`, `<x-filament::modal>`) rather than rolling uncompiled raw HTML buttons.
11. **Responsive viewports**: Ensure full functional compatibility across desktop (~1440px), laptop (~1280px), and mobile viewports.
12. **Feedback states**: Every Create/Edit/Action workflow must provide immediate loading (`wire:loading`), success notifications, and inline validation error indicators.

## Mandatory Execution Skills
- `seo-ops-execution-guard`
- `seo-ops-token-discipline`
- `compact-coding`
