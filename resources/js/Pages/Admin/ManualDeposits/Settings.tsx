import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';

type Settings = { enabled: boolean; bank_name: string; account_name: string; account_number: string; instructions: string };
export default function ManualDepositSettings({ settings }: { settings: Settings }) {
  const [form, setForm] = useState(settings);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const update = (key: keyof Settings, value: string | boolean) => setForm(current => ({ ...current, [key]: value }));
  const submit = (e: FormEvent) => { e.preventDefault(); setBusy(true); router.put('/admin/manual-deposits/settings', { ...form, reason }, { preserveScroll: true, onFinish: () => setBusy(false) }); };
  return <DashboardLayout title="Manual deposit instructions" description="Configure customer-facing bank transfer details and availability." admin>
    <Head title="Manual deposit settings · Semizzy" />
    <form onSubmit={submit} className="mx-auto max-w-3xl space-y-5 rounded-2xl border border-slate-200 p-5 dark:border-slate-800">
      <label className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-4 dark:bg-slate-900"><span><span className="block font-semibold">Manual deposits enabled</span><span className="text-sm text-slate-500">Disabling hides submission and blocks new requests.</span></span><input type="checkbox" checked={form.enabled} onChange={e => update('enabled', e.target.checked)} /></label>
      {(['bank_name', 'account_name', 'account_number'] as const).map(key => <label key={key} className="block text-sm font-medium">{({ bank_name: 'Bank name', account_name: 'Account name', account_number: 'Account number' })[key]}<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={form[key]} onChange={e => update(key, e.target.value)} maxLength={160} /></label>)}
      <label className="block text-sm font-medium">Customer instructions<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" rows={5} value={form.instructions} onChange={e => update('instructions', e.target.value)} required minLength={10} maxLength={1500} /></label>
      <label className="block text-sm font-medium">Reason for change (audit note)<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" rows={2} value={reason} onChange={e => setReason(e.target.value)} required minLength={8} maxLength={500} /></label>
      <button disabled={busy} className="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{busy ? 'Saving…' : 'Save deposit settings'}</button>
    </form>
  </DashboardLayout>;
}
