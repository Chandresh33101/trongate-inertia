import { createInertiaApp } from '@inertiajs/react';
// import { createRoot } from 'react-dom/client';
import ErrorBoundary from './Components/ErrorBoundary.jsx';
import AppLayout from './Layouts/AppLayout.jsx';
// Lazy glob: Vite emits one chunk per page file, loaded on navigation. This should auto code split all pages, and allow for separate chunks to be loaded on demand, this keeps the app small.
const pages = import.meta.glob('./Pages/**/*.jsx');

// Read the initial page from either format Inertia has used.
function readInitialPage() {
  const el = document.getElementById('app');
  if (el?.dataset.page) return JSON.parse(el.dataset.page);

  const script = document.querySelector('script[data-page="app"][type="application/json"]');
  if (script?.textContent) return JSON.parse(script.textContent);

  throw new Error('Inertia initial page data not found.');
}

createInertiaApp({
  title: (title) => (title ? `${title} - Trongate v2` : "Trongate v2"),
  layout: (name) => {
    switch (true) {
        case name === 'welcome':
            return null;
        case name.startsWith('Demo/'):
            return [AppLayout];
    }
  },
  id: 'app',
  page: readInitialPage(),
  resolve: (name) => {
    const loader = pages[`./Pages/${name}.jsx`];
    if (!loader) throw new Error(`Inertia page not found: ${name}`);
    return loader();
  },
  withApp(app) {
    return (
      <ErrorBoundary>
        {app}
      </ErrorBoundary>
    );
  },
  /*setup({ el, App, props }) {
    createRoot(el).render(
      <ErrorBoundary>
        <App {...props} />
      </ErrorBoundary>
    );
  },*/
  progress: { color: '#4b5563' },
});