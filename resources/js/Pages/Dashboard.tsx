import { useEffect, useState, type FormEvent } from 'react';
import { AlertCircle, ArrowDownToLine, ArrowUpRight, CheckCircle2, Clock3, CreditCard, Eye, EyeOff, RefreshCw, Search, Smartphone, WalletCards } from 'lucide-react';
import DashboardLayout from '../Components/DashboardLayout';

type Wallet = { balance_minor: number; available_balance_minor: number; currency: string; status: string } | null;
type Transaction = { id: string; type: string; status: string; currency: string; amount_minor: number; description: string | null; reference: string | null; created_at: string | null };
type Order = { id: string; service_type: string; network: string; recipient: string; amount_minor: number; currency: string; status: string; created_at: string | null };
type Funding = { id: string; reference: string; amount_minor: number; currency: string; status: string; created_at: string | null };
type Pagination = { currentPage: number; lastPage: number; total: number };
type ActivityFilters = { search: string; status: string };

function money(minor: number, currency = 'NGN') {
  return new Intl.NumberFormat('en-NG', { style: 'currency', currency, maximumFractionDigits: 2 }).format(minor / 100);
}
function dateLabel(value: string | null) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}
function statusClass(status: string) {
  if (['succeeded', 'successful', 'completed', 'paid'].includes(status.toLowerCase())) return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
  if (['failed', 'rejected', 'cancelled'].includes(status.toLowerCase())) return 'bg-rose-500/10 text-rose-700 dark:text-rose-300';
  return 'bg-amber-500/10 text-amber-800 dark:text-amber-300';
}

