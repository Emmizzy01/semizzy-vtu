import { useEffect, useState, type ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import ThemeToggle from './ThemeToggle';
import { applyThemePreference } from './theme';
import {
  Activity, ArrowLeftRight, CreditCard, GraduationCap, Landmark, LayoutDashboard, LifeBuoy,
  Eye, EyeOff, Globe, LogOut, Menu, Package, PlugZap, Search, ShieldCheck, Smartphone, SlidersHorizontal,
  Tv, UserRound, Users, WalletCards, Wifi, X, Zap,
} from 'lucide-react';

type DashboardLayoutProps = {
  title: string;
  description: string;
  admin?: boolean;
  children: ReactNode;
  canManageStaff?: boolean;
  canApproveRefunds?: boolean;
  customerPreview?: boolean;
  walletBalance?: string | null;
  walletBalanceVisible?: boolean;
  onToggleWalletBalance?: () => void;
};
type NavItem = { label: string; href: string; icon: typeof LayoutDashboard; match?: string; emoji?: string };

export default function DashboardLayout({ title, description, admin = false, children, customerPreview = false, walletBalance = null, walletBalanceVisible = true, onToggleWalletBalance }: DashboardLayoutProps) {
  const { platform, auth } = usePage<{ platform?: { name?: string; theme_mode?: 'light' | 'dark' }; auth?: { user?: { name?: string; email?: string; tier?: string; level?: string; user_level?: string; user_type?: string; avatar_url?: string | null; avatarUrl?: string | null } | null } }>().props;
  const currentUser = auth?.user;
  const userName = currentUser?.name?.trim() || 'Semizzy user';
  const rawUserTier = currentUser?.tier || currentUser?.user_level || currentUser?.level || currentUser?.user_type || 'Customer';
  const userTier = ({ level_1: 'Tier1', level_2: 'Tier2', level_3: 'Tier3', level_4: 'Tier4/Merchant', tier1: 'Tier1', tier2: 'Tier2', tier3: 'Tier3', tier4: 'Tier4/Merchant' } as Record<string, string>)[rawUserTier.toLowerCase()] || rawUserTier.replace(/_/g, ' ');
  const avatarUrl = currentUser?.avatar_url || currentUser?.avatarUrl || null;
  const initials = userName.split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'S';
  const platformName = platform?.name || 'SEMIZZY';
  const themeMode = platform?.theme_mode === 'dark' ? 'dark' : 'light';
  useEffect(() => { applyThemePreference(themeMode); }, [themeMode]);
  const [menuOpen, setMenuOpen] = useState(false);
  const [location, setLocation] = useState(() => ({ path: typeof window === 'undefined' ? '' : window.location.pathname, hash: typeof window === 'undefined' ? '' : window.location.hash }));
  useEffect(() => {
    const sync = () => setLocation({ path: window.location.pathname, hash: window.location.hash });
    window.addEventListener('hashchange', sync); window.addEventListener('popstate', sync);
    return () => { window.removeEventListener('hashchange', sync); window.removeEventListener('popstate', sync); };
  }, []);
  useEffect(() => {
    if (!menuOpen) return;
    const close = (event: KeyboardEvent) => { if (event.key === 'Escape') setMenuOpen(false); };
    window.addEventListener('keydown', close);
    return () => window.removeEventListener('keydown', close);
  }, [menuOpen]);

  const customerItems: NavItem[] = [
    { label: 'Dashboard', href: customerPreview ? '/customer/dashboard' : '/dashboard', icon: LayoutDashboard },
    { label: 'Buy Airtime', href: '/wallet/vtu/airtime', icon: Smartphone },
    { label: 'Buy Data', href: '/wallet/vtu/data', icon: Wifi },
    { label: 'Electricity', href: '/wallet/vtu/electricity', icon: Zap },
    { label: 'Cable TV', href: '/wallet/vtu/tv', icon: Tv },
    { label: 'Internet', href: '/services', icon: PlugZap },
    { label: 'Education', href: '/services/education', icon: GraduationCap },
    { label: 'Transactions', href: (customerPreview ? '/customer/dashboard' : '/dashboard') + '#activity', icon: Activity, match: '#activity' },
  ];
  const customerFooter: NavItem[] = [
    { label: 'Home', href: customerPreview ? '/customer/dashboard' : '/dashboard', icon: LayoutDashboard, emoji: '🏠' },
    { label: 'Orders', href: (customerPreview ? '/customer/dashboard' : '/dashboard') + '#orders', icon: Package, match: '#orders', emoji: '📜' },
    { label: 'Wallet', href: (customerPreview ? '/customer/dashboard' : '/dashboard') + '#wallet', icon: WalletCards, match: '#wallet', emoji: '👛' },
    { label: 'Support', href: '/support', icon: LifeBuoy, emoji: '🎧' },
    { label: 'Profile', href: '/profile', icon: UserRound, emoji: '👤' },
  ];
  const adminGroups: { title: string; items: NavItem[] }[] = [
    { title: 'Workspace', items: [{ label: 'Admin Dashboard', href: '/admin/dashboard', icon: LayoutDashboard }] },
    { title: 'Finance', items: [
      { label: 'Manual deposits', href: '/admin/manual-deposits', icon: WalletCards },
      { label: 'Refund operations', href: '/admin/refunds', icon: CreditCard },
      { label: 'Payment gateway', href: '/admin/payment-gateway', icon: Landmark },
      { label: 'Storefront commissions', href: '/admin/storefront-commissions', icon: CreditCard },
    ] },
    { title: 'Operations', items: [
      { label: 'VTU orders', href: '/admin/vtu-orders', icon: Smartphone },
      { label: 'VTU products', href: '/admin/vtu-products', icon: Package },
      { label: 'VTU website requests', href: '/admin/vtu-website-requests', icon: Package },
      { label: 'Streaming catalogue', href: '/admin/streaming-subscriptions', icon: Tv },
      { label: 'Education catalogue', href: '/admin/education-services', icon: GraduationCap },
      { label: 'Users, types & levels', href: '/admin/users', icon: Users },
      { label: 'User level policies', href: '/admin/user-levels', icon: UserRound },
      { label: 'Level upgrade requests', href: '/admin/user-level-upgrade-requests', icon: ShieldCheck },
      { label: 'KYC reviews', href: '/admin/kyc-reviews', icon: ShieldCheck },
      { label: 'Support tickets', href: '/admin/support', icon: LifeBuoy },
    ] },
    { title: 'Platform control', items: [
      { label: 'API providers', href: '/admin/api-providers', icon: PlugZap },
      { label: 'Homepage editor', href: '/admin/platform-settings#homepage', icon: Globe },
      { label: 'Support page editor', href: '/admin/support-pages', icon: LifeBuoy },
      { label: 'Site control & settings', href: '/admin/platform-settings', icon: SlidersHorizontal },
      { label: 'Feature controls', href: '/admin/feature-controls', icon: SlidersHorizontal },
      { label: 'Security activity', href: '/admin/security-events', icon: ShieldCheck },
      { label: 'Staff & permissions', href: '/admin/staff', icon: Users },
      { label: 'Permission audit history', href: '/admin/staff/permission-audits', icon: Activity },
    ] },
    { title: 'Customer view', items: [{ label: 'Customer dashboard', href: '/customer/dashboard', icon: ArrowLeftRight }] },
  ];
  const active = (item: NavItem) => {
    if (item.match?.startsWith('#')) return location.hash === item.match;
    if (item.href.includes('#')) return location.path === item.href.split('#')[0] && location.hash === '#' + item.href.split('#')[1];
    return item.href === '/dashboard' || item.href === '/customer/dashboard' || item.href === '/admin/dashboard'
      ? location.path === item.href
      : location.path === item.href || (item.href === '/services' && location.path.startsWith('/services'));
  };
  const link = (item: NavItem, compact = false) => {
    const Icon = item.icon;
    const selected = active(item);
    return <a key={item.label} href={item.href} onClick={() => setMenuOpen(false)} aria-current={selected ? 'page' : undefined}
      className={compact
        ? `flex min-h-[58px] min-w-0 flex-1 flex-col items-center justify-center gap-1 rounded-xl px-1 text-[10px] ${selected ? 'font-bold text-[#0f8a4f]' : 'text-gray-400'}`
        : `flex min-h-11 items-center gap-3 rounded-lg p-2.5 text-[13px] transition ${selected ? 'bg-[#0f8a4f]/10 font-bold text-[#0f8a4f]' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-slate-800'}`}>
      {compact ? <span aria-hidden="true" className="text-[18px] leading-5">{item.emoji}</span> : <Icon size={18} aria-hidden="true" />}<span className={compact ? 'truncate' : 'min-w-0 flex-1'}>{item.label}</span>
    </a>;
  };

  return <>
    <Head title={title} />
    <main className="min-h-screen bg-[#eef4ef] pb-20 text-gray-900 dark:bg-[#0f1f17] dark:text-gray-100">
      <header className="sticky top-0 z-40 border-b border-black/5 bg-white/95 backdrop-blur dark:border-white/10 dark:bg-slate-950/95">
        <div className={`mx-auto flex ${admin ? "max-w-[1400px]" : "max-w-[1200px]"} items-center justify-between gap-3 px-4 py-3 sm:px-6`}>
          <a href={admin ? '/admin/dashboard' : customerPreview ? '/customer/dashboard' : '/dashboard'} className={`flex min-w-0 items-center gap-2 font-bold text-xl ${admin ? 'md:hidden' : 'md:hidden'}`}>
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[#0f8a4f] text-sm text-white">S</span>
            <span className="truncate">{platformName}</span>
            {admin && <span className="hidden rounded-full bg-[#0f8a4f]/10 px-2 py-1 text-[10px] font-bold text-[#0f8a4f] sm:inline">ADMIN</span>}
          </a>
          <div className="flex items-center gap-2">
            {!admin && <a href="/profile" className="flex min-w-0 items-center gap-2 rounded-full px-2 py-1 hover:bg-gray-50 dark:hover:bg-slate-800">
              <span className="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-full bg-emerald-100 text-xs font-bold text-emerald-800">{avatarUrl ? <img src={avatarUrl} alt="" className="h-full w-full object-cover" /> : initials}</span>
              <span className="hidden max-w-36 sm:block"><span className="block truncate text-xs font-bold">{userName}</span><span className="block truncate text-[10px] text-[#0f8a4f]">{userTier}</span></span>
            </a>}
            {admin && <form action="/admin/search" method="get" className="hidden md:block"><label className="flex h-9 items-center gap-2 rounded-lg border px-3 text-xs text-gray-500"><Search size={14}/><input name="q" minLength={2} maxLength={100} required placeholder="Search admin records" className="w-36 bg-transparent outline-none lg:w-52"/><button type="submit" className="font-bold text-[#0f8a4f]">Search</button></label></form>}
            <form method="post" action="/logout"><input type="hidden" name="_token" value={typeof document === 'undefined' ? '' : (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '')}/><button type="submit" aria-label="Sign out" className="grid h-9 w-9 place-items-center rounded-full text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-slate-800"><LogOut size={17}/></button></form>
            <button type="button" onClick={() => setMenuOpen(v => !v)} aria-label={menuOpen ? 'Close navigation menu' : 'Open navigation menu'} aria-expanded={menuOpen} className={`grid h-10 w-10 place-items-center rounded-lg border md:hidden`}>{menuOpen ? <X size={19}/> : <Menu size={19}/>}</button>
          </div>
        </div>
      </header>
      {menuOpen && <nav aria-label="Mobile menu" className="border-b bg-white px-4 py-3 dark:border-white/10 dark:bg-slate-950 md:hidden"><div className="mx-auto grid max-w-7xl gap-1">
        {admin ? adminGroups.map(group => <section key={group.title} className="py-1"><p className="px-3 pb-1 text-[10px] font-bold uppercase tracking-wider text-gray-400">{group.title}</p>{group.items.map(item => link(item))}</section>) : customerItems.map(item => link(item))}
      </div></nav>}
      <div className={`mx-auto grid ${admin ? "max-w-[1400px] md:grid-cols-[260px_minmax(0,1fr)]" : "max-w-[1200px] md:grid-cols-[260px_minmax(0,1fr)]"}`}>
        <aside className={`sticky top-[65px] hidden h-[calc(100vh-65px)] overflow-y-auto border-r border-black/5 bg-white p-6 dark:border-white/10 dark:bg-slate-950 md:block`}>
          <a href={admin ? '/admin/dashboard' : customerPreview ? '/customer/dashboard' : '/dashboard'} className="mb-6 flex items-center gap-2 font-bold text-xl"><span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[#0f8a4f] text-sm text-white">S</span><span className="truncate">{platformName}</span>{admin && <span className="rounded-full bg-[#0f8a4f]/10 px-2 py-1 text-[10px] font-bold text-[#0f8a4f]">ADMIN</span>}</a>
          {!admin && <div className="mb-6"><section aria-label="Wallet summary" className="mb-6 rounded-xl bg-gradient-to-br from-[#0e7a44] to-[#0a5c32] p-4 text-white"><p className="text-xs text-white/80">Wallet Balance</p><div className="mt-1 flex items-center gap-2"><p className="min-w-0 flex-1 break-words text-lg font-extrabold">{walletBalance ? (walletBalanceVisible ? walletBalance : "••••••") : "—"}</p>{walletBalance && onToggleWalletBalance && <button type="button" onClick={onToggleWalletBalance} aria-label={walletBalanceVisible ? "Hide wallet balance" : "Show wallet balance"} className="grid h-8 w-8 shrink-0 place-items-center rounded-full hover:bg-white/10">{walletBalanceVisible ? <EyeOff size={16}/> : <Eye size={16}/>}</button>}</div><div className="mt-3 flex gap-2"><a href="/wallet/manual-deposits" className="flex min-h-9 flex-1 items-center justify-center rounded-lg bg-white px-2 py-2 text-[11px] font-extrabold text-[#0a5c32]">Fund Wallet</a><a href="/wallet/transfer" className="flex min-h-9 flex-1 items-center justify-center rounded-lg bg-[#12a15a] px-2 py-2 text-[11px] font-extrabold text-white">Transfer</a></div></section><p className="mb-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">Your account</p><nav className="space-y-1">{customerItems.map(item => link(item))}</nav><div className="mt-6 rounded-xl bg-[#eef4ef] p-3 text-xs text-gray-500 dark:bg-slate-800 dark:text-gray-300">Need help? <a href="/support" className="font-bold text-[#0f8a4f]">Contact support</a></div></div>}
          {admin && adminGroups.map(group => <section key={group.title} className="mb-6"><p className="mb-2 px-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">{group.title}</p><nav className="space-y-1">{group.items.map(item => link(item))}</nav></section>)}
        </aside>
        <section className={`min-w-0 p-4 ${admin ? "md:p-6" : "mx-auto w-full max-w-[390px] md:max-w-none md:p-8"}`}>
          {(admin || customerPreview) && <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold">{title}</h1>{description && <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{description}</p>}</div>{admin && <ThemeToggle themeMode={themeMode} />}</div>}
          {children}
        </section>
      </div>
      {!admin && <nav aria-label="Main navigation" className="fixed inset-x-0 bottom-0 z-50 flex h-[72px] items-center justify-around border-t border-black/5 bg-white px-2 pb-[env(safe-area-inset-bottom)] dark:border-white/10 dark:bg-slate-950 md:hidden">
        {customerFooter.map(item => link(item, true))}
      </nav>}
    </main>
  </>;
}
