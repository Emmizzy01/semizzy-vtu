import { ArrowLeft, CheckCircle2, CircleAlert, CreditCard, ShieldCheck } from 'lucide-react';
import { Head } from '@inertiajs/react';

type Gateway = {
  name: string;
  configured: boolean;
  callbackConfigured: boolean;
  mode: 'Test' | 'Live' | 'Unknown';
  callbackHost: string | null;
  webhookPath: string;
};

export default function PaymentGateway({ gateway }: { gateway: Gateway }) {
  return (
    <>
      <Head title="Payment Gateway" />
      <main className="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
        <a href="/admin/dashboard" className="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-[var(--muted)] hover:text-[var(--brand)]"><ArrowLeft size={16} /> Admin overview</a>
        <div className="mt-5 flex items-start gap-4">
          <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><CreditCard size={26} /></span>
          <div><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">Finance integrations</p><h1 className="mt-1 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Payment gateway</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">Review the server-side Paystack configuration used for wallet funding. Secret credentials are never sent to this page.</p></div>
        </div>
        <section className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <StatusCard title="Provider" value={gateway.name} detail="Wallet funding and transaction verification" ok />
          <StatusCard title="Secret key" value={gateway.configured ? 'Configured' : 'Missing'} detail={gateway.configured ? 'A server-side key is present; its value is hidden.' : 'Add PAYSTACK_SECRET_KEY to the server environment.'} ok={gateway.configured} />
          <StatusCard title="Environment" value={gateway.mode} detail="Detected from the key prefix only; no key is exposed." ok={gateway.mode !== 'Unknown'} />
          <StatusCard title="Callback URL" value={gateway.callbackConfigured ? 'Configured' : 'Missing'} detail={gateway.callbackHost ? `HTTPS callback host: ${gateway.callbackHost}` : 'Set PAYSTACK_CALLBACK_URL to the deployed HTTPS return URL.'} ok={gateway.callbackConfigured} />
          <StatusCard title="Webhook endpoint" value="Application route" detail={gateway.webhookPath} ok />
        </section>
        <section className="mt-7 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
          <div className="flex items-start gap-3"><ShieldCheck className="mt-1 shrink-0 text-[var(--brand)]" size={22} /><div><h2 className="font-display text-lg font-extrabold">Safe configuration procedure</h2><ol className="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-[var(--muted)]"><li>Set PAYSTACK_SECRET_KEY in the hosting environment; use a test key until sandbox checks pass.</li><li>Set PAYSTACK_CALLBACK_URL to the correct HTTPS URL for this deployment.</li><li>Configure Paystack to send payment events to the webhook endpoint shown above.</li><li>After changing server environment values, refresh Laravel's configuration cache using the approved hosting deployment procedure.</li><li>Run a test payment and verify that the signed webhook and server-side transaction verification complete before enabling live payments.</li></ol></div></div>
          <p className="mt-5 rounded-xl border border-[var(--line)] p-4 text-sm leading-6 text-[var(--muted)]">This page is currently a read-only configuration monitor. It does not save credentials or activate live payments from the browser.</p>
        </section>
      </main>
    </>
  );
}

function StatusCard({ title, value, detail, ok }: { title: string; value: string; detail: string; ok: boolean }) {
  return <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><div className="flex items-center justify-between gap-3"><p className="text-xs font-bold text-[var(--muted)]">{title}</p>{ok ? <CheckCircle2 size={18} className="text-emerald-600" /> : <CircleAlert size={18} className="text-amber-600" />}</div><h2 className="mt-4 break-words font-display text-xl font-extrabold">{value}</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">{detail}</p></article>;
}
