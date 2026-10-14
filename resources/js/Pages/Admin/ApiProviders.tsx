import { ArrowLeft, CheckCircle2, CircleAlert, PlugZap, ShieldCheck } from 'lucide-react';
import { Head } from '@inertiajs/react';

type Provider = {
  driver: string;
  name: string;
  genericEndpointConfigured: boolean;
  genericTokenConfigured: boolean;
  vtpassSelected: boolean;
  vtpassBaseUrl: string | null;
  vtpassApiKeyConfigured: boolean;
  vtpassSecretKeyConfigured: boolean;
  mode: string;
  catalogCommand: string;
};

export default function ApiProviders({ provider }: { provider: Provider }) {
  const selectedReady = provider.vtpassSelected
    ? Boolean(provider.vtpassBaseUrl && provider.vtpassApiKeyConfigured && provider.vtpassSecretKeyConfigured)
    : Boolean(provider.genericEndpointConfigured && provider.genericTokenConfigured);

  return (
    <>
      <Head title="API Providers" />
      <main className="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
        <a href="/admin/dashboard" className="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-[var(--muted)] hover:text-[var(--brand)]"><ArrowLeft size={16} /> Admin overview</a>
        <div className="mt-5 flex items-start gap-4">
          <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><PlugZap size={26} /></span>
          <div><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">VTU integrations</p><h1 className="mt-1 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">API providers</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">Check which VTU provider is selected and whether its required server-side settings are present.</p></div>
        </div>
        <section className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <StatusCard title="Selected adapter" value={provider.driver} detail={`Configured provider name: ${provider.name}`} ok />
          <StatusCard title="Selected adapter credentials" value={selectedReady ? 'Configuration present' : 'Needs configuration'} detail={provider.vtpassSelected ? 'VTpass base URL and both credential fields must be present.' : 'The generic adapter needs its endpoint and bearer token.'} ok={selectedReady} />
          <StatusCard title="VTpass environment" value={provider.mode} detail={provider.vtpassBaseUrl ?? 'Only the official sandbox or live API URL is recognised.'} ok={provider.mode === 'Sandbox' || provider.mode === 'Live'} />
          <StatusCard title="VTpass API key" value={provider.vtpassApiKeyConfigured ? 'Configured' : 'Missing'} detail="Credential value is hidden from the browser." ok={provider.vtpassApiKeyConfigured} />
          <StatusCard title="VTpass secret key" value={provider.vtpassSecretKeyConfigured ? 'Configured' : 'Missing'} detail="Credential value is hidden from the browser." ok={provider.vtpassSecretKeyConfigured} />
          <StatusCard title="Generic endpoint / token" value={provider.genericEndpointConfigured && provider.genericTokenConfigured ? 'Configured' : 'Not fully configured'} detail="Used only when the generic adapter is selected." ok={provider.genericEndpointConfigured && provider.genericTokenConfigured} />
        </section>
        <section className="mt-7 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
          <div className="flex items-start gap-3"><ShieldCheck className="mt-1 shrink-0 text-[var(--brand)]" size={22} /><div><h2 className="font-display text-lg font-extrabold">Provider setup and release checks</h2><ol className="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-[var(--muted)]"><li>Set VTU_PROVIDER_DRIVER=vtpass to select VTpass, or keep the generic adapter if that is the intended integration.</li><li>For initial testing, use VTU_VTPASS_BASE_URL=https://sandbox.vtpass.com/api with sandbox API and secret keys.</li><li>Verify the provider catalogue, variation codes, product prices, and provider account balance before enabling products.</li><li>Preview the catalogue with <code className="rounded bg-[var(--brand-soft)] px-1.5 py-0.5">{provider.catalogCommand}</code>. Apply it only after review; the command's apply option changes catalogue records.</li><li>Test success, failure, pending, and timeout reconciliation in sandbox before switching to live credentials.</li></ol></div></div>
          <p className="mt-5 rounded-xl border border-[var(--line)] p-4 text-sm leading-6 text-[var(--muted)]">This page is a read-only provider monitor, not a credential editor. It does not submit purchases, change environment variables, or switch the live provider.</p>
        </section>
      </main>
    </>
  );
}

function StatusCard({ title, value, detail, ok }: { title: string; value: string; detail: string; ok: boolean }) {
  return <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><div className="flex items-center justify-between gap-3"><p className="text-xs font-bold text-[var(--muted)]">{title}</p>{ok ? <CheckCircle2 size={18} className="text-emerald-600" /> : <CircleAlert size={18} className="text-amber-600" />}</div><h2 className="mt-4 break-words font-display text-xl font-extrabold">{value}</h2><p className="mt-2 text-sm leading-6 text-[var(--muted)]">{detail}</p></article>;
}
