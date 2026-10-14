import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ReactNode } from 'react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import SiteNavigation from './Components/SiteNavigation';

const appName = import.meta.env.VITE_APP_NAME || 'SEMIZZY VTU';

type PageWithLayout = {
  default: React.ComponentType & { layout?: (page: ReactNode) => ReactNode };
};

function restoreTheme() {
  try {
    const savedTheme = window.localStorage.getItem('semizzy-theme');
    if (savedTheme === 'light' || savedTheme === 'dark') {
      document.documentElement.dataset.theme = savedTheme;
    }
  } catch {
    // The app remains usable if browser storage is unavailable.
  }
}

restoreTheme();

createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: async (name) => {
    const page = await resolvePageComponent<PageWithLayout>(
      `./Pages/${name}.tsx`,
      import.meta.glob('./Pages/**/*.tsx'),
    );

    // Dashboard pages already use DashboardLayout, which owns their navigation.
    // Auth screens remain focused on sign-in/registration rather than app navigation.
    if (name === 'Dashboard' || name === 'Admin/Dashboard' || name.startsWith('Auth/')) {
      return page;
    }

    const PageComponent = page.default;
    PageComponent.layout = (content) => (
      <SiteNavigation admin={name.startsWith('Admin/')} publicPage={name === 'Welcome'}>
        {content}
      </SiteNavigation>
    );

    return page;
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
  progress: {
    color: '#635bff',
  },
});
