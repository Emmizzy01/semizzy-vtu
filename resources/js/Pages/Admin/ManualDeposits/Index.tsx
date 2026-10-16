import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';
import { ExternalLink, Search } from 'lucide-react';

type Deposit = { id: string; customer: string | null; email: string | null; amount_minor: number; reference: string; status: string; created_at: string | null; evidence_viewed_by_current_admin: boolean; duplicate_evidence: boolean; review_reason: string | null };
type PageData = { data: Deposit[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };
const money = (amount: number) => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(amount / 100);

export default function ManualDepositsAdmin({ deposits, filters }: { deposits: PageData; filters: { status: string; search: string } }) {
  const [search, setSearch] = useState(filters.search);
  const [reason, setReason] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const submitSearch = (e: FormEvent) => { e.preventDefault(); router.get('/admin/manual-deposits', { status: filters.status, search }, { preserveState: true, preserveScroll: true }); };
  const review = (id: string, decision: 'approve' | 'reject') => {
    const why = (reason[id] || '').trim();
    if (why.length < 8) { setError('Add a review reason of at least 8 characters before deciding.'); return; }
    if (decision === 'approve' && !window.confirm('Confirm the transfer has been independently verified and should credit this wallet once?')) return;
    setError(''); setBusy(id);
    router.post('/admin/manual-deposits/' + encodeURIComponent(id) + '/review', { decision, reason: why }, { preserveScroll: true, onFinish: () => setBusy('') });
  };
  return <DashboardLayout title="Manual deposit review" description="Verify transfer evidence before approving a one-time wallet credit." admin>
    <Head title="Manual deposit review · Semizzy" />
    <div className="mx-auto max-w-6xl space-y-5">
      {error && <div role="alert" className="rounded-xl bg-rose-500/10 p-3 text-sm text-rose-700">{error}</div>}
      <form onSubmit={submitSearch} className="flex flex-wrap gap-3 rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
        <select className="rounded-xl border bg-transparent p-2 dark:border-slate-700" value={filters.status} onChange={e => router.get('/admin/manual-deposits', { status: e.target.value, search }, { preserveState: true })}><option value="pending">Pending</option><option value="review_required">Needs reconciliation</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="all">All</option></select>
        <input className="min-w-0 flex-1 rounded-xl border bg-transparent p-2 dark:border-slate-700" placeholder="Customer, email, deposit ID or transfer ref" value={search} onChange={e => setSearch(e.target.value)} />
        <button className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-white"><Search size={16} />Search</button>
        <a className="rounded-xl border px-4 py-2 dark:border-slate-700" href="/admin/manual-deposits/settings">Deposit instructions</a>
      </form>
      <div className="space-y-4">{deposits.data.length === 0 ? <div className="rounded-2xl border p-8 text-center text-sm text-slate-500 dark:border-slate-800">No deposits match this view.</div> : deposits.data.map(d => <article key={d.id} className="space-y-4 rounded-2xl border border-slate-200 p-5 dark:border-slate-800">
        <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold">{money(d.amount_minor)} · {d.customer || 'Unknown customer'}</h2><p className="text-sm text-slate-500">{d.email} · {d.reference}</p><p className="mt-1 break-all text-xs text-slate-500">Deposit ID: {d.id} · {d.created_at ? new Date(d.created_at).toLocaleString() : '—'}</p></div><span className="rounded-full bg-slate-500/10 px-3 py-1 text-xs font-semibold capitalize">{d.status}</span></div>
        <div className="flex flex-wrap items-center gap-3"><a className="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm dark:border-slate-700" href={'/admin/manual-deposits/' + encodeURIComponent(d.id) + '/evidence'} target="_blank" rel="noreferrer"><ExternalLink size={15} />Open private evidence</a>{!d.evidence_viewed_by_current_admin && (d.status === 'pending' || d.status === 'review_required') && <span className="text-xs text-amber-700 dark:text-amber-300">Evidence must be opened before review.</span>}</div>
        {d.duplicate_evidence && <p className="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-700 dark:text-rose-300">Duplicate receipt evidence detected. Approval is blocked; investigate and reject or escalate this submission.</p>}
        {(d.status === 'pending' || d.status === 'review_required') && <div className="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800"><label className="block text-sm font-medium">Reason / verification note<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" rows={2} value={reason[d.id] || ''} onChange={e => setReason(v => ({ ...v, [d.id]: e.target.value }))} minLength={8} maxLength={500} /></label><div className="flex flex-wrap gap-2"><button type="button" disabled={busy === d.id || !d.evidence_viewed_by_current_admin || d.duplicate_evidence} onClick={() => review(d.id, 'approve')} className="rounded-xl bg-emerald-700 px-4 py-2 font-semibold text-white disabled:opacity-40">{busy === d.id ? 'Saving…' : 'Verify & credit once'}</button><button type="button" disabled={busy === d.id || !d.evidence_viewed_by_current_admin} onClick={() => review(d.id, 'reject')} className="rounded-xl border border-rose-500/40 px-4 py-2 text-rose-700 disabled:opacity-40 dark:text-rose-300">Reject</button></div></div>}
        {d.review_reason && <p className="text-sm text-slate-500">Review note: {d.review_reason}</p>}
      </article>)}</div>
      <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500"><span>{deposits.total} submissions · page {deposits.current_page} of {deposits.last_page}</span><div className="flex gap-2">{deposits.links.map((link, i) => link.url ? <a key={i} href={link.url} className={'rounded-lg border px-3 py-2 dark:border-slate-700 ' + (link.active ? 'bg-emerald-700 text-white' : '')} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={i} className="rounded-lg border px-3 py-2 opacity-40 dark:border-slate-700" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div></div>
    </div>
  </DashboardLayout>;
}
