import { defineConfig, Plugin } from 'vite';
import { resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

/**
 * Vite/Rollup externalization plugin for WordPress 6.2+.
 * Maps React, ReactDOM, react-dom/client, react/jsx-runtime, and @wordpress/api-fetch
 * directly to WordPress core globals (window.wp.element & window.wp.apiFetch)
 * so zero duplicate React runtime is bundled.
 */
function wordpressExternalsPlugin(): Plugin {
  const virtualMap: Record<string, string> = {
    react: `
      const el = (window.wp && window.wp.element) || {};
      export const createElement = el.createElement;
      export const Fragment = el.Fragment;
      export const useState = el.useState;
      export const useEffect = el.useEffect;
      export const useMemo = el.useMemo;
      export const useCallback = el.useCallback;
      export const useRef = el.useRef;
      export const useContext = el.useContext;
      export const createContext = el.createContext;
      export const useReducer = el.useReducer;
      export const useLayoutEffect = el.useLayoutEffect;
      export const memo = el.memo;
      export const forwardRef = el.forwardRef;
      export const Component = el.Component;
      export const StrictMode = el.StrictMode;
      export const Suspense = el.Suspense;
      export default el;
    `,
    'react/jsx-runtime': `
      const el = (window.wp && window.wp.element) || {};
      export const Fragment = el.Fragment;
      export function jsx(type, props, key) {
        const nextProps = key !== undefined ? Object.assign({}, props, { key }) : props;
        return el.createElement(type, nextProps);
      }
      export const jsxs = jsx;
      export default { Fragment: el.Fragment, jsx, jsxs: jsx };
    `,
    'react-dom': `
      const el = (window.wp && window.wp.element) || {};
      export const render = el.render;
      export const unmountComponentAtNode = el.unmountComponentAtNode;
      export const findDOMNode = el.findDOMNode;
      export const createPortal = el.createPortal;
      export const flushSync = el.flushSync;
      export default el;
    `,
    'react-dom/client': `
      const el = (window.wp && window.wp.element) || {};
      export const createRoot = el.createRoot;
      export const hydrateRoot = el.hydrateRoot;
      export default { createRoot: el.createRoot, hydrateRoot: el.hydrateRoot };
    `,
    '@wordpress/api-fetch': `
      const apiFetch = (window.wp && window.wp.apiFetch) || null;
      export default apiFetch;
    `,
  };

  return {
    name: 'vanturax-wordpress-externals',
    enforce: 'pre',
    resolveId(source) {
      if (Object.prototype.hasOwnProperty.call(virtualMap, source)) {
        return `\0wp-external:${source}`;
      }
      return null;
    },
    load(id) {
      if (id.startsWith('\0wp-external:')) {
        const key = id.replace('\0wp-external:', '');
        return virtualMap[key] ?? null;
      }
      return null;
    },
  };
}

export default defineConfig(({ mode }) => {
  const isProd = mode === 'production';

  return {
    plugins: [wordpressExternalsPlugin()],
    build: {
      outDir: resolve(__dirname, 'assets/build'),
      emptyOutDir: true,
      sourcemap: !isProd,
      minify: isProd ? 'esbuild' : false,
      target: 'es2020',
      lib: {
        entry: resolve(__dirname, 'admin-app/src/main.tsx'),
        name: 'VanturaxFormsAdmin',
        formats: ['iife'],
        fileName: () => 'admin.js',
      },
      rollupOptions: {
        output: {
          assetFileNames: (assetInfo) => {
            if (assetInfo.name && assetInfo.name.endsWith('.css')) {
              return 'admin.css';
            }
            return '[name][extname]';
          },
        },
      },
    },
  };
});
