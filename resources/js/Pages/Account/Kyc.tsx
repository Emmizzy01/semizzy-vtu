import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { FileCheck2, ShieldCheck } from 'lucide-react';

type Submission = { id: number; document_type: string; status: string; review_reason: string | null; created_at: string | null; reviewed_at: string | null };
const statusLabels: Record<string, string> = { unverified: 'Not verified', pending: 'Pending review', verified: 'Verified', rejected: 'Rejected', manual_review: 'Manual review' };
const documentLabels: Record<string, string> = { nin: 'National Identification Number (NIN) document', bvn: 'Bank Verification Number (BVN) document', passport: 'International passport', drivers_license: "Driver's licence", voters_card: "Voter's card", other: 'Other identity document' };

export default function KycPage({ kycStatus, submissions, canSubmit }: { kycStatus: string; submissions: Submission[]; canSubmit: boolean }) {
  const [documentType, setDocumentType] = useState('nin');
  const [evidence, setEvidence] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!evidence) return;
    const data = new FormData();
    data.append('document_type', documentType);
    data.append('evidence', evidence);
    setBusy(true);
    router.post('/account/kyc', data, { forceFormData: true, preserveScroll: true, onFinish: () => setBusy(false) });
  };
  const pretty = (value: string) => statusLabels[value] || value.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
  return <DashboardLayout title="Identity verification" description="Submit identity evidence for secure, manual review.">
    <Head title="Identity verification · Semizzy" />
    <div className="mx-auto max-w-3xl space-y-5">
      <section className="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-5">
        <div className="flex items-center gap-3"><ShieldCheck size={24} className="text-emerald-700 dark:text-emerald-300"/><div><h2 className="font-semibold">Current KYC status: {pretty(kycStatus)}</h2><p className="text-sm text-slate-600 dark:text-slate-300">Verification is granted only after an authorised reviewer checks your submitted evidence.</p></div></div>
      </section>
      {canSubmit && <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <h2 className="font-semibold">Submit identity document</h2>
        <p className="mt-1 text-sm text-slate-500">Accepted files: PDF, JPG or PNG, up to 5 MB. Files are stored privately and are not published to your profile.</p>
        <form onSubmit={submit} className="mt-4 space-y-4">
          <label className="block text-sm font-medium">Document type<select className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={documentType} onChange={event => setDocumentType(event.target.value)}>{Object.entries(documentLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
          <label className="block text-sm font-medium">Identity evidence<input className="mt-1 block w-full rounded-xl border p-3 text-sm dark:border-slate-700" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onChange={event => setEvidence(event.target.files?.[0] ?? null)} required /></label>
          <button disabled={!evidence || busy} className="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{busy ? 'Submitting securely…' : 'Submit for review'}</button>
        </form>
      </section>}
      {!canSubmit && kycStatus !== 'verified' && <p className="rounded-xl border border-slate-200 p-4 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">A submission is already awaiting review. You can submit another document after the current review is completed.</p>}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="mb-3 flex items-center gap-2"><FileCheck2 size={20}/><h2 className="font-semibold">Submission history</h2></div>
        {submissions.length === 0 ? <p className="text-sm text-slate-500">No identity submissions yet.</p> : <div className="space-y-3">{submissions.map(item => <article key={item.id} className="rounded-xl border p-3 dark:border-slate-800"><div className="flex flex-wrap items-center justify-between gap-2"><span className="font-medium">{documentLabels[item.document_type] || pretty(item.document_type)}</span><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs dark:bg-slate-800">{pretty(item.status)}</span></div>{item.created_at && <p className="mt-1 text-xs text-slate-500">Submitted {new Date(item.created_at).toLocaleString()}</p>}{item.review_reason && <p className="mt-2 text-sm">Review note: {item.review_reason}</p>}</article>)}</div>}
      </section>
    </div>
  </DashboardLayout>;
}
