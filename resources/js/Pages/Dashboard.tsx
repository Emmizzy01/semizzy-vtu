import { useState } from 'react';
import { ArrowDownToLine, ArrowUpRight, BookOpen, CheckCircle2, Eye, EyeOff, GraduationCap, ShieldCheck, Smartphone, Tv, Wifi, Zap, Megaphone, Sparkles, WalletCards, Headset, Landmark, CircleHelp, Gift, ChevronRight, CircleAlert, HandCoins, PlaySquare, SmartphoneNfc } from 'lucide-react';
import DashboardLayout from '../Components/DashboardLayout';

type Wallet = { balance_minor: number; available_balance_minor: number; currency: string; status: string } | null;
type Transaction = { id: string; type: string; status: string; currency: string; amount_minor: number; description: string | null; reference: string | null; created_at: string | null };

function money(minor: number, currency = 'NGN') {
  return new Intl.NumberFormat('en-NG', { style: 'currency', currency, maximumFractionDigits: 2 }).format(minor / 100);
}
export default function CustomerDashboard({ wallet, transactions, isAdminPreview = false, customer }: {
  wallet: Wallet; transactions: Transaction[]; isAdminPreview?: boolean; customer?: { name?: string; tier?: string; level?: string; avatarUrl?: string | null; kycStatus?: string };
}) {
  const [balanceVisible, setBalanceVisible] = useState(true);
  return (
    <DashboardLayout title={isAdminPreview ? 'Customer dashboard preview' : 'Your dashboard'} description={isAdminPreview ? 'Preview the customer experience for your own staff account. Customer data and permissions are unchanged.' : 'Your wallet, digital services, and recent activity in one secure place.'} customerPreview={isAdminPreview}>
      {isAdminPreview && <aside className="mb-5 flex flex-col gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-extrabold">Staff preview mode</p><p className="mt-1 text-xs leading-5 text-[var(--muted)]">This is your own account’s customer view, not a customer impersonation session.</p></div><a href="/admin/dashboard" className="inline-flex min-h-10 items-center justify-center rounded-xl bg-[var(--brand)] px-4 py-2 text-sm font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2">Back to Command Center</a></aside>}
      <section className="mx-auto max-w-3xl space-y-4 pb-3">
        <header className="flex items-center gap-3 rounded-[24px] bg-[var(--surface)] p-4 shadow-sm">
          {customer?.avatarUrl
            ? <img src={customer.avatarUrl} alt="" className="h-14 w-14 shrink-0 rounded-full object-cover" />
            : <span aria-hidden="true" className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-emerald-100 text-lg font-black text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{(customer?.name || 'S').trim().split(/\\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase()}</span>}
          <div className="min-w-0 flex-1">
            <p className="text-xs font-semibold text-[var(--muted)]">Welcome back</p>
            <h1 className="truncate text-lg font-extrabold">{customer?.name || 'Semizzy user'}</h1>
            <div className="mt-1 flex flex-wrap gap-2">
              {customer?.tier && <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Tier: {customer.tier}</span>}
              {customer?.level && <span className="rounded-full border border-[var(--line)] px-2.5 py-1 text-[10px] font-bold text-[var(--muted)]">Level: {customer.level}</span>}
            </div>
          </div>
          <a href="/profile" className="shrink-0 rounded-xl border border-[var(--line)] px-3 py-2 text-xs font-bold">Profile</a>
        </header>
        <article className="overflow-hidden rounded-[28px] bg-emerald-600 text-white shadow-sm">
          <div className="p-5 sm:p-6">
            <div className="flex items-center justify-between gap-3"><span className="inline-flex items-center gap-2 text-xs font-bold text-white/90"><ShieldCheck size={17}/> {wallet?.status === 'active' ? 'Available' : wallet ? 'Wallet ' + wallet.status : 'Wallet not set up'} <span className="text-white/70">●</span></span><a href="/wallet/manual-deposits" className="inline-flex min-h-10 items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 text-xs font-extrabold hover:bg-white/20"><ArrowDownToLine size={16}/> Add Money</a></div>
            <div className="mt-3 flex items-center gap-2"><p className="text-3xl font-black tracking-tight">{wallet ? (balanceVisible ? money(wallet.available_balance_minor, wallet.currency) : '••••••') : '—'}</p>{wallet && <button type="button" onClick={() => setBalanceVisible(v=>!v)} aria-label={balanceVisible ? 'Hide balance' : 'Show balance'} className="grid h-8 w-8 place-items-center rounded-full hover:bg-white/10">{balanceVisible ? <EyeOff size={17}/> : <Eye size={17}/>}</button>}<a href="/wallet/manual-deposits" aria-label="View wallet activity"><ChevronRight size={23}/></a></div>
            <p className="mt-5 text-xs text-white/75">Wallet balance</p>
            {wallet && <p className="mt-1 text-[10px] text-white/75">Ledger balance: {balanceVisible ? money(wallet.balance_minor,wallet.currency) : '••••••'}</p>}
          </div>
          <a href="/wallet/manual-deposits" className="flex items-center justify-between gap-3 bg-white/90 px-5 py-3 text-xs text-slate-800 dark:bg-slate-950/90 dark:text-slate-100"><span className="flex min-w-0 items-center gap-2"><CheckCircle2 size={18} className="shrink-0 text-emerald-600"/><span className="truncate">{transactions[0] ? (transactions[0].description || transactions[0].type.replaceAll('_',' ')) + ' ' + money(transactions[0].amount_minor,transactions[0].currency) : 'Your latest wallet activity will appear here'}</span></span><span className="flex shrink-0 items-center gap-1 font-bold">Wallet <ChevronRight size={16}/></span></a>
        </article>

        <div className="grid grid-cols-4 gap-2 rounded-[26px] bg-[var(--surface)] p-4 shadow-sm">
          {[{title:'Airtime',icon:<Smartphone size={24}/>,href:'/wallet/vtu/airtime'},{title:'Deposit',icon:<WalletCards size={24}/>,href:'/wallet/manual-deposits'},{title:'Services',icon:<Zap size={24}/>,href:'/services'},{title:'Support',icon:<Headset size={24}/>,href:'/support'}].map(item=><a key={item.title} href={item.href} className="flex min-w-0 flex-col items-center gap-2 rounded-xl py-1 text-center"><span className="grid h-14 w-14 place-items-center rounded-[20px] bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">{item.icon}</span><span className="text-[11px] font-bold">{item.title}</span></a>)}
        </div>

        {customer?.kycStatus && !['verified','approved','complete','completed'].includes(customer.kycStatus.toLowerCase()) && <a href="/profile" className="flex items-center gap-3 rounded-[24px] bg-[var(--surface)] p-4 shadow-sm"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-600"><CircleAlert size={28}/></span><span className="min-w-0 flex-1"><span className="block font-extrabold">KYC verification needed</span><span className="mt-1 block text-xs text-[var(--muted)]">Complete your identity checks to access eligible account limits.</span></span><ChevronRight size={19}/></a>}

        <section className="rounded-[26px] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="dashboard-services-heading">
          <div className="mb-4 flex items-center justify-between gap-2"><div><h2 id="dashboard-services-heading" className="font-extrabold">Services</h2><p className="mt-1 text-[10px] text-[var(--muted)]">Everything you need, in one place</p></div><a href="/services" className="inline-flex items-center gap-1 text-xs font-extrabold text-[var(--brand)]">More <ChevronRight size={16}/></a></div>
          <div className="grid grid-cols-4 gap-x-2 gap-y-4 sm:grid-cols-4 lg:grid-cols-6" aria-label="Popular services">
            {[{title:'Airtime',href:'/wallet/vtu/airtime',icon:<Smartphone size={23}/>},{title:'Data',href:'/wallet/vtu/data',icon:<Wifi size={23}/>},{title:'Electricity',href:'/wallet/vtu/electricity',icon:<Zap size={23}/>},{title:'TV',href:'/wallet/vtu/tv',icon:<PlaySquare size={23}/>},{title:'Education',href:'/services',icon:<GraduationCap size={23}/>},{title:'Government',href:'/services',icon:<Landmark size={23}/>},{title:'Streaming',href:'/services',icon:<Tv size={23}/>},{title:'Social Media',href:'/services',icon:<Megaphone size={23}/>},{title:'Manual Deposit',href:'/wallet/manual-deposits',icon:<WalletCards size={23}/>},{title:'SMM',href:'/services',icon:<Megaphone size={23}/>},{title:'Security',href:'/security/transaction-pin',icon:<ShieldCheck size={23}/>},{title:'Support',href:'/support',icon:<CircleHelp size={23}/>},{title:'Exam PINs',href:'/services',icon:<BookOpen size={23}/>},{title:'Betting',href:'/services',icon:<SmartphoneNfc size={23}/>},{title:'Number Services',href:'/services',icon:<SmartphoneNfc size={23}/>},{title:'More Services',href:'/services',icon:<ArrowUpRight size={23}/> }].map(service=><a key={service.title} href={service.href} className="flex min-w-0 flex-col items-center gap-2 text-center"><span className="grid h-12 w-12 place-items-center rounded-[19px] bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">{service.icon}</span><span className="text-[10px] font-bold leading-tight sm:text-xs">{service.title}</span></a>)}
          </div>
        </section>

        <section id="rewards" className="scroll-mt-6 rounded-[26px] bg-gradient-to-r from-emerald-50 to-amber-50 p-5 text-slate-800 dark:from-emerald-950 dark:to-slate-900 dark:text-white"><div className="flex items-center justify-between"><div><h2 className="font-extrabold">Complete Tasks, Earn Rewards</h2><p className="mt-1 text-xs text-slate-600 dark:text-slate-300">Discover available Semizzy benefits</p></div><Gift size={26} className="text-emerald-600"/></div><div className="mt-4 flex items-center gap-3 border-t border-dashed border-emerald-700/20 pt-4"><span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-white text-emerald-600"><Sparkles size={25}/></span><span className="min-w-0 flex-1"><span className="block text-sm font-extrabold">Rewards & promotions</span><span className="mt-1 block text-[10px] text-slate-600 dark:text-slate-300">Check eligible offers as they become available.</span></span><a href="/account/tiers" className="rounded-full bg-emerald-600 px-4 py-2.5 text-xs font-extrabold text-white">View tiers</a></div></section>

        <a href="#rewards" className="flex items-center gap-3 rounded-[24px] bg-[var(--surface)] p-4 shadow-sm"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-600"><HandCoins size={25}/></span><span className="min-w-0 flex-1"><span className="block font-extrabold">Invite friends to Semizzy</span><span className="mt-1 block text-xs text-[var(--muted)]">View the rewards and benefits section.</span></span><span className="rounded-full bg-emerald-600 px-4 py-2 text-xs font-extrabold text-white">GO</span></a>
      </section>

    </DashboardLayout>
  );
}

