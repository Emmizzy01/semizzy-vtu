import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';

export default function Register() {
  const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    transaction_pin: '',
    transaction_pin_confirmation: '',
  });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post('/register');
  }

  const inputClass = 'mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2';

  return (
    <>
      <Head title="Create account" />
      <main className="grid min-h-screen place-items-center bg-[var(--page)] px-4 py-10 text-[var(--ink)]">
        <section className="w-full max-w-md rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 shadow-sm sm:p-8">
          <a href="/" className="mb-8 flex items-center gap-3">
            <span aria-hidden="true" className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
            <span><strong className="block font-display text-lg">SEMIZZY</strong><span className="text-xs text-[var(--muted)]">Digital made simple</span></span>
          </a>
          <div className="mb-6">
            <h1 className="font-display text-2xl font-extrabold">Create your account</h1>
            <p className="mt-2 text-sm leading-6 text-[var(--muted)]">Start with an account to access Semizzy services.</p>
          </div>
          <form onSubmit={submit} className="space-y-4">
            <label className="block text-sm font-semibold">Full name
              <input id="register-name" name="name" autoComplete="name" required maxLength={120} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} aria-invalid={Boolean(form.errors.name)} aria-describedby={form.errors.name ? 'register-name-error' : undefined} className={inputClass} />
              {form.errors.name && <span id="register-name-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.name}</span>}
            </label>
            <label className="block text-sm font-semibold">Email address
              <input id="register-email" name="email" type="email" autoComplete="email" autoCapitalize="none" spellCheck={false} required value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} aria-invalid={Boolean(form.errors.email)} aria-describedby={form.errors.email ? 'register-email-error' : undefined} className={inputClass} />
              {form.errors.email && <span id="register-email-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.email}</span>}
            </label>
            <label className="block text-sm font-semibold">Password
              <input id="register-password" name="password" type="password" autoComplete="new-password" required minLength={12} value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} aria-invalid={Boolean(form.errors.password)} aria-describedby={form.errors.password ? 'register-password-hint register-password-error' : 'register-password-hint'} className={inputClass} />
              <span id="register-password-hint" className="mt-1 block text-xs font-normal text-[var(--muted)]">Use at least 12 characters, including letters, uppercase and lowercase characters, and numbers.</span>
              {form.errors.password && <span id="register-password-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.password}</span>}
            </label>
            <label className="block text-sm font-semibold">Confirm password
              <input id="register-password-confirmation" name="password_confirmation" type="password" autoComplete="new-password" required minLength={12} value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} aria-invalid={Boolean(form.errors.password_confirmation)} aria-describedby={form.errors.password_confirmation ? 'register-password-confirmation-error' : undefined} className={inputClass} />
              {form.errors.password_confirmation && <span id="register-password-confirmation-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.password_confirmation}</span>}
            </label>

            <div className="rounded-2xl border border-[var(--line)] p-4">
              <div className="font-semibold">Transaction PIN <span className="font-normal text-[var(--muted)]">(optional)</span></div>
              <p className="mt-1 text-xs leading-5 text-[var(--muted)]">Create a six-digit PIN now to authorize wallet-funded purchases. You can set it later from Security if you skip this step.</p>
              <label className="mt-3 block text-sm font-semibold" htmlFor="register-transaction-pin">Six-digit PIN
                <input id="register-transaction-pin" name="transaction_pin" type="password" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} autoComplete="off" placeholder="••••••" value={form.data.transaction_pin} onChange={(event) => form.setData('transaction_pin', event.target.value.replace(/\D/g, '').slice(0, 6))} aria-invalid={Boolean(form.errors.transaction_pin)} aria-describedby={form.errors.transaction_pin ? 'register-transaction-pin-error' : 'register-transaction-pin-hint'} className={inputClass} />
                <span id="register-transaction-pin-hint" className="mt-1 block text-xs font-normal text-[var(--muted)]">Avoid repeated digits or easy sequences.</span>
                {form.errors.transaction_pin && <span id="register-transaction-pin-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.transaction_pin}</span>}
              </label>
              <label className="mt-3 block text-sm font-semibold" htmlFor="register-transaction-pin-confirmation">Confirm transaction PIN
                <input id="register-transaction-pin-confirmation" name="transaction_pin_confirmation" type="password" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} autoComplete="off" placeholder="••••••" value={form.data.transaction_pin_confirmation} onChange={(event) => form.setData('transaction_pin_confirmation', event.target.value.replace(/\D/g, '').slice(0, 6))} aria-invalid={Boolean(form.errors.transaction_pin_confirmation)} aria-describedby={form.errors.transaction_pin_confirmation ? 'register-transaction-pin-confirmation-error' : undefined} className={inputClass} />
                {form.errors.transaction_pin_confirmation && <span id="register-transaction-pin-confirmation-error" role="alert" className="mt-1 block text-xs text-rose-500">{form.errors.transaction_pin_confirmation}</span>}
              </label>
            </div>

            <button type="submit" disabled={form.processing} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2 disabled:opacity-60">
              {form.processing && <LoaderCircle size={18} className="animate-spin" aria-hidden="true" />} Create account
            </button>
          </form>
          <p className="mt-6 text-center text-sm text-[var(--muted)]">Already registered? <Link href="/login" className="font-bold text-[var(--brand)]">Sign in</Link></p>
          <p className="mt-5 flex items-center justify-center gap-2 text-xs text-[var(--muted)]"><ShieldCheck size={14} aria-hidden="true" /> Your transaction PIN is stored as a secure hash.</p>
        </section>
      </main>
    </>
  );
}
