import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { FileSearch, ShieldCheck } from 'lucide-react';

type Submission = { id: number; public_id: string; user: { id: number; name: string; email: string }; document_type: string; status: string; evidence_viewed_at: string | null; created_at: string | null };
type PageData = { data: Submission[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };
const documentLabels: Record<string, string> = { nin: 'NIN document', bvn: 'BVN document', passport: 'International passport', drivers_license: "Driver's licence", voters_card: "Voter's card", other: 'Other identity document' };

export default function KycReviews({ submissions }: { submissions: PageData }) {
  const [reasons, setReasons] = useState<Record<number, string>>({});
  const [decisions, setDecisions] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const decide = (submission: Submission) => {
    const reason = (reasons[submission.id] || '').trim();
    const decision = decisions[submission.id] || 'approved';
    if (reason.length < 8) return;
    setBusy(submission.id);
    router.post('/admin/kyc-reviews/' + submission.id + '/review', { decision, reason }, { preserveScroll: true, onFinish: () => setBusy(null) });
  };
  return <DashboardLayout title="KYC evidence review" description="Review private identity evidence and record reasoned decisions." admin>
    <Head title="KYC evidence review · Semizzy" />
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"><div className="flex items-center gap-2 font-semibold"><ShieldCheck size={18}/>Sensitive identity data</div><p className="mt-1 text-slate-600 dark:text-slate-300">Open the private evidence before approving. Evidence access and every decision are audited. Never download or share identity files outside approved review.</p></div>
      {submissions.data.length === 0 ? <div className="rounded-2xl border p-6 text-sm text-slate-500 dark:border-slate-800">No submissions are currently awaiting review.</div> : submissions.data.map(item => <article key={item.id} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold">{item.user.name}</h2><p className="text-sm text-slate-500">{item.user.email} · User #{item.user.id}</p><p className="mt-2 text-sm">{documentLabels[item.document_type] || item.document_type.replace(/_/g, ' ')}</p><p className="text-xs text-slate-500">Status: {item.status} · Submission #{item.id}</p></div><a href={'/admin/kyc-reviews/' + item.id + '/evidence'} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold dark:border-slate-700"><FileSearch size={16}/>Open private evidence</a></div>
        <label className="block text-sm font-medium">Decision<select className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={decisions[item.id] || 'approved'} onChange={event => setDecisions(current => ({ ...current, [item.id]: event.target.value }))}><option value="approved">Approve and verify</option><option value="rejected">Reject</option><option value="manual_review">Request further review</option></select></label>
        <label className="block text-sm font-medium">Reason (required)<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" minLength={8} maxLength={500} rows={2} value={reasons[item.id] || ''} onChange={event => setReasons(current => ({ ...current, [item.id]: event.target.value }))} placeholder="Record what was checked and why this decision was made."/></label>
        <button disabled={busy === item.id || (reasons[item.id] || '').trim().length < 8} onClick={() => decide(item)} className="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{busy === item.id ? 'Saving review…' : 'Save review decision'}</button>
      </article>)}
      {submissions.last_page > 1 && <nav className="flex flex-wrap gap-2">{submissions.links.map((link, index) => <a key={index} href={link.url || '#'} onClick={event => { if (!link.url) event.preventDefault(); }} className={'rounded-lg border px-3 py-2 text-sm dark:border-slate-700 ' + (link.active ? 'bg-emerald-700 text-white' : '')} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
    </div>
  </DashboardLayout>;
}
