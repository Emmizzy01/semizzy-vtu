import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { Building2, Clock3, Upload } from 'lucide-react';

type Deposit = { id: string; amount_minor: number; reference: string; status: string; created_at: string | null; reviewed_at: string | null; review_reason: string | null };
type Instructions = { bank_name: string; account_name: string; account_number: string; instructions: string; enabled: boolean };
const money = (amount: number) => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(amount / 100);

export default function ManualDeposit({ instructions, deposits }: { instructions: Instructions; walletCurrency: string; deposits: Deposit[] }) {
  const [amount, setAmount] = useState('1000');
  const [reference, setReference] = useState('');
  const [evidence, setEvidence] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('status') || '');

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setError('');
    const parsed = Number(amount);
    if (!Number.isFinite(parsed) || parsed < 100 || parsed > 5000000) { setError('Enter an amount between ₦100 and ₦5,000,000.'); return; }
    if (!reference.trim() || !evidence) { setError('Enter your bank transfer reference and attach a JPG, PNG or PDF receipt.'); return; }
    const data = new FormData();
    data.append('amount_minor', String(Math.round(parsed * 100)));
    data.append('transfer_reference', reference.trim());
    data.append('evidence', evidence);
    setBusy(true);
    router.post('/wallet/manual-deposits', data, { forceFormData: true, onFinish: () => setBusy(false), onSuccess: () => { setReference(''); setEvidence(null); setNotice('Deposit submitted. Your wallet remains unchanged until an administrator verifies the transfer.'); } });
  };

  return <DashboardLayout title="Manual deposit" description="Fund your wallet by bank transfer, then submit proof for review.">
    <Head title="Manual deposit · Semizzy" />
    <div className="mx-auto max-w-4xl space-y-6">
      {notice && <div role="status" className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm">{notice}</div>}
      {error && <div role="alert" className="rounded-xl border border-rose-500/30 p-4 text-sm text-rose-700 dark:text-rose-300">{error}</div>}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
        <div className="mb-4 flex items-center gap-3"><Building2 className="text-emerald-600" /><div><h2 className="font-semibold">Bank transfer instructions</h2><p className="text-sm text-slate-500">Use these details and keep your transfer receipt.</p></div></div>
        {!instructions.enabled ? <p className="rounded-xl bg-amber-500/10 p-4 text-sm">Manual deposits are temporarily unavailable. Please use another available funding method.</p> : <>
          <dl className="grid gap-4 sm:grid-cols-2">
            <div><dt className="text-xs uppercase tracking-wide text-slate-500">Bank</dt><dd className="font-semibold">{instructions.bank_name || 'Not configured yet'}</dd></div>
            <div><dt className="text-xs uppercase tracking-wide text-slate-500">Account name</dt><dd className="font-semibold">{instructions.account_name || 'Not configured yet'}</dd></div>
            <div><dt className="text-xs uppercase tracking-wide text-slate-500">Account number</dt><dd className="font-semibold">{instructions.account_number || 'Not configured yet'}</dd></div>
          </dl>
          <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-700 dark:bg-slate-900 dark:text-slate-300">{instructions.instructions}</p>
          <p className="mt-3 text-xs text-amber-700 dark:text-amber-300">Do not upload a receipt for a transfer you have not completed. Uploading evidence does not credit your wallet.</p>
        </>}
      </section>
      {instructions.enabled && <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
        <h2 className="font-semibold">Submit a completed transfer</h2>
        <label className="block text-sm font-medium">Amount (NGN)<input className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700" inputMode="decimal" value={amount} onChange={e => setAmount(e.target.value)} required /></label>
        <label className="block text-sm font-medium">Bank transfer reference<input className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700" value={reference} onChange={e => setReference(e.target.value)} maxLength={120} required /></label>
        <label className="block text-sm font-medium">Transfer evidence (JPG, PNG or PDF, max 5 MB)<input className="mt-1 block w-full rounded-xl border border-slate-300 p-3 text-sm dark:border-slate-700" type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={e => setEvidence(e.target.files?.[0] ?? null)} required /></label>
        <button disabled={busy} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50"><Upload size={17} />{busy ? 'Submitting…' : 'Submit for review'}</button>
      </form>}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <h2 className="mb-4 flex items-center gap-2 font-semibold"><Clock3 size={18} />Your manual deposits</h2>
        {deposits.length === 0 ? <p className="text-sm text-slate-500">No manual deposit submissions yet.</p> : <div className="space-y-3">{deposits.map(d => <div key={d.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-900"><div><p className="font-medium">{money(d.amount_minor)}</p><p className="text-xs text-slate-500">{d.reference} · {d.created_at ? new Date(d.created_at).toLocaleString() : '—'}</p>{d.review_reason && <p className="mt-1 text-sm text-slate-500">Review note: {d.review_reason}</p>}</div><span className="rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold capitalize">{d.status}</span></div>)}</div>}
      </section>
    </div>
  </DashboardLayout>;
}
