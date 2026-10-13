import { Head } from '@inertiajs/react';
import { AlertCircle, ArrowDownLeft, CheckCircle2, Clock3, RefreshCw, Search, ShieldCheck, WalletCards, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type RefundStatus =
  | 'requested'
  | 'processing'
  | 'provider_pending'
  | 'succeeded'
  | 'failed'
  | 'blocked_insufficient_wallet_funds'
  | 'review_required';

type Refund = {
  id: string;
  payment_intent_id: string;
  payment_reference: string;
  requested_by_user_id: number;
  approved_by_user_id: number | null;
  amount_minor: number;
  currency: string;
  reason: string;
  status: RefundStatus;
  provider_status: string | null;
  provider_refund_reference: string | null;
  requested_at: string | null;
  approved_at: string | null;
  settled_at: string | null;
};

type QueueResponse = {
  data: Refund[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

const filters = [
  { value: 'all', label: 'All requests' },
  { value: 'requested', label: 'Awaiting approval' },
  { value: 'provider_pending', label: 'Provider pending' },
  { value: 'blocked_insufficient_wallet_funds', label: 'Balance blocked' },
  { value: 'review_required', label: 'Needs review' },
  { value: 'succeeded', label: 'Completed' },
  { value: 'failed', label: 'Failed' },
] as const;

const statusLabels: Record<RefundStatus, string> = {
  requested: 'Awaiting approval',
  processing: 'Processing',
  provider_pending: 'Provider pending',
  succeeded: 'Completed',
  failed: 'Failed',
  blocked_insufficient_wallet_funds: 'Balance blocked',
  review_required: 'Needs review',
};

function money(amountMinor: number, currency: string) {
  return new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: currency || 'NGN',
    maximumFractionDigits: 2,
  }).format(amountMinor / 100);
}

function dateLabel(value: string | null) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-NG', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

function statusStyle(status: RefundStatus) {
  if (status === 'succeeded') return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
  if (status === 'failed' || status === 'blocked_insufficient_wallet_funds') return 'border-rose-500/25 bg-rose-500/10 text-rose-700 dark:text-rose-300';
  if (status === 'review_required') return 'border-amber-500/25 bg-amber-500/10 text-amber-800 dark:text-amber-300';
  return 'border-[var(--line)] bg-[var(--page)] text-[var(--muted)]';
}

export default function RefundOperationsDashboard() {
  const [rows, setRows] = useState<Refund[]>([]);
  const [total, setTotal] = useState(0);
  const [filter, setFilter] = useState<(typeof filters)[number]['value']>('all');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ per_page: '100' });
      if (filter !== 'all') params.set('status', filter);
      const response = await fetch(`/admin/payment-refunds?${params.toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (!response.ok) throw new Error(response.status === 403 ? 'Your account cannot view refund operations.' : 'Could not load the refund queue.');
      const result = (await response.json()) as QueueResponse;
      setRows(result.data);
      setTotal(result.meta.total);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Could not load the refund queue.');
    } finally {
      setLoading(false);
    }
  }, [filter]);

  useEffect(() => { void load(); }, [load]);

  const visibleRows = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return rows;
    return rows.filter((row) =>
      [row.id, row.payment_reference, row.reason, row.provider_refund_reference ?? '', statusLabels[row.status]]
        .some((value) => value.toLowerCase().includes(term)),
    );
  }, [rows, search]);

  const counts = useMemo(() => ({
    action: rows.filter((row) => row.status === 'requested' || row.status === 'blocked_insufficient_wallet_funds').length,
    review: rows.filter((row) => row.status === 'review_required').length,
    pending: rows.filter((row) => row.status === 'provider_pending' || row.status === 'processing').length,
    completed: rows.filter((row) => row.status === 'succeeded').length,
  }), [rows]);

  async function approve(refund: Refund) {
    const retryBlocked = refund.status === 'blocked_insufficient_wallet_funds';
    const confirmed = window.confirm(
      retryBlocked
        ? 'Recheck this blocked refund? It will proceed only if the wallet now has enough available funds.'
        : `Approve refund of ${money(refund.amount_minor, refund.currency)} for payment ${refund.payment_reference}?`,
    );
    if (!confirmed) return;

    setBusyId(refund.id);
    setError('');
    setNotice('');
    try {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      const response = await fetch(`/admin/payment-refunds/${encodeURIComponent(refund.id)}/approve`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify({}),
      });
      const payload = await response.json().catch(() => ({})) as { data?: Refund; message?: string };
      if (!response.ok) {
        if (payload.data) setRows((current) => current.map((item) => item.id === refund.id ? payload.data! : item));
        throw new Error(payload.message || (response.status === 409 ? 'This refund needs further review.' : 'Approval could not be completed.'));
      }
      setNotice(`Refund ${refund.id.slice(0, 8)} updated to ${payload.data?.status ? statusLabels[payload.data.status] : 'the latest status'}.`);
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Approval could not be completed.');
      await load();
    } finally {
      setBusyId(null);
    }
  }

  return (
    <>
      <Head title="Refund operations" />
      <main className="min-h-screen bg-[var(--page)] px-4 py-5 text-[var(--ink)] sm:px-6 lg:px-10 lg:py-8">
        <div className="mx-auto max-w-7xl">
          <header className="flex flex-wrap items-center justify-between gap-4">
            <a href="/" className="flex items-center gap-3" aria-label="SEMIZZY home">
              <span className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span><span className="block font-display text-lg font-extrabold tracking-tight">SEMIZZY</span><span className="block text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--muted)]">Operations</span></span>
            </a>
            <div className="flex items-center gap-2">
              <span className="hidden items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-xs font-semibold text-[var(--muted)] sm:inline-flex"><ShieldCheck size={15} /> Restricted access</span>
              <button type="button" onClick={() => { const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = next; localStorage.setItem('semizzy-theme', next); }} className="rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-sm font-semibold hover:border-[var(--brand)]">Toggle theme</button>
              <button type="button" onClick={() => void load()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60"><RefreshCw size={15} className={loading ? 'animate-spin' : ''} /> Refresh</button>
            </div>
          </header>

          <section className="mt-9 flex flex-col justify-between gap-4 md:flex-row md:items-end">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-[var(--brand)]">Payments · Risk controls</p>
              <h1 className="mt-2 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Refund operations</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">Review customer requests, approve eligible refunds, and follow provider settlement without bypassing wallet controls.</p>
            </div>
            <div className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-4 py-3">
              <p className="text-xs font-semibold text-[var(--muted)]">Requests in current filter</p>
              <p className="mt-1 font-display text-2xl font-extrabold tabular-nums">{total}</p>
            </div>
          </section>

          <section className="mt-7 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Metric icon={<ArrowDownLeft size={18} />} label="Approval queue" value={counts.action} detail="Requested or balance-blocked" />
            <Metric icon={<AlertCircle size={18} />} label="Needs review" value={counts.review} detail="Manual investigation" />
            <Metric icon={<Clock3 size={18} />} label="Provider pending" value={counts.pending} detail="Awaiting final status" />
            <Metric icon={<CheckCircle2 size={18} />} label="Completed" value={counts.completed} detail="Provider confirmed" />
          </section>

          {error && <div role="alert" className="mt-5 flex items-start gap-3 rounded-2xl border border-rose-500/25 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-300"><AlertCircle size={18} className="mt-0.5 shrink-0" /><span>{error}</span></div>}
          {notice && <div role="status" className="mt-5 flex items-start gap-3 rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-4 text-sm text-emerald-700 dark:text-emerald-300"><CheckCircle2 size={18} className="mt-0.5 shrink-0" /><span>{notice}</span></div>}

          <section className="mt-6 overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)] shadow-sm shadow-slate-950/[0.03]">
            <div className="flex flex-col gap-4 border-b border-[var(--line)] p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
              <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><WalletCards size={20} /></span><div><h2 className="font-display text-lg font-extrabold">Refund queue</h2><p className="text-xs text-[var(--muted)]">Latest 100 matching requests</p></div></div>
              <div className="flex flex-col gap-3 sm:flex-row">
                <label className="relative block"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" /><span className="sr-only">Search refund queue</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search reference or reason" className="w-full rounded-xl border border-[var(--line)] bg-[var(--page)] py-2.5 pl-9 pr-3 text-sm outline-none focus:border-[var(--brand)] sm:w-64" /></label>
                <label><span className="sr-only">Filter by status</span><select value={filter} onChange={(event) => setFilter(event.target.value as typeof filter)} className="w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-2.5 text-sm font-semibold outline-none focus:border-[var(--brand)] sm:w-52">{filters.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
              </div>
            </div>

            {loading ? <div className="flex items-center justify-center gap-3 px-6 py-16 text-sm text-[var(--muted)]"><RefreshCw size={17} className="animate-spin" /> Loading refund records…</div>
              : visibleRows.length === 0 ? <div className="px-6 py-16 text-center"><span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-[var(--page)] text-[var(--muted)]"><WalletCards size={22} /></span><p className="mt-4 font-bold">No refund requests found</p><p className="mt-1 text-sm text-[var(--muted)]">Try a different status filter or search term.</p></div>
              : <div className="divide-y divide-[var(--line)]">
                {visibleRows.map((refund) => (
                  <article key={refund.id} className="grid gap-4 p-4 sm:p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2"><span className="font-display text-lg font-extrabold tabular-nums">{money(refund.amount_minor, refund.currency)}</span><span className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold ${statusStyle(refund.status)}`}>{statusLabels[refund.status]}</span></div>
                      <p className="mt-1 break-all text-xs font-semibold text-[var(--muted)]">Payment {refund.payment_reference} · Request {refund.id.slice(0, 8)}</p>
                      <p className="mt-3 text-sm leading-6">{refund.reason}</p>
                      <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--muted)]"><span>Requested {dateLabel(refund.requested_at)}</span>{refund.approved_at && <span>Approved {dateLabel(refund.approved_at)}</span>}{refund.settled_at && <span>Settled {dateLabel(refund.settled_at)}</span>}</div>
                      {refund.provider_refund_reference && <p className="mt-2 break-all text-xs text-[var(--muted)]">Provider ref: {refund.provider_refund_reference}</p>}
                      {refund.status === 'blocked_insufficient_wallet_funds' && <p className="mt-2 flex items-center gap-1.5 text-xs font-semibold text-rose-700 dark:text-rose-300"><XCircle size={14} /> Customer wallet did not have enough available funds.</p>}
                      {refund.status === 'review_required' && <p className="mt-2 flex items-center gap-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300"><AlertCircle size={14} /> Verify provider status before retrying or releasing reserved funds.</p>}
                    </div>
                    {(refund.status === 'requested' || refund.status === 'blocked_insufficient_wallet_funds') && <button type="button" disabled={busyId === refund.id} onClick={() => void approve(refund)} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-2.5 text-sm font-bold text-white transition hover:opacity-90 disabled:cursor-wait disabled:opacity-60">{busyId === refund.id ? <RefreshCw size={15} className="animate-spin" /> : <ShieldCheck size={16} />}{refund.status === 'requested' ? 'Approve refund' : 'Recheck eligibility'}</button>}
                  </article>
                ))}
              </div>}
            <footer className="border-t border-[var(--line)] bg-[var(--page)]/60 px-4 py-3 text-xs text-[var(--muted)] sm:px-5">Wallet reservations and provider calls are server-controlled. Never retry an uncertain provider result until reconciled.</footer>
          </section>
          <p className="mt-5 text-center text-xs text-[var(--muted)]">SEMIZZY financial operations · Access is audited and permission-gated.</p>
        </div>
      </main>
    </>
  );
}

function Metric({ icon, label, value, detail }: { icon: React.ReactNode; label: string; value: number; detail: string }) {
  return <div className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:p-5"><div className="flex items-center justify-between gap-2"><span className="text-xs font-bold text-[var(--muted)]">{label}</span><span className="text-[var(--brand)]">{icon}</span></div><p className="mt-3 font-display text-2xl font-extrabold tabular-nums">{value}</p><p className="mt-1 text-[11px] leading-4 text-[var(--muted)]">{detail}</p></div>;
}
