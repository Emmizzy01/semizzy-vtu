import { useState } from 'react';
import { Activity, AlertTriangle, Search, Smartphone, RefreshCw } from 'lucide-react';
import { router } from '@inertiajs/react';
import DashboardLayout from '../../../Components/DashboardLayout';

type Order = {
  id: string; customer: string; customer_email: string | null; service_type: string; network: string;
  recipient: string; amount_minor: number; currency: string; status: string; provider: string;
  provider_reference: string | null; failure_code: string | null; created_at: string | null;
  dispatch_started_at: string | null; completed_at: string | null;
};
type Pagination = { currentPage: number; lastPage: number; total: number };
type Filters = { search: string; status: string };
type Metrics = { unresolved: number; awaitingReconciliation: number; failed: number };

function money(minor: number, currency: string) {
  return new Intl.NumberFormat('en-NG', { style: 'currency', currency, maximumFractionDigits: 2 }).format(minor / 100);
}
function dateLabel(value: string | null) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}
function statusClass(status: string) {
  if (status === 'successful') return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
  if (['failed', 'cancelled'].includes(status)) return 'bg-rose-500/10 text-rose-700 dark:text-rose-300';
  if (status === 'awaiting_reconciliation') return 'bg-amber-500/15 text-amber-800 dark:text-amber-300';
  return 'bg-[var(--page)] text-[var(--muted)]';
}

