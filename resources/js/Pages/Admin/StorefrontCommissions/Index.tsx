import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BadgeDollarSign, CheckCircle2, Clock3, RefreshCw, ShieldCheck, Store } from 'lucide-react';

type Storefront = {
  id: number;
  slug: string;
  display_name: string;
  status: string;
  commission_rate_basis_points: number;
  workspace_name: string | null;
  owner_name: string | null;
  owner_email: string | null;
  accrued_commissions_count: number;
  accrued_commissions_minor: number;
};

type Commission = {
  id: number;
  storefront_name: string | null;
  storefront_slug: string | null;
  beneficiary_name: string | null;
  beneficiary_email: string | null;
  order_public_id: string | null;
  order_status: string | null;
  gross_amount_minor: number;
  rate_basis_points: number;
  commission_amount_minor: number;
  status: string;
  settled_at: string | null;
  created_at: string | null;
};

type Paginator<T> = { data: T[]; current_page: number; last_page: number; total: number; next_page_url: string | null; prev_page_url: string | null };

function money(minor: number) {
  return new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 2 }).format(minor / 100);
}

function dateLabel(value: string | null) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function statusClass(status: string) {
  return status === 'settled'
    ? 'bg-emerald-100 text-emerald-800'
    : status === 'accrued'
      ? 'bg-amber-100 text-amber-800'
      : 'bg-slate-100 text-slate-700';
}

