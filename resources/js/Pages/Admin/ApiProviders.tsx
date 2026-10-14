import { ArrowLeft, PlugZap, ShieldCheck } from 'lucide-react';
import { Head, useForm } from '@inertiajs/react';

type Provider = {
  driver: string;
  name: string;
  genericEndpoint: string;
  genericEndpointConfigured: boolean;
  genericTokenConfigured: boolean;
  vtpassSelected: boolean;
  vtpassBaseUrl: string;
  vtpassApiKeyConfigured: boolean;
  vtpassSecretKeyConfigured: boolean;
  mode: string;
  catalogCommand: string;
};

export default function ApiProviders({ provider }: { provider: Provider }) {
  const form = useForm({
    driver: provider.driver === 'vtpass' ? 'vtpass' : 'configured',
    name: provider.name,
    generic_endpoint: provider.genericEndpoint || '',
    generic_token: '',
    vtpass_base_url: provider.vtpassBaseUrl || 'https://sandbox.vtpass.com/api',
    vtpass_api_key: '',
    vtpass_secret_key: '',
    confirm_live: false,
  });

  return (
    <>
      <Head title="API Providers" />
      <main className="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
        <a href="/admin/dashboard" className="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-[var(--muted)] hover:text-[var(--brand)]"><ArrowLeft size={16} /> Admin overview</a>
        <div className="mt-5 flex items-start gap-4"><span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><PlugZap size={26} /></span><div><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">VTU integrations</p><h1 className="mt-1 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">API providers</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">Choose and configure the server-side VTU adapter. Existing credentials are never sent back to the browser.</p></div></div>
        <section className="mt-7 grid gap-4 sm:grid-cols-3"><Status title="Selected adapter" value={provider.driver} detail={`Provider name: ${provider.name}`} /><Status title="VTpass credentials" value={provider.vtpassApiKeyConfigured && provider.vtpassSecretKeyConfigured ? 'Configured · hidden' : 'Incomplete'} detail={`Environment: ${provider.mode}`} /><Status title="Generic adapter" value={provider.genericEndpointConfigured && provider.genericTokenConfigured ? 'Configured' : 'Incomplete'} detail="HTTPS endpoint and bearer token are required." /></section>
        <form onSubmit={e => { e.preventDefault(); form.put('/admin/api-providers'); }} className="mt-7 max-w-4xl space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-7">
          <div className="flex items-start gap-3"><ShieldCheck className="mt-1 shrink-0 text-[var(--brand)]" size={22} /><div><h2 className="font-display text-lg font-extrabold">Provider configuration</h2><p className="mt-1 text-sm leading-6 text-[var(--muted)]">Blank credential fields mean keep the saved value. Start with sandbox and switch to live only after testing.</p></div></div>
          <label className="block space-y-2"><span className="text-sm font-bold">Active adapter</span><select value={form.data.driver} onChange={e => form.setData('driver', e.target.value as 'configured' | 'vtpass')} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-sm"><option value="configured">Generic HTTPS provider</option><option value="vtpass">VTpass</option></select>{form.errors.driver && <span className="text-sm text-red-600">{form.errors.driver}</span>}</label>
          <Field label="Provider display name" value={form.data.name} onChange={v => form.setData('name', v)} error={form.errors.name} />
          {form.data.driver === 'configured' ? <>
            <Field label="Generic provider HTTPS endpoint" value={form.data.generic_endpoint} onChange={v => form.setData('generic_endpoint', v)} error={form.errors.generic_endpoint} placeholder="https://provider.example/api" />
            <Field label="Replace generic bearer token (optional)" value={form.data.generic_token} onChange={v => form.setData('generic_token', v)} error={form.errors.generic_token} type="password" placeholder={provider.genericTokenConfigured ? 'Saved token is hidden — enter only to replace' : 'Enter provider token'} />
          </> : <>
            <label className="block space-y-2"><span className="text-sm font-bold">VTpass environment</span><select value={form.data.vtpass_base_url} onChange={e => form.setData('vtpass_base_url', e.target.value)} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-sm"><option value="https://sandbox.vtpass.com/api">Sandbox (recommended)</option><option value="https://vtpass.com/api">Live</option></select>{form.errors.vtpass_base_url && <span className="text-sm text-red-600">{form.errors.vtpass_base_url}</span>}</label>
            <Field label="Replace VTpass API key (optional)" value={form.data.vtpass_api_key} onChange={v => form.setData('vtpass_api_key', v)} error={form.errors.vtpass_api_key} type="password" placeholder={provider.vtpassApiKeyConfigured ? 'Saved key is hidden — enter only to replace' : 'Enter API key'} />
            <Field label="Replace VTpass secret key (optional)" value={form.data.vtpass_secret_key} onChange={v => form.setData('vtpass_secret_key', v)} error={form.errors.vtpass_secret_key} type="password" placeholder={provider.vtpassSecretKeyConfigured ? 'Saved key is hidden — enter only to replace' : 'Enter secret key'} />
          </>}
          {form.data.vtpass_base_url === 'https://vtpass.com/api' && <label className="flex items-start gap-3 rounded-xl border border-amber-500/40 p-4 text-sm"><input type="checkbox" checked={form.data.confirm_live} onChange={e => form.setData('confirm_live', e.target.checked)} className="mt-1" /><span><strong>Confirm live VTpass mode.</strong><br />I understand that this selects the live VTpass API.</span></label>}
          {form.errors.confirm_live && <p className="text-sm text-red-600">{form.errors.confirm_live}</p>}
          {form.hasErrors && <p className="text-sm text-red-600">Please correct the highlighted fields before saving.</p>}
          <button disabled={form.processing} className="min-h-11 rounded-xl bg-[var(--brand)] px-5 py-3 text-sm font-bold text-white disabled:opacity-60">{form.processing ? 'Saving…' : 'Save provider settings'}</button>
          {form.recentlySuccessful && <p className="text-sm font-semibold text-emerald-600">Settings saved.</p>}
          <p className="text-xs leading-5 text-[var(--muted)]">Catalogue preview command: <code>{provider.catalogCommand}</code>. Review provider product mappings before enabling sales.</p>
        </form>
      </main>
    </>
  );
}

function Field({ label, value, onChange, error, placeholder, type = 'text' }: { label: string; value: string; onChange: (v: string) => void; error?: string; placeholder?: string; type?: string }) {
  return <label className="block space-y-2"><span className="text-sm font-bold">{label}</span><input type={type} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2 text-sm" />{error && <span className="block text-sm text-red-600">{error}</span>}</label>;
}
function Status({ title, value, detail }: { title: string; value: string; detail: string }) {
  return <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><p className="text-xs font-bold text-[var(--muted)]">{title}</p><h2 className="mt-3 break-words font-display text-xl font-extrabold">{value}</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">{detail}</p></article>;
}
