import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import { Search, ShieldCheck, UserCog } from 'lucide-react';

type UserRow = { id: number; name: string; email: string; user_type: string; user_level: string; kyc_status: string; account_status: string; account_status_reason: string | null; account_status_changed_at: string | null; created_at: string | null };
type PageData = { data: UserRow[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };
type Filters = { search: string; type: string; level: string; kyc: string };
type Options = { types: string[]; levels: string[]; kyc: string[] };
const labels: Record<string,string> = { customer:'Customer', vendor:'Vendor', reseller:'Reseller', agent:'Agent', business_team:'Business team', support:'Support', finance:'Finance', compliance:'Compliance', administrator:'Administrator', level_1:'Tier 1', level_2:'Tier 2', level_3:'Tier 3', level_4:'Merchant (Tier 4)', unverified:'Unverified', pending:'Pending review', verified:'Verified', rejected:'Rejected', manual_review:'Manual review' };

export default function UserManagement({ users, filters, options }: { users: PageData; filters: Filters; options: Options }) {
  const [search, setSearch] = useState(filters.search);
  const [edits, setEdits] = useState<Record<number, { user_type: string; user_level: string; kyc_status: string; reason: string }>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const [statusBusy, setStatusBusy] = useState<number | null>(null);
  const [error, setError] = useState('');
  const filter = (e: FormEvent) => { e.preventDefault(); router.get('/admin/users', { search, type: filters.type, level: filters.level, kyc: filters.kyc }, { preserveState: true, preserveScroll: true }); };
  const change = (u: UserRow, key: 'user_type'|'user_level'|'kyc_status'|'reason', value: string) => setEdits(current => ({ ...current, [u.id]: { user_type: current[u.id]?.user_type ?? u.user_type, user_level: current[u.id]?.user_level ?? u.user_level, kyc_status: current[u.id]?.kyc_status ?? u.kyc_status, reason: current[u.id]?.reason ?? '', [key]: value } }));
  const changeAccountStatus = (u: UserRow) => {
    const v = edits[u.id] ?? { user_type: u.user_type, user_level: u.user_level, kyc_status: u.kyc_status, reason: '' };
    if (v.reason.trim().length < 8) { setError('Enter a reason of at least 8 characters before changing account status.'); return; }
    setError(''); setStatusBusy(u.id);
    router.put('/admin/users/' + u.id + '/status', { account_status: u.account_status === 'suspended' ? 'active' : 'suspended', reason: v.reason }, { preserveScroll: true, onFinish: () => setStatusBusy(null) });
  };
  const save = (u: UserRow) => {
    const v = edits[u.id] ?? { user_type: u.user_type, user_level: u.user_level, kyc_status: u.kyc_status, reason: '' };
    if (v.reason.trim().length < 8) { setError('Each change needs a reason of at least 8 characters.'); return; }
    setError(''); setBusy(u.id);
    router.put('/admin/users/' + u.id + '/classification', v, { preserveScroll: true, onFinish: () => setBusy(null) });
  };
  return <DashboardLayout title="Users, types & levels" description="Manage account classification and KYC review state with a reasoned audit trail." admin>
    <Head title="Users, types & levels · Semizzy" />
    <div className="mx-auto max-w-7xl space-y-5">
      <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"><div className="flex items-center gap-2 font-semibold"><ShieldCheck size={17} />Access safety</div><p className="mt-1 text-slate-600 dark:text-slate-300">Account type and level are classifications, not permission grants. Administrator, finance, support and compliance access still requires explicit server-side permissions. KYC approval does not automatically grant every privilege. New KYC verification is blocked here until identity evidence can be reviewed and audited.</p></div>
      {error && <div role="alert" className="rounded-xl bg-rose-500/10 p-3 text-sm text-rose-700">{error}</div>}
      <form onSubmit={filter} className="flex flex-wrap gap-2 rounded-2xl border border-slate-200 p-3 dark:border-slate-800">
        <input className="min-w-0 flex-1 rounded-xl border bg-transparent p-2 dark:border-slate-700" placeholder="Search name or email" value={search} onChange={e => setSearch(e.target.value)} />
        {(['type','level','kyc'] as const).map(key => <select key={key} className="rounded-xl border bg-transparent p-2 dark:border-slate-700" value={filters[key]} onChange={e => router.get('/admin/users', { search, type: key==='type'?e.target.value:filters.type, level:key==='level'?e.target.value:filters.level, kyc:key==='kyc'?e.target.value:filters.kyc }, { preserveState: true })}><option value="all">All {key === 'type' ? 'types' : key === 'level' ? 'levels' : 'KYC states'}</option>{(key==='type'?options.types:key==='level'?options.levels:options.kyc).map(v=><option key={v} value={v}>{labels[v] || v}</option>)}</select>)}
        <button className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-white"><Search size={16}/>Search</button>
      </form>
      <div className="space-y-4">{users.data.map(u => { const v = edits[u.id] ?? { user_type: u.user_type || 'customer', user_level: u.user_level || 'level_1', kyc_status: u.kyc_status || 'unverified', reason: '' }; return <article key={u.id} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950"><div className="flex items-start gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><UserCog size={21}/></span><div className="min-w-0 flex-1"><h2 className="font-semibold">{u.name}</h2><p className="break-all text-sm text-slate-500">{u.email} · User #{u.id}</p><p className="mt-1 text-xs text-slate-500">Current: {labels[u.user_type] || u.user_type} · {labels[u.user_level] || u.user_level} · KYC {labels[u.kyc_status] || u.kyc_status}</p><p className={"mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold " + (u.account_status === "suspended" ? "bg-rose-500/10 text-rose-700 dark:text-rose-300" : "bg-emerald-500/10 text-emerald-700 dark:text-emerald-300")}>{u.account_status === "suspended" ? "Account suspended" : "Account active"}</p>{u.account_status_reason && <p className="mt-1 text-xs text-slate-500">Status reason: {u.account_status_reason}</p>}</div></div><div className="grid gap-3 sm:grid-cols-3">{(['user_type','user_level','kyc_status'] as const).map(key => <label key={key} className="block text-xs font-semibold uppercase tracking-wide text-slate-500">{key==='user_type'?'User type':key==='user_level'?'User level':'KYC status'}<select className="mt-1 w-full rounded-xl border bg-transparent p-3 text-sm font-normal normal-case dark:border-slate-700" value={v[key]} onChange={e => change(u,key,e.target.value)}>{(key==='user_type'?options.types:key==='user_level'?options.levels:options.kyc).map(option=><option key={option} value={option} disabled={key==='kyc_status' && option==='verified' && u.kyc_status!=='verified'}>{labels[option] || option}{key==='kyc_status' && option==='verified' && u.kyc_status!=='verified' ? ' (requires evidence review)' : ''}</option>)}</select></label>)}</div><label className="block text-sm font-medium">Reason for change<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" rows={2} value={v.reason} onChange={e => change(u,'reason',e.target.value)} minLength={8} maxLength={500}/></label><div className="flex flex-wrap gap-2"><button disabled={busy===u.id} onClick={() => save(u)} className="rounded-xl bg-emerald-700 px-4 py-2 font-semibold text-white disabled:opacity-50">{busy===u.id?'Saving…':'Save classification'}</button><button type="button" disabled={statusBusy===u.id} onClick={() => changeAccountStatus(u)} className="rounded-xl border border-rose-500/40 px-4 py-2 font-semibold text-rose-700 disabled:opacity-50 dark:text-rose-300">{statusBusy===u.id ? "Updating…" : u.account_status === "suspended" ? "Reactivate account" : "Suspend account"}</button></div></article>; })}</div>
      <div className="flex items-center justify-between text-sm text-slate-500"><span>{users.total} users · page {users.current_page} of {users.last_page}</span><div className="flex gap-2">{users.links.map((link,i)=>link.url?<a key={i} href={link.url} className={'rounded-lg border px-3 py-2 dark:border-slate-700 '+(link.active?'bg-emerald-700 text-white':'')} dangerouslySetInnerHTML={{__html:link.label}}/>:<span key={i} className="rounded-lg border px-3 py-2 opacity-40 dark:border-slate-700" dangerouslySetInnerHTML={{__html:link.label}}/>)}</div></div>
    </div>
  </DashboardLayout>;
}
