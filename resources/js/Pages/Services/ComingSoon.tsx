import { Head } from '@inertiajs/react';
import { ArrowLeft, Clock3, ShieldCheck } from 'lucide-react';
import DashboardLayout from '../../Components/DashboardLayout';

export default function ServiceComingSoon({ serviceName }: { serviceName: string }) {
  return <DashboardLayout title={serviceName} description="">
    <Head title={serviceName + ' · Semizzy'} />
    <section className="mx-auto flex min-h-[55vh] max-w-2xl flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-10">
      <span className="grid h-16 w-16 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><Clock3 size={30}/></span>
      <p className="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-emerald-700 dark:text-emerald-300">Coming soon</p>
      <h1 className="mt-2 text-2xl font-extrabold">{serviceName}</h1>
      <p className="mt-3 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">This service is included in the Semizzy experience and its entry point is ready. The provider or operational workflow is not enabled yet, so no payment or transaction can be started from this page.</p>
      <div className="mt-5 flex items-center gap-2 rounded-xl bg-slate-50 px-4 py-3 text-left text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"><ShieldCheck size={17} className="shrink-0 text-emerald-600"/><span>Transactions will only be enabled after the required service integration and security checks are complete.</span></div>
      <div className="mt-6 flex w-full flex-col justify-center gap-3 sm:w-auto sm:flex-row">
        <a href="/dashboard" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white"><ArrowLeft size={16}/> Back to dashboard</a>
        <a href="/services" className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold dark:border-slate-700">Browse services</a>
      </div>
    </section>
  </DashboardLayout>;
}
