import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, LifeBuoy, ShieldCheck } from 'lucide-react';

type HelpPageData = { slug: string; label: string; icon: string; title: string; intro: string; body: string; image: string };
type SupportLink = { slug: string; label: string; icon: string };

export default function HelpPage({ page, supportLinks }: { page: HelpPageData; supportLinks: SupportLink[] }) {
  return <div className="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-white">
    <Head title={page.label + ' · Semizzy'} />
    <header className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
      <div className="mx-auto flex max-w-6xl items-center justify-between px-5 py-4">
        <Link href="/" className="flex items-center gap-2 font-extrabold tracking-tight"><span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-700 text-white">S</span> SEMIZZY</Link>
        <Link href="/support" className="rounded-full bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800">Contact support</Link>
      </div>
    </header>
    <main className="mx-auto max-w-5xl space-y-6 px-5 py-8 md:py-12">
      <Link href="/" className="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 hover:underline"><ArrowLeft size={16} /> Back to Semizzy</Link>
      <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="grid md:grid-cols-2">
          <div className="p-7 md:p-10">
            <div className="mb-5 grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-3xl dark:bg-emerald-950">{page.icon}</div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Semizzy Support</p>
            <h1 className="mt-3 text-3xl font-extrabold tracking-tight">{page.title}</h1>
            <p className="mt-4 leading-7 text-slate-600 dark:text-slate-300">{page.intro}</p>
            <Link href="/support" className="mt-7 inline-flex items-center gap-2 rounded-full bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Open a support ticket <ArrowRight size={16} /></Link>
          </div>
          <div className="min-h-64 bg-slate-100 dark:bg-slate-900">
            <img src={page.image} alt="" className="h-full min-h-64 w-full object-cover" loading="lazy" />
          </div>
        </div>
        <div className="border-t border-slate-100 p-7 dark:border-slate-800 md:p-10">
          <div className="mb-4 flex items-center gap-2 font-bold"><LifeBuoy size={18} className="text-emerald-700" /> {page.label}</div>
          <div className="whitespace-pre-line text-sm leading-7 text-slate-700 dark:text-slate-300">{page.body}</div>
          <div className="mt-7 flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"><ShieldCheck size={19} className="mt-0.5 shrink-0" /><p>For your safety, never share your password, one-time password (OTP) or transaction PIN with anyone claiming to be support.</p></div>
        </div>
      </section>
      <section>
        <h2 className="mb-3 text-lg font-bold">More support topics</h2>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {supportLinks.filter(item => item.slug !== page.slug).map(item => <Link key={item.slug} href={'/help/' + item.slug} className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 hover:border-emerald-500 dark:border-slate-800 dark:bg-slate-900"><span className="text-2xl">{item.icon}</span><span className="text-sm font-semibold">{item.label}</span></Link>)}
        </div>
      </section>
    </main>
    <footer className="border-t border-slate-200 py-6 text-center text-xs text-slate-500 dark:border-slate-800">© 2026 Semizzy. All rights reserved.</footer>
  </div>;
}
