import { Head } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';

type Transfer = {
  id: string;
  direction: 'sent' | 'received';
  recipient?: string | null;
  sender?: string | null;
  amount_minor: number;
  currency: string;
  status: string;
  created_at?: string | null;
};

export default function WalletTransfer() {
  const [recipient, setRecipient] = useState('');
  const [amount, setAmount] = useState('');
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [history, setHistory] = useState<Transfer[]>([]);

  async function loadHistory() {
    try {
      const response = await fetch('/wallet/transfers/history', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (response.ok) {
        const body = await response.json();
        setHistory(Array.isArray(body.data) ? body.data : []);
      }
    } catch { /* History is optional until the request succeeds. */ }
  }

  useEffect(() => { void loadHistory(); }, []);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setMessage('');
    try {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
      const response = await fetch('/wallet/transfers', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          recipient: recipient.trim(),
          amount_minor: Math.round(Number(amount) * 100),
          transaction_pin: pin,
          idempotency_key: crypto.randomUUID(),
        }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body.message || 'Transfer could not be completed.');
      setMessage('Transfer completed successfully.');
      setRecipient('');
      setAmount('');
      setPin('');
      await loadHistory();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Transfer could not be completed.');
    } finally {
      setBusy(false);
    }
  }

  return <DashboardLayout title="Wallet transfer" description="Send funds securely to another Semizzy user.">
    <Head title="Wallet transfer · Semizzy" />
    <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
        <h1 className="text-xl font-extrabold">Send to a Semizzy user</h1>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Use the recipient's username or registered email. Check the details before confirming.</p>
        <form className="mt-6 space-y-4" onSubmit={submit}>
          <label className="block text-sm font-semibold">Recipient username or email
            <input required minLength={3} maxLength={190} value={recipient} onChange={e => setRecipient(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" autoComplete="off" />
          </label>
          <label className="block text-sm font-semibold">Amount (NGN)
            <input required type="number" min="1" max="5000000" step="0.01" value={amount} onChange={e => setAmount(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" />
          </label>
          <label className="block text-sm font-semibold">4-digit transaction PIN
            <input required inputMode="numeric" pattern="[0-9]{4}" maxLength={4} type="password" value={pin} onChange={e => setPin(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal tracking-[0.35em] dark:border-slate-700" autoComplete="off" />
          </label>
          <div className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">Transfers cannot be sent to your own account. Confirm the recipient carefully before sending.</div>
          {message && <p role="status" className="rounded-xl bg-slate-100 p-3 text-sm dark:bg-slate-800">{message}</p>}
          <button disabled={busy || !recipient.trim() || !(Number(amount) > 0) || !/^\d{4}$/.test(pin)} className="w-full rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white disabled:opacity-50">{busy ? 'Processing…' : 'Confirm transfer'}</button>
        </form>
      </section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
        <h2 className="text-xl font-extrabold">Recent transfers</h2>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Sent and received transfers from your account.</p>
        <div className="mt-5 space-y-3">
          {history.length === 0 ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800">No transfers to show yet.</p> : history.map(item => <article key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3 dark:border-slate-800">
            <div className="min-w-0"><p className="font-bold">{item.direction === 'sent' ? `To ${item.recipient || 'Semizzy user'}` : `From ${item.sender || 'Semizzy user'}`}</p><p className="mt-1 text-xs text-slate-500">{item.created_at ? new Date(item.created_at).toLocaleString() : item.status}</p></div>
            <p className="shrink-0 font-extrabold">{item.direction === 'sent' ? '−' : '+'}₦{(item.amount_minor / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}</p>
          </article>)}
        </div>
      </section>
    </div>
  </DashboardLayout>;
}
