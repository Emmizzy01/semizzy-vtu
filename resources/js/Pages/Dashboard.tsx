import { useEffect, useState, type FormEvent } from 'react';
import { ArrowDownToLine, ArrowUpRight, Activity, BookOpen, CheckCircle2, Clock3, CreditCard, Eye, EyeOff, GraduationCap, ShieldCheck, Smartphone, Tv, Wifi, Zap, Megaphone, Sparkles, WalletCards, Bell, Headset, ScanLine, Landmark, PiggyBank, CircleHelp, Gift, ChevronRight, CircleAlert, HandCoins, PlaySquare, Users, SmartphoneNfc, Search, } from 'lucide-react';
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

export default function CustomerDashboard({ wallet, transactions, orders, fundingHistory, transactionPagination, orderPagination, transactionFilters, orderFilters, isAdminPreview = false, customer }: {
  wallet: Wallet; transactions: Transaction[]; orders: Order[]; fundingHistory: Funding[];
  transactionPagination: Pagination; orderPagination: Pagination; transactionFilters: ActivityFilters; orderFilters: ActivityFilters; isAdminPreview?: boolean; customer?: { name?: string; tier?: string; level?: string; avatarUrl?: string | null; kycStatus?: string };
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
      <section className="mx-auto max-w-3xl space-y-4 pb-3">
        <div className="flex items-center justify-between gap-3 px-1 pt-1">
          <a href="/profile" className="flex min-w-0 items-center gap-3" aria-label="Open profile and account level">
            <span className="relative grid h-12 w-12 shrink-0 place-items-center rounded-full border-2 border-white bg-emerald-100 text-base font-extrabold text-emerald-800 shadow-sm dark:border-slate-800 dark:bg-emerald-950 dark:text-emerald-200">
              {customer?.avatarUrl ? <img src={customer.avatarUrl} alt="" className="h-full w-full rounded-full object-cover" /> : (customer?.name || 'S').trim().split(/\\s+/).slice(0,2).map(part => part[0]).join('').toUpperCase()}
              <span className="absolute -bottom-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full border border-white bg-amber-100 px-1 text-[9px] font-black text-amber-900 dark:border-slate-900" title={customer?.tier || customer?.level || 'Account level'}>{customer?.level || customer?.tier || '—'}</span>
            </span>
            <span className="min-w-0"><span className="block text-[10px] font-semibold uppercase tracking-wide text-[var(--muted)]">Welcome back</span><span className="block truncate text-lg font-extrabold">{customer?.name || 'Semizzy user'}</span><span className="block truncate text-[10px] font-bold text-[var(--brand)]">{customer?.tier || 'Customer'}{customer?.level ? ' · ' + customer.level : ''}</span></span>
          </a>
          <div className="flex shrink-0 items-center gap-3 text-[var(--ink)]">
            <a href="/support" aria-label="Help and support" className="relative grid h-10 w-10 place-items-center rounded-full hover:bg-[var(--brand-soft)]"><Headset size={23}/><span className="absolute -right-1 -top-1 rounded-full bg-pink-200 px-1.5 py-0.5 text-[8px] font-extrabold text-pink-800">HELP</span></a>
            <button type="button" aria-label="Scan code" onClick={() => setNotice('QR scanning is not enabled yet. Use the available service links for now.')} className="grid h-10 w-10 place-items-center rounded-full hover:bg-[var(--brand-soft)]"><ScanLine size={23}/></button>
            <a href="/notifications" aria-label="Notifications" className="relative grid h-10 w-10 place-items-center rounded-full hover:bg-[var(--brand-soft)]"><Bell size={23}/><span className="absolute right-0 top-0 grid h-4 min-w-4 place-items-center rounded-full bg-rose-500 px-1 text-[9px] font-extrabold text-white">!</span></a>
          </div>
        </div>

        <article className="overflow-hidden rounded-[28px] bg-emerald-600 text-white shadow-sm">
          <div className="p-5 sm:p-6">
            <div className="flex items-center justify-between gap-3"><span className="inline-flex items-center gap-2 text-xs font-bold text-white/90"><ShieldCheck size={17}/> {wallet?.status === 'active' ? 'Available' : wallet ? 'Wallet ' + wallet.status : 'Wallet not set up'} <span className="text-white/70">●</span></span><a href="#fund-wallet" className="inline-flex min-h-10 items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 text-xs font-extrabold hover:bg-white/20"><ArrowDownToLine size={16}/> Add Money</a></div>
            <div className="mt-3 flex items-center gap-2"><p className="text-3xl font-black tracking-tight">{wallet ? (balanceVisible ? money(wallet.available_balance_minor, wallet.currency) : '••••••') : '—'}</p>{wallet && <button type="button" onClick={() => setBalanceVisible(v=>!v)} aria-label={balanceVisible ? 'Hide balance' : 'Show balance'} className="grid h-8 w-8 place-items-center rounded-full hover:bg-white/10">{balanceVisible ? <EyeOff size={17}/> : <Eye size={17}/>}</button>}<a href="#transactions" aria-label="View wallet transactions"><ChevronRight size={23}/></a></div>
            <p className="mt-5 text-xs text-white/75">Wallet balance</p>
            {wallet && <p className="mt-1 text-[10px] text-white/75">Ledger balance: {balanceVisible ? money(wallet.balance_minor,wallet.currency) : '••••••'}</p>}
          </div>
          <a href="#transactions" className="flex items-center justify-between gap-3 bg-white/90 px-5 py-3 text-xs text-slate-800 dark:bg-slate-950/90 dark:text-slate-100"><span className="flex min-w-0 items-center gap-2"><CheckCircle2 size={18} className="shrink-0 text-emerald-600"/><span className="truncate">{transactions[0] ? (transactions[0].description || transactions[0].type.replaceAll('_',' ')) + ' ' + money(transactions[0].amount_minor,transactions[0].currency) : 'Your latest wallet activity will appear here'}</span></span><span className="flex shrink-0 items-center gap-1 font-bold">Transactions <ChevronRight size={16}/></span></a>
        </article>

        <div className="grid grid-cols-4 gap-2 rounded-[26px] bg-[var(--surface)] p-4 shadow-sm">
          {[{title:'To Bank',icon:<Landmark size={24}/>,href:'/services'},{title:'Save',icon:<PiggyBank size={24}/>,href:'/services'},{title:'Pay Bills',icon:<Zap size={24}/>,href:'/services'},{title:'Support',icon:<Headset size={24}/>,href:'/support'}].map(item=><a key={item.title} href={item.href} className="flex min-w-0 flex-col items-center gap-2 rounded-xl py-1 text-center"><span className="grid h-14 w-14 place-items-center rounded-[20px] bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">{item.icon}</span><span className="text-[11px] font-bold">{item.title}</span></a>)}
        </div>

        {customer?.kycStatus && !['verified','approved','complete','completed'].includes(customer.kycStatus.toLowerCase()) && <a href="/profile" className="flex items-center gap-3 rounded-[24px] bg-[var(--surface)] p-4 shadow-sm"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-600"><CircleAlert size={28}/></span><span className="min-w-0 flex-1"><span className="block font-extrabold">KYC verification needed</span><span className="mt-1 block text-xs text-[var(--muted)]">Complete your identity checks to access eligible account limits.</span></span><ChevronRight size={19}/></a>}

        <section className="rounded-[26px] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="dashboard-services-heading">
          <div className="mb-4 flex items-center justify-between gap-2"><div><h2 id="dashboard-services-heading" className="font-extrabold">Services</h2><p className="mt-1 text-[10px] text-[var(--muted)]">Everything you need, in one place</p></div><a href="/services" className="inline-flex items-center gap-1 text-xs font-extrabold text-[var(--brand)]">More <ChevronRight size={16}/></a></div>
          <div className="grid grid-cols-4 gap-x-2 gap-y-4 sm:grid-cols-2 lg:grid-cols-6" aria-label="Popular services">
            {[{title:'Airtime',href:'/wallet/vtu',icon:<Smartphone size={23}/>},{title:'Data',href:'/wallet/vtu',icon:<Wifi size={23}/>},{title:'Electricity',href:'/services',icon:<Zap size={23}/>},{title:'TV',href:'/services',icon:<PlaySquare size={23}/>},{title:'Education',href:'/services',icon:<GraduationCap size={23}/>},{title:'Government',href:'/services',icon:<Landmark size={23}/>},{title:'Streaming',href:'/services',icon:<Tv size={23}/>},{title:'Social Media',href:'/services',icon:<Megaphone size={23}/>},{title:'Manual Deposit',href:'/wallet/manual-deposits',icon:<WalletCards size={23}/>},{title:'Transactions',href:'#transactions',icon:<Activity size={23}/>},{title:'Security',href:'/security/transaction-pin',icon:<ShieldCheck size={23}/>},{title:'Support',href:'/support',icon:<CircleHelp size={23}/>},{title:'Exam PINs',href:'/services',icon:<BookOpen size={23}/>},{title:'Betting',href:'/services',icon:<SmartphoneNfc size={23}/>},{title:'Rewards',href:'/services',icon:<Gift size={23}/>},{title:'Refer & Earn',href:'/services',icon:<Users size={23}/>},{title:'Funding History',href:'#funding-history',icon:<CreditCard size={23}/>},{title:'More Services',href:'/services',icon:<ArrowUpRight size={23}/> }].map((service,index)=><a key={service.title} href={service.href} className={'flex min-w-0 flex-col items-center gap-2 text-center ' + (index>=16?'hidden lg:flex':index>=6?'hidden sm:flex':'')}><span className="grid h-12 w-12 place-items-center rounded-[19px] bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">{service.icon}</span><span className="text-[10px] font-bold leading-tight sm:text-xs">{service.title}</span></a>)}
          </div>
        </section>

        <section className="rounded-[26px] bg-gradient-to-r from-emerald-50 to-amber-50 p-5 text-slate-800 dark:from-emerald-950 dark:to-slate-900 dark:text-white"><div className="flex items-center justify-between"><div><h2 className="font-extrabold">Complete Tasks, Earn Rewards</h2><p className="mt-1 text-xs text-slate-600 dark:text-slate-300">Discover available Semizzy benefits</p></div><Gift size={26} className="text-emerald-600"/></div><div className="mt-4 flex items-center gap-3 border-t border-dashed border-emerald-700/20 pt-4"><span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-white text-emerald-600"><Sparkles size={25}/></span><span className="min-w-0 flex-1"><span className="block text-sm font-extrabold">Rewards & promotions</span><span className="mt-1 block text-[10px] text-slate-600 dark:text-slate-300">Check eligible offers as they become available.</span></span><a href="/services" className="rounded-full bg-emerald-600 px-4 py-2.5 text-xs font-extrabold text-white">Explore</a></div></section>

        <a href="/services" className="flex items-center gap-3 rounded-[24px] bg-[var(--surface)] p-4 shadow-sm"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-600"><HandCoins size={25}/></span><span className="min-w-0 flex-1"><span className="block font-extrabold">Invite friends to Semizzy</span><span className="mt-1 block text-xs text-[var(--muted)]">Explore referral rewards and other services.</span></span><span className="rounded-full bg-emerald-600 px-4 py-2 text-xs font-extrabold text-white">GO</span></a>

        <article id="fund-wallet" className="rounded-[24px] border border-[var(--line)] bg-[var(--surface)] p-5">
          <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><CreditCard size={20}/></span><div><h2 className="font-display text-lg font-extrabold">Add money to wallet</h2><p className="text-xs text-[var(--muted)]">Secure provider checkout</p></div></div>
          <form onSubmit={startFunding} className="mt-4"><label htmlFor="fund-amount" className="text-xs font-bold text-[var(--muted)]">Amount in naira (₦)</label><div className="mt-2 flex gap-2"><input id="fund-amount" type="number" min="1" max="5000000" step="0.01" inputMode="decimal" required value={amount} onChange={(event) => setAmount(event.target.value)} className="min-w-0 flex-1 rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 text-sm outline-none focus:border-[var(--brand)]" /><button type="submit" disabled={funding} className="min-h-11 rounded-xl bg-[var(--brand)] px-4 text-sm font-bold text-white disabled:opacity-60">{funding ? 'Starting…' : 'Continue'}</button></div><p className="mt-2 text-xs text-[var(--muted)]">Wallet credits only after payment verification.</p></form>
          {error && <p role="alert" className="mt-3 rounded-xl bg-rose-500/10 p-3 text-xs text-rose-700">{error}</p>}{notice && <p role="status" className="mt-3 text-xs text-emerald-700">{notice}</p>}
        </article>
      </section>
      <section id="transactions" className="mt-8 grid gap-5 xl:grid-cols-2">
        <div className="overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
          <div className="border-b border-[var(--line)] p-5">
            <div className="flex items-center justify-between gap-3"><div><h2 className="font-display text-lg font-extrabold">Wallet transaction history</h2><p className="mt-1 text-xs text-[var(--muted)]">Search and filter your wallet transactions</p></div><WalletCards size={20} className="shrink-0 text-[var(--brand)]" aria-hidden="true" /></div>
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
            ? <EmptyState message={transactionFilters.search || transactionFilters.status !== 'all' ? 'No transactions match these filters. Clear the search or choose all statuses to see more.' : 'No wallet transactions yet. Your activity will appear here when you use a supported wallet service.'} />
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

function EmptyState({ message }: { message: string }) {
  return <div className="px-5 py-10 text-center"><span className="mx-auto grid h-10 w-10 place-items-center rounded-xl bg-[var(--page)] text-[var(--muted)]"><WalletCards size={20} aria-hidden="true" /></span><p className="mx-auto mt-3 max-w-sm text-sm leading-6 text-[var(--muted)]">{message}</p></div>;
}
