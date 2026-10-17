import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';

export default function VerifyLoginCode() {
  const form = useForm({ code: '' });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post('/login/verify');
  }

  return (
    <>
      <Head title="Verify sign-in" />
      <main className="grid min-h-screen place-items-center bg-[var(--page)] px-4 py-10 text-[var(--ink)]">
        <section className="w-full max-w-md rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 shadow-sm sm:p-8">
          <div className="mb-6 grid h-12 w-12 place-items-center rounded-2xl bg-[var(--brand)]/10 text-[var(--brand)]"><ShieldCheck size={25} /></div>
          <h1 className="font-display text-2xl font-extrabold">Verify it’s you</h1>
          <p className="mt-2 text-sm leading-6 text-[var(--muted)]">Enter the six-digit code sent to your email to finish signing in. The code expires after a short time and can only be used once.</p>
          <form onSubmit={submit} className="mt-6 space-y-5">
            <label className="block text-sm font-semibold">Verification code
              <input name="code" type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required autoFocus value={form.data.code} onChange={(event) => form.setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))} aria-invalid={Boolean(form.errors.code)} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 text-center font-mono text-2xl tracking-[0.4em] outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)]" />
              {form.errors.code && <span role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.code}</span>}
              {form.errors.identifier && <span role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.identifier}</span>}
            </label>
            <button type="submit" disabled={form.processing || form.data.code.length !== 6} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white disabled:opacity-60">
              {form.processing && <LoaderCircle size={18} className="animate-spin" aria-hidden="true" />} Verify and sign in
            </button>
          </form>
          <p className="mt-5 text-center text-sm text-[var(--muted)]"><Link href="/login" className="font-semibold text-[var(--brand)]">Return to sign in</Link></p>
        </section>
      </main>
    </>
  );
}
