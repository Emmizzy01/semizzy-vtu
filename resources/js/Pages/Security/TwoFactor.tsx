import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';
import { KeyRound, ShieldCheck } from 'lucide-react';

type Props = {
  enabled: boolean;
  enabledAt: string | null;
  setupSecret: string | null;
  provisioningUri: string | null;
  recoveryCodes: string[] | null;
};

export default function TwoFactor({ enabled, enabledAt, setupSecret, provisioningUri, recoveryCodes }: Props) {
  const enableForm = useForm({ code: '' });
  const disableForm = useForm({ password: '', code: '' });
  const enable = (event: FormEvent) => {
    event.preventDefault();
    enableForm.post('/security/two-factor/enable', { preserveScroll: true, onSuccess: () => enableForm.reset() });
  };
  const disable = (event: FormEvent) => {
    event.preventDefault();
    disableForm.post('/security/two-factor/disable', { preserveScroll: true, onSuccess: () => disableForm.reset() });
  };

  return <DashboardLayout>
    <Head title="Two-factor security" />
    <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">
      <div>
        <p className="text-sm text-[var(--muted-foreground)]">Account security</p>
        <h1 className="mt-1 text-2xl font-semibold">Two-factor authentication</h1>
        <p className="mt-2 text-sm text-[var(--muted-foreground)]">Require a time-based code from an authenticator app after your password and any enabled email verification.</p>
      </div>

      {enabled ? <section className="rounded-2xl border p-5">
        <div className="flex items-center gap-3"><ShieldCheck className="h-6 w-6" aria-hidden="true" /><div><h2 className="font-semibold">Authenticator protection is enabled</h2><p className="text-sm text-[var(--muted-foreground)]">Enabled {enabledAt ? new Date(enabledAt).toLocaleString() : 'successfully'}</p></div></div>
        {recoveryCodes && recoveryCodes.length > 0 && <div className="mt-5 rounded-xl border p-4">
          <h3 className="font-semibold">Save your recovery codes now</h3>
          <p className="mt-1 text-sm text-[var(--muted-foreground)]">Each code can be used once if you cannot access your authenticator. These codes are shown only after setup.</p>
          <ul className="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">{recoveryCodes.map((code) => <li key={code} className="rounded border p-2">{code}</li>)}</ul>
          <button type="button" onClick={() => navigator.clipboard.writeText(recoveryCodes.join('\n'))} className="mt-3 rounded-lg border px-3 py-2 text-sm">Copy recovery codes</button>
        </div>}
        <form onSubmit={disable} className="mt-6 space-y-3">
          <h3 className="font-semibold">Turn off two-factor authentication</h3>
          <label className="block text-sm">Current password<input type="password" autoComplete="current-password" value={disableForm.data.password} onChange={(e) => disableForm.setData('password', e.target.value)} className="mt-1 block w-full rounded-lg border bg-transparent px-3 py-2" required /></label>
          <label className="block text-sm">Authenticator or unused recovery code<input value={disableForm.data.code} onChange={(e) => disableForm.setData('code', e.target.value)} className="mt-1 block w-full rounded-lg border bg-transparent px-3 py-2" required maxLength={32} /></label>
          {disableForm.errors.password && <p role="alert" className="text-sm text-red-600">{disableForm.errors.password}</p>}
          {disableForm.errors.code && <p role="alert" className="text-sm text-red-600">{disableForm.errors.code}</p>}
          <button disabled={disableForm.processing} className="rounded-lg border px-4 py-2 font-medium disabled:opacity-50">Disable protection</button>
        </form>
      </section> : <section className="rounded-2xl border p-5">
        <div className="flex items-center gap-3"><KeyRound className="h-6 w-6" aria-hidden="true" /><div><h2 className="font-semibold">Set up an authenticator app</h2><p className="text-sm text-[var(--muted-foreground)]">Use any TOTP-compatible authenticator app.</p></div></div>
        <ol className="mt-4 list-decimal space-y-2 pl-5 text-sm">
          <li>Open your authenticator app and add a new account manually.</li>
          <li>Enter this secret key. Keep it private.</li>
          <li>Enter the current six-digit code to confirm setup.</li>
        </ol>
        {setupSecret && <div className="mt-4 rounded-xl border p-4"><p className="text-xs text-[var(--muted-foreground)]">Secret key</p><p className="mt-1 break-all font-mono text-lg font-semibold">{setupSecret}</p><button type="button" onClick={() => navigator.clipboard.writeText(setupSecret)} className="mt-2 rounded-lg border px-3 py-2 text-sm">Copy secret</button></div>}
        {provisioningUri && <details className="mt-3 text-sm"><summary className="cursor-pointer">Show setup URI</summary><p className="mt-2 break-all rounded border p-3 font-mono">{provisioningUri}</p></details>}
        <form onSubmit={enable} className="mt-5 space-y-3">
          <label className="block text-sm">Six-digit authenticator code<input inputMode="numeric" autoComplete="one-time-code" value={enableForm.data.code} onChange={(e) => enableForm.setData('code', e.target.value)} className="mt-1 block w-full rounded-lg border bg-transparent px-3 py-2" required pattern="[0-9]{6}" maxLength={6} /></label>
          {enableForm.errors.code && <p role="alert" className="text-sm text-red-600">{enableForm.errors.code}</p>}
          <button disabled={enableForm.processing} className="rounded-lg border px-4 py-2 font-medium disabled:opacity-50">Verify and enable</button>
        </form>
      </section>}
    </div>
  </DashboardLayout>;
}