export default function StorefrontCommissionsIndex({ storefronts, commissions }: { storefronts: Paginator<Storefront>; commissions: Paginator<Commission> }) {
  const [rates, setRates] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState('');

  function updateRate(store: Storefront) {
    const raw = rates[store.id] ?? String(store.commission_rate_basis_points);
    const rate = Number(raw);
    if (!Number.isInteger(rate) || rate < 0 || rate > 10000) {
      setError('Enter a whole-number rate from 0 to 10,000 basis points.');
      return;
    }
    setError('');
    setBusy(store.id);
    router.put('/admin/storefronts/' + store.id + '/commission-rate', { commission_rate_basis_points: rate }, {
      preserveScroll: true,
      onError: (errors) => setError(Object.values(errors).flat()[0] || 'Commission rate could not be saved.'),
      onFinish: () => setBusy(null),
      onSuccess: () => setRates((current) => { const next = { ...current }; delete next[store.id]; return next; }),
    });
  }

  function settle(commission: Commission) {
    if (!window.confirm('Settle ' + money(commission.commission_amount_minor) + ' to the reseller owner wallet? Only verified successful orders may be settled.')) return;
    setError('');
    setBusy(commission.id * -1);
    router.post('/admin/storefront-commissions/' + commission.id + '/settle', {}, {
      preserveScroll: true,
      onError: (errors) => setError(Object.values(errors).flat()[0] || 'Settlement was rejected.'),
      onFinish: () => setBusy(null),
    });
  }

  return (
    <>
      <Head title="Reseller commissions" />
      <main className="min-h-screen bg-[var(--page)] px-4 py-5 text-[var(--ink)] sm:px-6 lg:px-10 lg:py-8">
        <div className="mx-auto max-w-7xl space-y-7">
          <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex items-start gap-3">
              <span className="grid h-12 w-12 place-items-center rounded-2xl bg-[var(--brand)] text-white"><BadgeDollarSign size={24} /></span>
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-[var(--muted)]">SEMIZZY · Admin controls</p>
                <h1 className="mt-1 text-2xl font-extrabold tracking-tight sm:text-3xl">Reseller commissions</h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--muted)]">Configure each storefront’s commission rate and review settlement eligibility. Commission records are created from verified successful orders, never from checkout attempts.</p>
              </div>
            </div>
            <span className="inline-flex items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-xs font-semibold"><ShieldCheck size={15} /> Restricted admin access</span>
          </header>

          {error && <div role="alert" className="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</div>}

          <section className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:p-6">
            <div className="mb-4 flex items-center justify-between gap-3">
              <div><h2 className="text-lg font-bold">Storefront rate policy</h2><p className="mt-1 text-sm text-[var(--muted)]">100 basis points = 1%. A rate of 0 disables new commission accrual.</p></div>
              <Store className="text-[var(--muted)]" />
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead><tr className="border-b border-[var(--line)] text-xs uppercase tracking-wide text-[var(--muted)]"><th className="px-3 py-3">Storefront / owner</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Rate (bps)</th><th className="px-3 py-3">Accrued</th><th className="px-3 py-3">Action</th></tr></thead>
                <tbody>
                  {storefronts.data.map((store) => <tr key={store.id} className="border-b border-[var(--line)] last:border-0">
                    <td className="px-3 py-4"><div className="font-semibold">{store.display_name}</div><div className="mt-1 text-xs text-[var(--muted)]">{store.workspace_name || 'Workspace unavailable'} · /store/{store.slug}</div><div className="mt-1 text-xs text-[var(--muted)]">{store.owner_name || 'Owner unavailable'}{store.owner_email ? ' · ' + store.owner_email : ''}</div></td>
                    <td className="px-3 py-4"><span className={'rounded-full px-2.5 py-1 text-xs font-semibold ' + (store.status === 'published' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700')}>{store.status}</span></td>
                    <td className="px-3 py-4"><label className="sr-only" htmlFor={'rate-' + store.id}>Commission basis points for {store.display_name}</label><input id={'rate-' + store.id} inputMode="numeric" type="number" min="0" max="10000" step="1" value={rates[store.id] ?? String(store.commission_rate_basis_points)} onChange={(event) => setRates((current) => ({ ...current, [store.id]: event.target.value }))} className="w-28 rounded-lg border border-[var(--line)] bg-[var(--page)] px-3 py-2" /></td>
                    <td className="px-3 py-4"><div className="font-semibold">{money(store.accrued_commissions_minor)}</div><div className="text-xs text-[var(--muted)]">{store.accrued_commissions_count} awaiting settlement</div></td>
                    <td className="px-3 py-4"><button type="button" disabled={busy === store.id} onClick={() => updateRate(store)} className="rounded-lg bg-[var(--brand)] px-3 py-2 text-xs font-bold text-white disabled:opacity-50">{busy === store.id ? 'Saving…' : 'Save rate'}</button></td>
                  </tr>)}
                  {storefronts.data.length === 0 && <tr><td colSpan={5} className="px-3 py-8 text-center text-[var(--muted)]">No reseller storefronts found.</td></tr>}
                </tbody>
              </table>
            </div>
            <div className="mt-4 flex items-center justify-between text-xs text-[var(--muted)]"><span>{storefronts.total} storefronts</span><div className="flex gap-2"><button type="button" disabled={!storefronts.prev_page_url} onClick={() => storefronts.prev_page_url && router.get(storefronts.prev_page_url, {}, { preserveScroll: true })} className="rounded-lg border border-[var(--line)] px-3 py-2 disabled:opacity-40">Previous</button><span className="self-center">Page {storefronts.current_page} of {storefronts.last_page}</span><button type="button" disabled={!storefronts.next_page_url} onClick={() => storefronts.next_page_url && router.get(storefronts.next_page_url, {}, { preserveScroll: true })} className="rounded-lg border border-[var(--line)] px-3 py-2 disabled:opacity-40">Next</button></div></div>
          </section>

          <section className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:p-6">
            <div className="mb-4"><h2 className="text-lg font-bold">Commission ledger</h2><p className="mt-1 text-sm text-[var(--muted)]">Settlement is available only for accrued commissions whose linked order remains verified as successful.</p></div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[900px] text-left text-sm">
                <thead><tr className="border-b border-[var(--line)] text-xs uppercase tracking-wide text-[var(--muted)]"><th className="px-3 py-3">Order / store</th><th className="px-3 py-3">Beneficiary</th><th className="px-3 py-3">Gross / rate</th><th className="px-3 py-3">Commission</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Action</th></tr></thead>
                <tbody>
                  {commissions.data.map((commission) => <tr key={commission.id} className="border-b border-[var(--line)] last:border-0">
                    <td className="px-3 py-4"><div className="font-mono text-xs">{commission.order_public_id || 'Order unavailable'}</div><div className="mt-1 font-semibold">{commission.storefront_name || 'Store unavailable'}</div><div className="mt-1 text-xs text-[var(--muted)]">Order status: {commission.order_status || 'unknown'}</div><div className="mt-1 text-xs text-[var(--muted)]">Created {dateLabel(commission.created_at)}</div></td>
                    <td className="px-3 py-4"><div>{commission.beneficiary_name || 'Owner unavailable'}</div><div className="text-xs text-[var(--muted)]">{commission.beneficiary_email || ''}</div></td>
                    <td className="px-3 py-4"><div>{money(commission.gross_amount_minor)}</div><div className="text-xs text-[var(--muted)]">{commission.rate_basis_points} bps ({(commission.rate_basis_points / 100).toFixed(2)}%)</div></td>
                    <td className="px-3 py-4 font-semibold">{money(commission.commission_amount_minor)}</td>
                    <td className="px-3 py-4"><span className={'rounded-full px-2.5 py-1 text-xs font-semibold ' + statusClass(commission.status)}>{commission.status}</span>{commission.settled_at && <div className="mt-1 text-xs text-[var(--muted)]">{dateLabel(commission.settled_at)}</div>}</td>
                    <td className="px-3 py-4">{commission.status === 'accrued' && commission.order_status === 'successful' ? <button type="button" disabled={busy === commission.id * -1} onClick={() => settle(commission)} className="inline-flex items-center gap-2 rounded-lg bg-[var(--brand)] px-3 py-2 text-xs font-bold text-white disabled:opacity-50">{busy === commission.id * -1 ? <RefreshCw size={13} className="animate-spin" /> : <CheckCircle2 size={13} />}Settle</button> : <span className="inline-flex items-center gap-1 text-xs text-[var(--muted)]"><Clock3 size={13} /> No action</span>}</td>
                  </tr>)}
                  {commissions.data.length === 0 && <tr><td colSpan={6} className="px-3 py-8 text-center text-[var(--muted)]">No commission records yet. Records appear after verified successful storefront orders with a non-zero configured rate.</td></tr>}
                </tbody>
              </table>
            </div>
            <div className="mt-4 flex items-center justify-between text-xs text-[var(--muted)]"><span>{commissions.total} commission records</span><div className="flex gap-2"><button type="button" disabled={!commissions.prev_page_url} onClick={() => commissions.prev_page_url && router.get(commissions.prev_page_url, {}, { preserveScroll: true, preserveState: true })} className="rounded-lg border border-[var(--line)] px-3 py-2 disabled:opacity-40">Previous</button><span className="self-center">Page {commissions.current_page} of {commissions.last_page}</span><button type="button" disabled={!commissions.next_page_url} onClick={() => commissions.next_page_url && router.get(commissions.next_page_url, {}, { preserveScroll: true, preserveState: true })} className="rounded-lg border border-[var(--line)] px-3 py-2 disabled:opacity-40">Next</button></div></div>
          </section>
          <p className="text-xs leading-relaxed text-[var(--muted)]">All amounts are Nigerian naira. Rates are stored in basis points (1% = 100 bps). The default rate is 0 bps; configuring a rate is an explicit commercial decision. This page does not run migrations or trigger purchases.</p>
        </div>
      </main>
    </>
  );
}
