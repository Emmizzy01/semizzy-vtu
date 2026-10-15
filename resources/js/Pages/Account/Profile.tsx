import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { KeyRound, ShieldCheck, UserRound } from 'lucide-react';

type Profile = { name: string; email: string; user_type: string; user_level: string; kyc_status: string; transaction_pin_configured: boolean; transaction_pin_changed_at: string | null };
export default function ProfilePage({ profile }: { profile: Profile }) {
  const [name, setName] = useState(profile.name);
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [passwordBusy, setPasswordBusy] = useState(false);
  const submitProfile = (e: FormEvent) => { e.preventDefault(); setBusy(true); router.put('/profile', { name }, { preserveScroll: true, onFinish: () => setBusy(false) }); };
  const submitPassword = (e: FormEvent) => { e.preventDefault(); setPasswordBusy(true); router.put('/profile/password', { current_password: currentPassword, password, password_confirmation: confirmation }, { preserveScroll: true, onFinish: () => { setPasswordBusy(false); setCurrentPassword(''); setPassword(''); setConfirmation(''); } }); };
  const titleCase = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
  return <DashboardLayout title="Profile & password" description="Manage your account name and protect account access.">
    <Head title="Profile & password · Semizzy" />
    <div className="mx-auto max-w-3xl space-y-5">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="mb-4 flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><UserRound size={22}/></span><div><h2 className="font-semibold">Account profile</h2><p className="text-sm text-slate-500">Your email remains read-only here until a verified email-change workflow is available.</p></div></div>
        <form onSubmit={submitProfile} className="space-y-4">
          <label className="block text-sm font-medium">Display name<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={name} onChange={e => setName(e.target.value)} minLength={2} maxLength={120} required /></label>
          <label className="block text-sm font-medium">Email address<input className="mt-1 w-full rounded-xl border bg-slate-100 p-3 text-slate-500 dark:border-slate-700 dark:bg-slate-900" value={profile.email} readOnly /></label>
          <div className="grid gap-3 sm:grid-cols-3">{[['Account type',profile.user_type],['Account level',profile.user_level],['KYC status',profile.kyc_status]].map(([label,value])=><div key={label} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-900"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-semibold">{titleCase(value)}</p></div>)}</div>
          <button disabled={busy || name.trim().length<2} className="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{busy?'Saving…':'Save profile'}</button>
        </form>
      </section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="mb-4 flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><KeyRound size={22}/></span><div><h2 className="font-semibold">Change password</h2><p className="text-sm text-slate-500">Use at least 12 characters with lowercase, uppercase and a number.</p></div></div>
        <form onSubmit={submitPassword} className="space-y-4">
          <label className="block text-sm font-medium">Current password<input type="password" autoComplete="current-password" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={currentPassword} onChange={e=>setCurrentPassword(e.target.value)} required /></label>
          <label className="block text-sm font-medium">New password<input type="password" autoComplete="new-password" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={password} onChange={e=>setPassword(e.target.value)} minLength={12} required /></label>
          <label className="block text-sm font-medium">Confirm new password<input type="password" autoComplete="new-password" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={confirmation} onChange={e=>setConfirmation(e.target.value)} minLength={12} required /></label>
          <div className="rounded-xl bg-emerald-700/5 p-3 text-xs leading-5 text-slate-500"><ShieldCheck size={15} className="mr-1 inline"/>Password changes are audited. The current session is refreshed after a successful change.</div>
          <button disabled={passwordBusy} className="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{passwordBusy?'Updating securely…':'Change password'}</button>
        </form>
      </section>
    </div>
  </DashboardLayout>;
}
