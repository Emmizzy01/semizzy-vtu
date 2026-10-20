import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Passkeys } from '@laravel/passkeys';
import DashboardLayout from '../../Components/DashboardLayout';

type Credential = { id: number; name: string; last_used_at: string | null; created_at: string | null };
type Props = { enabled: boolean; credentials: Credential[]; recentlyConfirmed: boolean };

export default function PasskeysPage({ enabled, credentials, recentlyConfirmed }: Props) {
  const confirmForm = useForm({ current_password: '' });
  const [confirmed, setConfirmed] = useState(recentlyConfirmed);
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  function confirmPassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    confirmForm.post('/security/passkeys/confirm', {
      preserveScroll: true,
      onSuccess: () => { setConfirmed(true); confirmForm.reset(); setMessage('Password confirmed. Passkey management is unlocked for five minutes.'); },
    });
  }

  async function registerPasskey() {
    if (!confirmed || !name.trim()) return;
    setBusy(true); setError(''); setMessage('');
    try {
      await Passkeys.register({ name: name.trim() });
      setMessage('Passkey registration completed.');
      setName('');
      router.reload();
    } catch {
      setError('Passkey registration did not complete. Check that this browser supports passkeys and the server is correctly configured.');
    } finally {
      setBusy(false);
    }
  }

  function removePasskey(id: number) {
    if (!confirmed || !window.confirm('Remove this passkey from your Semizzy account?')) return;
    router.delete('/user/passkeys/' + id, {
      preserveScroll: true,
      onSuccess: () => setMessage('Passkey removed.'),
      onError: () => setError('The passkey could not be removed. Confirm your password again and retry.'),
    });
  }

  return <DashboardLayout title="Passkeys & device biometrics" description="Use a device passkey, fingerprint, face recognition or device PIN when supported.">
    <Head title="Passkeys & device biometrics" />
    <div className="mx-auto max-w-3xl space-y-5">
      {!enabled && <section className="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
        <h2 className="font-semibold">Passkeys are not enabled yet</h2>
        <p className="mt-2 text-sm">The feature is intentionally disabled until production HTTPS, relying-party settings, dependencies and migrations are verified. Your current login method is unchanged.</p>
      </section>}
      {enabled && <>
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
          <h2 className="font-semibold">Confirm your identity</h2>
          <p className="mt-1 text-sm text-slate-500">Enter your current password before adding or removing a passkey. Confirmation expires after five minutes.</p>
          {!confirmed && <form onSubmit={confirmPassword} className="mt-4 space-y-3">
            <label className="block text-sm font-medium">Current password<input type="password" autoComplete="current-password" value={confirmForm.data.current_password} onChange={event => confirmForm.setData('current_password', event.target.value)} className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" required /></label>
            {confirmForm.errors.current_password && <p role="alert" className="text-sm text-rose-600">{confirmForm.errors.current_password}</p>}
            <button disabled={confirmForm.processing} className="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{confirmForm.processing ? 'Checking…' : 'Confirm password'}</button>
          </form>}
          {confirmed && <p className="mt-3 text-sm font-medium text-emerald-700 dark:text-emerald-300">Password confirmed for passkey management.</p>}
        </section>
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
          <h2 className="font-semibold">Add a passkey</h2>
          <p className="mt-1 text-sm text-slate-500">Your device creates a public/private key pair. The private key stays with your authenticator; Semizzy stores the public credential needed to verify sign-in. If separate OTP or TOTP is enabled on your account, use password sign-in until the passkey step-up handoff is completed.</p>
          <label className="mt-4 block text-sm font-medium">Device label<input value={name} onChange={event => setName(event.target.value)} maxLength={120} placeholder="e.g. My Android phone" className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" /></label>
          <button type="button" onClick={registerPasskey} disabled={!confirmed || !name.trim() || busy} className="mt-3 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{busy ? 'Waiting for your device…' : 'Register this device'}</button>
        </section>
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
          <h2 className="font-semibold">Registered passkeys</h2>
          {credentials.length === 0 ? <p className="mt-3 text-sm text-slate-500">No passkeys registered for this account.</p> : <ul className="mt-3 divide-y dark:divide-slate-800">{credentials.map(credential => <li key={credential.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div><p className="font-medium">{credential.name}</p><p className="text-xs text-slate-500">Added {credential.created_at ? new Date(credential.created_at).toLocaleString() : '—'} · Last used {credential.last_used_at ? new Date(credential.last_used_at).toLocaleString() : 'not yet used'}</p></div>
            <button type="button" onClick={() => removePasskey(credential.id)} disabled={!confirmed} className="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-700 disabled:opacity-50 dark:border-rose-900 dark:text-rose-300">Remove</button>
          </li>)}</ul>}
        </section>
      </>}
      {message && <p role="status" className="rounded-xl border border-emerald-300 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:text-emerald-200">{message}</p>}
      {error && <p role="alert" className="rounded-xl border border-rose-300 p-3 text-sm text-rose-700 dark:border-rose-900 dark:text-rose-300">{error}</p>}
    </div>
  </DashboardLayout>;
}
