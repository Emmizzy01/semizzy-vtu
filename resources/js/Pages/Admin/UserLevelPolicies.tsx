import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { AlertTriangle, Save, ShieldCheck } from 'lucide-react';

type Policy = { level: string; label: string; wallet_balance_limit_minor: number | null; max_single_transaction_minor: number | null; daily_transaction_limit_minor: number | null; requires_verified_kyc: boolean; is_active: boolean };
const nairaInput = (value: number | null) => value === null ? '' : String(value / 100);
const minorInput = (value: string) => value.trim() === '' ? null : Math.round(Number(value) * 100);
export default function UserLevelPolicies({ policies }: { policies: Policy[] }) {
  const [values, setValues] = useState<Record<string, { wallet: string; max: string; daily: string; kyc: boolean; active: boolean; reason: string }>>(() => Object.fromEntries(policies.map(p => [p.level, { wallet: nairaInput(p.wallet_balance_limit_minor), max: nairaInput(p.max_single_transaction_minor), daily: nairaInput(p.daily_transaction_limit_minor), kyc: p.requires_verified_kyc, active: p.is_active, reason: '' }])));
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const change = (level: string, key: 'wallet'|'max'|'daily'|'kyc'|'active'|'reason', value: string|boolean) => setValues(old => ({ ...old, [level]: { ...old[level], [key]: value } }));
  const save = (level: string) => {
    const v = values[level];
    if (v.reason.trim().length < 8) { setError('Each policy change requires a reason of at least 8 characters.'); return; }
    const wallet = minorInput(v.wallet), max = minorInput(v.max), daily = minorInput(v.daily);
    if ((v.wallet.trim() && (!Number.isFinite(Number(v.wallet)) || Number(v.wallet) <= 0)) || (v.max.trim() && (!Number.isFinite(Number(v.max)) || Number(v.max) <= 0)) || (v.daily.trim() && (!Number.isFinite(Number(v.daily)) || Number(v.daily) <= 0))) { setError('Enter positive NGN amounts or leave a limit blank.'); return; }
    if (max !== null && daily !== null && daily < max) { setError('Daily limit cannot be lower than the single-transaction limit.'); return; }
    setError(''); setBusy(level);
    router.put('/admin/user-levels/' + level, { wallet_balance_limit_minor: wallet, max_single_transaction_minor: max, daily_transaction_limit_minor: daily, requires_verified_kyc: v.kyc, is_active: v.active, reason: v.reason }, { preserveScroll: true, onFinish: () => setBusy('') });
  };
  return <DashboardLayout title="User level policies" description="Configure four account levels, KYC requirements and wallet-funded VTU transaction limits." admin>
    <Head title="User level policies · Semizzy" />
    <div className="mx-auto max-w-5xl space-y-5">
      <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"><div className="flex items-center gap-2 font-semibold"><AlertTriangle size={17}/>Policy behavior</div><p className="mt-1 text-slate-600 dark:text-slate-300">Blank amount limits preserve the existing platform limits. Configured limits are enforced server-side during the locked wallet transaction. Changing KYC status does not automatically change a user’s level or grant staff permissions.</p></div>
      {error && <div role="alert" className="rounded-xl bg-rose-500/10 p-3 text-sm text-rose-700">{error}</div>}
      {policies.map(policy => { const v=values[policy.level]; return <section key={policy.level} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><ShieldCheck size={21}/></span><div><h2 className="font-semibold">{policy.label}</h2><p className="text-xs text-slate-500">{policy.level}</p></div><span className={'ml-auto rounded-full px-3 py-1 text-xs font-semibold '+(v.active?'bg-emerald-500/10 text-emerald-700':'bg-slate-500/10 text-slate-500')}>{v.active?'Active':'Disabled'}</span></div>
        <div className="grid gap-4 sm:grid-cols-2"><label className="block text-sm font-medium">Maximum wallet balance (NGN)<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" inputMode="decimal" placeholder="Not configured" value={v.wallet} onChange={e=>change(policy.level,"wallet",e.target.value)}/></label><label className="block text-sm font-medium">Maximum per transaction (NGN)<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" inputMode="decimal" placeholder="Use platform limit" value={v.max} onChange={e=>change(policy.level,'max',e.target.value)}/></label><label className="block text-sm font-medium">Daily transaction limit (NGN)<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" inputMode="decimal" placeholder="No additional level cap" value={v.daily} onChange={e=>change(policy.level,'daily',e.target.value)}/></label></div>
        <label className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900"><span>Require verified KYC before VTU purchases</span><input type="checkbox" checked={v.kyc} onChange={e=>change(policy.level,'kyc',e.target.checked)}/></label>
        <label className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900"><span>Allow transactions for this level</span><input type="checkbox" checked={v.active} onChange={e=>change(policy.level,'active',e.target.checked)}/></label>
        <label className="block text-sm font-medium">Reason for policy change<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" rows={2} value={v.reason} onChange={e=>change(policy.level,'reason',e.target.value)} minLength={8} maxLength={500}/></label>
        <button disabled={busy===policy.level} onClick={()=>save(policy.level)} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 font-semibold text-white disabled:opacity-50"><Save size={16}/>{busy===policy.level?'Saving…':'Save policy'}</button>
      </section>; })}
    </div>
  </DashboardLayout>;
}
