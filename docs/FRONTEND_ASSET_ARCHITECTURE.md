# Frontend Asset Architecture: Dedicated Addon Vite Boundary

## 1. Context & Motivation

In the SEO-OPS omnichannel architecture, `seo-ops-addons-rpa` is a standalone repository integrated into the main Laravel application (`omnichannel-client`) via directory junction.

Previously, Action Tag Registry used an ad-hoc single-file inline bundling script (`build-react.cjs`) that:
- Inlined raw JS directly into Blade, bloating HTML responses.
- Lacked CSS preprocessing and hash-based cache-busting.
- Broke on Livewire SPA navigation (`livewire:navigated`) due to unmanaged DOM replacements and duplicate root initialization.
- Couched assumptions on `__DIR__` relative paths which diverged between the repo checkout and the junction link.

To solve this sustainably, Social Automation adopts the **Dedicated Addon Vite Boundary** pattern, aligning with the proven architecture of the `Seeding` addon (`SeedingVite.php`).

---

## 2. Architecture Overview

```
+-------------------------------------------------------------+
|                      Browser / Client                       |
+-------------------------------------------------------------+
       |                                              |
       | 1. HTTP GET (Filament Page)                  | 2. Static Assets (JS / CSS)
       v                                              v
+-----------------------------+           +------------------------------+
| Laravel Host                |           | Nginx / Public Web Directory |
| (omnichannel-client)        |           | /public/build-social-automa..|
|                             |           +------------------------------+
|  Blade: action-tags.blade   |                          ^
|    SocialAutomationVite     |                          |
|         tags()              |                          |
+-----------------------------+                          |
       |                                                 |
       | Read manifest.json                              |
       v                                                 |
+-----------------------------------------------------+  |
| Manifest Contract (public/build-social-automation/) |--+
|   - assets/index-[hash].js                          |
|   - assets/action-tags-[hash].css                   |
+-----------------------------------------------------+
```

### Key Separation of Concerns
1. **Core Laravel Asset Isolation**: Core Vite operates in `public/build/` and `public/hot`. Social Automation strictly confines itself to `public/build-social-automation/` and `public/hot-social-automation`. Neither touches or locks the other's files.
2. **Dedicated Dev Port**: Social Automation Vite dev server runs on port `5175` (while Core runs on `5173` and Seeding on `5174`), eliminating port contention.
3. **No Node Runtime in Production**: Production only requires static file serving from `public/build-social-automation/` by Nginx/Apache.

---

## 3. Core Components

### 3.1. `SocialAutomationVite.php` (`src/Support/SocialAutomationVite.php`)
Acts as the asset gateway inside Laravel:
- **`tags()`**: Emits either Vite HMR `<script type="module">` tags (with React Refresh preamble) or production `<script>` and `<link rel="stylesheet">` tags.
- **Stale Hot File Protection**: Verifies that the server listed in `public/hot-social-automation` is actually alive using a rapid HTTP header check (0.5s timeout). If the dev server was shut down but the hot file was left behind, it automatically falls back to production assets without throwing errors.
- **Defensive Public Path Resolution**: Resolves paths cleanly across standalone repo paths, junctions, and bare PHPUnit unit tests without failing when `Container` lacks `publicPath`.

### 3.2. Scoped CSS (`resources/css/action-tags.css`)
- Contains all required layout, badge, button, and table styling under `.action-tags-workspace`.
- Does not rely on Filament Core's Tailwind scanner, guaranteeing styles remain intact regardless of host Tailwind configurations.

### 3.3. React Lifecycle & SPA Navigation (`resources/js/action-tags/index.tsx`)
Filament v3 leverages Livewire SPA navigation (`wire:navigate`). To ensure seamless mounting across page switches without DOM conflicts:
- **`wire:ignore` & `wire:key`**: Declared on the mount container to prevent Livewire DOM diffing from trampling the React tree.
- **Root Management**: Maintains a reference to `activeRoot`. If Livewire navigates away or DOM changes, the active root is cleanly unmounted (`activeRoot.unmount()`) before mounting a new instance.
- **Event Listeners**: Subscribes to `DOMContentLoaded` and `livewire:navigated` (and fallback `livewire:load`) with deduplication guards to prevent multiple triggers.

---

## 4. Development & Build Commands

All build scripts are defined in `package.json`:

```bash
# In seo-ops-addons-rpa directory:

# 1. Production Build (Compiles to public/build-social-automation and resources/dist)
npm run build

# 2. Local Dev Server with HMR (Starts on port 5175, writes hot-social-automation)
npm run dev
```

---

## 5. Production Deployment Guide

1. **Building Assets**:
   Run `npm run build` during CI/CD or packaging. This generates:
   - `D:\work\omnichannel-client\public\build-social-automation\manifest.json`
   - `D:\work\omnichannel-client\public\build-social-automation\assets\index-[hash].js`
   - `D:\work\omnichannel-client\public\build-social-automation\assets\action-tags-[hash].css`
   - A mirror copy in `resources/dist/` inside the addon repository.

2. **Publishing to Web Server**:
   Ensure `public/build-social-automation/` is published to the web root of the target Laravel host (`public/` directory).

3. **Verifying Zero Node Dependency**:
   - Delete `public/hot-social-automation` if it exists.
   - Access the Action Tags page `/social-automation/action-tags`.
   - All JS and CSS requests return HTTP 200 with proper cache headers.
