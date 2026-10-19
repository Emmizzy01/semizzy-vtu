import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';

type Referral = { id: number; status: string; referrer_name: string | null; referrer_email: string | null; referred_name: string | null; referred_email: string | null; reward_minor: number; qualifying_reference: string | null; review_note: string | null; created_at: string | null };
type Props = { enabled: boolean; reward_minor: number; qualifying_event: string; stats: { pending: number; qualified: number; rewarded: number; rejected: number }; referrals: { data: Referral[]; current_page: number; last_page: number } };

export default function ReferralProgram(props: Props) {
  const settings = useForm({ enabled: props.enabled, reward_minor: String(props.reward_minor / 100), qualifying_event: props.qualifying_event, reason: '' });
  const [busyId, setBusyId] = useState<number | null>(null);
  const [references, setReferences] = useState<Record<number, string>>({});
  const [notes, setNotes] = useState<Record<number, string>>({});
  function save(event: FormEvent) {
    event.preventDefault();
    settings.transform(data => ({ ...data, reward_minor: Math.round(Number(data.reward_minor) * 100) }));
    settings.put('/admin/referrals/settings', { preserveScroll: true });
  }
  function review(id: number, decision: 'qualify' | 'reject') {
    setBusyId(id);
    router.post('/admin/referrals/' + id + '/review', { decision, qualifying_reference: references[id] || '', review_note: notes[id] || '' }, { preserveScroll: true, onFinish: () => setBusyId(null) });
  }
  return <DashboardLayout title="Referral programme" description="Configure rewards and review qualifying events before crediting user wallets." admin>
    <Head title="Referral programme · Semizzy Admin" />
    <div className="mx-auto max-w-6xl space-y-6">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-7">
        <h1 className="text-xl font-extrabold">Programme settings</h1>
        <form onSubmit={save} className="mt-5 grid gap-4 sm:grid-cols-2">
          <label className="flex items-center gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><input type="checkbox" checked={settings.data.enabled} onChange={e => settings.setData('enabled', e.target.checked)} /><span><span className="block font-bold">Enable referrals</span><span className="block text-xs text-slate-500">New sign-ups will be attributed only while enabled.</span></span></label>
          <label className="block text-sm font-semibold">Reward per approved referral (₦)<input type="number" min="0" max="1000000" step="0.01" value={settings.data.reward_minor} onChange={e => settings.setData('reward_minor', e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" /></label>
          <label className="block text-sm font-semibold">Qualifying event<select value={settings.data.qualifying_event} onChange={e => settings.setData('qualifying_event', e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700"><option value="admin_verified">Admin-verified qualifying event</option></select></label>
          <label className="block text-sm font-semibold">Audit reason<input required minLength={8} maxLength={500} value={settings.data.reason} onChange={e => settings.setData('reason', e.target.value)} placeholder="Why are these referral settings changing?" className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" /></label>
          {settings.errors.reward_minor && <p role="alert" className="text-sm text-red-600">{settings.errors.reward_minor}</p>}
          <button disabled={settings.processing} className="rounded-xl bg-emerald-700 px-5 py-3 font-bold text-white sm:col-span-2">{settings.processing ? 'Saving…' : 'Save audited settings'}</button>
        </form>
      </section>
      <section className="grid gap-3 sm:grid-cols-4">{[['Pending', props.stats.pending], ['Qualified', props.stats.qualified], ['Rewarded', props.stats.rewarded], ['Rejected', props.stats.rejected]].map(([name, value]) => <div key={String(name)} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"><p className="text-sm text-slate-500">{name}</p><p className="mt-1 text-2xl font-black">{value}</p></div>)}</section>
      <section className="space-y-4"><h2 className="text-xl font-extrabold">Referral review queue</h2>
        {props.referrals.data.length === 0 ? <p className="rounded-xl border border-slate-200 p-5 text-sm text-slate-500">No referral attributions to review.</p> : props.referrals.data.map(item => <article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
          <div className="flex flex-wrap justify-between gap-3"><div><p className="font-bold">{item.referred_name || 'New user'} <span className="font-normal text-slate-500">({item.referred_email || 'no email'})</span></p><p className="mt-1 text-sm text-slate-500">Referred by {item.referrer_name || 'User'} · {item.referrer_email || ''}</p><p className="mt-1 text-xs text-slate-500">Created {item.created_at ? new Date(item.created_at).toLocaleString() : ''}</p></div><span className="h-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold dark:bg-slate-800">{item.status.replaceAll('_', ' ')}</span></div>
          {item.status !== 'rewarded' && item.status !== 'rejected' && <div className="mt-4 grid gap-3 sm:grid-cols-2">
            <label className="block text-sm font-semibold">Qualifying event reference <input value={references[item.id] || ''} onChange={e => setReferences(s => ({ ...s, [item.id]: e.target.value }))} maxLength={120} placeholder="Verified order/deposit reference" className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" /></label>
            <label className="block text-sm font-semibold">Review note <input value={notes[item.id] || ''} onChange={e => setNotes(s => ({ ...s, [item.id]: e.target.value }))} minLength={8} maxLength={500} placeholder="Record the verification performed" className="mt-2 w-full rounded-xl border border-slate-300 bg-transparent px-3 py-3 font-normal dark:border-slate-700" /></label>
            <button disabled={busyId === item.id || !(notes[item.id] || '').trim() || !(references[item.id] || '').trim()} onClick={() => review(item.id, 'qualify')} className="rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white disabled:opacity-50">Verify event & pay reward</button>
            <button disabled={busyId === item.id || (notes[item.id] || '').trim().length < 8} onClick={() => review(item.id, 'reject')} className="rounded-xl border border-red-300 px-4 py-3 font-bold text-red-700 disabled:opacity-50 dark:text-red-300">Reject referral</button>
          </div>}
        </article>)}
      </section>
    </div>
  </DashboardLayout>;
}
