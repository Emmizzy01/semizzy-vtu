import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType, ReactNode } from 'react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import SiteNavigation from './Components/SiteNavigation';
import { applyThemePreference, readThemePreference } from './Components/theme';

const appName = import.meta.env.VITE_APP_NAME || 'SEMIZZY VTU';

type PageWithLayout = {
  default: ComponentType<any> & { layout?: (page: ReactNode) => ReactNode };
};

applyThemePreference(readThemePreference());

createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: async (name) => {
    const page = await resolvePageComponent<PageWithLayout>(
      `./Pages/${name}.tsx`,
      import.meta.glob('./Pages/**/*.tsx') as Record<string, () => Promise<PageWithLayout>>,
    );

    // These pages own their complete layout. Wrapping them in SiteNavigation
    // duplicates headers and mobile navigation, which can crowd or obscure auth forms.
    if (name === 'Dashboard' || name === 'Admin/Dashboard' || name === 'Welcome' || name.startsWith('Auth/') || name === 'Wallet/VtuPurchase') {
      return page;
    }

    const PageComponent = page.default;
    PageComponent.layout = (content) => (
      <SiteNavigation admin={name.startsWith('Admin/')} publicPage={name === 'Welcome' || name.startsWith('Auth/')}>
        {content}
      </SiteNavigation>
    );

    return page;
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
  progress: {
    color: '#087443',
  },
});
