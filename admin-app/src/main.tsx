import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import { App } from './App';
import './styles/admin.css';

function mountVanturaxAdmin(): void {
  const container = document.getElementById('vxf-admin-root');
  if (!container) {
    return;
  }

  const bootstrap = window.vxfAdminData;
  if (!bootstrap) {
    container.innerHTML =
      '<div class="notice notice-error inline"><p>Vanturax Forms bootstrap configuration (vxfAdminData) failed to load.</p></div>';
    return;
  }

  if (!window.wp?.element) {
    container.innerHTML =
      '<div class="notice notice-error inline"><p>WordPress wp.element runtime is unavailable. Ensure WordPress 6.2 or higher is active.</p></div>';
    return;
  }

  if (apiFetch && bootstrap.restNonce) {
    apiFetch.use(apiFetch.createNonceMiddleware(bootstrap.restNonce));
  }

  const appElement = <App bootstrap={bootstrap} />;

  // WordPress 6.2+ ships React 18 with wp.element.createRoot.
  if (typeof createRoot === 'function') {
    const root = createRoot(container);
    root.render(appElement);
    return;
  }

  // Safe fallback if a third-party script overrides wp.element with a legacy build.
  const legacyRender = window.wp.element.render;
  if (typeof legacyRender === 'function') {
    legacyRender(appElement, container);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountVanturaxAdmin);
} else {
  mountVanturaxAdmin();
}
