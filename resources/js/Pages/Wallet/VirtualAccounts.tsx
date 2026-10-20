import { useState, type FormEvent } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { Building2, Copy, RefreshCw, ShieldCheck } from 'lucide-react';

type VirtualAccount = {
  id: string;
  bank_name: string;
  account_name: string;
  account_number: string;
  status: string;
  currency: string;
};

export default function VirtualAccounts({
  accounts,
  provisioningEnabled,
}: {
  accounts: VirtualAccount[];
  provisioningEnabled: boolean;
}) {
  const [bank, setBank] = useState('wema-bank');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const provision = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await fetch('/wallet/virtual-accounts', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ preferred_bank: bank }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || 'Virtual account could not be requested.');
      setNotice('Your provider-issued virtual account is ready.');
      window.location.reload();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Virtual account request failed.');
    } finally {
      setBusy(false);
    }
  };

  const copy = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setNotice('Account number copied.');
    } catch {
      setError('Could not copy automatically. Please select the account number and copy it.');
    }
  };

  return (
    <DashboardLayout>
      <Head title="Virtual Accounts" />
      <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-6">
        <div>
          <p className="text-sm font-medium text-[var(--muted-foreground)]">Wallet funding</p>
          <h1 className="mt-1 text-2xl font-semibold">Dedicated virtual accounts</h1>
          <p className="mt-2 max-w-2xl text-sm text-[var(--muted-foreground)]">
            Use an account assigned to you by the payment provider. Funds are not credited from a transfer screenshot;
            verified provider confirmation and reconciliation are required.
          </p>
        </div>

        {notice && <div role="status" className="rounded-xl border p-3 text-sm">{notice}</div>}
        {error && <div role="alert" className="rounded-xl border border-red-300 p-3 text-sm text-red-700">{error}</div>}

        <section className="rounded-2xl border p-5">
          <div className="flex items-start gap-3">
            <div className="rounded-xl border p-3"><Building2 className="h-5 w-5" aria-hidden="true" /></div>
            <div className="min-w-0 flex-1">
              <h2 className="font-semibold">Your assigned accounts</h2>
              <p className="mt-1 text-sm text-[var(--muted-foreground)]">Only provider-issued account details are displayed here.</p>
            </div>
          </div>

          {accounts.length === 0 ? (
            <div className="mt-5 rounded-xl border border-dashed p-5 text-sm text-[var(--muted-foreground)]">
              No virtual account has been assigned yet.
            </div>
          ) : (
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              {accounts.map((account) => (
                <article key={account.id} className="rounded-xl border p-4">
                  <div className="flex items-center gap-2 font-semibold"><Building2 className="h-4 w-4" aria-hidden="true" />{account.bank_name}</div>
                  <p className="mt-3 text-xs text-[var(--muted-foreground)]">Account name</p>
                  <p className="break-words font-medium">{account.account_name}</p>
                  <p className="mt-3 text-xs text-[var(--muted-foreground)]">Account number</p>
                  <div className="flex items-center justify-between gap-3">
                    <p className="text-xl font-semibold tracking-wide">{account.account_number}</p>
                    <button type="button" onClick={() => void copy(account.account_number)} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                      <Copy className="h-4 w-4" aria-hidden="true" /> Copy
                    </button>
                  </div>
                  <div className="mt-3 flex items-center gap-2 text-sm text-[var(--muted-foreground)]">
                    <ShieldCheck className="h-4 w-4" aria-hidden="true" /> {account.status === 'active' ? 'Provider assigned' : account.status}
                  </div>
                </article>
              ))}
            </div>
          )}
        </section>

        <section className="rounded-2xl border p-5">
          <h2 className="font-semibold">Request a virtual account</h2>
          <p className="mt-1 text-sm text-[var(--muted-foreground)]">Provisioning stays unavailable until provider onboarding and verified deposit reconciliation are approved.</p>
          <form onSubmit={provision} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <label className="flex-1 text-sm font-medium">
              Preferred bank
              <select value={bank} onChange={(e) => setBank(e.target.value)} className="mt-2 block w-full rounded-lg border bg-transparent px-3 py-2.5" disabled={!provisioningEnabled || busy}>
                <option value="wema-bank">Wema Bank</option>
                <option value="titan-paystack">Titan Paystack</option>
              </select>
            </label>
            <button type="submit" disabled={!provisioningEnabled || busy} className="inline-flex items-center justify-center gap-2 rounded-lg border px-4 py-2.5 font-medium disabled:cursor-not-allowed disabled:opacity-50">
              {busy ? <RefreshCw className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Building2 className="h-4 w-4" aria-hidden="true" />}
              {busy ? 'Requesting…' : 'Request account'}
            </button>
          </form>
          {!provisioningEnabled && <p className="mt-3 text-sm text-amber-700">Not enabled yet. This is intentional: do not enable until deposit webhook matching, account assignment limits, and reconciliation are verified.</p>}
        </section>
      </div>
    </DashboardLayout>
  );
}
