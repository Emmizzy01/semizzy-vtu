import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { ClipboardList, Clock3, Send, ShieldCheck } from 'lucide-react';

type RequestItem = {
  public_id: string; request_type: string; business_name: string | null; description: string;
  status: string; admin_note: string | null; created_at: string | null;
};
type Pager = { data: RequestItem[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };

const labels: Record<string, string> = {
  new_vtu_site: 'New VTU website', redesign: 'Website redesign', api_integration: 'API integration',
  maintenance: 'Maintenance & support', other: 'Other request',
};
const pretty = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function VtuWebsiteRequests({ available, requests }: { available: boolean; requests: Pager }) {
  const [requestType, setRequestType] = useState('new_vtu_site');
  const [businessName, setBusinessName] = useState('');
  const [phone, setPhone] = useState('');
  const [budget, setBudget] = useState('');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    router.post('/service-requests/vtu-website', {
      request_type: requestType, business_name: businessName, contact_phone: phone,
      budget: budget === '' ? null : budget, description,
    }, { preserveScroll: true, onFinish: () => setBusy(false) });
  };

  return <DashboardLayout title="VTU website requests" description="Request a VTU website service and track the review from your account.">
    <Head title="VTU website requests · Semizzy" />
    <div className="mx-auto grid max-w-6xl gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 sm:p-6">
        <div className="flex items-start gap-3"><span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-700/10 text-emerald-700"><ClipboardList size={22}/></span><div><h2 className="font-bold">Tell us what you need</h2><p className="mt-1 text-sm text-slate-500">This is a service request, not an instant website-builder or hosting purchase.</p></div></div>
        {!available && <div role="status" className="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm">Request submissions are currently paused while an administrator enables this service. Your existing requests remain visible below.</div>}
        <form onSubmit={submit} className="mt-5 space-y-4">
          <label className="block text-sm font-medium">Request type<select disabled={!available || busy} value={requestType} onChange={e=>setRequestType(e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700">{Object.entries(labels).map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></label>
          <label className="block text-sm font-medium">Business or brand name <span className="font-normal text-slate-500">(optional)</span><input disabled={!available || busy} value={businessName} onChange={e=>setBusinessName(e.target.value)} maxLength={120} className="mt-1.5 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700" placeholder="Your business name"/></label>
          <label className="block text-sm font-medium">Contact phone<input disabled={!available || busy} required value={phone} onChange={e=>setPhone(e.target.value)} maxLength={20} className="mt-1.5 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700" placeholder="08012345678"/></label>
          <label className="block text-sm font-medium">Estimated budget in NGN <span className="font-normal text-slate-500">(optional)</span><input disabled={!available || busy} type="number" min="0" max="10000000" step="0.01" value={budget} onChange={e=>setBudget(e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700" placeholder="e.g. 50000"/></label>
          <label className="block text-sm font-medium">Describe the service you need<textarea disabled={!available || busy} required minLength={20} maxLength={5000} rows={5} value={description} onChange={e=>setDescription(e.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700" placeholder="Describe your requirements, existing site (if any), desired features and timeline."/></label>
          <div className="flex items-start gap-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300"><ShieldCheck size={16} className="shrink-0 text-emerald-700"/><span>Submitting a request does not charge your wallet or guarantee acceptance. An administrator will review the details and update the status here.</span></div>
          <button disabled={!available || busy} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"><Send size={16}/>{busy ? 'Submitting…' : 'Submit service request'}</button>
        </form>
      </section>
      <section className="space-y-3">
        <div className="flex items-center justify-between gap-3"><div><h2 className="font-bold">Your requests</h2><p className="mt-1 text-sm text-slate-500">{requests.total} request(s) submitted</p></div><Clock3 size={22} className="text-slate-400"/></div>
        {requests.data.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">You have not submitted any VTU website service requests yet.</div> : requests.data.map(item => <article key={item.public_id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950"><div className="flex flex-wrap items-start justify-between gap-2"><div><p className="text-xs font-semibold text-slate-500">{item.public_id}</p><h3 className="mt-1 font-semibold">{labels[item.request_type] || pretty(item.request_type)}</h3></div><span className="rounded-full bg-emerald-700/10 px-3 py-1 text-xs font-semibold text-emerald-800 dark:text-emerald-300">{pretty(item.status)}</span></div>{item.business_name && <p className="mt-2 text-sm text-slate-500">{item.business_name}</p>}<p className="mt-3 whitespace-pre-wrap break-words text-sm">{item.description}</p>{item.admin_note && <div className="mt-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900"><p className="text-xs font-bold text-slate-500">Admin update</p><p className="mt-1 whitespace-pre-wrap">{item.admin_note}</p></div>}<p className="mt-3 text-xs text-slate-500">{item.created_at ? new Date(item.created_at).toLocaleString() : 'Date unavailable'}</p></article>)}
        {requests.last_page > 1 && <nav className="flex flex-wrap gap-2" aria-label="Request pages">{requests.links.map((link,i)=><button key={i} disabled={!link.url} onClick={()=>link.url&&router.visit(link.url,{preserveScroll:true})} className={'rounded-lg border px-3 py-2 text-xs dark:border-slate-700 '+(link.active?'border-emerald-700 bg-emerald-700 text-white':'')}>{link.label.replace('&laquo;','«').replace('&raquo;','»')}</button>)}</nav>}
      </section>
    </div>
  </DashboardLayout>;
}
