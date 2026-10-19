import { Head } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';

type Bank = { name: string; code: string };
type Beneficiary = { id: number; bank_name: string; account_name: string; account_number_masked: string; verification_status: string };
type Payout = { id: string; bank_name?: string; account_name?: string; account_number_masked?: string; amount_minor: number; fee_minor: number; status: string; reference: string; created_at?: string };

async function jsonRequest(path: string, init: RequestInit = {}) {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
  const response = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, ...(init.headers || {}) },
  });
  const body = await response.json();
  if (!response.ok) throw new Error(body.message || 'The request could not be completed.');
  return body;
}

export default function BankTransfer() {
  const [banks, setBanks] = useState<Bank[]>([]);
  const [beneficiaries, setBeneficiaries] = useState<Beneficiary[]>([]);
  const [payouts, setPayouts] = useState<Payout[]>([]);
  const [bankCode, setBankCode] = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [beneficiaryId, setBeneficiaryId] = useState('');
  const [amount, setAmount] = useState('');
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  async function refresh() {
    const [bankData, beneficiaryData, payoutData] = await Promise.all([
      jsonRequest('/wallet/bank-transfer/banks'),
      jsonRequest('/wallet/bank-transfer/beneficiaries'),
      jsonRequest('/wallet/bank-transfer/history'),
    ]);
    setBanks(bankData.data || []);
    setBeneficiaries(beneficiaryData.data || []);
    setPayouts(payoutData.data || []);
  }

  useEffect(() => {
    void refresh().catch(e => setError(e instanceof Error ? e.message : 'Bank services are currently unavailable.'));
  }, []);

  async function saveBeneficiary(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    try {
      const body = await jsonRequest('/wallet/bank-transfer/beneficiaries', { method: 'POST', body: JSON.stringify({ bank_code: bankCode, account_number: accountNumber }) });
      setMessage('Bank account verified and saved.');
      setAccountNumber('');
      setBeneficiaryId(String(body.data.id));
      await refresh();
    } catch (e) { setError(e instanceof Error ? e.message : 'Account verification failed.'); }
    finally { setBusy(false); }
  }

  async function requestPayout(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    try {
      const body = await jsonRequest('/wallet/bank-transfer', { method: 'POST', body: JSON.stringify({
        beneficiary_id: Number(beneficiaryId), amount_minor: Math.round(Number(amount) * 100),
        transaction_pin: pin, idempotency_key: crypto.randomUUID(),
      }) });
      setMessage(body.message || 'Bank transfer request recorded.');
      setAmount(''); setPin('');
      await refresh();
    } catch (e) { setError(e instanceof Error ? e.message : 'Bank transfer could not be submitted.'); }
    finally { setBusy(false); }
  }

  return <DashboardLayout title="Transfer to bank" description="Verify a bank account and request a secure payout.">
    <Head title="Transfer to bank · Semizzy" />
    <div className="mx-auto grid max-w-6xl gap-6 xl:grid-cols-2">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
        <h1 className="text-xl font-extrabold">Bank account</h1>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Choose a bank and enter the 10-digit account number. The name returned by the verification provider is saved with the account.</p>
        <form onSubmit={saveBeneficiary} className="mt-5 space-y-4">
          <label className="block text-sm font-semibold">Bank
            <select required value={bankCode} onChange={e => setBankCode(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700">
              <option value="">Select bank</option>
              {banks.map(bank => <option key={bank.code} value={bank.code}>{bank.name}</option>)}
            </select>
          </label>
          <label className="block text-sm font-semibold">Account number
            <input required inputMode="numeric" pattern="[0-9]{10}" maxLength={10} value={accountNumber} onChange={e => setAccountNumber(e.target.value.replace(/\D/g, '').slice(0, 10))} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" />
          </label>
          <button disabled={busy || !bankCode || !/^\d{10}$/.test(accountNumber)} className="w-full rounded-xl border border-emerald-700 px-4 py-3 font-bold text-emerald-800 disabled:opacity-50 dark:text-emerald-300">{busy ? 'Verifying…' : 'Verify and save account'}</button>
        </form>
        <h2 className="mt-7 text-lg font-extrabold">Saved bank accounts</h2>
        <div className="mt-3 space-y-2">
          {beneficiaries.length === 0 ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800">No verified bank accounts saved yet.</p> : beneficiaries.map(item => <button type="button" key={item.id} onClick={() => setBeneficiaryId(String(item.id))} className={`w-full rounded-xl border p-3 text-left ${beneficiaryId === String(item.id) ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-950/40' : 'border-slate-200 dark:border-slate-700'}`}>
            <span className="block font-bold">{item.account_name}</span><span className="mt-1 block text-sm text-slate-500">{item.bank_name} · {item.account_number_masked}</span>
          </button>)}
        </div>
      </section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
        <h2 className="text-xl font-extrabold">Transfer to bank</h2>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Transfers require a verified saved account and your transaction PIN. Provider-pending transfers are not treated as completed.</p>
        <form onSubmit={requestPayout} className="mt-5 space-y-4">
          <label className="block text-sm font-semibold">Verified recipient
            <select required value={beneficiaryId} onChange={e => setBeneficiaryId(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700">
              <option value="">Select saved account</option>
              {beneficiaries.map(item => <option key={item.id} value={item.id}>{item.account_name} · {item.bank_name} · {item.account_number_masked}</option>)}
            </select>
          </label>
          <label className="block text-sm font-semibold">Amount (NGN)
            <input required type="number" min="1" max="5000000" step="0.01" value={amount} onChange={e => setAmount(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" />
          </label>
          <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">Fee currently shown as ₦0.00. Payouts remain disabled until the administrator enables and verifies the provider configuration.</p>
          <label className="block text-sm font-semibold">4-digit transaction PIN
            <input required type="password" inputMode="numeric" pattern="[0-9]{4}" maxLength={4} value={pin} onChange={e => setPin(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal tracking-[0.35em] dark:border-slate-700" autoComplete="off" />
          </label>
          {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200">{error}</p>}
          {message && <p role="status" className="rounded-xl bg-slate-100 p-3 text-sm dark:bg-slate-800">{message}</p>}
          <button disabled={busy || !beneficiaryId || !(Number(amount) > 0) || !/^\d{4}$/.test(pin)} className="w-full rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white disabled:opacity-50">{busy ? 'Processing…' : 'Request bank transfer'}</button>
        </form>
        <h2 className="mt-7 text-lg font-extrabold">Recent bank transfers</h2>
        <div className="mt-3 space-y-2">
          {payouts.length === 0 ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800">No bank transfers yet.</p> : payouts.map(item => <article key={item.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <div className="flex items-start justify-between gap-3"><div><p className="font-bold">{item.bank_name || 'Bank payout'} · {item.account_number_masked || ''}</p><p className="mt-1 text-xs text-slate-500">{item.id} · {item.created_at ? new Date(item.created_at).toLocaleString() : ''}</p></div><span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold dark:bg-slate-800">{item.status.replaceAll('_', ' ')}</span></div>
            <p className="mt-2 font-extrabold">₦{(item.amount_minor / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}</p>
          </article>)}
        </div>
      </section>
    </div>
  </DashboardLayout>;
}
