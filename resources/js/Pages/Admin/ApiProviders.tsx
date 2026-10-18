import { ArrowLeft, CircleCheck, CircleX, LockKeyhole, PlugZap, ShieldCheck } from 'lucide-react';
import { Head, useForm } from '@inertiajs/react';

type Provider = {
  isolated: boolean;
  name?: string;
  configurationLocked?: boolean;
  purchasesEnabled?: boolean;
  statusMessage?: string;
  genericEndpoint?: string;
  genericEndpointConfigured?: boolean;
  genericTokenConfigured?: boolean;
  catalogCommand?: string;
};

export default function ApiProviders({ provider }: { provider: Provider }) {
  const form = useForm({
    driver: 'configured',
    name: provider.name || 'configured_vtu',
    generic_endpoint: provider.genericEndpoint || '',
    generic_token: '',
    reason: '',
  });

  return (
    <>
      <Head title="API Providers" />
      <main className="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
        <a href="/admin/dashboard" className="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-[var(--muted)] hover:text-[var(--brand)]"><ArrowLeft size={16} /> Admin overview</a>
        {provider.isolated ? (
          <div className="mt-6 max-w-5xl space-y-5">
            <section className="rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-8">
              <div className="flex items-start gap-4">
                <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><PlugZap size={24} /></span>
                <div className="min-w-0">
                  <p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">Provider integrations</p>
                  <h1 className="mt-2 font-display text-2xl font-extrabold sm:text-3xl">{provider.name || 'VTU provider'} integration status</h1>
                  <p className="mt-3 max-w-3xl text-sm leading-6 text-[var(--muted)]">{provider.statusMessage || 'This integration is isolated while its technical review is pending.'} Provider settings are read-only during this hold.</p>
                </div>
              </div>
            </section>
            <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Status title="Configuration access" value="Locked for review" detail="Secret credentials, endpoint configuration and provider selection remain protected." />
              <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><p className="text-xs font-bold text-[var(--muted)]">New purchases</p><h2 className="mt-3 flex items-center gap-2 font-display text-xl font-extrabold"><CircleX size={20} className="text-amber-600" /> Blocked</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">No new purchases will be submitted through this isolated integration.</p></article>
              <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><p className="text-xs font-bold text-[var(--muted)]">Existing orders</p><h2 className="mt-3 flex items-center gap-2 font-display text-xl font-extrabold"><CircleCheck size={20} className="text-emerald-600" /> Available</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">Existing transaction records and reconciliation remain available.</p></article>
            </section>
            <section className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
              <div className="flex items-start gap-3"><LockKeyhole size={21} className="mt-0.5 shrink-0 text-[var(--muted)]" /><div><h2 className="font-display text-lg font-extrabold">Why are settings locked?</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">The provider's technical review and release checks must be completed before credentials, endpoint details or provider-selection controls can be changed here. This restriction does not hide this status page or remove access to existing transaction records.</p></div></div>
            </section>
          </div>
        ) : (
          <>
            <div className="mt-5 flex items-start gap-4"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><PlugZap size={26} /></span><div><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">VTU integrations</p><h1 className="mt-1 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">API providers</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">Configure a server-side generic HTTPS adapter. Provider readiness must be verified separately before purchases are enabled.</p></div></div>
            <section className="mt-7 grid gap-4 sm:grid-cols-2"><Status title="Generic adapter endpoint" value={provider.genericEndpointConfigured ? 'Configured' : 'Incomplete'} detail="HTTPS endpoint is required." /><Status title="Generic adapter token" value={provider.genericTokenConfigured ? 'Configured · hidden' : 'Incomplete'} detail="Credentials are never sent back to the browser." /></section>
            <form onSubmit={e => { e.preventDefault(); form.put('/admin/api-providers'); }} className="mt-7 max-w-4xl space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-7">
              <div className="flex items-start gap-3"><ShieldCheck className="mt-1 shrink-0 text-[var(--brand)]" size={22} /><div><h2 className="font-display text-lg font-extrabold">Generic provider configuration</h2><p className="mt-1 text-sm leading-6 text-[var(--muted)]">Blank credential fields keep saved credentials. Save changes with an audit reason.</p></div></div>
              <Field label="Provider display name" value={form.data.name} onChange={v => form.setData('name', v)} error={form.errors.name} />
              <Field label="Generic provider HTTPS endpoint" value={form.data.generic_endpoint} onChange={v => form.setData('generic_endpoint', v)} error={form.errors.generic_endpoint} placeholder="https://provider.example/api" />
              <Field label="Replace generic bearer token (optional)" value={form.data.generic_token} onChange={v => form.setData('generic_token', v)} error={form.errors.generic_token} type="password" placeholder={provider.genericTokenConfigured ? 'Saved token is hidden — enter only to replace' : 'Enter provider token'} />
              <label className="block space-y-2"><span className="text-sm font-bold">Reason for change (required audit note)</span><textarea value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} minLength={8} maxLength={500} required rows={3} className="w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-3 text-sm" />{form.errors.reason && <span className="text-sm text-red-600">{form.errors.reason}</span>}</label>
              {form.hasErrors && <p className="text-sm text-red-600">Please correct the highlighted fields before saving.</p>}
              <button disabled={form.processing} className="min-h-11 rounded-xl bg-[var(--brand)] px-5 py-3 text-sm font-bold text-white disabled:opacity-60">{form.processing ? 'Saving…' : 'Save provider settings'}</button>
              {form.recentlySuccessful && <p className="text-sm font-semibold text-emerald-600">Settings saved.</p>}
              <p className="text-xs leading-5 text-[var(--muted)]">Catalogue preview command: <code>{provider.catalogCommand}</code>. Review provider product mappings before enabling sales.</p>
            </form>
          </>
        )}
      </main>
    </>
  );
}

function Field({ label, value, onChange, error, placeholder, type = 'text' }: { label: string; value: string; onChange: (v: string) => void; error?: string; placeholder?: string; type?: 'text' | 'password' }) {
  return <label className="block space-y-2"><span className="text-sm font-bold">{label}</span><input type={type} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2 text-sm" />{error && <span className="block text-sm text-red-600">{error}</span>}</label>;
}
function Status({ title, value, detail }: { title: string; value: string; detail: string }) {
  return <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><p className="text-xs font-bold text-[var(--muted)]">{title}</p><h2 className="mt-3 break-words font-display text-xl font-extrabold">{value}</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">{detail}</p></article>;
}
