import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { Tv, ShieldCheck } from 'lucide-react';

type Product = { id:number; platform:string; name:string; code:string; duration:string|null; price_minor:number; currency:string };
const money = (minor:number) => new Intl.NumberFormat('en-NG',{style:'currency',currency:'NGN'}).format(minor/100);

export default function StreamingSubscriptions({available,products}:{available:boolean;products:Product[]}) {
 return <DashboardLayout title="Streaming subscriptions" description="Streaming plans from verified providers.">
  <Head title="Streaming subscriptions · Semizzy"/>
  <div className="mx-auto max-w-6xl space-y-5">
   {!available && <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-5"><div className="flex items-start gap-3"><ShieldCheck className="mt-1 h-5 w-5 text-amber-600"/><div><h2 className="font-semibold">Service is being prepared</h2><p className="mt-1 text-sm text-slate-600 dark:text-slate-300">Streaming purchases are temporarily unavailable while provider fulfilment and transaction recovery are verified. No payment can be started from this page.</p></div></div></div>}
   {available && products.length===0 && <div className="rounded-2xl border p-8 text-center"><Tv className="mx-auto h-8 w-8 text-slate-400"/><h2 className="mt-3 font-semibold">No verified plans available</h2><p className="mt-1 text-sm text-slate-500">Please check again later.</p></div>}
   <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{products.map(p=><article key={p.id} className="rounded-2xl border border-slate-200 p-5 dark:border-slate-800"><div className="flex items-center gap-3"><span className="rounded-xl bg-slate-100 p-3 dark:bg-slate-800"><Tv className="h-5 w-5"/></span><div><p className="text-xs text-slate-500">{p.platform}</p><h3 className="font-semibold">{p.name}</h3></div></div><div className="mt-5 flex items-end justify-between"><div><p className="text-xl font-bold">{money(p.price_minor)}</p>{p.duration&&<p className="text-xs text-slate-500">{p.duration}</p>}</div><span className="text-xs text-emerald-600">Verified plan</span></div></article>)}</div>
   <p className="text-xs text-slate-500">Need help? <Link href="/support" className="underline">Contact support</Link>.</p>
  </div>
 </DashboardLayout>;
}
