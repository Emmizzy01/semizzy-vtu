import { Head } from '@inertiajs/react';
import {
  ArrowRight,
  BadgeCheck,
  Bell,
  ChevronRight,
  Droplets,
  Gift,
  Globe,
  GraduationCap,
  Headphones,
  Lightbulb,
  MessageCircle,
  Plane,
  ShieldCheck,
  Smartphone,
  Sparkles,
  Ticket,
  Tv,
  Wallet,
  Wifi,
  Zap,
} from 'lucide-react';

const services = [
  { label: 'Airtime', icon: Smartphone, tone: 'bg-emerald-50 text-emerald-700' },
  { label: 'Data', icon: Wifi, tone: 'bg-sky-50 text-sky-700' },
  { label: 'Electricity', icon: Lightbulb, tone: 'bg-amber-50 text-amber-700' },
  { label: 'Cable TV', icon: Tv, tone: 'bg-violet-50 text-violet-700' },
  { label: 'Internet', icon: Globe, tone: 'bg-cyan-50 text-cyan-700' },
  { label: 'Water Bill', icon: Droplets, tone: 'bg-blue-50 text-blue-700' },
  { label: 'Education', icon: GraduationCap, tone: 'bg-orange-50 text-orange-700' },
  { label: 'Betting', icon: Ticket, tone: 'bg-rose-50 text-rose-700' },
  { label: 'Airtime to Cash', icon: Wallet, tone: 'bg-lime-50 text-lime-700' },
  { label: 'Bulk SMS', icon: MessageCircle, tone: 'bg-indigo-50 text-indigo-700' },
  { label: 'Gift Cards', icon: Gift, tone: 'bg-pink-50 text-pink-700' },
  { label: 'Flight', icon: Plane, tone: 'bg-teal-50 text-teal-700' },
];

const benefits = [
  {
    icon: ShieldCheck,
    title: 'Security comes first',
    description: 'Account protections and careful transaction handling are built into the platform.',
  },
  {
    icon: Zap,
    title: 'Everyday services',
    description: 'Find the digital services you use most in one simple, organized place.',
  },
  {
    icon: BadgeCheck,
    title: 'Made to grow with you',
    description: 'A modular platform for individuals, resellers, and growing businesses.',
  },
];

