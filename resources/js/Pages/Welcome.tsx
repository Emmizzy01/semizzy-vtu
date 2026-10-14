import { Head } from '@inertiajs/react';
import { ArrowUpRight, ShieldCheck, Smartphone, Wallet } from 'lucide-react';

const highlights = [
  {
    icon: Wallet,
    title: 'Wallet-first foundation',
    description: 'Designed around traceable balances, ledger entries, and safer transaction lifecycles.',
  },
  {
    icon: Smartphone,
    title: 'Everyday digital services',
    description: 'A modular path for airtime, data, bill payments, and reseller workflows.',
  },
  {
    icon: ShieldCheck,
    title: 'Security by design',
    description: 'Server-side authorization, auditability, and careful handling of provider secrets.',
  },
];

export default function Welcome() {
  return (
    <>
      <Head title="Welcome" />
      <main className="min-h-screen overflow-hidden bg-[var(--page)] text-[var(--ink)]">
        <div className="mx-auto max-w-7xl px-5 py-6 sm:px-8 lg:px-12">
          <header className="flex items-center justify-between gap-4 border-b border-[var(--line)] pb-5">
            <a href="/" aria-label="SEMIZZY home" className="flex items-center gap-3">
              <span aria-hidden="true" className="grid h-10 w-10 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span><strong className="block font-display text-base font-extrabold">SEMIZZY</strong><span className="block text-[10px] text-[var(--muted)]">Digital made simple</span></span>
            </a>
            <nav aria-label="Account navigation" className="flex items-center gap-2 sm:gap-3">
              <a href="/login" className="inline-flex min-h-10 items-center rounded-xl px-3 py-2 text-sm font-bold text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--ink)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]">Sign in</a>
              <a href="/register" className="inline-flex min-h-10 items-center rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2">Create account</a>
            </nav>
          </header>

          <section className="grid items-center gap-12 pb-20 pt-16 sm:pt-24 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16 lg:pb-28">
            <div>
              <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 py-1.5 text-xs font-bold text-[var(--muted)]">
                <span className="h-2 w-2 rounded-full bg-emerald-500" />
                A new foundation for everyday finance
              </div>
              <h1 className="max-w-3xl font-display text-4xl font-extrabold leading-[1.08] tracking-[-0.045em] sm:text-6xl lg:text-7xl">
                Your digital life, <span className="text-[var(--brand)]">in one place.</span>
              </h1>
              <p className="mt-6 max-w-xl text-base leading-8 text-[var(--muted)] sm:text-lg">
                SEMIZZY is being built as a dependable home for VTU services, wallet tools, reseller businesses, and more — with clarity, control, and security at the core.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <a href="/register" className="inline-flex min-h-12 items-center gap-2 rounded-full bg-[var(--brand)] px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-emerald-950/15 transition hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2">
                  Get started <ArrowUpRight size={17} aria-hidden="true" />
                </a>
                <a href="/login" className="inline-flex min-h-12 items-center rounded-full border border-[var(--line)] px-5 py-3.5 text-sm font-semibold text-[var(--ink)] hover:bg-[var(--surface)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]">I already have an account</a>
              </div>
              <div className="mt-10 flex items-center gap-3 text-xs font-medium text-[var(--muted)]">
                <span className="flex -space-x-2">
                  <span className="h-7 w-7 rounded-full border-2 border-[var(--page)] bg-indigo-200" />
                  <span className="h-7 w-7 rounded-full border-2 border-[var(--page)] bg-teal-200" />
                  <span className="h-7 w-7 rounded-full border-2 border-[var(--page)] bg-amber-200" />
                </span>
                Mobile-first · Modular · Security-minded
              </div>
            </div>

            <div className="relative mx-auto w-full max-w-lg">
              <div className="absolute -inset-6 rounded-[3rem] bg-gradient-to-br from-indigo-500/15 via-cyan-400/10 to-emerald-300/15 blur-2xl" />
              <div className="relative rounded-[2rem] border border-[var(--line)] bg-[var(--surface)] p-5 shadow-2xl shadow-slate-950/10 sm:p-7">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-sm font-semibold text-[var(--muted)]">Your SEMIZZY space</p>
                    <p className="mt-1 font-display text-2xl font-extrabold">Everything, connected.</p>
                  </div>
                  <span aria-hidden="true" className="rounded-xl bg-emerald-500/10 p-3 text-emerald-600"><ShieldCheck size={21} /></span>
                </div>
                <div className="mt-7 rounded-2xl bg-slate-950 p-5 text-white sm:p-6">
                  <div className="flex items-center justify-between text-sm text-slate-300">
                    <span>Wallet overview</span><span className="rounded-full bg-white/10 px-2.5 py-1 text-xs">Preview</span>
                  </div>
                  <div className="mt-5 font-display text-3xl font-extrabold tracking-tight">₦ ••••••</div>
                  <p className="mt-2 text-xs text-slate-400">Your balance will appear here</p>
                  <div className="mt-6 grid grid-cols-3 gap-2">
                    {['Airtime', 'Data', 'Bills'].map((item) => (
                      <div key={item} className="rounded-xl border border-white/10 bg-white/[0.06] px-2 py-3 text-center text-xs font-semibold">{item}</div>
                    ))}
                  </div>
                </div>
                <div className="mt-5 grid grid-cols-2 gap-3">
                  <div className="rounded-2xl border border-[var(--line)] p-4">
                    <p className="text-xs text-[var(--muted)]">Designed around</p>
                    <p className="mt-1 font-bold">Trust & control</p>
                  </div>
                  <div className="rounded-2xl border border-[var(--line)] p-4">
                    <p className="text-xs text-[var(--muted)]">Experience</p>
                    <p className="mt-1 font-bold">Light + dark</p>
                  </div>
                </div>
                <p className="mt-4 text-center text-[11px] text-[var(--muted)]">Illustrative interface — no live payments are enabled.</p>
              </div>
            </div>
          </section>

          <section id="foundation" className="border-t border-[var(--line)] py-14 sm:py-20">
            <div className="max-w-2xl">
              <p className="text-xs font-extrabold uppercase tracking-[0.22em] text-[var(--brand)]">The foundation</p>
              <h2 className="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Built for more than a quick transaction.</h2>
              <p className="mt-4 leading-7 text-[var(--muted)]">The core is being organized around reliable money movement, useful digital services, and clear operational controls.</p>
            </div>
            <div className="mt-9 grid gap-4 md:grid-cols-3">
              {highlights.map(({ icon: Icon, title, description }, index) => (
                <article key={title} className="rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 transition hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-950/5">
                  <span aria-hidden="true" className="grid h-12 w-12 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><Icon size={22} /></span>
                  <p className="mt-6 text-xs font-bold text-[var(--muted)]">0{index + 1}</p>
                  <h3 className="mt-2 font-display text-lg font-extrabold">{title}</h3>
                  <p className="mt-3 text-sm leading-6 text-[var(--muted)]">{description}</p>
                </article>
              ))}
            </div>
          </section>

          <footer className="flex flex-col gap-3 border-t border-[var(--line)] py-7 text-xs text-[var(--muted)] sm:flex-row sm:items-center sm:justify-between">
            <span>© {new Date().getFullYear()} SEMIZZY VTU</span>
            <span>Foundation stage · No live transactions enabled</span>
          </footer>
        </div>
      </main>
    </>
  );
}
