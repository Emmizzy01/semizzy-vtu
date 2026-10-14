import { useState, type ReactNode } from 'react';
import ThemeToggle from './ThemeToggle';
import { Head } from '@inertiajs/react';
import {
  ArrowLeft,
  CreditCard,
  LayoutDashboard,
  LogOut,
  Menu,
  PlugZap,
  Smartphone,
  ClipboardList,
  Users,
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
};

export default function DashboardLayout({
  title,
  description,
  admin = false,
  children,
  canManageStaff = false,
  canApproveRefunds = false,
}: DashboardLayoutProps) {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const overviewHref = admin ? '/admin/dashboard' : '/dashboard';
  const navLinkClass = 'inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl border border-[var(--line)] px-3 py-2 text-sm font-semibold transition hover:border-[var(--brand)] hover:bg-[var(--brand-soft)]';
  const activeLinkClass = 'inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl bg-[var(--brand-soft)] px-3 py-2 text-sm font-bold text-[var(--brand)]';

  return (
    <>
      <Head title={title} />
      <main className="min-h-screen bg-[var(--page)] pb-20 text-[var(--ink)] sm:pb-0">
        <header className="sticky top-0 z-30 border-b border-[var(--line)] bg-[var(--page)]/95 backdrop-blur">
          <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <a href={overviewHref} className="flex min-w-0 items-center gap-3" aria-label="SEMIZZY dashboard">
              <span className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span className="min-w-0">
                <span className="block font-display text-base font-extrabold tracking-tight">SEMIZZY</span>
                <span className="block text-[10px] font-bold uppercase tracking-[0.18em] text-[var(--muted)]">{admin ? 'Command Center' : 'Your account'}</span>
              </span>
            </a>
            <div className="flex shrink-0 items-center gap-2">
              {admin && <a href="/customer/dashboard" className="hidden min-h-10 items-center gap-1 rounded-xl border border-[var(--line)] px-3 py-2 text-xs font-semibold sm:inline-flex"><ArrowLeft size={14} /> Customer view</a>}
              <ThemeToggle />
              <form method="post" action="/logout">
                <input type="hidden" name="_token" value={typeof document === 'undefined' ? '' : (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '')} />
                <button type="submit" className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-3 text-sm font-bold text-white hover:opacity-90"><LogOut size={15} /><span className="hidden sm:inline">Sign out</span></button>
              </form>
              <button
                type="button"
                onClick={() => setMobileMenuOpen((open) => !open)}
                aria-label={mobileMenuOpen ? 'Close navigation menu' : 'Open navigation menu'}
                aria-expanded={mobileMenuOpen}
                aria-controls="semizzy-mobile-navigation"
                className="inline-flex min-h-10 min-w-10 items-center justify-center rounded-xl border border-[var(--line)] bg-[var(--surface)] hover:border-[var(--brand)] sm:hidden"
              >
                {mobileMenuOpen ? <X size={20} /> : <Menu size={20} />}
              </button>
            </div>
          </div>

          <nav aria-label="Main navigation" className="mx-auto hidden max-w-7xl flex-wrap gap-2 px-4 pb-3 sm:flex sm:px-6 lg:px-8">
            <a href={overviewHref} className={activeLinkClass}><LayoutDashboard size={15} /> Overview</a>
            {!admin && <a href="/wallet/vtu" className={navLinkClass}><Smartphone size={15} /> Airtime & data</a>}
            {!admin && <a href="#transactions" className={navLinkClass}><WalletCards size={15} /> Transactions</a>}
            {!admin && <a href="#fund-wallet" className={navLinkClass}><CreditCard size={15} /> Fund wallet</a>}
            {admin && canManageStaff && <a href="/admin/staff" className={navLinkClass}><Users size={15} /> Staff & permissions</a>}
            {admin && canManageStaff && <a href="/admin/staff/users" className={navLinkClass}><Users size={15} /> Staff users</a>}
            {admin && canManageStaff && <a href="/admin/staff/permission-audits" className={navLinkClass}><ClipboardList size={15} /> Permission audits</a>}
            {admin && canManageStaff && <a href="/admin/payment-gateway" className={navLinkClass}><CreditCard size={15} /> Payment gateway</a>}
            {admin && canManageStaff && <a href="/admin/api-providers" className={navLinkClass}><PlugZap size={15} /> API providers</a>}
            {admin && canApproveRefunds && <a href="/admin/refunds" className={navLinkClass}><CreditCard size={15} /> Refund operations</a>}
            {admin && <a href="/wallet/vtu" className={navLinkClass}><Smartphone size={15} /> VTU service</a>}
            {admin && <a href="/customer/dashboard" className={navLinkClass}><WalletCards size={15} /> Customer view</a>}
          </nav>

          {mobileMenuOpen && (
            <nav id="semizzy-mobile-navigation" aria-label="Mobile navigation" className="border-t border-[var(--line)] bg-[var(--surface)] px-4 py-3 sm:hidden">
              <div className="mx-auto grid max-w-7xl gap-2">
                <a onClick={() => setMobileMenuOpen(false)} href={overviewHref} className={activeLinkClass}><LayoutDashboard size={17} /> Overview</a>
                {!admin && <a onClick={() => setMobileMenuOpen(false)} href="/wallet/vtu" className={navLinkClass}><Smartphone size={17} /> Airtime & data</a>}
                {!admin && <a onClick={() => setMobileMenuOpen(false)} href="#transactions" className={navLinkClass}><WalletCards size={17} /> Transactions</a>}
                {!admin && <a onClick={() => setMobileMenuOpen(false)} href="#fund-wallet" className={navLinkClass}><CreditCard size={17} /> Fund wallet</a>}
                {admin && canManageStaff && <a onClick={() => setMobileMenuOpen(false)} href="/admin/staff" className={navLinkClass}><Users size={17} /> Staff & permissions</a>}
                {admin && canManageStaff && <a onClick={() => setMobileMenuOpen(false)} href="/admin/staff/users" className={navLinkClass}><Users size={17} /> Staff users</a>}
                {admin && canManageStaff && <a onClick={() => setMobileMenuOpen(false)} href="/admin/staff/permission-audits" className={navLinkClass}><ClipboardList size={17} /> Permission audits</a>}
                {admin && canManageStaff && <a onClick={() => setMobileMenuOpen(false)} href="/admin/payment-gateway" className={navLinkClass}><CreditCard size={17} /> Payment gateway</a>}
                {admin && canManageStaff && <a onClick={() => setMobileMenuOpen(false)} href="/admin/api-providers" className={navLinkClass}><PlugZap size={17} /> API providers</a>}
                {admin && canApproveRefunds && <a onClick={() => setMobileMenuOpen(false)} href="/admin/refunds" className={navLinkClass}><CreditCard size={17} /> Refund operations</a>}
                {admin && <a onClick={() => setMobileMenuOpen(false)} href="/wallet/vtu" className={navLinkClass}><Smartphone size={17} /> VTU service</a>}
                {admin && <a onClick={() => setMobileMenuOpen(false)} href="/customer/dashboard" className={navLinkClass}><WalletCards size={17} /> Customer view</a>}
              </div>
            </nav>
          )}
        </header>

        <div className="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
          <div className="mb-7">
            <p className="text-xs font-extrabold uppercase tracking-[0.2em] text-[var(--brand)]">{admin ? 'Operations workspace' : 'Customer workspace'}</p>
            <h1 className="mt-2 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{title}</h1>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)] sm:text-base">{description}</p>
          </div>
          {children}
          <footer className="mt-10 border-t border-[var(--line)] py-5 text-center text-xs text-[var(--muted)]">SEMIZZY · Secure, mobile-first digital services</footer>
        </div>

        <nav aria-label="Quick navigation" className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-[var(--line)] bg-[var(--surface)]/95 px-1 pb-[env(safe-area-inset-bottom)] pt-2 backdrop-blur sm:hidden">
          <a href={overviewHref} className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-bold text-[var(--brand)]"><LayoutDashboard size={19} /><span>Home</span></a>
          {!admin && <a href="/wallet/vtu" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><Smartphone size={19} /><span>Services</span></a>}
          {!admin && <a href="#transactions" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><WalletCards size={19} /><span>Activity</span></a>}
          {!admin && <a href="#fund-wallet" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><CreditCard size={19} /><span>Fund</span></a>}
          {admin && canManageStaff && <a href="/admin/staff" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><Users size={19} /><span>Staff</span></a>}
          {admin && <a href="/wallet/vtu" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><Smartphone size={19} /><span>VTU</span></a>}
          {admin && <a href="/customer/dashboard" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><WalletCards size={19} /><span>Customer</span></a>}
          {admin && canApproveRefunds && <a href="/admin/refunds" className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[10px] font-semibold text-[var(--muted)]"><CreditCard size={19} /><span>Refunds</span></a>}
        </nav>
      </main>
    </>
  );
}
