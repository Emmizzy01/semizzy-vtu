import { ArrowLeft, CreditCard, ShieldCheck } from 'lucide-react';
import { Head, useForm } from '@inertiajs/react';

type Gateway = {
  name: string;
  configured: boolean;
  callbackConfigured: boolean;
  mode: 'Test' | 'Live' | 'Unknown';
  callbackHost: string | null;
  callbackUrl: string;
  webhookPath: string;
};

export default function PaymentGateway({ gateway }: { gateway: Gateway }) {
  const form = useForm({ secret_key: '', callback_url: gateway.callbackUrl || '', confirm_live: false });

  return (
    <>
      <Head title="Payment Gateway" />
      <main className="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
        <a href="/admin/dashboard" className="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-[var(--muted)] hover:text-[var(--brand)]"><ArrowLeft size={16} /> Admin overview</a>
        <div className="mt-5 flex items-start gap-4">
          <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><CreditCard size={26} /></span>
          <div><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">Finance integrations</p><h1 className="mt-1 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Payment gateway</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">Edit the Paystack connection used for wallet funding. Saved credentials are encrypted and never displayed again.</p></div>
        </div>
        <section className="mt-7 grid gap-4 sm:grid-cols-3">
          <Status title="Provider" value={gateway.name} detail="Wallet funding and verification" />
          <Status title="Current key" value={gateway.configured ? 'Configured · hidden' : 'Missing'} detail={`Detected mode: ${gateway.mode}`} />
          <Status title="Webhook" value="Application route" detail={gateway.webhookPath} />
        </section>
        <form onSubmit={e => { e.preventDefault(); form.put('/admin/payment-gateway'); }} className="mt-7 max-w-3xl space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-7">
          <div className="flex items-start gap-3"><ShieldCheck className="mt-1 shrink-0 text-[var(--brand)]" size={22} /><div><h2 className="font-display text-lg font-extrabold">Paystack settings</h2><p className="mt-1 text-sm leading-6 text-[var(--muted)]">Leave the secret field empty to keep the currently saved key. Use a test key until test transactions have been verified.</p></div></div>
          <Field label="Replace secret key (optional)" value={form.data.secret_key} onChange={v => form.setData('secret_key', v)} error={form.errors.secret_key} placeholder={gateway.configured ? 'Saved key is hidden — enter only to replace' : 'Enter Paystack test or live secret key'} type="password" />
          <Field label="HTTPS callback URL" value={form.data.callback_url} onChange={v => form.setData('callback_url', v)} error={form.errors.callback_url} placeholder="https://your-domain.example/wallet/funding/return" />
          {gateway.mode === 'Live' || form.data.secret_key.startsWith('sk_live_') ? <label className="flex items-start gap-3 rounded-xl border border-amber-500/40 p-4 text-sm"><input type="checkbox" checked={form.data.confirm_live} onChange={e => form.setData('confirm_live', e.target.checked)} className="mt-1" /><span><strong>Confirm live payment credentials.</strong><br />I understand this can enable real-money payment operations once the deployment is configured.</span></label> : null}
          {form.errors.confirm_live && <p className="text-sm text-red-600">{form.errors.confirm_live}</p>}
          {form.hasErrors && <p className="text-sm text-red-600">Please correct the highlighted fields before saving.</p>}
          <button disabled={form.processing} className="min-h-11 rounded-xl bg-[var(--brand)] px-5 py-3 text-sm font-bold text-white disabled:opacity-60">{form.processing ? 'Saving…' : 'Save payment settings'}</button>
          {form.recentlySuccessful && <p className="text-sm font-semibold text-emerald-600">Settings saved.</p>}
        </form>
      </main>
    </>
  );
}

function Field({ label, value, onChange, error, placeholder, type = 'text' }: { label: string; value: string; onChange: (v: string) => void; error?: string; placeholder?: string; type?: 'text' | 'password' }) {
  return <label className="block space-y-2"><span className="text-sm font-bold">{label}</span><input type={type} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2 text-sm" /><span className="block text-sm text-red-600">{error}</span></label>;
}

function Status({ title, value, detail }: { title: string; value: string; detail: string }) {
  return <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><p className="text-xs font-bold text-[var(--muted)]">{title}</p><h2 className="mt-3 break-words font-display text-xl font-extrabold">{value}</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">{detail}</p></article>;
}
