import { useState, type ChangeEvent, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { KeyRound, ShieldCheck, UserRound, ChevronRight, MonitorSmartphone, Camera, BadgeCheck, Upload } from 'lucide-react';

type Profile = {
  name: string; email: string; user_type: string; user_level: string; kyc_status: string;
  avatar_url: string | null; address: string | null; date_of_birth: string | null; sex: string | null; mobile_number: string | null;
  mobile_verified: boolean; transaction_pin_configured: boolean; transaction_pin_changed_at: string | null;
};
const tierLabel = (value: string) => ({ level_1: 'Tier1', level_2: 'Tier2', level_3: 'Tier3', level_4: 'Tier4/Merchant', tier1: 'Tier1', tier2: 'Tier2', tier3: 'Tier3', tier4: 'Tier4/Merchant' } as Record<string, string>)[value.toLowerCase()] || value.replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
export default function ProfilePage({ profile }: { profile: Profile }) {
  const [name, setName] = useState(profile.name);
  const [address, setAddress] = useState(profile.address || '');
  const [dateOfBirth, setDateOfBirth] = useState(profile.date_of_birth || '');
  const [sex, setSex] = useState(profile.sex || '');
  const [mobileNumber, setMobileNumber] = useState(profile.mobile_number || '');
  const [verificationCode, setVerificationCode] = useState('');
  const [verificationBusy, setVerificationBusy] = useState(false);
  const [verificationSent, setVerificationSent] = useState(false);
  const [verificationMessage, setVerificationMessage] = useState('');
  const [verificationError, setVerificationError] = useState('');
  const [avatar, setAvatar] = useState<File | null>(null);
  const [avatarPreview, setAvatarPreview] = useState(profile.avatar_url || '');
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [passwordBusy, setPasswordBusy] = useState(false);
  const submitProfile = (e: FormEvent) => {
    e.preventDefault(); setBusy(true);
    router.post('/profile', { _method: 'put', name, address, date_of_birth: dateOfBirth || '', sex, mobile_number: mobileNumber.trim(), avatar }, {
      preserveScroll: true, forceFormData: true, onFinish: () => setBusy(false),
    });
  };
  const selectAvatar = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] || null;
    setAvatar(file);
    if (file) setAvatarPreview(URL.createObjectURL(file));
  };
  const submitPassword = (e: FormEvent) => { e.preventDefault(); setPasswordBusy(true); router.put('/profile/password', { current_password: currentPassword, password, password_confirmation: confirmation }, { preserveScroll: true, onFinish: () => { setPasswordBusy(false); setCurrentPassword(''); setPassword(''); setConfirmation(''); } }); };
  return <DashboardLayout title="Profile & password" description="Manage your personal details, account tier and security.">
    <Head title="Profile & password · Semizzy" />
    <div className="mx-auto max-w-3xl space-y-5">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 sm:p-6">
        <div className="mb-5 flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><UserRound size={22}/></span><div><h2 className="font-semibold">Personal profile</h2><p className="text-sm text-slate-500">Update your avatar and contact details below.</p></div></div>
        <form onSubmit={submitProfile} className="space-y-4">
          <div className="flex flex-wrap items-center gap-4 rounded-xl bg-slate-50 p-4 dark:bg-slate-900">
            <span className="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-full border-2 border-emerald-600/30 bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
              {avatarPreview ? <img src={avatarPreview} alt="Profile avatar preview" className="h-full w-full object-cover"/> : <UserRound size={30}/>}
            </span>
            <div className="min-w-0 flex-1"><p className="font-semibold">Profile avatar</p><p className="mt-1 text-xs text-slate-500">JPG, PNG or WebP · maximum 3 MB</p><label className="mt-3 inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold dark:border-slate-700"><Camera size={16}/> Choose photo<input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={selectAvatar}/></label>{avatar&&<p className="mt-2 break-all text-xs text-emerald-700">{avatar.name}</p>}</div>
          </div>
          <label className="block text-sm font-medium">Display name<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={name} onChange={e => setName(e.target.value)} minLength={2} maxLength={120} required /></label>
          <label className="block text-sm font-medium">Email address<input className="mt-1 w-full rounded-xl border bg-slate-100 p-3 text-slate-500 dark:border-slate-700 dark:bg-slate-900" value={profile.email} readOnly /><span className="mt-1 block text-xs text-slate-500">Email stays read-only until verified email changes are supported.</span></label>
          <label className="block text-sm font-medium">Residential address<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={address} onChange={e=>setAddress(e.target.value)} maxLength={255} autoComplete="street-address" placeholder="House number, street, city, state"/></label>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block text-sm font-medium">Date of birth<input type="date" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={dateOfBirth} onChange={e=>setDateOfBirth(e.target.value)} max={new Date().toISOString().slice(0,10)}/></label>
            <label className="block text-sm font-medium">Sex<select className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={sex} onChange={e=>setSex(e.target.value)}><option value="">Prefer not to specify</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option><option value="prefer_not_to_say">Prefer not to say</option></select></label>
          </div>
          <label className="block text-sm font-medium">Mobile number with country code<input type="tel" inputMode="tel" autoComplete="tel" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={mobileNumber} onChange={e=>setMobileNumber(e.target.value)} placeholder="+2348012345678" pattern="\+[1-9][0-9]{7,14}" title="Use international format, for example +2348012345678"/><span className="mt-1 block text-xs text-slate-500">Use international format, e.g. +2348012345678 for Nigeria.</span></label>
          <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div><p className="font-semibold">Mobile-number verification</p><p className="mt-1 text-sm text-slate-500">{profile.mobile_verified && mobileNumber.trim() === (profile.mobile_number || '') ? 'This number is verified and can be used to sign in.' : 'Verify ownership before using this number to sign in. Save any number changes first.'}</p></div>
              {profile.mobile_verified && mobileNumber.trim() === (profile.mobile_number || '') && <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Verified</span>}
            </div>
            {(!profile.mobile_verified || mobileNumber.trim() !== (profile.mobile_number || '')) && <div className="mt-3 space-y-3">
              <button type="button" disabled={verificationBusy || !mobileNumber.trim() || mobileNumber.trim() !== (profile.mobile_number || '')} onClick={() => { setVerificationBusy(true); setVerificationError(''); setVerificationMessage(''); router.post('/profile/mobile-number/verification', {}, { preserveScroll: true, onSuccess: () => { setVerificationSent(true); setVerificationMessage('Verification code sent. Check your SMS inbox.'); }, onError: errors => setVerificationError(String(errors.mobile_number || 'SMS verification could not be started.')), onFinish: () => setVerificationBusy(false) }); }} className="rounded-xl border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 disabled:opacity-50 dark:text-emerald-300">{verificationBusy ? 'Sending…' : verificationSent ? 'Send another code' : 'Send verification code'}</button>
              <form onSubmit={e => { e.preventDefault(); setVerificationBusy(true); setVerificationError(''); setVerificationMessage(''); router.post('/profile/mobile-number/verification/confirm', { code: verificationCode }, { preserveScroll: true, onSuccess: () => { setVerificationCode(''); setVerificationSent(false); setVerificationMessage('Mobile number verified successfully.'); }, onError: errors => setVerificationError(String(errors.verification_code || errors.mobile_number || 'The code could not be verified.')), onFinish: () => setVerificationBusy(false) }); }} className="flex flex-wrap gap-2">
                <input aria-label="SMS verification code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} pattern="[0-9]{6}" value={verificationCode} onChange={e=>setVerificationCode(e.target.value.replace(/\D/g, '').slice(0, 6))} placeholder="6-digit code" className="min-w-0 flex-1 rounded-xl border bg-transparent p-3 dark:border-slate-700" />
                <button type="submit" disabled={verificationBusy || verificationCode.length !== 6 || !verificationSent} className="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">Verify number</button>
              </form>
              {verificationMessage && <p role="status" className="text-sm text-emerald-700 dark:text-emerald-300">{verificationMessage}</p>}
              {verificationError && <p role="alert" className="text-sm text-rose-700 dark:text-rose-300">{verificationError}</p>}
              <p className="text-xs text-slate-500">SMS verification stays unavailable until an HTTPS SMS provider is configured and enabled by the operator.</p>
            </div>}
          </div>
          <div className="grid gap-3 sm:grid-cols-3">{[['Account type',profile.user_type],['Account tier',tierLabel(profile.user_level)],['KYC status',profile.kyc_status.replace(/_/g,' ')]].map(([label,value])=><div key={label} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-900"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-semibold capitalize">{value}</p></div>)}</div>
          <button disabled={busy || name.trim().length<2} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{busy?'Saving…':<><Upload size={17}/> Save profile</>}</button>
        </form>
      </section>
      <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/20">
        <div className="flex items-start gap-3"><BadgeCheck size={24} className="mt-0.5 shrink-0 text-amber-700 dark:text-amber-300"/><div className="min-w-0 flex-1"><h2 className="font-semibold">Your account: {tierLabel(profile.user_level)}</h2><p className="mt-1 text-sm text-slate-600 dark:text-slate-300">Move to the next tier to access higher account limits and eligible features.</p><a href="/account/tiers" className="mt-4 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white">Upgrade account to next tier <ChevronRight size={18}/></a></div></div>
      </section>
      <a href="/account/sessions" className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950"><span className="flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><MonitorSmartphone size={22}/></span><span><span className="block font-semibold">Sessions & devices</span><span className="text-sm text-slate-500">Review and revoke access on other devices.</span></span></span><ChevronRight size={18}/></a>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="mb-4 flex items-center gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><KeyRound size={22}/></span><div><h2 className="font-semibold">Change password</h2><p className="text-sm text-slate-500">Use at least 12 characters with lowercase, uppercase and a number.</p></div></div>
        <form onSubmit={submitPassword} className="space-y-4">
          <label className="block text-sm font-medium">Current password<input type="password" autoComplete="current-password" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={currentPassword} onChange={e=>setCurrentPassword(e.target.value)} required /></label>
          <label className="block text-sm font-medium">New password<input type="password" autoComplete="new-password" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={password} onChange={e=>setPassword(e.target.value)} minLength={12} required /></label>
          <label className="block text-sm font-medium">Confirm new password<input type="password" autoComplete="new-password" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={confirmation} onChange={e=>setConfirmation(e.target.value)} minLength={12} required /></label>
          <div className="rounded-xl bg-emerald-700/5 p-3 text-xs leading-5 text-slate-500"><ShieldCheck size={15} className="mr-1 inline"/>Password changes are audited. Other sessions are revoked after a successful change.</div>
          <button disabled={passwordBusy} className="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{passwordBusy?'Updating securely…':'Change password'}</button>
        </form>
      </section>
    </div>
  </DashboardLayout>;
}
