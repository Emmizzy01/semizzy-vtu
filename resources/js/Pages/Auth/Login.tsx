import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';

export default function Login() {
  const form = useForm({ email: '', password: '', remember: false });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post('/login');
  }

  return (
    <>
      <Head title="Sign in" />
      <main className="grid min-h-screen place-items-center bg-[var(--page)] px-4 py-10 text-[var(--ink)]">
        <section className="w-full max-w-md rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 shadow-sm sm:p-8">
          <a href="/" className="mb-8 flex items-center gap-3">
            <span aria-hidden="true" className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
            <span><strong className="block font-display text-lg">SEMIZZY</strong><span className="text-xs text-[var(--muted)]">Digital made simple</span></span>
          </a>
          <div className="mb-6">
            <h1 className="font-display text-2xl font-extrabold">Welcome back</h1>
            <p className="mt-2 text-sm leading-6 text-[var(--muted)]">Sign in to access your wallet and VTU services.</p>
          </div>
          <form onSubmit={submit} className="space-y-5">
            <label className="block text-sm font-semibold">Email address
              <input id="login-email" name="email" type="email" autoComplete="username" autoCapitalize="none" spellCheck={false} required value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} aria-invalid={Boolean(form.errors.email)} aria-describedby={form.errors.email ? 'login-email-error' : undefined} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" />
              {form.errors.email && <span id="login-email-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.email}</span>}
            </label>
            <label className="block text-sm font-semibold">Password
              <input id="login-password" name="password" type="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} aria-invalid={Boolean(form.errors.password)} aria-describedby={form.errors.password ? 'login-password-error' : undefined} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" />
              {form.errors.password && <span id="login-password-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.password}</span>}
            </label>
            <label className="flex items-center gap-2 text-sm text-[var(--muted)]">
              <input id="remember-me" name="remember" type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} className="rounded border-[var(--line)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" />
              Remember me on this device
            </label>
            <button type="submit" disabled={form.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2 disabled:opacity-60">
              {form.processing && <LoaderCircle size={18} className="animate-spin" aria-hidden="true" />} Sign in
            </button>
          </form>
          <p className="mt-6 text-center text-sm text-[var(--muted)]">New to Semizzy? <Link href="/register" className="font-bold text-[var(--brand)]">Create an account</Link></p>
          <p className="mt-5 flex items-center justify-center gap-2 text-xs text-[var(--muted)]"><ShieldCheck size={14} aria-hidden="true" /> Your password is sent only over your site's HTTPS connection.</p>
        </section>
      </main>
    </>
  );
}
