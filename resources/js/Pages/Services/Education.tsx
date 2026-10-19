import { Head } from '@inertiajs/react';
import { GraduationCap, ShieldCheck, Clock3 } from 'lucide-react';
import DashboardLayout from '../../Components/DashboardLayout';

type Product = { id: number; service_type: string; exam_body: string; name: string; code: string; price_minor: number; currency: string; requires_candidate_validation: boolean };

export default function Education({ available, products, purchaseEnabled, message }: { available: boolean; products: Product[]; purchaseEnabled: boolean; message: string }) {
  return <DashboardLayout title="Education services" description="Examination and authorized education services">
    <Head title="Education services · Semizzy" />
    <div className="mx-auto max-w-5xl space-y-5">
      <section className="rounded-2xl bg-white p-6 shadow-sm dark:bg-slate-900 sm:p-8">
        <div className="flex items-start gap-4"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><GraduationCap size={28}/></span>
          <div><p className="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Education & authorized services</p><h1 className="mt-1 text-2xl font-extrabold">Education services</h1><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">Examination pins and supported education services will appear here after provider, pricing and candidate-validation checks are complete.</p></div>
        </div>
      </section>
      {!available ? <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30"><div className="flex gap-3"><Clock3 className="mt-0.5 shrink-0 text-amber-700 dark:text-amber-300"/><div><h2 className="font-bold">Service is not enabled yet</h2><p className="mt-1 text-sm leading-6 text-slate-700 dark:text-slate-300">{message}</p></div></div></section> : null}
      {available && products.length > 0 ? <section className="grid gap-3 sm:grid-cols-2">{products.map(product => { const siblings = products.filter(candidate => candidate.exam_body === product.exam_body && candidate.name.trim().toLowerCase() === product.name.trim().toLowerCase() && candidate.price_minor === product.price_minor); const option = siblings.length > 1 ? `Option ${siblings.findIndex(candidate => candidate.id === product.id) + 1}` : ''; return <article key={product.id} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><p className="text-xs font-bold uppercase text-emerald-700">{product.exam_body}</p><h2 className="mt-1 font-bold">{product.name}</h2>{option && <p className="mt-1 text-xs text-slate-500">{option}</p>}<p className="mt-2 text-sm">₦{(product.price_minor / 100).toLocaleString('en-NG', {minimumFractionDigits: 2})}</p><p className="mt-3 text-xs text-slate-500">Purchase is not enabled for this catalogue.</p></article>; })}</section> : null}
      <section className="flex gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-900"><ShieldCheck className="shrink-0 text-emerald-700"/><p className="text-slate-600 dark:text-slate-300">{purchaseEnabled ? 'Provider-verified purchase workflows will be shown when available.' : 'No payment or purchase can be started here. Candidate validation, provider status, idempotency, lookup/recovery and refund handling must be verified first.'}</p></section>
    </div>
  </DashboardLayout>;
}