export default function Welcome() {
  return (
    <>
      <Head title="Home" />
      <main className="min-h-screen overflow-hidden bg-[#eef4ef] text-[#111827]">
        <div className="mx-auto max-w-7xl px-4 pb-8 pt-4 sm:px-6 lg:px-8">
          <header className="flex items-center justify-between gap-3 rounded-2xl border border-black/5 bg-white px-4 py-3 shadow-sm sm:px-5">
            <a href="/" aria-label="SEMIZZY home" className="flex min-w-0 items-center gap-3">
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-[#0f8a4f] text-xl font-extrabold text-white shadow-sm">S</span>
              <span className="min-w-0">
                <strong className="block text-base font-extrabold tracking-tight text-[#0a2e1c]">SEMIZZY</strong>
                <span className="block text-[10px] font-semibold tracking-wide text-[#6b7280]">DIGITAL MADE SIMPLE</span>
              </span>
            </a>
            <nav aria-label="Account navigation" className="flex shrink-0 items-center gap-1.5 sm:gap-3">
              <a href="/login" className="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-bold text-[#6b7280] transition hover:bg-[#eef4ef] hover:text-[#0a5c32]">Sign in</a>
              <a href="/register" className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[#0f8a4f] px-3.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#0a5c32] sm:px-5">
                Join Semizzy <ArrowRight size={16} aria-hidden="true" />
              </a>
            </nav>
          </header>

          <section className="grid items-center gap-7 py-7 sm:py-10 lg:grid-cols-[1.02fr_0.98fr] lg:gap-10">
            <div className="rounded-[2rem] border border-black/5 bg-white p-6 shadow-sm sm:p-9 lg:p-10">
              <div className="mb-5 inline-flex items-center gap-2 rounded-full bg-[#d6f5e2] px-3 py-1.5 text-xs font-bold text-[#0a5c32]">
                <Sparkles size={14} aria-hidden="true" />
                Your digital services, together
              </div>
              <h1 className="max-w-2xl text-4xl font-extrabold leading-[1.08] tracking-[-0.045em] text-[#0a2e1c] sm:text-5xl lg:text-6xl">
                Everyday digital services. <span className="text-[#0f8a4f]">Made simple.</span>
              </h1>
              <p className="mt-5 max-w-xl text-base leading-7 text-[#6b7280] sm:text-lg">
                Buy airtime and data, manage your wallet, and explore useful digital services from one convenient Semizzy account.
              </p>
              <div className="mt-7 flex flex-wrap gap-3">
                <a href="/register" className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#0f8a4f] px-6 py-3 text-sm font-extrabold text-white shadow-md shadow-emerald-950/10 transition hover:-translate-y-0.5 hover:bg-[#0a5c32]">
                  Get started <ArrowRight size={17} aria-hidden="true" />
                </a>
                <a href="#services" className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-[#d9e5dc] bg-white px-5 py-3 text-sm font-bold text-[#0a5c32] transition hover:bg-[#f6faf7]">
                  Explore services <ChevronRight size={17} aria-hidden="true" />
                </a>
              </div>
              <div className="mt-7 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold text-[#6b7280]">
                <span className="inline-flex items-center gap-1.5"><ShieldCheck size={15} className="text-[#0f8a4f]" /> Security-minded</span>
                <span className="inline-flex items-center gap-1.5"><Smartphone size={15} className="text-[#0f8a4f]" /> Mobile-friendly</span>
                <span className="inline-flex items-center gap-1.5"><Wallet size={15} className="text-[#0f8a4f]" /> Wallet tools</span>
              </div>
            </div>

            <div className="relative mx-auto w-full max-w-xl">
              <div className="absolute -inset-3 rounded-[2.5rem] bg-[#0f8a4f]/10 blur-2xl" />
              <div className="relative rounded-[2rem] border border-black/5 bg-white p-4 shadow-xl shadow-emerald-950/10 sm:p-5">
                <div className="flex items-center justify-between gap-3 px-1 pb-4">
                  <div>
                    <p className="text-xs font-bold text-[#6b7280]">WELCOME TO SEMIZZY</p>
                    <h2 className="mt-1 text-xl font-extrabold tracking-tight text-[#0a2e1c]">Your digital space</h2>
                  </div>
                  <span className="grid h-10 w-10 place-items-center rounded-2xl bg-[#d6f5e2] text-[#0f8a4f]"><Bell size={19} aria-hidden="true" /></span>
                </div>
                <div className="rounded-2xl bg-[#0f8a4f] p-5 text-white sm:p-6">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-white/80">Wallet</p>
                      <p className="mt-2 text-2xl font-extrabold tracking-tight sm:text-3xl">Your money, in view.</p>
                    </div>
                    <span className="rounded-xl bg-white/15 p-3"><Wallet size={21} aria-hidden="true" /></span>
                  </div>
                  <p className="mt-3 max-w-sm text-sm leading-6 text-white/80">Create an account to access wallet tools and available services.</p>
                  <a href="/register" className="mt-5 inline-flex min-h-10 items-center gap-2 rounded-xl bg-white px-4 text-sm font-extrabold text-[#0a5c32] transition hover:bg-emerald-50">
                    Open your account <ArrowRight size={16} aria-hidden="true" />
                  </a>
                </div>
                <div className="mt-4 flex items-center justify-between px-1">
                  <h3 className="text-sm font-extrabold text-[#0a2e1c]">Popular services</h3>
                  <a href="#services" className="inline-flex items-center gap-1 text-xs font-bold text-[#0f8a4f]">See all <ChevronRight size={14} aria-hidden="true" /></a>
                </div>
                <div className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
                  {services.slice(0, 8).map(({ label, icon: Icon, tone }) => (
                    <a key={label} href="/register" className="group flex min-h-[92px] flex-col items-center justify-center gap-2 rounded-2xl border border-[#edf1ed] bg-[#fbfdfb] px-2 py-3 text-center transition hover:border-[#b8e6c9] hover:bg-[#f1fbf4]">
                      <span className={`grid h-10 w-10 place-items-center rounded-xl ${tone}`}><Icon size={19} aria-hidden="true" /></span>
                      <span className="text-[11px] font-bold text-[#374151]">{label}</span>
                    </a>
                  ))}
                </div>
                <p className="mt-3 text-center text-[10px] font-medium text-[#6b7280]">Sign in to see the services available to your account.</p>
              </div>
            </div>
          </section>

          <section id="services" className="scroll-mt-6 rounded-[2rem] border border-black/5 bg-white p-5 shadow-sm sm:p-7 lg:p-8">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <p className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0f8a4f]">Popular services</p>
                <h2 className="mt-2 text-2xl font-extrabold tracking-tight text-[#0a2e1c] sm:text-3xl">Everything starts in one place.</h2>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-[#6b7280]">Explore the service categories planned for Semizzy. Availability can depend on your account and current service activation.</p>
              </div>
              <a href="/register" className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[#e7f8ed] px-4 text-sm font-extrabold text-[#0a5c32] transition hover:bg-[#d6f5e2]">Create account <ArrowRight size={15} aria-hidden="true" /></a>
            </div>
            <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-6">
              {services.map(({ label, icon: Icon, tone }) => (
                <a key={label} href="/register" className="group flex min-h-[126px] flex-col items-start rounded-2xl border border-[#edf1ed] bg-[#fbfdfb] p-4 transition hover:-translate-y-0.5 hover:border-[#b8e6c9] hover:bg-white hover:shadow-md">
                  <span className={`grid h-11 w-11 place-items-center rounded-2xl ${tone}`}><Icon size={21} aria-hidden="true" /></span>
                  <span className="mt-4 text-sm font-extrabold text-[#0a2e1c]">{label}</span>
                  <span className="mt-1 text-xs font-medium text-[#6b7280]">Explore service <ArrowRight size={12} className="ml-1 inline" aria-hidden="true" /></span>
                </a>
              ))}
            </div>
          </section>

          <section className="grid gap-4 py-7 md:grid-cols-3 sm:py-10">
            {benefits.map(({ icon: Icon, title, description }) => (
              <article key={title} className="rounded-3xl border border-black/5 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                <span className="grid h-12 w-12 place-items-center rounded-2xl bg-[#d6f5e2] text-[#0f8a4f]"><Icon size={22} aria-hidden="true" /></span>
                <h2 className="mt-5 text-lg font-extrabold text-[#0a2e1c]">{title}</h2>
                <p className="mt-2 text-sm leading-6 text-[#6b7280]">{description}</p>
              </article>
            ))}
          </section>

          <section className="flex flex-col items-start justify-between gap-5 rounded-[2rem] bg-[#0a2e1c] p-6 text-white sm:flex-row sm:items-center sm:p-9">
            <div className="max-w-2xl">
              <p className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#9ee7b8]">Ready when you are</p>
              <h2 className="mt-2 text-2xl font-extrabold tracking-tight sm:text-3xl">Start your Semizzy journey.</h2>
              <p className="mt-2 text-sm leading-6 text-white/70">Create your account to access the dashboard and the services enabled for you.</p>
            </div>
            <a href="/register" className="inline-flex min-h-12 shrink-0 items-center gap-2 rounded-xl bg-[#12a15a] px-5 text-sm font-extrabold text-white transition hover:bg-[#0f8a4f]">Create account <ArrowRight size={16} aria-hidden="true" /></a>
          </section>

          <footer className="mt-6 flex flex-col gap-3 border-t border-[#dce7de] py-6 text-xs font-medium text-[#6b7280] sm:flex-row sm:items-center sm:justify-between">
            <span>© {new Date().getFullYear()} SEMIZZY</span>
            <span className="inline-flex items-center gap-1.5"><ShieldCheck size={14} className="text-[#0f8a4f}" /> We Design. We Develop. We Deliver.</span>
            <div className="flex gap-4"><a href="/login" className="hover:text-[#0a5c32]">Sign in</a><a href="/register" className="hover:text-[#0a5c32]">Register</a></div>
          </footer>
        </div>
      </main>
    </>
  );
}
