import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { KeyRound, LoaderCircle, ShieldCheck } from 'lucide-react';

export default function ForgotPassword({ status }: { status?: string | null }) {
  const form = useForm({ email: '' });
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); form.post('/forgot-password'); }
  return <><Head title="Reset password"/><main className="grid min-h-screen place-items-center bg-[var(--page)] px-4 py-10 text-[var(--ink)]"><section className="w-full max-w-md rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 shadow-sm sm:p-8">
    <a href="/" className="mb-8 flex items-center gap-3"><span className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span><span><strong className="block font-display text-lg">SEMIZZY</strong><span className="text-xs text-[var(--muted)]">Digital made simple</span></span></a>
    <span className="mb-4 inline-grid rounded-2xl bg-[var(--brand)]/10 p-3 text-[var(--brand)]"><KeyRound size={22}/></span><h1 className="font-display text-2xl font-extrabold">Forgot your password?</h1><p className="mt-2 text-sm leading-6 text-[var(--muted)]">Enter your account email. If it matches an account, we’ll send a time-limited reset link.</p>
    {status && <p role="status" className="mt-4 rounded-xl bg-emerald-600/10 p-3 text-sm text-emerald-700 dark:text-emerald-300">{status}</p>}
    <form onSubmit={submit} className="mt-6 space-y-4"><label className="block text-sm font-semibold">Email address<input type="email" autoComplete="email" autoCapitalize="none" required maxLength={255} value={form.data.email} onChange={e=>form.setData('email',e.target.value)} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]" />{form.errors.email && <span role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.email}</span>}</label><button disabled={form.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white disabled:opacity-60">{form.processing&&<LoaderCircle size={18} className="animate-spin"/>}Send reset link</button></form>
    <p className="mt-6 text-center text-sm"><Link href="/login" className="font-semibold text-[var(--brand)]">Back to sign in</Link></p><p className="mt-5 flex items-center justify-center gap-2 text-xs text-[var(--muted)]"><ShieldCheck size={14}/> Reset links expire and can only be used once.</p>
  </section></main></>;
}
