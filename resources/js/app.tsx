import '../css/app.css';

import { createInertiaApp, usePage } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { useEffect, type ComponentType, type ReactNode } from 'react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import SiteNavigation from './Components/SiteNavigation';
import { applyThemePreference } from './Components/theme';

const appName = import.meta.env.VITE_APP_NAME || 'SEMIZZY VTU';

type PageWithLayout = {
  default: ComponentType<any> & { layout?: (page: ReactNode) => ReactNode };
};

function GlobalThemeSync({ children }: { children: ReactNode }) {
  const { platform } = usePage<{ platform?: { theme_mode?: 'light' | 'dark' } }>().props;
  const mode = platform?.theme_mode === 'dark' ? 'dark' : 'light';
  useEffect(() => { applyThemePreference(mode); }, [mode]);
  return <>{children}</>;
}

createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: async (name) => {
    const page = await resolvePageComponent<PageWithLayout>(
      `./Pages/${name}.tsx`,
      import.meta.glob('./Pages/**/*.tsx') as Record<string, () => Promise<PageWithLayout>>,
    );

    // These pages own their complete layout. Wrapping them in SiteNavigation
    // duplicates headers and mobile navigation, which can crowd or obscure auth forms.
    const PageComponent = page.default;
    if (name === 'Dashboard' || name === 'Admin/Dashboard' || name === 'Welcome' || name.startsWith('Auth/') || name === 'Wallet/VtuPurchase') {
      PageComponent.layout = (content) => <GlobalThemeSync>{content}</GlobalThemeSync>;
      return page;
    }

    PageComponent.layout = (content) => (
      <GlobalThemeSync>
        <SiteNavigation admin={name.startsWith('Admin/')} publicPage={name === 'Welcome' || name.startsWith('Auth/')}>
          {content}
        </SiteNavigation>
      </GlobalThemeSync>
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
