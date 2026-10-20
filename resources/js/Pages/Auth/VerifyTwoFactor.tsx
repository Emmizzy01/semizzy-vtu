import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { ShieldCheck } from 'lucide-react';

export default function VerifyTwoFactor() {
  const form = useForm({ code: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/login/two-factor', { onFinish: () => form.reset('code') });
  };
  return <div className="flex min-h-screen items-center justify-center px-4 py-10">
    <Head title="Verify your sign-in" />
    <div className="w-full max-w-md rounded-2xl border p-6">
      <div className="mb-5 flex items-center gap-3"><ShieldCheck className="h-7 w-7" aria-hidden="true" /><div><h1 className="text-xl font-semibold">Verify your sign-in</h1><p className="text-sm text-[var(--muted-foreground)]">Enter your authenticator code or one unused recovery code.</p></div></div>
      <form onSubmit={submit} className="space-y-4">
        <label className="block text-sm">Verification code<input autoFocus autoComplete="one-time-code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} className="mt-1 block w-full rounded-lg border bg-transparent px-3 py-2.5" maxLength={32} required /></label>
        {form.errors.code && <p role="alert" className="text-sm text-red-600">{form.errors.code}</p>}
        {form.errors.identifier && <p role="alert" className="text-sm text-red-600">{form.errors.identifier}</p>}
        <button disabled={form.processing} className="w-full rounded-lg border px-4 py-2.5 font-medium disabled:opacity-50">Verify and continue</button>
      </form>
    </div>
  </div>;
}
