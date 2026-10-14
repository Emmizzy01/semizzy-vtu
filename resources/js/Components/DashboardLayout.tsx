import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { ArrowLeft, LayoutDashboard, LogOut, Moon, Sun } from 'lucide-react';

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
  function toggleTheme() {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { window.localStorage.setItem('semizzy-theme', next); } catch { /* optional preference */ }
  }

  return (
    <>
      <Head title={title} />
      <main className="min-h-screen bg-[var(--page)] text-[var(--ink)]">
        <header className="sticky top-0 z-20 border-b border-[var(--line)] bg-[var(--page)]/95 backdrop-blur">
          <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <a href={admin ? '/admin/dashboard' : '/dashboard'} className="flex min-w-0 items-center gap-3" aria-label="SEMIZZY dashboard">
              <span className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span className="min-w-0">
                <span className="block font-display text-base font-extrabold tracking-tight">SEMIZZY</span>
                <span className="block text-[10px] font-bold uppercase tracking-[0.18em] text-[var(--muted)]">{admin ? 'Command Center' : 'Your account'}</span>
              </span>
            </a>
            <div className="flex items-center gap-2">
              {admin && <a href="/dashboard" className="hidden items-center gap-1 rounded-xl border border-[var(--line)] px-3 py-2 text-xs font-semibold sm:inline-flex"><ArrowLeft size={14} /> Customer view</a>}
              <button type="button" onClick={toggleTheme} aria-label="Toggle light or dark theme" className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-sm font-semibold hover:border-[var(--brand)]">
                <Sun size={16} className="hidden [html[data-theme=dark]_&]:block" /><Moon size={16} className="[html[data-theme=dark]_&]:hidden" /><span className="hidden sm:inline">Theme</span>
              </button>
              <form method="post" action="/logout">
                <input type="hidden" name="_token" value={typeof document === 'undefined' ? '' : (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '')} />
                <button type="submit" className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--brand)] px-3 text-sm font-bold text-white hover:opacity-90"><LogOut size={15} /><span className="hidden sm:inline">Sign out</span></button>
              </form>
            </div>
          </div>
          <nav aria-label="Main navigation" className="mx-auto flex max-w-7xl gap-2 overflow-x-auto px-4 pb-3 sm:px-6 lg:px-8">
            <a href={admin ? '/admin/dashboard' : '/dashboard'} className="inline-flex shrink-0 items-center gap-2 rounded-full bg-[var(--brand-soft)] px-3 py-2 text-xs font-bold text-[var(--brand)]"><LayoutDashboard size={14} /> Overview</a>
            {!admin && <a href="/wallet/vtu" className="shrink-0 rounded-full border border-[var(--line)] px-3 py-2 text-xs font-semibold hover:border-[var(--brand)]">Airtime & data</a>}
            {admin && canApproveRefunds && <a href="/admin/refunds" className="shrink-0 rounded-full border border-[var(--line)] px-3 py-2 text-xs font-semibold hover:border-[var(--brand)]">Refund operations</a>}
            {admin && canManageStaff && <a href="/admin/staff" className="shrink-0 rounded-full border border-[var(--line)] px-3 py-2 text-xs font-semibold hover:border-[var(--brand)]">Staff & permissions</a>}
          </nav>
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
      </main>
    </>
  );
}
