import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { MonitorSmartphone, ShieldCheck, LogOut, RefreshCw } from 'lucide-react';

type Session = { id: string; ip_address: string | null; user_agent: string; last_activity: string; is_current: boolean };
export default function SessionsPage({ sessions }: { sessions: Session[] }) {
  const revoke = (id: string) => { if (window.confirm('Sign out this device? It will need to sign in again.')) router.delete(`/account/sessions/${id}`, { preserveScroll: true }); };
  return <DashboardLayout title="Sessions & devices" description="Review signed-in devices and revoke access you no longer recognize.">
    <Head title="Sessions & devices · Semizzy" />
    <div className="mx-auto max-w-3xl space-y-5">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><ShieldCheck size={22}/></span><div><h2 className="font-semibold">Active sessions</h2><p className="text-sm text-slate-500">Revocation is checked on subsequent authenticated requests.</p></div></div>
          <button onClick={() => { if (window.confirm('Sign out every other device?')) router.post('/account/sessions/revoke-others', {}, { preserveScroll: true }); }} className="rounded-xl border px-4 py-2 text-sm font-semibold dark:border-slate-700">Sign out other devices</button>
        </div>
      </section>
      {sessions.length === 0 ? <div className="rounded-2xl border border-dashed p-8 text-center text-sm text-slate-500 dark:border-slate-700">No active sessions were found yet. This device is registered as you use your account.</div> : sessions.map(session => <section key={session.id} className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 gap-3"><span className="rounded-xl bg-slate-100 p-3 dark:bg-slate-900"><MonitorSmartphone size={22}/></span><div className="min-w-0"><div className="flex flex-wrap items-center gap-2 font-semibold">{session.is_current ? 'This device' : 'Signed-in device'}{session.is_current && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Current</span>}</div><p className="mt-1 break-words text-sm text-slate-600 dark:text-slate-300">{session.user_agent}</p><p className="mt-1 text-xs text-slate-500">{session.ip_address || 'IP unavailable'} · Last activity {new Date(session.last_activity).toLocaleString()}</p></div></div>
        <button onClick={() => revoke(session.id)} className="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700 dark:border-rose-900 dark:text-rose-300"><LogOut size={16}/>{session.is_current ? 'Sign out' : 'Revoke'}</button>
      </section>)}
      <p className="flex gap-2 text-xs leading-5 text-slate-500"><RefreshCw size={14} className="mt-0.5 shrink-0"/>Device labels use browser-provided user-agent data and IP address; they are not guaranteed hardware identities or geolocation.</p>
    </div>
  </DashboardLayout>;
}