export default function CustomerDashboard({ wallet, transactions, orders, fundingHistory, transactionPagination, orderPagination, transactionFilters, orderFilters, isAdminPreview = false }: {
  wallet: Wallet; transactions: Transaction[]; orders: Order[]; fundingHistory: Funding[];
  transactionPagination: Pagination; orderPagination: Pagination; transactionFilters: ActivityFilters; orderFilters: ActivityFilters; isAdminPreview?: boolean;
}) {
  const [amount, setAmount] = useState('1000');
  const [funding, setFunding] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [balanceVisible, setBalanceVisible] = useState(true);
  const [transactionQuery, setTransactionQuery] = useState(transactionFilters.search);
  const [transactionStatus, setTransactionStatus] = useState(transactionFilters.status);
  const [orderQuery, setOrderQuery] = useState(orderFilters.search);
  const [orderStatus, setOrderStatus] = useState(orderFilters.status);
  const filteredTransactions = transactions;

  useEffect(() => {
    const status = new URLSearchParams(window.location.search).get('funding');
    if (status === 'success') {
      setNotice('Payment verified and your wallet has been credited.');
    } else if (status === 'test-success') {
      setNotice('Paystack test checkout verified successfully. No real money was taken and your wallet balance was not changed.');
    } else if (status === 'pending') {
      setError('Payment is not verified yet, so your wallet has not been credited. Check your funding history again shortly.');
    } else if (status === 'unverified') {
      setError('We could not verify this payment return. Your wallet has not been credited. Please contact support if you completed payment.');
    }
  }, []);

  async function startFunding(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    setNotice('');
    const naira = Number(amount);
    if (!Number.isFinite(naira) || naira < 1 || naira > 5_000_000 || Math.round(naira * 100) !== naira * 100) {
      setError('Enter an amount between ₦1 and ₦5,000,000 with no more than two decimal places.');
      return;
    }

    setFunding(true);
    try {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      const response = await fetch('/wallet/funding-intents', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify({ amount_minor: Math.round(naira * 100) }),
      });
      const payload = await response.json().catch(() => ({})) as { data?: { checkout_url?: string }; message?: string };
      if (!response.ok) throw new Error(payload.message || 'Wallet funding could not be started. Please try again.');
      if (!payload.data?.checkout_url) throw new Error('The payment provider did not return a checkout link. Please contact support before trying again.');
      window.location.assign(payload.data.checkout_url);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Wallet funding could not be started.');
      setFunding(false);
    }
  }

  return (
    <DashboardLayout title={isAdminPreview ? 'Customer dashboard preview' : 'Your dashboard'} description={isAdminPreview ? 'Preview the customer experience for your own staff account. Customer data and permissions are unchanged.' : 'Your wallet, digital services, and recent activity in one secure place.'} customerPreview={isAdminPreview}>
      {isAdminPreview && <aside className="mb-5 flex flex-col gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-extrabold">Staff preview mode</p><p className="mt-1 text-xs leading-5 text-[var(--muted)]">This is your own account’s customer view, not a customer impersonation session.</p></div><a href="/admin/dashboard" className="inline-flex min-h-10 items-center justify-center rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2">Back to Command Center</a></aside>}
      <section className="grid gap-4 lg:grid-cols-[1.15fr_0.85fr]">
        <article className="relative overflow-hidden rounded-3xl bg-[var(--brand-strong)] p-6 text-white shadow-xl shadow-emerald-950/10 sm:p-8">
          <div className="absolute -right-12 -top-16 h-52 w-52 rounded-full bg-emerald-300/20 blur-3xl" />
          <div className="relative flex items-start justify-between gap-4">
            <div className="min-w-0"><p className="text-sm font-semibold text-white/85">Available wallet balance</p><div className="mt-3 flex flex-wrap items-center gap-3"><p className="font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{wallet ? (balanceVisible ? money(wallet.available_balance_minor, wallet.currency) : '••••••••') : 'Not set up yet'}</p>{wallet && <button type="button" onClick={() => setBalanceVisible((visible) => !visible)} aria-label={balanceVisible ? 'Hide wallet balance' : 'Show wallet balance'} aria-pressed={!balanceVisible} className="inline-flex min-h-10 min-w-10 items-center justify-center rounded-xl text-white/90 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">{balanceVisible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}</button>}</div><p className="mt-2 text-xs text-white/75">{wallet ? (wallet.status === 'active' ? 'Your NGN wallet is active' : `Wallet status: ${wallet.status}`) : 'A wallet will be created when you start a supported wallet operation.'}</p></div>
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-white/10"><WalletCards size={23} aria-hidden="true" /></span>
          </div>
          <div className="relative mt-7 flex flex-wrap gap-3">
            <a href="/wallet/vtu" className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-extrabold text-slate-950 hover:bg-slate-100"><Smartphone size={17} aria-hidden="true" /> Buy airtime & data <ArrowUpRight size={15} aria-hidden="true" /></a>
            <a href="#fund-wallet" className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/20 px-4 py-3 text-sm font-bold text-white hover:bg-white/10"><ArrowDownToLine size={17} aria-hidden="true" /> Fund wallet</a>
          </div>
          {wallet && <p className="relative mt-5 text-xs text-white/75">Total ledger balance: {balanceVisible ? money(wallet.balance_minor, wallet.currency) : '••••••••'}</p>}
        </article>

        <article id="fund-wallet" className="rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
          <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><CreditCard size={20} aria-hidden="true" /></span><div><h2 className="font-display text-lg font-extrabold">Fund your wallet</h2><p className="text-xs text-[var(--muted)]">Secure provider checkout</p></div></div>
          <form onSubmit={startFunding} className="mt-5">
            <label htmlFor="fund-amount" className="text-xs font-bold text-[var(--muted)]">Amount in naira (₦)</label>
            <div className="mt-2 flex gap-2"><input id="fund-amount" type="number" min="1" max="5000000" step="0.01" inputMode="decimal" required value={amount} onChange={(event) => setAmount(event.target.value)} className="min-w-0 flex-1 rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 text-sm outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" /><button type="submit" disabled={funding} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 text-sm font-bold text-white disabled:opacity-60">{funding ? <RefreshCw size={15} className="animate-spin" aria-hidden="true" /> : <ArrowUpRight size={15} aria-hidden="true" />}{funding ? 'Starting…' : 'Continue'}</button></div>
            <p className="mt-2 text-xs leading-5 text-[var(--muted)]">You will leave SEMIZZY to complete payment with the configured provider. Your wallet is credited only after provider verification.</p>
          </form>
          {error && <p role="alert" className="mt-4 flex items-start gap-2 rounded-xl bg-rose-500/10 p-3 text-xs leading-5 text-rose-700 dark:text-rose-300"><AlertCircle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />{error}</p>}
          {notice && <p role="status" className="mt-4 flex items-center gap-2 text-xs text-emerald-700 dark:text-emerald-300"><CheckCircle2 size={16} aria-hidden="true" />{notice}</p>}
        </article>
      </section>

      <section className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <QuickLink href="/wallet/vtu" icon={<Smartphone size={20} />} title="Airtime & data" detail="Buy mobile services" />
        <QuickLink href="#transactions" icon={<ArrowDownToLine size={20} />} title="Transactions" detail="Review wallet activity" />
        <QuickLink href="#funding-history" icon={<CreditCard size={20} />} title="Funding history" detail="See payment attempts" />
        <QuickLink href="#orders" icon={<Clock3 size={20} />} title="Order history" detail="Review recent service orders" />
      </section>

      <section id="transactions" className="mt-8 grid gap-5 xl:grid-cols-2">
        <div className="overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
          <div className="border-b border-[var(--line)] p-5">
            <div className="flex items-center justify-between gap-3"><div><h2 className="font-display text-lg font-extrabold">Recent wallet activity</h2><p className="mt-1 text-xs text-[var(--muted)]">Search and filter your latest wallet transactions</p></div><WalletCards size={20} className="shrink-0 text-[var(--brand)]" aria-hidden="true" /></div>
            <form method="get" action={isAdminPreview ? '/customer/dashboard' : '/dashboard'} className="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
              <input type="hidden" name="orders_page" value={orderPagination.currentPage} />
              <input type="hidden" name="order_search" value={orderFilters.search} />
              <input type="hidden" name="order_status" value={orderFilters.status} />
              <label className="relative block min-w-0"><span className="sr-only">Search wallet activity</span><Search size={16} aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" /><input type="search" name="transaction_search" value={transactionQuery} onChange={(event) => setTransactionQuery(event.target.value)} maxLength={100} placeholder="Search description or reference" className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] py-2 pl-9 pr-3 text-sm outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" /></label>
              <label className="block"><span className="sr-only">Filter transactions by status</span><select name="transaction_status" value={transactionStatus} onChange={(event) => setTransactionStatus(event.target.value)} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-2 text-sm outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"><option value="all">All statuses</option><option value="pending">Pending</option><option value="successful">Successful</option><option value="failed">Failed</option></select></label>
              <button type="submit" className="min-h-11 rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white">Apply</button>
            </form>
            <p className="mt-3 text-xs text-[var(--muted)]" aria-live="polite">Showing {transactions.length} of {transactionPagination.total} matching transactions · Page {transactionPagination.currentPage} of {transactionPagination.lastPage}</p>
          </div>
          {transactions.length === 0
            ? <EmptyState message="No wallet transactions yet. Your activity will appear here when you use a supported wallet service." />
            : filteredTransactions.length === 0
              ? <EmptyState message="No recent transactions match those filters. Try another search or status." />
              : <div className="divide-y divide-[var(--line)]">{filteredTransactions.map((item) => <article key={item.id} className="flex items-start justify-between gap-3 p-4 sm:px-5"><div className="min-w-0"><p className="break-words text-sm font-bold">{item.description || item.type.replaceAll('_', ' ')}</p><p className="mt-1 break-all text-xs text-[var(--muted)]">{item.reference || item.id} · {dateLabel(item.created_at)}</p><span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ${statusClass(item.status)}`}>{item.status.replaceAll('_', ' ')}</span></div><p className="shrink-0 text-sm font-extrabold tabular-nums">{money(item.amount_minor, item.currency)}</p></article>)}</div>}
          <HistoryPager page={transactionPagination.currentPage} lastPage={transactionPagination.lastPage} pageName="transactions_page" anchor="transactions" isAdminPreview={isAdminPreview} />
        </div>

        <div id="orders" className="overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
          <div className="border-b border-[var(--line)] p-5"><div className="flex items-center justify-between gap-3"><div><h2 className="font-display text-lg font-extrabold">Airtime & data order history</h2><p className="mt-1 text-xs text-[var(--muted)]">Search and filter all of your VTU service requests</p></div><Smartphone size={20} className="shrink-0 text-[var(--brand)]" aria-hidden="true" /></div>
            <form method="get" action={isAdminPreview ? '/customer/dashboard' : '/dashboard'} className="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
              <input type="hidden" name="transactions_page" value={transactionPagination.currentPage} />
              <input type="hidden" name="transaction_search" value={transactionFilters.search} />
              <input type="hidden" name="transaction_status" value={transactionFilters.status} />
              <label className="block min-w-0"><span className="sr-only">Search VTU orders</span><input type="search" name="order_search" value={orderQuery} onChange={(event) => setOrderQuery(event.target.value)} maxLength={100} placeholder="Search network, recipient, reference" className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-2 text-sm outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)]" /></label>
              <label className="block"><span className="sr-only">Filter orders by status</span><select name="order_status" value={orderStatus} onChange={(event) => setOrderStatus(event.target.value)} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-2 text-sm outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)]"><option value="all">All statuses</option><option value="pending">Pending</option><option value="processing">Processing / review</option><option value="successful">Successful</option><option value="failed">Failed / cancelled</option></select></label>
              <button type="submit" className="min-h-11 rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white">Apply</button>
            </form>
            <p className="mt-3 text-xs text-[var(--muted)]" aria-live="polite">Showing {orders.length} of {orderPagination.total} matching orders · Page {orderPagination.currentPage} of {orderPagination.lastPage}</p>
          </div>
          {orders.length === 0 ? <EmptyState message={orderFilters.search || orderFilters.status !== 'all' ? 'No orders match these filters. Clear the search or choose all statuses to see more.' : 'No VTU orders yet. When you purchase airtime or data, order updates will appear here.'} /> : <div className="divide-y divide-[var(--line)]">{orders.map((item) => <article key={item.id} className="flex items-start justify-between gap-3 p-4 sm:px-5"><div className="min-w-0"><p className="text-sm font-bold">{item.network} · {item.service_type}</p><p className="mt-1 break-all text-xs text-[var(--muted)]">{item.recipient} · {dateLabel(item.created_at)} · {item.id}</p><span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ${statusClass(item.status)}`}>{item.status.replaceAll('_', ' ')}</span></div><p className="shrink-0 text-sm font-extrabold tabular-nums">{money(item.amount_minor, item.currency)}</p></article>)}</div>}
          <HistoryPager page={orderPagination.currentPage} lastPage={orderPagination.lastPage} pageName="orders_page" anchor="orders" isAdminPreview={isAdminPreview} />
        </div>
      </section>

      <section id="funding-history" className="mt-5 overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
        <div className="flex items-center justify-between border-b border-[var(--line)] p-5"><div><h2 className="font-display text-lg font-extrabold">Wallet funding history</h2><p className="mt-1 text-xs text-[var(--muted)]">Payment attempts linked to your wallet</p></div><Clock3 size={20} className="text-[var(--brand)]" aria-hidden="true" /></div>
        {fundingHistory.length === 0 ? <EmptyState message="No wallet funding attempts yet." /> : <div className="divide-y divide-[var(--line)]">{fundingHistory.map((item) => <article key={item.id} className="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-5"><div><p className="text-sm font-bold">{money(item.amount_minor, item.currency)}</p><p className="mt-1 break-all text-xs text-[var(--muted)]">{item.reference} · {dateLabel(item.created_at)}</p></div><span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${statusClass(item.status)}`}>{item.status.replaceAll('_', ' ')}</span></article>)}</div>}
      </section>
    </DashboardLayout>
  );
}

function HistoryPager({ page, lastPage, pageName, anchor, isAdminPreview }: { page: number; lastPage: number; pageName: 'transactions_page' | 'orders_page'; anchor: string; isAdminPreview: boolean }) {
  if (lastPage <= 1) return null;
  const pageUrl = (nextPage: number) => {
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    params.set(pageName, String(nextPage));
    const path = isAdminPreview ? '/customer/dashboard' : '/dashboard';
    return `${path}?${params.toString()}#${anchor}`;
  };
  return <nav aria-label={anchor === 'transactions' ? 'Transaction history pages' : 'Order history pages'} className="flex items-center justify-between gap-3 border-t border-[var(--line)] p-4">
    {page > 1 ? <a href={pageUrl(page - 1)} className="inline-flex min-h-10 items-center rounded-xl border border-[var(--line)] px-3 text-sm font-bold hover:border-[var(--brand)]">Previous</a> : <span className="text-sm text-[var(--muted)]">First page</span>}
    <span className="text-xs text-[var(--muted)]">Page {page} of {lastPage}</span>
    {page < lastPage ? <a href={pageUrl(page + 1)} className="inline-flex min-h-10 items-center rounded-xl border border-[var(--line)] px-3 text-sm font-bold hover:border-[var(--brand)]">Next</a> : <span className="text-sm text-[var(--muted)]">Last page</span>}
  </nav>;
}

function QuickLink({ href, icon, title, detail }: { href: string; icon: React.ReactNode; title: string; detail: string }) {
  return <a href={href} className="flex min-h-24 items-center gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 transition hover:-translate-y-0.5 hover:border-[var(--brand)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"><span aria-hidden="true" className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]">{icon}</span><span><span className="block text-sm font-extrabold">{title}</span><span className="mt-1 block text-xs text-[var(--muted)]">{detail}</span></span><ArrowUpRight size={16} className="ml-auto shrink-0 text-[var(--muted)]" aria-hidden="true" /></a>;
}
function EmptyState({ message }: { message: string }) {
  return <div className="px-5 py-10 text-center"><span className="mx-auto grid h-10 w-10 place-items-center rounded-xl bg-[var(--page)] text-[var(--muted)]"><WalletCards size={20} aria-hidden="true" /></span><p className="mx-auto mt-3 max-w-sm text-sm leading-6 text-[var(--muted)]">{message}</p></div>;
}
