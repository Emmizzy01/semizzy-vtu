import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';
import { Activity, ArrowUpRight, CreditCard, Search, Smartphone, Users, WalletCards } from 'lucide-react';

type Result = { title: string; subtitle: string; status: string; href?: string; amount_minor?: number; currency?: string; created_at: string | null };
type Results = { users: Result[]; orders: Result[]; manualDeposits: Result[]; walletTransactions: Result[]; funding: Result[] };
const money = (amount: number, currency = 'NGN') => new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(amount / 100);

export default function GlobalSearch({ query, results }: { query: string; results: Results }) {
  const [term, setTerm] = useState(query);
  const submit = (event: FormEvent) => { event.preventDefault(); if (term.trim().length >= 2) router.get('/admin/search', { q: term.trim() }, { preserveScroll: true }); };
  const groups = [
    { key: 'users', title: 'Users', icon: Users, items: results.users },
    { key: 'orders', title: 'VTU orders', icon: Smartphone, items: results.orders },
    { key: 'manualDeposits', title: 'Manual deposits', icon: WalletCards, items: results.manualDeposits },
    { key: 'walletTransactions', title: 'Wallet transactions', icon: Activity, items: results.walletTransactions },
    { key: 'funding', title: 'Payment intents', icon: CreditCard, items: results.funding },
  ] as const;
  const total = groups.reduce((sum, group) => sum + group.items.length, 0);
  return <DashboardLayout title="Global search" description="Search across customer identities and safe operational records. Provider payloads and secrets are never returned." admin>
    <Head title="Global search · Semizzy" />
    <div className="mx-auto max-w-6xl space-y-5">
      <form onSubmit={submit} className="flex gap-2 rounded-2xl border border-slate-200 p-3 dark:border-slate-800"><input className="min-w-0 flex-1 rounded-xl border bg-transparent p-3 dark:border-slate-700" value={term} onChange={e => setTerm(e.target.value)} minLength={2} maxLength={100} placeholder="Search name, email, reference, recipient or order ID" required/><button className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white"><Search size={17}/>Search</button></form>
      <p className="text-sm text-slate-500">{total} matching records in the first 5 results per category for “{query}”.</p>
      <div className="space-y-5">{groups.map(group => { const Icon = group.icon; return <section key={group.key} className="space-y-2"><h2 className="flex items-center gap-2 font-semibold"><Icon size={18}/>{group.title}<span className="text-xs font-normal text-slate-500">({group.items.length})</span></h2>{group.items.length === 0 ? <p className="rounded-xl border border-dashed p-4 text-sm text-slate-500 dark:border-slate-800">No matching {group.title.toLowerCase()}.</p> : <div className="divide-y divide-slate-200 rounded-2xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">{group.items.map((item,i) => <div key={group.key+'-'+i} className="flex flex-wrap items-center justify-between gap-3 p-4"><div className="min-w-0"><p className="break-all text-sm font-semibold">{item.title}</p><p className="mt-1 break-words text-xs text-slate-500">{item.subtitle}</p><p className="mt-1 text-xs text-slate-500">{item.created_at ? new Date(item.created_at).toLocaleString() : '—'}</p></div><div className="flex items-center gap-3"><span className="text-right"><span className="block text-xs font-semibold capitalize text-slate-500">{item.status.replaceAll('_',' ')}</span>{item.amount_minor !== undefined && <span className="mt-1 block text-sm font-semibold">{money(item.amount_minor,item.currency)}</span>}</span>{item.href && <a href={item.href} aria-label={'Open '+group.title+' search results'} className="rounded-lg border p-2 dark:border-slate-700"><ArrowUpRight size={16}/></a>}</div></div>)}</div>}</section>;})}</div>
    </div>
  </DashboardLayout>;
}
