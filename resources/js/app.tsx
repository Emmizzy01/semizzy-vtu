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
  const { platform } = usePage<{ platform?: { theme_mode?: 'light' | 'dark'; skin?: string } }>().props;
  const mode = platform?.theme_mode === 'dark' ? 'dark' : 'light';
  const skin = platform?.skin || 'modern-green';
  useEffect(() => {
    applyThemePreference(mode);
    if (skin === 'modern-green') document.documentElement.removeAttribute('data-brand');
    else document.documentElement.dataset.brand = skin;
  }, [mode, skin]);
  return <>{children}</>;
}

createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: async (name) => {
    const page = await resolvePageComponent<PageWithLayout>(
      `./Pages/${name}.tsx`,
      import.meta.glob('./Pages/**/*.tsx') as Record<string, () => Promise<PageWithLayout>>,
    );

    const PageComponent = page.default;

    // These page families render their own complete dashboard/header layout.
    // Wrapping them in SiteNavigation creates two stacked headers and duplicate
    // navigation. Keep the global theme synchronizer, but only one layout owner.
    const ownsDashboardLayout =
      name === 'Dashboard' ||
      name === 'Admin/Dashboard' ||
      name.startsWith('Admin/') ||
      name.startsWith('Account/') ||
      name.startsWith('Security/') ||
      name.startsWith('Services/') ||
      name.startsWith('Workspaces/') ||
      name.startsWith('Support/') ||
      name === 'Wallet/ManualDeposit' ||
      name === 'Wallet/VtuPurchase';

    // Public/auth pages and dashboard-layout pages own their complete layout.
    if (ownsDashboardLayout || name === 'Welcome' || name.startsWith('Auth/')) {
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
