import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { LockKeyhole, ShieldCheck } from 'lucide-react';

export default function TransactionPin({ configured, lockedUntil, failedAttempts }: { configured: boolean; lockedUntil: string | null; failedAttempts: number }) {
  const [currentPin, setCurrentPin] = useState('');
  const [pin, setPin] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = (event: FormEvent) => { event.preventDefault(); setBusy(true); router.put('/security/transaction-pin', { current_pin: currentPin, pin, pin_confirmation: confirmation }, { preserveScroll: true, onFinish: () => { setBusy(false); setCurrentPin(''); setPin(''); setConfirmation(''); } }); };
  return <DashboardLayout title="Transaction security" description="Create or change the four-digit PIN used to authorize wallet-funded purchases.">
    <Head title="Transaction security · Semizzy" />
    <div className="mx-auto max-w-2xl space-y-5">
      <section className="rounded-2xl border border-emerald-700/20 bg-emerald-700/[0.05] p-5"><div className="flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><ShieldCheck size={23}/></span><div><h2 className="font-semibold">{configured ? 'Transaction PIN is configured' : 'Protect wallet-funded transactions'}</h2><p className="mt-1 text-sm text-slate-500">This PIN is separate from your login password and is stored as a one-way hash.</p></div></div>{lockedUntil && new Date(lockedUntil).getTime() > Date.now() && <p className="mt-4 rounded-xl bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300">PIN actions are temporarily locked until {new Date(lockedUntil).toLocaleString()}.</p>}{failedAttempts > 0 && <p className="mt-3 text-xs text-slate-500">Recent failed attempts: {failedAttempts} of 5 before a temporary lock.</p>}</section>
      <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        {configured && <label className="block text-sm font-medium">Current PIN<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" type="password" inputMode="numeric" autoComplete="off" value={currentPin} onChange={e => setCurrentPin(e.target.value.replace(/\D/g, '').slice(0,4))} minLength={4} maxLength={4} required /></label>}
        <label className="block text-sm font-medium">New four-digit PIN<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" type="password" inputMode="numeric" autoComplete="new-password" value={pin} onChange={e => setPin(e.target.value.replace(/\D/g, '').slice(0,4))} minLength={4} maxLength={4} required /></label>
        <label className="block text-sm font-medium">Confirm new PIN<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" type="password" inputMode="numeric" autoComplete="new-password" value={confirmation} onChange={e => setConfirmation(e.target.value.replace(/\D/g, '').slice(0,4))} minLength={4} maxLength={4} required /></label>
        <div className="rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-500 dark:bg-slate-900">Avoid repeated digits, simple sequences and birthdays. Five incorrect verification attempts trigger a 15-minute lock.</div>
        <button disabled={busy || Boolean(lockedUntil && new Date(lockedUntil).getTime() > Date.now())} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50"><LockKeyhole size={17}/>{busy ? 'Saving securely…' : configured ? 'Change transaction PIN' : 'Set transaction PIN'}</button>
      </form>
    </div>
  </DashboardLayout>;
}
