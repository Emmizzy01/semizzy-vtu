import { useEffect, useState, type ReactNode } from 'react';
import ThemeToggle from './ThemeToggle';
import MobileAppHeader from './MobileAppHeader';
import { applyThemePreference } from './theme';
import { Head, usePage } from '@inertiajs/react';
import {
  Activity,
  ArrowLeftRight,
  CreditCard,
  LifeBuoy,
  LayoutDashboard,
  LogOut,
  Menu,
  Package,
  PlugZap,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  Smartphone,
  Users,
  UserRound,
  WalletCards,
  X,
} from 'lucide-react';

type DashboardLayoutProps = {
  title: string;
  description: string;
  admin?: boolean;
  children: ReactNode;
  canManageStaff?: boolean;
  canApproveRefunds?: boolean;
  customerPreview?: boolean;
};

type NavItem = { label: string; href: string; icon: typeof LayoutDashboard; match?: string };

export default function DashboardLayout({
  title,
  description,
  admin = false,
  children,
  customerPreview = false,
}: DashboardLayoutProps) {
  const { platform, auth } = usePage<{ platform?: { name?: string; theme_mode?: 'light' | 'dark'; skin?: 'modern-green' | 'navy-teal' | 'minimal-blue' | 'obsidian-luxe' | 'arctic-light' }; auth?: { user?: { name?: string; email?: string; tier?: string; level?: string; user_level?: string; user_type?: string; avatar_url?: string | null; avatarUrl?: string | null } | null } }>().props;
  const currentUser = auth?.user;
  const userName = currentUser?.name?.trim() || 'Semizzy user';
  const rawUserTier = currentUser?.tier || currentUser?.user_level || currentUser?.level || currentUser?.user_type || 'Customer';
  const userTier = ({ level_1: 'Tier1', level_2: 'Tier2', level_3: 'Tier3', level_4: 'Tier4/Merchant', tier1: 'Tier1', tier2: 'Tier2', tier3: 'Tier3', tier4: 'Tier4/Merchant' } as Record<string, string>)[rawUserTier.toLowerCase()] || rawUserTier.replace(/_/g, ' ');
  const avatarUrl = currentUser?.avatar_url || currentUser?.avatarUrl || null;
  const userInitials = userName.split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'S';
  const platformName = platform?.name || 'SEMIZZY';
  const globalThemeMode = platform?.theme_mode === 'dark' ? 'dark' : 'light';
  useEffect(() => { applyThemePreference(globalThemeMode); }, [globalThemeMode]);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [location, setLocation] = useState(() => ({
    path: typeof window === 'undefined' ? '' : window.location.pathname,
    hash: typeof window === 'undefined' ? '' : window.location.hash,
  }));
  useEffect(() => {
    const syncLocation = () => setLocation({ path: window.location.pathname, hash: window.location.hash });
    window.addEventListener('hashchange', syncLocation);
    window.addEventListener('popstate', syncLocation);
    return () => {
      window.removeEventListener('hashchange', syncLocation);
      window.removeEventListener('popstate', syncLocation);
    };
  }, []);
  useEffect(() => {
    if (!mobileMenuOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMobileMenuOpen(false);
    };
    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [mobileMenuOpen]);
  const currentPath = location.path;
  const currentHash = location.hash;
  const overviewHref = customerPreview ? '/customer/dashboard' : admin ? '/admin/dashboard' : '/dashboard';

  // Keep the fixed customer footer to exactly five primary destinations.
  const customerItems: NavItem[] = [
    { label: 'Home', href: customerPreview ? '/customer/dashboard' : '/dashboard', icon: LayoutDashboard, match: customerPreview ? '/customer/dashboard' : '/dashboard' },
    { label: 'Orders', href: customerPreview ? '/customer/dashboard#orders' : '/dashboard#orders', icon: Package, match: '#orders' },
    { label: 'Wallet', href: '/wallet/manual-deposits', icon: WalletCards, match: '/wallet/manual-deposits' },
    { label: 'Support', href: '/support', icon: LifeBuoy, match: '/support' },
    { label: 'Profile', href: '/profile', icon: UserRound, match: '/profile' },
  ];
  // The full customer sidebar/mobile drawer exposes secondary account tools without
  // overcrowding the five-item fixed footer.
  const customerSidebarItems: NavItem[] = [
    ...customerItems,
    { label: 'Identity verification', href: '/account/kyc', icon: ShieldCheck, match: '/account/kyc' },
    { label: 'Account levels', href: '/account/tiers', icon: ShieldCheck, match: '/account/tiers' },
    { label: 'Transaction PIN', href: '/security/transaction-pin', icon: ShieldCheck, match: '/security/transaction-pin' },
    { label: 'Sessions & devices', href: '/account/sessions', icon: Smartphone, match: '/account/sessions' },
    { label: 'Business workspaces', href: '/workspaces', icon: Users, match: '/workspaces' },
    { label: 'Help & support', href: '/support', icon: LifeBuoy, match: '/support' },
  ];

  const adminGroups: { title: string; items: NavItem[] }[] = [
    { title: 'Workspace', items: [{ label: 'Command Center', href: '/admin/dashboard', icon: LayoutDashboard, match: '/admin/dashboard' }] },
    { title: 'Finance', items: [
      { label: 'Refund operations', href: '/admin/refunds', icon: CreditCard, match: '/admin/refunds' },
      { label: 'Manual deposits', href: '/admin/manual-deposits', icon: WalletCards, match: '/admin/manual-deposits' },
      { label: 'Payment gateway & deposit settings', href: '/admin/payment-gateway', icon: CreditCard, match: '/admin/payment-gateway' },
    ] },
    { title: 'Operations', items: [
      { label: 'VTU order queue', href: '/admin/vtu-orders', icon: Smartphone, match: '/admin/vtu-orders' },
      { label: 'VTU product catalogue', href: '/admin/vtu-products', icon: Package, match: '/admin/vtu-products' },
      { label: 'KYC evidence review', href: '/admin/kyc-reviews', icon: ShieldCheck, match: '/admin/kyc-reviews' },
      { label: 'Support tickets', href: '/admin/support', icon: LifeBuoy, match: '/admin/support' },
      { label: 'Users, types & levels', href: '/admin/users', icon: Users, match: '/admin/users' },
    ] },
    { title: 'Platform & security', items: [
      { label: 'API providers', href: '/admin/api-providers', icon: PlugZap, match: '/admin/api-providers' },
      { label: 'Staff & permissions', href: '/admin/staff', icon: Users, match: '/admin/staff' },
      { label: 'Site control & settings', href: '/admin/platform-settings', icon: SlidersHorizontal, match: '/admin/platform-settings' },
      { label: 'Feature controls', href: '/admin/feature-controls', icon: SlidersHorizontal, match: '/admin/feature-controls' },
      { label: 'Staff users', href: '/admin/staff/users', icon: Users, match: '/admin/staff/users' },
      { label: 'Permission audits', href: '/admin/staff/permission-audits', icon: Activity, match: '/admin/staff/permission-audits' },
      { label: 'User level policies', href: '/admin/user-levels', icon: ShieldCheck, match: '/admin/user-levels' },
      { label: 'Tier upgrade requests', href: '/admin/user-level-upgrade-requests', icon: ShieldCheck, match: '/admin/user-level-upgrade-requests' },
      { label: 'Security activity', href: '/admin/security-events', icon: Activity, match: '/admin/security-events' },
    ] },
    { title: 'Customer', items: [
      { label: 'Profile & password', href: '/profile', icon: UserRound, match: '/profile' },
      { label: 'Help & support', href: '/support', icon: LifeBuoy, match: '/support' },
      { label: 'VTU services', href: '/wallet/vtu', icon: Smartphone, match: '/wallet/vtu' },
      { label: 'Customer view', href: '/customer/dashboard', icon: WalletCards, match: '/customer/dashboard' },
    ] },
  ];

  const isActive = (item: NavItem) => {
    if (item.match?.startsWith('#')) return (currentPath === '/dashboard' || currentPath === '/customer/dashboard') && currentHash === item.match;
    if (item.href.includes('#')) return (currentPath === '/dashboard' || currentPath === '/customer/dashboard') && currentHash === item.href.split('#')[1] ? true : false;
    if (item.href === '/dashboard' || item.href === '/admin/dashboard' || item.href === '/customer/dashboard') return currentPath === item.href;
    return item.match === '/wallet/vtu' ? currentPath.startsWith('/wallet/vtu') : item.match ? currentPath === item.match : currentPath === item.href;
  };

  const linkClass = (active: boolean) =>
    `group flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] ${active
      ? 'bg-[var(--brand-soft)] font-bold text-[var(--brand)]'
      : 'font-semibold text-[var(--muted)] hover:bg-[var(--page)] hover:text-[var(--ink)]'}`;

  const renderLink = (item: NavItem, compact = false) => {
    const active = isActive(item);
    const Icon = item.icon;
    return (
      <a
        key={item.href}
        href={item.href}
        onClick={() => setMobileMenuOpen(false)}
        aria-current={active ? 'page' : undefined}
        className={compact
          ? `flex min-h-14 min-w-[68px] flex-1 shrink-0 flex-col items-center justify-center gap-1 rounded-xl px-1 text-[10px] transition hover:bg-[var(--page)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--brand)] ${active ? 'font-extrabold text-[var(--brand)]' : 'font-semibold text-[var(--muted)]'}`
          : linkClass(active)}
      >
        <Icon size={compact ? 19 : 18} className="shrink-0" aria-hidden="true" />
        <span className={compact ? 'max-w-full truncate' : 'min-w-0 flex-1'}>{item.label}</span>
        {!compact && active && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]" aria-hidden="true" />}
      </a>
    );
  };

  return (
    <>
      <Head title={title} />
      <main className="min-h-screen bg-[var(--page)] pb-24 text-[var(--ink)]">
        <MobileAppHeader admin={admin} customerPreview={customerPreview} />
        {<header className="sticky top-0 z-40 hidden border-b border-[var(--line)] bg-[var(--surface)]/95 backdrop-blur sm:block">
          <div className="mx-auto flex max-w-[1600px] items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            {!admin && !customerPreview ? <a href="/profile" className="flex min-w-0 items-center gap-3" aria-label="Open profile and account level">
              <span className="relative grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full border-2 border-white bg-emerald-100 text-sm font-extrabold text-emerald-800 shadow-sm dark:border-slate-800 dark:bg-emerald-950 dark:text-emerald-200">
                {avatarUrl ? <img src={avatarUrl} alt="" className="h-full w-full object-cover" /> : userInitials}
                <span className="absolute -bottom-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full border border-white bg-amber-100 px-1 text-[8px] font-black text-amber-900 dark:border-slate-900" title={userTier}>{userTier.slice(0, 1).toUpperCase()}</span>
              </span>
              <span className="min-w-0"><span className="block text-[10px] font-semibold uppercase tracking-wide text-[var(--muted)]">Welcome back</span><span className="block max-w-[38vw] truncate text-sm font-extrabold sm:max-w-none">{userName}</span><span className="block truncate text-[10px] font-bold text-[var(--brand)]">{userTier}</span></span>
            </a> : <a href={overviewHref} className="flex min-w-0 items-center gap-3" aria-label={`${platformName} dashboard`}>
              <span aria-hidden="true" className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span className="min-w-0"><span className="block font-display text-base font-extrabold tracking-tight">{platformName}</span><span className="block text-[10px] font-bold uppercase tracking-[0.18em] text-[var(--muted)]">{admin ? 'Command Center' : 'Customer preview'}</span></span>
            </a>}
            {admin && (
              <form action="/admin/search" method="get" className="hidden max-w-sm flex-1 px-6 lg:block">
                <label className="flex h-10 items-center gap-2 rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 text-sm text-[var(--muted)]">
                  <Search size={16} aria-hidden="true" /><input name="q" minLength={2} maxLength={100} required aria-label="Search admin records" placeholder="Global search…" className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-[var(--muted)]" />
                  <button type="submit" className="rounded-md border border-[var(--line)] px-2 py-1 text-[10px] font-bold">Search</button>
                </label>
              </form>
            )}
            <div className="flex shrink-0 items-center gap-2">
              {admin && <a href="/customer/dashboard" className="hidden min-h-10 items-center gap-1 rounded-xl border border-[var(--line)] px-3 py-2 text-xs font-semibold hover:border-[var(--brand)] sm:inline-flex"><ArrowLeftRight size={14} aria-hidden="true" /> Customer view</a>}
              {admin && <ThemeToggle themeMode={globalThemeMode} />}
              <form method="post" action="/logout">
                <input type="hidden" name="_token" value={typeof document === 'undefined' ? '' : (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '')} />
                <button type="submit" aria-label="Sign out" className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-3 text-sm font-bold text-white hover:opacity-90">
                  <LogOut size={15} aria-hidden="true" /><span className="hidden sm:inline">Sign out</span>
                </button>
              </form>
              <button type="button" onClick={() => setMobileMenuOpen((open) => !open)} aria-label={mobileMenuOpen ? 'Close navigation menu' : 'Open navigation menu'} aria-expanded={mobileMenuOpen} aria-controls="semizzy-mobile-navigation" className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-[var(--line)] bg-[var(--surface)] hover:border-[var(--brand)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2 lg:hidden">
                {mobileMenuOpen ? <X size={20} aria-hidden="true" /> : <Menu size={20} aria-hidden="true" />}
              </button>
            </div>
          </div>
        </header>}

        {mobileMenuOpen && (
          <nav id="semizzy-mobile-navigation" aria-label="Mobile navigation menu" className="border-b border-[var(--line)] bg-[var(--surface)] px-4 py-4 lg:hidden">
            <div className="mx-auto grid max-w-7xl gap-1">
              {admin
                ? adminGroups.map((group) => (
                    <section key={group.title} className="py-1">
                      <p className="px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.16em] text-[var(--muted)]">{group.title}</p>
                      {group.items.map((item) => renderLink(item))}
                    </section>
                  ))
                : <>{customerSidebarItems.map((item) => renderLink(item))}{customerPreview && <a href="/admin/dashboard" className="mt-3 flex min-h-11 items-center gap-3 rounded-xl border border-[var(--line)] px-3 py-2.5 text-sm font-bold text-[var(--brand)] hover:bg-[var(--page)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"><LayoutDashboard size={18} aria-hidden="true" /><span>Back to Command Center</span></a>}</>}
            </div>
          </nav>
        )}

        <div className={`mx-auto grid max-w-[1600px] lg:grid-cols-[250px_minmax(0,1fr)]`}>
          {<aside className="sticky top-[65px] hidden h-[calc(100vh-65px)] overflow-y-auto border-r border-[var(--line)] bg-[var(--surface)] px-4 py-6 lg:block">
            {admin ? adminGroups.map((group) => (
              <section key={group.title} className="mb-6">
                <p className="mb-2 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-[var(--muted)]">{group.title}</p>
                <div className="space-y-1">{group.items.map((item) => renderLink(item))}</div>
              </section>
            )) : (
              <>
                <p className="mb-2 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-[var(--muted)]">Your account</p>
                <div className="space-y-1">{customerSidebarItems.map((item) => renderLink(item))}</div>
                {customerPreview && <a href="/admin/dashboard" className="mt-4 flex min-h-11 items-center gap-3 rounded-xl border border-[var(--line)] px-3 py-2.5 text-sm font-bold text-[var(--brand)] hover:bg-[var(--page)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"><LayoutDashboard size={18} aria-hidden="true" /><span>Back to Command Center</span></a>}
                <div className="mt-6 rounded-2xl border border-[var(--line)] bg-[var(--page)] p-4">
                  <p className="text-sm font-bold">Need help?</p>
                  <p className="mt-1 text-xs leading-5 text-[var(--muted)]">Keep your transaction reference handy when contacting support.</p>
                </div>
              </>
            )}
          </aside>}

          <div className="min-w-0 px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
            {(admin || customerPreview) && <div className="mb-7">
              <p className="text-xs font-extrabold uppercase tracking-[0.2em] text-[var(--brand)]">{admin ? 'Operations workspace' : 'Customer preview'}</p>
              <h1 className="mt-2 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{title}</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)] sm:text-base">{description}</p>
            </div>}
            {children}
            <footer className="mt-10 border-t border-[var(--line)] py-5 text-center text-xs text-[var(--muted)]">{platformName} · Secure, mobile-first digital services</footer>
          </div>
        </div>

        {!admin && <nav aria-label="Main navigation" className="fixed inset-x-0 bottom-0 z-50 flex justify-around gap-1 border-t border-[var(--line)] bg-[var(--surface)]/95 px-2 pb-[env(safe-area-inset-bottom)] pt-2 backdrop-blur" style={{ scrollbarWidth: "thin" }}>
          {customerItems.map((item) => renderLink(item, true))}
        </nav>}
      </main>
    </>
  );
}
