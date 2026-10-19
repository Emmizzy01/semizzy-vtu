import { Head } from '@inertiajs/react';
import { useState } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';

type Referral = { id: number; name: string; joined_at: string | null; status: string; reward_minor: number };
type Props = {
  enabled: boolean;
  code: string;
  link: string;
  rewardNaira: number;
  qualifyingEvent: string;
  stats: { total: number; pending: number; qualified: number; rewarded: number };
  referrals: Referral[];
};

export default function Referrals(props: Props) {
  const [copied, setCopied] = useState(false);
  async function copyLink() {
    try {
      await navigator.clipboard.writeText(props.link);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  }
  const naira = (minor: number) => '₦' + (minor / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 });
  return <DashboardLayout title="Refer & Earn" description="Invite friends to Semizzy. Rewards are credited only after the configured qualifying event is reviewed.">
    <Head title="Refer & Earn · Semizzy" />
    <div className="mx-auto max-w-5xl space-y-6">
      {!props.enabled && <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">The referral programme is currently paused. Your existing referral history remains visible.</div>}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7">
        <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Your invite code</p>
        <h1 className="mt-2 text-3xl font-black tracking-[0.12em]">{props.code}</h1>
        <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">Share your link with friends. Each new account can be attributed once, and self-referrals are not accepted.</p>
        <div className="mt-5 flex flex-col gap-3 sm:flex-row">
          <input readOnly value={props.link} aria-label="Your referral link" className="min-w-0 flex-1 rounded-xl border border-slate-300 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          <button type="button" onClick={copyLink} className="rounded-xl bg-emerald-700 px-5 py-3 font-bold text-white">{copied ? 'Copied' : 'Copy invite link'}</button>
        </div>
        <p className="mt-4 text-sm text-slate-500">Configured reward: <strong>{naira(Math.round(props.rewardNaira * 100))}</strong> per approved referral. Reward status: <strong>pending review until the qualifying event is verified.</strong></p>
      </section>
      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {[['Invited', props.stats.total], ['Pending review', props.stats.pending], ['Qualified', props.stats.qualified], ['Rewards paid', props.stats.rewarded]].map(([label, value]) => <div key={String(label)} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-2xl font-black">{value}</p></div>)}
      </section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <h2 className="text-lg font-extrabold">Referral history</h2>
        {props.referrals.length === 0 ? <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800">No sign-ups attributed to your link yet.</p> : <div className="mt-4 divide-y divide-slate-200 dark:divide-slate-700">{props.referrals.map(item => <div key={item.id} className="flex flex-wrap items-center justify-between gap-3 py-4"><div><p className="font-bold">{item.name}</p><p className="mt-1 text-xs text-slate-500">{item.joined_at ? new Date(item.joined_at).toLocaleDateString() : ''}</p></div><div className="text-right"><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold dark:bg-slate-800">{item.status.replaceAll('_', ' ')}</span><p className="mt-2 text-sm font-semibold">{naira(item.reward_minor)}</p></div></div>)}</div>}
      </section>
    </div>
  </DashboardLayout>;
}
