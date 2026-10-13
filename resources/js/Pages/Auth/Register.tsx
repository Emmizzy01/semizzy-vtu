import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';

export default function Register() {
  const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post('/register');
  }

  return (
    <>
      <Head title="Create account" />
      <main className="grid min-h-screen place-items-center bg-[var(--page)] px-4 py-10 text-[var(--ink)]">
        <section className="w-full max-w-md rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 shadow-sm sm:p-8">
          <a href="/" className="mb-8 flex items-center gap-3">
            <span className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
            <span><strong className="block font-display text-lg">SEMIZZY</strong><span className="text-xs text-[var(--muted)]">Digital made simple</span></span>
          </a>
          <div className="mb-6">
            <h1 className="font-display text-2xl font-extrabold">Create your account</h1>
            <p className="mt-2 text-sm leading-6 text-[var(--muted)]">Start with an account to access Semizzy services.</p>
          </div>
          <form onSubmit={submit} className="space-y-4">
            <label className="block text-sm font-semibold">Full name
              <input autoComplete="name" required maxLength={120} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]" />
              {form.errors.name && <span className="mt-1 block text-xs text-rose-500">{form.errors.name}</span>}
            </label>
            <label className="block text-sm font-semibold">Email address
              <input type="email" autoComplete="email" required value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]" />
              {form.errors.email && <span className="mt-1 block text-xs text-rose-500">{form.errors.email}</span>}
            </label>
            <label className="block text-sm font-semibold">Password
              <input type="password" autoComplete="new-password" required minLength={12} value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]" />
              <span className="mt-1 block text-xs font-normal text-[var(--muted)]">Use at least 12 characters with mixed case and numbers.</span>
              {form.errors.password && <span className="mt-1 block text-xs text-rose-500">{form.errors.password}</span>}
            </label>
            <label className="block text-sm font-semibold">Confirm password
              <input type="password" autoComplete="new-password" required minLength={12} value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]" />
            </label>
            <button type="submit" disabled={form.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white disabled:opacity-60">
              {form.processing && <LoaderCircle size={18} className="animate-spin" />} Create account
            </button>
          </form>
          <p className="mt-6 text-center text-sm text-[var(--muted)]">Already registered? <Link href="/login" className="font-bold text-[var(--brand)]">Sign in</Link></p>
          <p className="mt-5 flex items-center justify-center gap-2 text-xs text-[var(--muted)]"><ShieldCheck size={14} /> Use a unique password for your Semizzy account.</p>
        </section>
      </main>
    </>
  );
}
