import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { ClipboardList, Search, ShieldCheck } from 'lucide-react';

type RequestItem = {
  id:number; public_id:string; user_name:string; user_email:string|null; request_type:string;
  business_name:string|null; contact_phone:string; budget_minor:number|null; description:string;
  status:string; admin_note:string|null; created_at:string|null;
};
type Pager={data:RequestItem[];current_page:number;last_page:number;total:number;links:{url:string|null;label:string;active:boolean}[]};
type Filters={status:string;search:string};
const statuses=['submitted','in_review','awaiting_customer','approved','rejected','completed'];
const title=(value:string)=>value.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
const requestTypes:Record<string,string>={new_vtu_site:'New VTU website',redesign:'Website redesign',api_integration:'API integration',maintenance:'Maintenance & support',other:'Other request'};

export default function AdminVtuWebsiteRequests({requests,filters}:{requests:Pager;filters:Filters}) {
  const [search,setSearch]=useState(filters.search);
  const [status,setStatus]=useState(filters.status);
  const [editing,setEditing]=useState<number|null>(null);
  const [nextStatus,setNextStatus]=useState('in_review');
  const [note,setNote]=useState('');
  const [busy,setBusy]=useState(false);
  const filter=(event:FormEvent)=>{event.preventDefault();router.get('/admin/vtu-website-requests',{search,status},{preserveScroll:true});};
  const edit=(item:RequestItem)=>{setEditing(item.id);setNextStatus(item.status);setNote(item.admin_note||'');};
  const save=(event:FormEvent)=>{event.preventDefault();if(editing===null)return;setBusy(true);router.put('/admin/vtu-website-requests/'+editing+'/status',{status:nextStatus,admin_note:note},{preserveScroll:true,onFinish:()=>setBusy(false),onSuccess:()=>setEditing(null)});};

  return <DashboardLayout admin title="VTU website requests" description="Review customer service requests, record decisions and track fulfilment.">
    <Head title="VTU website requests · Admin · Semizzy"/>
    <div className="mx-auto max-w-7xl space-y-5">
      <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
        <form onSubmit={filter} className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_220px_auto]">
          <label className="flex min-h-11 items-center gap-2 rounded-xl border border-slate-300 px-3 dark:border-slate-700"><Search size={16} className="text-slate-500"/><input value={search} onChange={e=>setSearch(e.target.value)} maxLength={100} className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none" placeholder="Request ID, customer or business"/></label>
          <select value={status} onChange={e=>setStatus(e.target.value)} className="rounded-xl border border-slate-300 bg-transparent p-3 text-sm dark:border-slate-700"><option value="all">All statuses</option>{statuses.map(s=><option key={s} value={s}>{title(s)}</option>)}</select>
          <button className="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white">Filter requests</button>
        </form>
        <p className="mt-3 text-xs text-slate-500">{requests.total} request(s) match these filters.</p>
      </section>
      {requests.data.length===0 ? <div className="rounded-2xl border border-dashed border-slate-300 p-10 text-center dark:border-slate-700"><ClipboardList size={30} className="mx-auto text-slate-400"/><p className="mt-3 font-semibold">No service requests found</p><p className="mt-1 text-sm text-slate-500">New customer requests will appear here.</p></div> : <div className="grid gap-4 lg:grid-cols-2">{requests.data.map(item=><article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-bold text-slate-500">{item.public_id}</p><h2 className="mt-1 text-lg font-bold">{requestTypes[item.request_type]||title(item.request_type)}</h2><p className="mt-1 text-sm text-slate-500">{item.user_name} · {item.user_email||'No email'}</p></div><span className="rounded-full bg-slate-500/10 px-3 py-1 text-xs font-semibold">{title(item.status)}</span></div>
        {item.business_name&&<p className="mt-3 text-sm"><span className="font-semibold">Business:</span> {item.business_name}</p>}
        <p className="mt-2 text-sm"><span className="font-semibold">Contact:</span> {item.contact_phone}</p>
        {item.budget_minor!==null&&<p className="mt-1 text-sm"><span className="font-semibold">Estimated budget:</span> {(item.budget_minor/100).toLocaleString('en-NG',{style:'currency',currency:'NGN'})}</p>}
        <div className="mt-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900"><p className="mb-1 text-xs font-bold text-slate-500">Customer requirements</p><p className="whitespace-pre-wrap break-words">{item.description}</p></div>
        {item.created_at&&<p className="mt-2 text-xs text-slate-500">Submitted {new Date(item.created_at).toLocaleString()}</p>}
        {editing===item.id ? <form onSubmit={save} className="mt-4 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800"><label className="block text-sm font-medium">Request status<select value={nextStatus} onChange={e=>setNextStatus(e.target.value)} className="mt-1.5 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700">{statuses.map(s=><option key={s} value={s}>{title(s)}</option>)}</select></label><label className="block text-sm font-medium">Review note / next steps<textarea required minLength={8} maxLength={2000} rows={3} value={note} onChange={e=>setNote(e.target.value)} className="mt-1.5 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" placeholder="Record decision, requested information or completion details."/></label><div className="flex flex-wrap gap-2"><button disabled={busy} className="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-bold text-white disabled:opacity-50">{busy?'Saving…':'Save review'}</button><button type="button" onClick={()=>setEditing(null)} className="rounded-xl border px-4 py-3 text-sm dark:border-slate-700">Cancel</button></div></form> : <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-3 dark:border-slate-800">{item.admin_note?<p className="max-w-lg text-sm text-slate-500"><span className="font-semibold">Latest note:</span> {item.admin_note}</p>:<p className="text-sm text-slate-500">No review note recorded yet.</p>}<button onClick={()=>edit(item)} className="inline-flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold dark:border-slate-700"><ShieldCheck size={16}/>Review request</button></div>}
      </article>)}</div>}
      {requests.last_page>1&&<nav className="flex flex-wrap gap-2" aria-label="Request pages">{requests.links.map((link,i)=><button key={i} disabled={!link.url} onClick={()=>link.url&&router.visit(link.url,{preserveScroll:true})} className={'rounded-lg border px-3 py-2 text-xs dark:border-slate-700 '+(link.active?'border-emerald-700 bg-emerald-700 text-white':'')}>{link.label.replace('&laquo;','«').replace('&raquo;','»')}</button>)}</nav>}
    </div>
  </DashboardLayout>;
}
