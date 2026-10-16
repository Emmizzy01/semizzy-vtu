import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Eye, EyeOff, LoaderCircle } from 'lucide-react';

export default function Login() {
  const [showPassword, setShowPassword] = useState(false);
  const form = useForm({ identifier: '', password: '', remember: false });

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
            <label className="block text-sm font-semibold">Username, email or WhatsApp number
              <input id="login-identifier" name="identifier" type="text" autoComplete="username" autoCapitalize="none" spellCheck={false} required value={form.data.identifier} onChange={(event) => form.setData('identifier', event.target.value)} aria-invalid={Boolean(form.errors.identifier)} aria-describedby={form.errors.identifier ? 'login-identifier-error' : 'login-identifier-hint'} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" />
              {form.errors.identifier && <span id="login-identifier-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.identifier}</span>}
              <span id="login-identifier-hint" className="mt-1 block text-xs font-normal text-[var(--muted)]">WhatsApp sign-in requires a verified number linked to your account.</span>
            </label>
            <label className="block text-sm font-semibold">Password
              <span className="relative mt-2 block">
                <input id="login-password" name="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} aria-invalid={Boolean(form.errors.password)} aria-describedby={form.errors.password ? 'login-password-error' : undefined} className="w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 pr-12 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" />
                <button type="button" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} className="absolute inset-y-0 right-3 inline-flex items-center text-[var(--muted)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] rounded-md">
                  {showPassword ? <EyeOff size={19} aria-hidden="true" /> : <Eye size={19} aria-hidden="true" />}
                </button>
              </span>
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
          <p className="mt-4 text-center text-sm"><Link href="/forgot-password" className="font-semibold text-[var(--brand)]">Forgot your password?</Link></p>
          <p className="mt-6 text-center text-sm text-[var(--muted)]">New to Semizzy? <Link href="/register" className="font-bold text-[var(--brand)]">Create an account</Link></p>
        </section>
      </main>
    </>
  );
}
