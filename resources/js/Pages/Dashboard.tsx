import { useState } from 'react';
import { ArrowDownToLine, ArrowUpRight, ArrowLeftRight, CheckCircle2, Eye, EyeOff, GraduationCap, ShieldCheck, Smartphone, Tv, Wifi, Zap, Sparkles, WalletCards, Gift, ChevronRight, HandCoins, PlaySquare, Droplets, Dice5, KeyRound, MessageCircle, Plane, MoreHorizontal } from 'lucide-react';
import DashboardLayout from '../Components/DashboardLayout';

type Wallet = { balance_minor: number; available_balance_minor: number; currency: string; status: string } | null;
type Transaction = { id: string; type: string; status: string; currency: string; amount_minor: number; description: string | null; reference: string | null; created_at: string | null };
type ServiceOrder = { id: string; service_type: string; network: string; recipient: string; amount_minor: number; currency: string; status: string; created_at: string | null };
type Pagination = { currentPage: number; lastPage: number; total: number };

function money(minor: number, currency = 'NGN') {
  return new Intl.NumberFormat('en-NG', { style: 'currency', currency, maximumFractionDigits: 2 }).format(minor / 100);
}

export default function CustomerDashboard({ wallet, transactions, orders = [], transactionPagination, orderPagination, isAdminPreview = false }: {
  wallet: Wallet; transactions: Transaction[]; orders?: ServiceOrder[]; transactionPagination?: Pagination; orderPagination?: Pagination; isAdminPreview?: boolean; customer?: { name?: string; type?: string; tier?: string | null; level?: string; avatarUrl?: string | null; kycStatus?: string };
}) {
  const [balanceVisible, setBalanceVisible] = useState(true);
  return (
    <DashboardLayout title={isAdminPreview ? 'Customer dashboard preview' : 'Your dashboard'} description={isAdminPreview ? 'Preview the customer experience for your own staff account. Customer data and permissions are unchanged.' : 'Your wallet, digital services, and recent activity in one secure place.'} customerPreview={isAdminPreview}>
      {isAdminPreview && <aside className="mb-5 flex flex-col gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-extrabold">Staff preview mode</p><p className="mt-1 text-xs leading-5 text-[var(--muted)]">This is your own account’s customer view, not a customer impersonation session.</p></div><a href="/admin/dashboard" className="inline-flex min-h-10 items-center justify-center rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2">Back to Command Center</a></aside>}
      <section className="mx-auto flex w-full max-w-[1400px] flex-col gap-4 pb-3 lg:gap-5">

        <article className="relative w-full overflow-hidden rounded-[20px] bg-gradient-to-br from-[#0e7a44] to-[#0a5c32] text-white shadow-sm">
          <div className="relative z-10 p-5 sm:p-6">
            <div className="flex items-center justify-between gap-3"><span className="inline-flex items-center gap-2 text-xs font-bold text-white/90"><ShieldCheck size={17}/> {wallet?.status === 'active' ? 'Available' : wallet ? 'Wallet ' + wallet.status : 'Wallet not set up'} <span className="text-white/70">●</span></span><a href="/wallet/manual-deposits" className="inline-flex min-h-10 items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 text-xs font-extrabold hover:bg-white/20"><ArrowDownToLine size={16}/> Add Money</a></div>
            <div className="mt-3 flex items-center gap-2"><p className="text-3xl font-black tracking-tight">{wallet ? (balanceVisible ? money(wallet.available_balance_minor, wallet.currency) : '••••••') : '—'}</p>{wallet && <button type="button" onClick={() => setBalanceVisible(v=>!v)} aria-label={balanceVisible ? 'Hide balance' : 'Show balance'} className="grid h-8 w-8 place-items-center rounded-full hover:bg-white/10">{balanceVisible ? <EyeOff size={17}/> : <Eye size={17}/>}</button>}<a href="#activity" aria-label="View wallet activity"><ChevronRight size={23}/></a></div>
            <p className="mt-5 text-xs text-white/75">Wallet balance</p>
            {wallet && <p className="mt-1 text-[10px] text-white/75">Ledger balance: {balanceVisible ? money(wallet.balance_minor,wallet.currency) : '••••••'}</p>}
          </div>
          <div aria-hidden="true" className="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/10" /><div aria-hidden="true" className="pointer-events-none absolute -bottom-16 right-20 h-32 w-32 rounded-full bg-white/10" /><a href="#activity" className="relative z-10 flex items-center justify-between gap-3 bg-white/90 px-5 py-3 text-xs text-slate-800 dark:bg-slate-950/90 dark:text-slate-100"><span className="flex min-w-0 items-center gap-2"><CheckCircle2 size={18} className="shrink-0 text-emerald-600"/><span className="truncate">{transactions[0] ? (transactions[0].description || transactions[0].type.replaceAll('_',' ')) + ' ' + money(transactions[0].amount_minor,transactions[0].currency) : 'Your latest wallet activity will appear here'}</span></span><span className="flex shrink-0 items-center gap-1 font-bold">Wallet <ChevronRight size={16}/></span></a>
        </article>

        <div className="grid grid-cols-4 gap-3 rounded-[16px] bg-transparent p-0 lg:grid-cols-4 lg:gap-3">
          {[{title:'Airtime',icon:<Smartphone size={24}/>,href:'/wallet/vtu/airtime'},{title:'Data',icon:<Wifi size={24}/>,href:'/wallet/vtu/data'},{title:'Bills',icon:<Zap size={24}/>,href:'/services'},{title:'TV',icon:<Tv size={24}/>,href:'/wallet/vtu/tv'}].map(item=><a key={item.title} href={item.href} className="flex min-h-[88px] min-w-0 flex-col items-center justify-center gap-2 rounded-2xl px-1 py-2 text-center transition hover:bg-[var(--page)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"><span className="grid h-14 w-14 place-items-center rounded-[20px] bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">{item.icon}</span><span className="text-[11px] font-bold">{item.title}</span></a>)}
        </div>

        <section aria-labelledby="dashboard-promo-heading" className="relative mt-1 flex min-h-[96px] items-center justify-between gap-3 overflow-hidden rounded-[16px] bg-gradient-to-r from-[#0a5c32] to-[#12a15a] p-4 text-white shadow-sm">
          <div className="relative z-10 min-w-0">
            <h2 id="dashboard-promo-heading" className="text-sm font-extrabold leading-5">Enjoy 10% cashback on data purchases</h2>
            <p className="mt-1 text-[11px] text-white/80">Limited time offer</p>
          </div>
          <span aria-hidden="true" className="relative z-10 grid h-16 w-16 shrink-0 place-items-center rounded-full bg-white/20 text-3xl">👩‍💼</span>
          <span aria-hidden="true" className="pointer-events-none absolute -right-6 -top-8 h-28 w-28 rounded-full bg-white/10"/>
        </section>

        <section aria-labelledby="popular-data-heading" className="rounded-[16px] border bg-white p-4 shadow-sm">
          <div className="mb-4 flex items-center justify-between gap-3">
            <div><h2 id="popular-data-heading" className="font-extrabold">Buy Data</h2><p className="mt-1 text-xs text-[var(--muted)]">Choose your network to view available bundles and validity.</p></div>
            <Wifi size={22} className="shrink-0 text-[var(--brand)]" aria-hidden="true"/>
          </div>
          <div className="grid grid-cols-4 gap-2">
            {[{name:'MTN',tone:'bg-amber-100 text-amber-800',href:'/wallet/vtu/data?network=mtn'},{name:'Airtel',tone:'bg-rose-100 text-rose-800',href:'/wallet/vtu/data?network=airtel'},{name:'Glo',tone:'bg-green-100 text-green-800',href:'/wallet/vtu/data?network=glo'},{name:'9mobile',tone:'bg-lime-100 text-lime-800',href:'/wallet/vtu/data?network=9mobile'}].map(plan=><a key={plan.name} href={plan.href} className="group flex min-w-0 flex-col items-center rounded-xl border border-[var(--line)] px-1.5 py-3 text-center transition hover:border-emerald-500 hover:bg-[var(--page)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"><span className={'grid h-8 w-8 place-items-center rounded-full text-xs font-black '+plan.tone}>{plan.name.slice(0,1)}</span><span className="mt-2 text-[11px] font-extrabold">{plan.name}</span><span className="mt-1 text-[9px] leading-3 text-[var(--muted)]">View plans</span></a>)}
          </div>
          <a href="/wallet/vtu/data" className="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-xl bg-[var(--page)] px-4 py-2 text-xs font-extrabold text-[var(--brand)] hover:opacity-80">Browse all data plans <ArrowUpRight size={15}/></a>
          <p className="mt-3 text-[10px] leading-4 text-[var(--muted)]">Plan prices, data sizes and validity are shown on the live catalogue. No sample prices are displayed here.</p>
        </section>

        <section className="rounded-[16px] border bg-white p-4 shadow-sm" aria-labelledby="dashboard-services-heading">
          <div className="mb-4 flex items-center justify-between gap-2"><div><h2 id="dashboard-services-heading" className="font-extrabold">Popular Services</h2><p className="mt-1 text-[10px] text-[var(--muted)]">Quick access to Semizzy services</p></div><a href="/services" className="inline-flex items-center gap-1 text-xs font-extrabold text-[var(--brand)]">See all <ChevronRight size={16}/></a></div>
          <div className="grid grid-cols-4 gap-3" aria-label="Popular services">
            {[{title:'Electricity',href:'/wallet/vtu/electricity',icon:<Zap size={23}/>},{title:'Cable TV',href:'/wallet/vtu/tv',icon:<PlaySquare size={23}/>},{title:'Internet',href:'/services',icon:<Wifi size={23}/>},{title:'Water Bill',href:'/services',icon:<Droplets size={23}/>},{title:'Betting',href:'/services',icon:<Dice5 size={23}/>},{title:'Education',href:'/services',icon:<GraduationCap size={23}/>},{title:'Airtime to Cash',href:'/services',icon:<ArrowLeftRight size={23}/>},{title:'E-PIN',href:'/services',icon:<KeyRound size={23}/>},{title:'Bulk SMS',href:'/services',icon:<MessageCircle size={23}/>},{title:'Gift Cards',href:'/services',icon:<Gift size={23}/>},{title:'Flight',href:'/services',icon:<Plane size={23}/>},{title:'More',href:'/services',icon:<MoreHorizontal size={23}/> }].map(service=><a key={service.title} href={service.href} className="flex min-h-[84px] min-w-0 flex-col items-center justify-center gap-2 rounded-xl border bg-white p-3 text-center shadow-sm transition hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"><span className="grid h-10 w-10 place-items-center rounded-full bg-[#0f8a4f]/10 text-[#0f8a4f]">{service.icon}</span><span className="text-[10px] font-medium leading-tight text-gray-600">{service.title}</span></a>)}
          </div>
        </section>

        <section aria-label="Gadget sale promotion" className="mt-1 flex items-center justify-between gap-3 rounded-[16px] bg-gradient-to-r from-[#0f8a4f] to-[#0a5c32] p-4 text-white shadow-sm">
          <div className="min-w-0"><p className="text-[13px] font-extrabold">🔥 Big Gadget Sale! Up to 50% OFF</p><p className="mt-1 text-[11px] text-white/80">Browse available gadgets and offers</p></div>
          <a href="/services" className="inline-flex min-h-9 shrink-0 items-center justify-center rounded-full bg-white px-4 py-2 text-xs font-extrabold text-[#0a5c32] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">Shop Now</a>
        </section>

        <section aria-labelledby="dashboard-gadgets-heading" className="mt-1 rounded-[16px] border bg-white p-4 shadow-sm">
          <div className="mb-3 flex items-center justify-between gap-3"><h2 id="dashboard-gadgets-heading" className="font-extrabold">Shop Gadgets</h2><a href="/services" className="text-xs font-extrabold text-[#0f8a4f]">See All <ChevronRight size={14} className="inline" aria-hidden="true"/></a></div>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            {[{title:'Phones',icon:<Smartphone size={27}/>,tone:'bg-slate-100'},{title:'Power banks',icon:<Zap size={27}/>,tone:'bg-slate-100'},{title:'Headphones',icon:<PlaySquare size={27}/>,tone:'bg-slate-100'},{title:'Laptops',icon:<Landmark size={27}/>,tone:'bg-slate-100'},{title:'Computers',icon:<Landmark size={27}/>,tone:'bg-slate-100'},{title:'Accessories',icon:<SmartphoneNfc size={27}/>,tone:'bg-slate-100'},{title:'Smart watches',icon:<Activity size={27}/>,tone:'bg-slate-100'},{title:'Audio',icon:<PlaySquare size={27}/>,tone:'bg-slate-100'}].map(item=><a key={item.title} href="/services" className="min-w-0 rounded-xl border p-3 shadow-sm transition hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]">
              <span className={'grid h-[80px] place-items-center rounded-lg text-[var(--brand)] '+item.tone}>{item.icon}</span>
              <span className="mt-2 block text-[11px] font-extrabold">{item.title}</span>
              <span className="mt-1 block text-[10px] text-[var(--muted)]">Browse catalogue</span>
            </a>)}
          </div>
          <p className="mt-3 text-[10px] leading-4 text-[var(--muted)]">Gadget listings and verified prices will appear when the live catalogue is connected.</p>
        </section>

        <section aria-label="Referral promotion" className="mt-1 flex items-center justify-between gap-3 rounded-[16px] border border-amber-200 bg-[#fef3c7] p-4">
          <div className="min-w-0"><p className="text-[13px] font-extrabold text-[#92400e]">💰 Refer &amp; Earn with Semizzy</p><p className="mt-1 text-[11px] text-[#a16207]">Invite friends to discover Semizzy</p></div>
          <a href="/account/tiers" className="inline-flex min-h-9 shrink-0 items-center justify-center rounded-full bg-[#111827] px-4 py-2 text-xs font-extrabold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-700">Refer Now</a>
        </section>

        <section id="activity" className="scroll-mt-6 space-y-4 rounded-[26px] bg-[var(--surface)] p-4 shadow-sm lg:col-span-7" aria-labelledby="recent-transactions-heading">
          <div className="flex items-center justify-between gap-3"><div><h2 id="recent-transactions-heading" className="font-extrabold">Recent transactions</h2><p className="mt-1 text-xs text-[var(--muted)]">{transactionPagination?.total ?? transactions.length} wallet entries</p></div><WalletCards size={21} className="text-[var(--brand)]" aria-hidden="true" /></div>
          {transactions.length === 0 ? <p className="rounded-xl bg-[var(--page)] p-4 text-sm text-[var(--muted)]">No wallet transactions yet. Your funding and purchase activity will appear here.</p> : <div className="divide-y divide-[var(--line)]">{transactions.map(transaction => <article key={transaction.id} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0"><span className="mt-1 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-[var(--page)] text-[var(--brand)]">{transaction.type === 'credit' ? <ArrowDownToLine size={17}/> : <ArrowUpRight size={17}/>}</span><div className="min-w-0 flex-1"><p className="break-words text-sm font-bold">{transaction.description || transaction.type.replaceAll('_', ' ')}</p><p className="mt-1 break-all text-[10px] text-[var(--muted)]">{transaction.reference || transaction.id}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{transaction.created_at ? new Date(transaction.created_at).toLocaleString('en-NG') : 'Date unavailable'} · {transaction.status.replaceAll('_', ' ')}</p></div><p className={'shrink-0 text-sm font-extrabold tabular-nums ' + (transaction.type === 'credit' ? 'text-emerald-600' : 'text-[var(--ink)]')}>{transaction.type === 'credit' ? '+' : '−'}{money(transaction.amount_minor, transaction.currency)}</p></article>)}</div>}
          {transactionPagination && transactionPagination.lastPage > 1 && <div className="flex items-center justify-between border-t border-[var(--line)] pt-3 text-xs"><span className="text-[var(--muted)]">Page {transactionPagination.currentPage} of {transactionPagination.lastPage}</span><div className="flex gap-2">{transactionPagination.currentPage > 1 && <a className="rounded-lg border border-[var(--line)] px-3 py-2 font-bold" href={'/dashboard?transactions_page=' + (transactionPagination.currentPage - 1)}>Previous</a>}{transactionPagination.currentPage < transactionPagination.lastPage && <a className="rounded-lg border border-[var(--line)] px-3 py-2 font-bold" href={'/dashboard?transactions_page=' + (transactionPagination.currentPage + 1)}>Next</a>}</div></div>}
        </section>

        <section id="orders" className="scroll-mt-6 space-y-4 rounded-[26px] bg-[var(--surface)] p-4 shadow-sm lg:col-span-7" aria-labelledby="recent-orders-heading">
          <div className="flex items-center justify-between gap-3"><div><h2 id="recent-orders-heading" className="font-extrabold">Service orders</h2><p className="mt-1 text-xs text-[var(--muted)]">{orderPagination?.total ?? orders.length} orders</p></div><Smartphone size={21} className="text-[var(--brand)]" aria-hidden="true" /></div>
          {orders.length === 0 ? <p className="rounded-xl bg-[var(--page)] p-4 text-sm text-[var(--muted)]">No service orders yet. Your purchases and their statuses will appear here.</p> : <div className="divide-y divide-[var(--line)]">{orders.map(order => <article key={order.id} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0"><span className="mt-1 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-[var(--page)] text-[var(--brand)]"><Smartphone size={17}/></span><div className="min-w-0 flex-1"><p className="break-words text-sm font-bold">{order.service_type.replaceAll('-', ' ').replaceAll('_', ' ')} · {order.network}</p><p className="mt-1 break-all text-[10px] text-[var(--muted)]">Recipient: {order.recipient} · Ref: {order.id}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{order.created_at ? new Date(order.created_at).toLocaleString('en-NG') : 'Date unavailable'} · {order.status.replaceAll('_', ' ')}</p></div><p className="shrink-0 text-sm font-extrabold tabular-nums">{money(order.amount_minor, order.currency)}</p></article>)}</div>}
          {orderPagination && orderPagination.lastPage > 1 && <div className="flex items-center justify-between border-t border-[var(--line)] pt-3 text-xs"><span className="text-[var(--muted)]">Page {orderPagination.currentPage} of {orderPagination.lastPage}</span><div className="flex gap-2">{orderPagination.currentPage > 1 && <a className="rounded-lg border border-[var(--line)] px-3 py-2 font-bold" href={'/dashboard?orders_page=' + (orderPagination.currentPage - 1)}>Previous</a>}{orderPagination.currentPage < orderPagination.lastPage && <a className="rounded-lg border border-[var(--line)] px-3 py-2 font-bold" href={'/dashboard?orders_page=' + (orderPagination.currentPage + 1)}>Next</a>}</div></div>}
        </section>

        <section id="rewards" className="scroll-mt-6 rounded-[26px] bg-gradient-to-r from-emerald-50 to-amber-50 p-5 text-slate-800 dark:from-emerald-950 dark:to-slate-900 dark:text-white"><div className="flex items-center justify-between"><div><h2 className="font-extrabold">Complete Tasks, Earn Rewards</h2><p className="mt-1 text-xs text-slate-600 dark:text-slate-300">Discover available Semizzy benefits</p></div><Gift size={26} className="text-emerald-600"/></div><div className="mt-4 flex items-center gap-3 border-t border-dashed border-emerald-700/20 pt-4"><span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-white text-emerald-600"><Sparkles size={25}/></span><span className="min-w-0 flex-1"><span className="block text-sm font-extrabold">Rewards & promotions</span><span className="mt-1 block text-[10px] text-slate-600 dark:text-slate-300">Check eligible offers as they become available.</span></span><a href="/account/tiers" className="rounded-full bg-emerald-600 px-4 py-2.5 text-xs font-extrabold text-white">View tiers</a></div></section>

        <a href="#rewards" className="flex items-center gap-3 rounded-[24px] bg-[var(--surface)] p-4 shadow-sm lg:col-span-5"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-600"><HandCoins size={25}/></span><span className="min-w-0 flex-1"><span className="block font-extrabold">Invite friends to Semizzy</span><span className="mt-1 block text-xs text-[var(--muted)]">View the rewards and benefits section.</span></span><span className="rounded-full bg-emerald-600 px-4 py-2 text-xs font-extrabold text-white">GO</span></a>
      </section>

    </DashboardLayout>
  );
}

