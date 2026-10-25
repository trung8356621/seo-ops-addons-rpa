import React from 'react';
import { createRoot, Root } from 'react-dom/client';
import { App } from './App';

let activeRoot: Root | null = null;
let activeMountEl: HTMLElement | null = null;

export function mountActionRegistry(): void {
  const rootEl = document.getElementById('action-registry-root');

  // If container is not on the page, unmount stale root if it exists
  if (!rootEl) {
    if (activeRoot && activeMountEl && !document.body.contains(activeMountEl)) {
      try {
        activeRoot.unmount();
      } catch {
        /* ignore unmount failure */
      }
      activeRoot = null;
      activeMountEl = null;
    }
    return;
  }

  // If already mounted on this exact element, do not create duplicate roots
  if (activeRoot && activeMountEl === rootEl) {
    return;
  }

  // If mounted on an old element replaced by Livewire navigation, unmount old root
  if (activeRoot && activeMountEl && activeMountEl !== rootEl) {
    try {
      activeRoot.unmount();
    } catch {
      /* ignore unmount failure */
    }
    activeRoot = null;
    activeMountEl = null;
  }

  const rawInitial = rootEl.getAttribute('data-initial-data') || '{}';
  const apiBase = rootEl.getAttribute('data-api-base') || '/social-automation/api/action-registry';
  const csrfToken = rootEl.getAttribute('data-csrf') || '';

  let initialData = { sites: [], paths: [], actions: [], tags: [], requests: [] };
  try {
    initialData = JSON.parse(rawInitial);
  } catch (e) {
    console.error('[ActionRegistry] Failed to parse initial data', e);
  }

  const root = createRoot(rootEl);
  activeRoot = root;
  activeMountEl = rootEl;

  root.render(
    <React.StrictMode>
      <App initialData={initialData} apiBase={apiBase} csrfToken={csrfToken} />
    </React.StrictMode>
  );
}

// Single-registration guard to avoid accumulating duplicate event listeners
const WIN = window as unknown as { __sa_registry_listener_attached?: boolean };
if (!WIN.__sa_registry_listener_attached) {
  WIN.__sa_registry_listener_attached = true;

  // 1. Direct page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountActionRegistry);
  }

  // 2. Filament / Livewire SPA navigation
  document.addEventListener('livewire:navigated', mountActionRegistry);
  document.addEventListener('livewire:load', mountActionRegistry);
}

// Immediate invocation for cases where script is loaded after DOM is ready
mountActionRegistry();
