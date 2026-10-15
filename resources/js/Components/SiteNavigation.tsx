import { useEffect, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { ClipboardList, CreditCard, LayoutDashboard, Menu, PlugZap, Smartphone, Users, WalletCards, X } from 'lucide-react';
import ThemeToggle from './ThemeToggle';

type SiteNavigationProps = {
  children: ReactNode;
  admin?: boolean;
  publicPage?: boolean;
};

export default function SiteNavigation({ children, admin = false, publicPage = false }: SiteNavigationProps) {
  const { platform } = usePage<{ platform?: { name?: string; skin?: 'modern-green' | 'navy-teal' | 'minimal-blue' | 'obsidian-luxe' | 'arctic-light' } }>().props;
  const platformName = platform?.name || 'SEMIZZY';
  useEffect(() => {
    const skin = platform?.skin || 'modern-green';
    if (skin === 'modern-green') document.documentElement.removeAttribute('data-brand');
    else document.documentElement.dataset.brand = skin;
  }, [platform?.skin]);
  const [menuOpen, setMenuOpen] = useState(false);
  const linkClass = 'inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--line)] px-3 py-2 text-sm font-semibold transition hover:border-[var(--brand)] hover:bg-[var(--brand-soft)]';
  const overview = admin ? '/admin/dashboard' : '/dashboard';

  return (
    <div className="min-h-screen bg-[var(--page)] pb-20 text-[var(--ink)] sm:pb-0">
      <header className="sticky top-0 z-40 border-b border-[var(--line)] bg-[var(--page)]/95 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
          <a href={publicPage ? '/' : overview} className="flex min-w-0 items-center gap-3" aria-label="SEMIZZY home">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
            <span className="min-w-0">
              <span className="block font-display text-base font-extrabold tracking-tight">{platformName}</span>
              <span className="block text-[10px] font-bold uppercase tracking-[0.18em] text-[var(--muted)]">{publicPage ? 'Digital services' : admin ? 'Command Center' : 'Your account'}</span>
            </span>
          </a>
          <div className="flex shrink-0 items-center gap-2">
            <ThemeToggle />
            {publicPage && <a href="/login" className={linkClass + ' hidden sm:inline-flex'}>Sign in</a>}
            {publicPage && <a href="/register" className="hidden min-h-11 items-center rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white sm:inline-flex">Create account</a>}
            <button type="button" onClick={() => setMenuOpen((open) => !open)} aria-label={menuOpen ? 'Close navigation menu' : 'Open navigation menu'} aria-expanded={menuOpen} aria-controls="semizzy-site-mobile-navigation" className="inline-flex min-h-10 min-w-10 items-center justify-center rounded-xl border border-[var(--line)] bg-[var(--surface)] hover:border-[var(--brand)] sm:hidden">
              {menuOpen ? <X size={20} /> : <Menu size={20} />}
            </button>
          </div>
        </div>
        <nav aria-label="Desktop navigation" className="mx-auto hidden max-w-7xl flex-wrap gap-2 px-4 pb-3 sm:flex sm:px-6 lg:px-8">
          {publicPage ? (
            <>
              <a href="/" className={linkClass}><LayoutDashboard size={16} /> Home</a>
              <a href="/login" className={linkClass}>Sign in</a>
              <a href="/register" className={linkClass}>Create account</a>
            </>
          ) : admin ? (
            <>
              <a href="/admin/dashboard" className={linkClass}><LayoutDashboard size={16} /> Admin overview</a>
              <a href="/admin/staff" className={linkClass}><Users size={16} /> Staff & permissions</a>
              <a href="/admin/staff/users" className={linkClass}><Users size={16} /> Staff users</a>
              <a href="/admin/staff/permission-audits" className={linkClass}><ClipboardList size={16} /> Permission audits</a>
              <a href="/admin/payment-gateway" className={linkClass}><CreditCard size={16} /> Payment gateway</a>
              <a href="/admin/api-providers" className={linkClass}><PlugZap size={16} /> API providers</a>
              <a href="/wallet/vtu" className={linkClass}><Smartphone size={16} /> VTU service</a>
              <a href="/customer/dashboard" className={linkClass}><WalletCards size={16} /> Customer view</a>
            </>
          ) : (
            <>
              <a href="/dashboard" className={linkClass}><LayoutDashboard size={16} /> Home</a>
              <a href="/wallet/vtu" className={linkClass}><Smartphone size={16} /> Airtime & data</a>
              <a href="/dashboard#fund-wallet" className={linkClass}><CreditCard size={16} /> Wallet</a>
            </>
          )}
        </nav>
        {menuOpen && (
          <nav id="semizzy-site-mobile-navigation" aria-label="Mobile navigation menu" className="border-t border-[var(--line)] bg-[var(--surface)] px-4 py-3 sm:hidden">
            <div className="mx-auto grid max-w-7xl gap-2">
              {publicPage ? (
                <>
                  <a onClick={() => setMenuOpen(false)} href="/" className={linkClass}><LayoutDashboard size={17} /> Home</a>
                  <a onClick={() => setMenuOpen(false)} href="/login" className={linkClass}>Sign in</a>
                  <a onClick={() => setMenuOpen(false)} href="/register" className={linkClass}>Create account</a>
                </>
              ) : admin ? (
                <>
                  <a onClick={() => setMenuOpen(false)} href="/admin/dashboard" className={linkClass}><LayoutDashboard size={17} /> Admin overview</a>
                  <a onClick={() => setMenuOpen(false)} href="/admin/staff" className={linkClass}><Users size={17} /> Staff & permissions</a>
                  <a onClick={() => setMenuOpen(false)} href="/admin/staff/users" className={linkClass}><Users size={17} /> Staff users</a>
                  <a onClick={() => setMenuOpen(false)} href="/admin/staff/permission-audits" className={linkClass}><ClipboardList size={17} /> Permission audits</a>
                  <a onClick={() => setMenuOpen(false)} href="/admin/payment-gateway" className={linkClass}><CreditCard size={17} /> Payment gateway</a>
                  <a onClick={() => setMenuOpen(false)} href="/admin/api-providers" className={linkClass}><PlugZap size={17} /> API providers</a>
                  <a onClick={() => setMenuOpen(false)} href="/wallet/vtu" className={linkClass}><Smartphone size={17} /> VTU service</a>
                  <a onClick={() => setMenuOpen(false)} href="/customer/dashboard" className={linkClass}><WalletCards size={17} /> Customer view</a>
                </>
              ) : (
                <>
                  <a onClick={() => setMenuOpen(false)} href="/dashboard" className={linkClass}><LayoutDashboard size={17} /> Home</a>
                  <a onClick={() => setMenuOpen(false)} href="/wallet/vtu" className={linkClass}><Smartphone size={17} /> Airtime & data</a>
                  <a onClick={() => setMenuOpen(false)} href="/dashboard#fund-wallet" className={linkClass}><CreditCard size={17} /> Wallet</a>
                </>
              )}
            </div>
          </nav>
        )}
      </header>

      {children}

      <nav aria-label="Quick navigation" className="fixed inset-x-0 bottom-0 z-40 grid border-t border-[var(--line)] bg-[var(--surface)]/95 px-1 pb-[env(safe-area-inset-bottom)] pt-2 backdrop-blur sm:hidden" style={{ gridTemplateColumns: `repeat(${admin ? 4 : 3},minmax(0,1fr))` }}>
        {publicPage ? (
          <>
            <a href="/" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><LayoutDashboard size={19} /><span>Home</span></a>
            <a href="/login" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><Users size={19} /><span>Sign in</span></a>
            <a href="/register" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><CreditCard size={19} /><span>Register</span></a>
          </>
        ) : admin ? (
          <>
            <a href="/admin/dashboard" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><LayoutDashboard size={19} /><span>Overview</span></a>
            <a href="/admin/staff" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><Users size={19} /><span>Staff</span></a>
            <a href="/wallet/vtu" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><Smartphone size={19} /><span>VTU</span></a>
            <a href="/customer/dashboard" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><WalletCards size={19} /><span>Customer</span></a>
          </>
        ) : (
          <>
            <a href="/dashboard" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><LayoutDashboard size={19} /><span>Home</span></a>
            <a href="/wallet/vtu" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><Smartphone size={19} /><span>Services</span></a>
            <a href="/dashboard#fund-wallet" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><CreditCard size={19} /><span>Wallet</span></a>
          </>
        )}
      </nav>
    </div>
  );
}
