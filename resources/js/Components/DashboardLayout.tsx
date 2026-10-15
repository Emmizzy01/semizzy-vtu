import { useEffect, useState, type ReactNode } from 'react';
import ThemeToggle from './ThemeToggle';
import { Head, usePage } from '@inertiajs/react';
import {
  Activity,
  ArrowLeftRight,
  CreditCard,
  Grid2X2,
  Gift,
  LifeBuoy,
  LayoutDashboard,
  LogOut,
  Menu,
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
  canManageStaff = false,
  canApproveRefunds = false,
  customerPreview = false,
}: DashboardLayoutProps) {
  const { platform } = usePage<{ platform?: { name?: string; skin?: 'modern-green' | 'navy-teal' | 'minimal-blue' | 'obsidian-luxe' | 'arctic-light' } }>().props;
  const platformName = platform?.name || 'SEMIZZY';
  useEffect(() => {
    const skin = platform?.skin || 'emerald';
    if (skin === 'modern-green') document.documentElement.removeAttribute('data-brand');
    else document.documentElement.dataset.brand = skin;
  }, [platform?.skin]);
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

  const customerItems: NavItem[] = [
    { label: 'Home', href: customerPreview ? '/customer/dashboard' : '/dashboard', icon: LayoutDashboard, match: customerPreview ? '/customer/dashboard' : '/dashboard' },
    { label: 'Services', href: '/services', icon: Grid2X2, match: '/services' },
    { label: 'Rewards', href: customerPreview ? '/customer/dashboard#rewards' : '/dashboard#rewards', icon: Gift, match: '#rewards' },
    { label: 'Wallet', href: customerPreview ? '/customer/dashboard#fund-wallet' : '/dashboard#fund-wallet', icon: WalletCards, match: '#fund-wallet' },
    { label: 'Profile', href: '/profile', icon: UserRound, match: '/profile' },
  ];

  const adminGroups: { title: string; items: NavItem[] }[] = [
    { title: 'Workspace', items: [{ label: 'Command Center', href: '/admin/dashboard', icon: LayoutDashboard, match: '/admin/dashboard' }] },
    ...(canApproveRefunds ? [{ title: 'Finance', items: [{ label: 'Refund operations', href: '/admin/refunds', icon: CreditCard, match: '/admin/refunds' }] }] : []),
    ...(canManageStaff ? [{ title: 'Operations', items: [{ label: 'VTU order queue', href: '/admin/vtu-orders', icon: Smartphone, match: '/admin/vtu-orders' },
      { label: 'Support tickets', href: '/admin/support', icon: LifeBuoy, match: '/admin/support' },
      { label: 'Manual deposits', href: '/admin/manual-deposits', icon: WalletCards, match: '/admin/manual-deposits' }] }] : []),
    ...(canManageStaff ? [{ title: 'Platform', items: [
      { label: 'Payment gateway & manual deposits', href: '/admin/payment-gateway', icon: CreditCard, match: '/admin/payment-gateway' },
      { label: 'API providers', href: '/admin/api-providers', icon: PlugZap, match: '/admin/api-providers' },
      { label: 'Staff & permissions', href: '/admin/staff', icon: Users, match: '/admin/staff' },
      { label: 'Site control & settings', href: '/admin/platform-settings', icon: SlidersHorizontal, match: '/admin/platform-settings' },
      { label: 'Feature controls', href: '/admin/feature-controls', icon: SlidersHorizontal, match: '/admin/feature-controls' },
      { label: 'Staff users', href: '/admin/staff/users', icon: Users, match: '/admin/staff/users' },
      { label: 'Permission audits', href: '/admin/staff/permission-audits', icon: Activity, match: '/admin/staff/permission-audits' },
      { label: 'User level policies', href: '/admin/user-levels', icon: ShieldCheck, match: '/admin/user-levels' },
      { label: 'Tier upgrade requests', href: '/admin/user-level-upgrade-requests', icon: ShieldCheck, match: '/admin/user-level-upgrade-requests' },
      { label: 'Security activity', href: '/admin/security-events', icon: Activity, match: '/admin/security-events' },
    ] }] : []),
    { title: 'Customer', items: [
      { label: 'Profile & password', href: '/profile', icon: UserRound, match: '/profile' },
      { label: 'Help & support', href: '/support', icon: LifeBuoy, match: '/support' },
      { label: 'VTU services', href: '/wallet/vtu', icon: Smartphone, match: '/wallet/vtu' },
      { label: 'Customer view', href: '/customer/dashboard', icon: WalletCards, match: '/customer/dashboard' },
      ...(canManageStaff ? [{ label: 'Users, types & levels', href: '/admin/users', icon: Users, match: '/admin/users' }] : []),
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

  const mobileItems = admin
    ? [
        { label: 'Home', href: '/admin/dashboard', icon: LayoutDashboard, match: '/admin/dashboard' },
        ...(canApproveRefunds ? [{ label: 'Refunds', href: '/admin/refunds', icon: CreditCard, match: '/admin/refunds' }] : []),
        ...(canManageStaff ? [
          { label: 'VTU orders', href: '/admin/vtu-orders', icon: Smartphone, match: '/admin/vtu-orders' },
          { label: 'Manual deposits', href: '/admin/manual-deposits', icon: WalletCards, match: '/admin/manual-deposits' },
          { label: 'Site control', href: '/admin/platform-settings', icon: ShieldCheck, match: '/admin/platform-settings' },
          { label: 'Feature controls', href: '/admin/feature-controls', icon: SlidersHorizontal, match: '/admin/feature-controls' },
          { label: 'User levels', href: '/admin/user-levels', icon: ShieldCheck, match: '/admin/user-levels' },
          { label: 'Users & KYC', href: '/admin/users', icon: Users, match: '/admin/users' },
          { label: 'Security activity', href: '/admin/security-events', icon: Activity, match: '/admin/security-events' },
          { label: 'Gateway & manual deposits', href: '/admin/payment-gateway', icon: CreditCard, match: '/admin/payment-gateway' },
          { label: 'Providers', href: '/admin/api-providers', icon: PlugZap, match: '/admin/api-providers' },
          { label: 'Staff', href: '/admin/staff', icon: Users, match: '/admin/staff' },
          { label: 'Staff users', href: '/admin/staff/users', icon: Users, match: '/admin/staff/users' },
          { label: 'Audits', href: '/admin/staff/permission-audits', icon: Activity, match: '/admin/staff/permission-audits' },
        ] : []),
        { label: 'VTU', href: '/wallet/vtu', icon: Smartphone, match: '/wallet/vtu' },
        { label: 'Customer', href: '/customer/dashboard', icon: WalletCards, match: '/customer/dashboard' },
      ]
    : customerItems;

  return (
    <>
      <Head title={title} />
      <main className="min-h-screen bg-[var(--page)] pb-24 text-[var(--ink)]">
        {(admin || customerPreview) && <header className="sticky top-0 z-40 border-b border-[var(--line)] bg-[var(--surface)]/95 backdrop-blur">
          <div className="mx-auto flex max-w-[1600px] items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <a href={overviewHref} className="flex min-w-0 items-center gap-3" aria-label={`${platformName} dashboard`}>
              <span aria-hidden="true" className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span className="min-w-0">
                <span className="block font-display text-base font-extrabold tracking-tight">{platformName}</span>
                {(admin || customerPreview) && <span className="block text-[10px] font-bold uppercase tracking-[0.18em] text-[var(--muted)]">{admin ? 'Command Center' : 'Customer preview'}</span>}
              </span>
            </a>
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
              <ThemeToggle />
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
                : <>{customerItems.map((item) => renderLink(item))}{customerPreview && <a href="/admin/dashboard" className="mt-3 flex min-h-11 items-center gap-3 rounded-xl border border-[var(--line)] px-3 py-2.5 text-sm font-bold text-[var(--brand)] hover:bg-[var(--page)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"><LayoutDashboard size={18} aria-hidden="true" /><span>Back to Command Center</span></a>}</>}
            </div>
          </nav>
        )}

        <div className={`mx-auto grid max-w-[1600px] ${(admin || customerPreview) ? 'lg:grid-cols-[250px_minmax(0,1fr)]' : ''}`}>
          {(admin || customerPreview) && <aside className="sticky top-[65px] hidden h-[calc(100vh-65px)] overflow-y-auto border-r border-[var(--line)] bg-[var(--surface)] px-4 py-6 lg:block">
            {admin ? adminGroups.map((group) => (
              <section key={group.title} className="mb-6">
                <p className="mb-2 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-[var(--muted)]">{group.title}</p>
                <div className="space-y-1">{group.items.map((item) => renderLink(item))}</div>
              </section>
            )) : (
              <>
                <p className="mb-2 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-[var(--muted)]">Your account</p>
                <div className="space-y-1">{customerItems.map((item) => renderLink(item))}</div>
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
        {admin && <nav aria-label="Admin quick navigation" className="fixed inset-x-0 bottom-0 z-30 flex justify-start gap-1 overflow-x-auto border-t border-[var(--line)] bg-[var(--surface)]/95 px-2 pb-[env(safe-area-inset-bottom)] pt-2 backdrop-blur lg:hidden" style={{ scrollbarWidth: "thin" }}>
          {mobileItems.map((item) => renderLink(item, true))}
        </nav>}
      </main>
    </>
  );
}