export default function VtuOrderOperations({ orders, pagination, filters, metrics }: {
  orders: Order[]; pagination: Pagination; filters: Filters; metrics: Metrics;
}) {
  const [search, setSearch] = useState(filters.search);
  const [status, setStatus] = useState(filters.status);
  const [reconciling, setReconciling] = useState<string | null>(null);
  const pageUrl = (page: number) => {
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    params.set('page', String(page));
    return '/admin/vtu-orders?' + params.toString();
  };

  return (
    <DashboardLayout admin canManageStaff title="VTU order operations" description="Restricted operations queue for monitoring orders and safely reconciling ambiguous provider outcomes.">
      <section className="grid gap-3 sm:grid-cols-3">
        <Metric label="Unresolved orders" value={metrics.unresolved} detail="Pending, processing, or awaiting reconciliation" />
        <Metric label="Awaiting reconciliation" value={metrics.awaitingReconciliation} detail="Provider outcome needs investigation" />
        <Metric label="Failed / cancelled" value={metrics.failed} detail="Terminal outcomes; inspect before support follow-up" />
      </section>
      <section className="mt-6 overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
        <header className="border-b border-[var(--line)] p-4 sm:p-5">
          <div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><Activity size={20} /></span><div><h2 className="font-display text-lg font-extrabold">Order queue</h2><p className="mt-1 text-sm text-[var(--muted)]">Search by order ID, customer, recipient, network, provider reference, or failure code.</p></div></div>
          <form method="get" action="/admin/vtu-orders" className="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
            <label className="relative block min-w-0"><span className="sr-only">Search VTU orders</span><Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" /><input name="search" value={search} onChange={(event) => setSearch(event.target.value)} maxLength={120} placeholder="Search orders or customers" className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] py-2 pl-9 pr-3 text-sm outline-none focus:border-[var(--brand)]" /></label>
            <label><span className="sr-only">Filter order status</span><select name="status" value={status} onChange={(event) => setStatus(event.target.value)} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-2 text-sm"><option value="unresolved">Unresolved</option><option value="all">All statuses</option><option value="pending">Pending</option><option value="processing">Processing</option><option value="awaiting_reconciliation">Awaiting reconciliation</option><option value="successful">Successful</option><option value="failed">Failed</option><option value="cancelled">Cancelled</option></select></label>
            <button type="submit" className="min-h-11 rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white">Apply filters</button>
          </form>
          <p className="mt-3 text-xs text-[var(--muted)]">Showing {orders.length} of {pagination.total} matching orders · Page {pagination.currentPage} of {pagination.lastPage}</p>
        </header>
        {orders.length === 0 ? <p className="px-5 py-12 text-center text-sm text-[var(--muted)]">No orders match this view. Try a different status or search term.</p> : <div className="divide-y divide-[var(--line)]">
          {orders.map((order) => <article key={order.id} className="grid gap-3 p-4 sm:p-5 xl:grid-cols-[minmax(0,1fr)_auto]">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2"><span className={'rounded-full px-2.5 py-1 text-[11px] font-bold ' + statusClass(order.status)}>{order.status.replaceAll('_', ' ')}</span><span className="text-sm font-extrabold">{order.network.toUpperCase()} · {order.service_type}</span><span className="break-all font-mono text-xs text-[var(--muted)]">{order.id}</span></div>
              <p className="mt-2 break-words text-sm font-bold">{order.customer} <span className="font-normal text-[var(--muted)]">{order.customer_email || ''}</span></p>
              <p className="mt-1 break-all text-sm text-[var(--muted)]">Recipient: {order.recipient} · Created {dateLabel(order.created_at)}</p>
              <p className="mt-1 break-all text-xs text-[var(--muted)]">Provider: {order.provider} · Reference: {order.provider_reference || 'Not assigned'}</p>
              {order.failure_code && <p className="mt-2 flex items-center gap-2 break-words text-xs text-amber-800 dark:text-amber-300"><AlertTriangle size={14} className="shrink-0" /> Failure / review code: {order.failure_code}</p>}
              {order.dispatch_started_at && <p className="mt-1 text-xs text-[var(--muted)]">Dispatch started: {dateLabel(order.dispatch_started_at)}{order.completed_at ? ' · Completed: ' + dateLabel(order.completed_at) : ''}</p>}
            </div>
            <div className="flex items-center justify-between gap-3 xl:flex-col xl:items-end xl:justify-center"><p className="text-lg font-extrabold tabular-nums">{money(order.amount_minor, order.currency)}</p>{['pending', 'processing', 'awaiting_reconciliation'].includes(order.status) && order.dispatch_started_at && <button type="button" disabled={reconciling === order.id} onClick={() => { setReconciling(order.id); router.post('/admin/vtu-orders/' + encodeURIComponent(order.id) + '/reconcile', {}, { preserveScroll: true, onFinish: () => setReconciling(null) }); }} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-3 py-2 text-xs font-bold text-white disabled:cursor-wait disabled:opacity-50"><RefreshCw size={14} className={reconciling === order.id ? 'animate-spin' : ''} />{reconciling === order.id ? 'Checking…' : 'Reconcile status'}</button>}</div>
          </article>)}
        </div>}
        {pagination.lastPage > 1 && <footer className="flex items-center justify-between gap-3 border-t border-[var(--line)] p-4"><a href={pageUrl(pagination.currentPage - 1)} aria-disabled={pagination.currentPage <= 1} className={'rounded-xl border border-[var(--line)] px-3 py-2 text-sm font-bold ' + (pagination.currentPage <= 1 ? 'pointer-events-none opacity-40' : '')}>Previous</a><span className="text-xs text-[var(--muted)]">Page {pagination.currentPage} of {pagination.lastPage}</span><a href={pageUrl(pagination.currentPage + 1)} aria-disabled={pagination.currentPage >= pagination.lastPage} className={'rounded-xl border border-[var(--line)] px-3 py-2 text-sm font-bold ' + (pagination.currentPage >= pagination.lastPage ? 'pointer-events-none opacity-40' : '')}>Next</a></footer>}
      </section>
      <p className="mt-4 text-xs leading-5 text-[var(--muted)]">Safety control: reconciliation performs a provider status lookup only. It never resubmits the purchase. A refund is posted only when the provider lookup confirms a definitive failure; ambiguous outcomes remain held for further reconciliation.</p>
    </DashboardLayout>
  );
}

function Metric({ label, value, detail }: { label: string; value: number; detail: string }) {
  return <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4"><p className="text-xs font-semibold text-[var(--muted)]">{label}</p><p className="mt-2 font-display text-2xl font-extrabold tabular-nums">{value}</p><p className="mt-1 text-xs leading-5 text-[var(--muted)]">{detail}</p></article>;
}
