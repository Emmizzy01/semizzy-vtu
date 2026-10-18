import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';
import { ArrowLeft, CircleCheck, CircleX, Download, LockKeyhole, PlugZap, ShieldCheck } from 'lucide-react';

type Provider = {
  key: string;
  name: string;
  configured: boolean;
  environment: string;
  baseHost: string | null;
  baseUrl: string;
  allowedBaseUrls: string[];
  apiKeyConfigured: boolean;
  catalogueImportSupported: boolean;
  purchaseRoutingEnabled: boolean;
  purchaseHold: boolean;
};

const categories = [{ value: 'data', label: 'Data plans' }, { value: 'airtime', label: 'Airtime denominations' }];

export default function ApiProviders({ providers, failoverEnabled, routingEnabled, priority }: {
  providers: Provider[];
  failoverEnabled: boolean;
  routingEnabled: boolean;
  priority: string[];
}) {
  const [category, setCategory] = useState<Record<string, string>>({ sogo: 'data', gsubz: 'data', dataway: 'data' });
  const [reason, setReason] = useState<Record<string, string>>({});
  const [credentials, setCredentials] = useState<Record<string, { base_url: string; api_key: string; reason: string }>>(() => Object.fromEntries(providers.filter(p => p.catalogueImportSupported).map(p => [p.key, { base_url: p.baseUrl || p.allowedBaseUrls[0] || '', api_key: '', reason: '' }])));
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');

  const saveCredentials = (provider: Provider) => {
    const draft = credentials[provider.key] || { base_url: provider.allowedBaseUrls[0] || '', api_key: '', reason: '' };
    if (draft.reason.trim().length < 8) {
      setError('Enter an audit reason of at least 8 characters before saving provider settings.');
      return;
    }
    setError('');
    setBusy(provider.key + ':credentials');
    router.put('/admin/api-providers/' + provider.key + '/credentials', draft, {
      preserveScroll: true,
      onFinish: () => setBusy(''),
      onSuccess: () => setCredentials(old => ({ ...old, [provider.key]: { ...draft, api_key: '' } })),
    });
  };

  const importCatalogue = (provider: Provider) => {
    if ((reason[provider.key] || '').trim().length < 8) {
      setError('Enter an audit reason of at least 8 characters before importing.');
      return;
    }
    setError('');
    setBusy(provider.key);
    router.post('/admin/provider-catalogue/' + provider.key + '/import', {
      category: category[provider.key] || 'data',
      reason: reason[provider.key],
    }, { preserveScroll: true, onFinish: () => setBusy('') });
  };

  return (
    <DashboardLayout title="API providers" description="Manage provider readiness and import provider catalogues." admin>
      <Head title="API Providers" />
      <main className="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
        <a href="/admin/dashboard" className="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-[var(--muted)] hover:text-[var(--brand)]"><ArrowLeft size={16} /> Admin overview</a>
        <section className="mt-6 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-8">
          <div className="flex items-start gap-4">
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]"><PlugZap size={24} /></span>
            <div className="min-w-0">
              <p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">Provider integrations</p>
              <h1 className="mt-2 font-display text-2xl font-extrabold sm:text-3xl">API providers & catalogue imports</h1>
              <p className="mt-3 max-w-3xl text-sm leading-6 text-[var(--muted)]">Provider credentials remain server-side. Importing updates the catalogue only; new products remain inactive and are not eligible for customer purchases until separately reviewed and approved.</p>
            </div>
          </div>
          <div className="mt-6 grid gap-3 sm:grid-cols-3">
            <Summary title="Provider routing" value={routingEnabled ? 'Enabled' : 'Disabled'} detail="Initial provider selection" />
            <Summary title="Automatic failover" value={failoverEnabled ? 'Enabled' : 'Disabled'} detail="Post-submit cross-provider retry" />
            <Summary title="Priority order" value={priority.join(' → ').toUpperCase()} detail="Used only when routing is enabled" />
          </div>
          {!failoverEnabled && <p className="mt-4 flex items-center gap-2 text-sm font-semibold text-emerald-700 dark:text-emerald-400"><ShieldCheck size={18} /> Automatic failover is disabled as requested.</p>}
        </section>

        {error && <p role="alert" className="mt-4 rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{error}</p>}

        <section className="mt-6 grid gap-4 lg:grid-cols-2">
          {providers.map((provider) => (
            <article key={provider.key} className="rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                  <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><PlugZap size={22} /></span>
                  <div><h2 className="font-display text-xl font-extrabold">{provider.name}</h2><p className="mt-1 text-xs text-[var(--muted)]">{provider.baseHost || 'Endpoint not configured'} · {provider.environment}</p></div>
                </div>
                <span className={'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold ' + (provider.configured ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300')}>
                  {provider.configured ? <CircleCheck size={14} /> : <CircleX size={14} />}{provider.configured ? 'Configured' : 'Credentials needed'}
                </span>
              </div>
              <div className="mt-5 grid gap-2 sm:grid-cols-2">
                <Status label="Catalogue import" value={provider.catalogueImportSupported ? 'Available' : 'Held'} />
                <Status label="Customer purchase routing" value={provider.purchaseRoutingEnabled ? 'Enabled' : 'Not enabled'} />
              </div>
              {provider.catalogueImportSupported && (
                <div className="mt-5 space-y-3 rounded-2xl border border-[var(--line)] p-4">
                  <div className="flex items-center gap-2 text-sm font-bold"><LockKeyhole size={17} /> Secure provider credentials</div>
                  <label className="block text-xs font-semibold text-[var(--muted)]">Official HTTPS endpoint
                    <select value={(credentials[provider.key]?.base_url || provider.baseUrl || provider.allowedBaseUrls[0])} onChange={e => setCredentials(old => ({ ...old, [provider.key]: { ...(old[provider.key] || { base_url: '', api_key: '', reason: '' }), base_url: e.target.value } }))} className="mt-1 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2.5 text-sm text-[var(--foreground)]">
                      {provider.allowedBaseUrls.map(url => <option key={url} value={url}>{url}</option>)}
                    </select>
                  </label>
                  <label className="block text-xs font-semibold text-[var(--muted)]">API key / secret
                    <input type="password" autoComplete="new-password" value={credentials[provider.key]?.api_key || ''} onChange={e => setCredentials(old => ({ ...old, [provider.key]: { ...(old[provider.key] || { base_url: provider.baseUrl, api_key: '', reason: '' }), api_key: e.target.value } }))} placeholder={provider.apiKeyConfigured ? 'Key already saved — leave blank to keep it' : 'Enter provider API key'} className="mt-1 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2.5 text-sm text-[var(--foreground)]" />
                  </label>
                  <label className="block text-xs font-semibold text-[var(--muted)]">Audit reason
                    <input value={credentials[provider.key]?.reason || ''} onChange={e => setCredentials(old => ({ ...old, [provider.key]: { ...(old[provider.key] || { base_url: provider.baseUrl, api_key: '', reason: '' }), reason: e.target.value } }))} placeholder="Why are you updating this provider?" className="mt-1 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2.5 text-sm text-[var(--foreground)]" />
                  </label>
                  <button type="button" disabled={busy !== ''} onClick={() => saveCredentials(provider)} className="inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-xl border border-[var(--line)] px-4 py-2 text-sm font-bold disabled:opacity-50">
                    <LockKeyhole size={15} />{busy === provider.key + ':credentials' ? 'Saving securely…' : 'Save provider credentials'}
                  </button>
                  <p className="text-xs leading-5 text-[var(--muted)]">Secrets are stored server-side and never returned to this page. Saving credentials enables catalogue reads only; it does not enable customer purchases or automatic failover.</p>
                </div>
              )}
              {provider.catalogueImportSupported && (
                <div className="mt-5 space-y-3 rounded-2xl border border-[var(--line)] p-4">
                  <div className="flex items-center gap-2 text-sm font-bold"><Download size={17} /> Import services / products</div>
                  <label className="block text-xs font-semibold text-[var(--muted)]">Category
                    <select value={category[provider.key] || 'data'} onChange={e => setCategory(old => ({ ...old, [provider.key]: e.target.value }))} className="mt-1 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2.5 text-sm text-[var(--foreground)]">
                      {categories.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>
                  </label>
                  <label className="block text-xs font-semibold text-[var(--muted)]">Audit reason
                    <input value={reason[provider.key] || ''} onChange={e => setReason(old => ({ ...old, [provider.key]: e.target.value }))} placeholder="e.g. Import current data plans for pricing review" className="mt-1 w-full rounded-xl border border-[var(--line)] bg-transparent px-3 py-2.5 text-sm text-[var(--foreground)]" />
                  </label>
                  <button type="button" disabled={!provider.configured || busy !== ''} onClick={() => importCatalogue(provider)} className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-2.5 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">
                    <Download size={16} />{busy === provider.key ? 'Importing…' : 'Import ' + (category[provider.key] || 'data').toUpperCase() + ' catalogue'}
                  </button>
                  {!provider.configured && <p className="text-xs leading-5 text-amber-700 dark:text-amber-300">Add this provider's API key to the server environment before importing. No secret key is shown here.</p>}
                </div>
              )}
              {provider.purchaseHold && <p className="mt-4 flex items-start gap-2 text-xs leading-5 text-[var(--muted)]"><LockKeyhole size={15} className="mt-0.5 shrink-0" />{provider.key === 'vtpass' ? 'VTpass remains isolated pending the existing recovery review.' : 'Dataway remains excluded from automatic routing until its account-specific transaction lookup contract is verified.'}</p>}
            </article>
          ))}
        </section>
      </main>
    </DashboardLayout>
  );
}

function Summary({ title, value, detail }: { title: string; value: string; detail: string }) {
  return <div className="rounded-2xl border border-[var(--line)] p-4"><p className="text-xs font-bold text-[var(--muted)]">{title}</p><p className="mt-2 break-words font-display text-lg font-extrabold">{value}</p><p className="mt-1 text-xs text-[var(--muted)]">{detail}</p></div>;
}

function Status({ label, value }: { label: string; value: string }) {
  return <div className="rounded-xl bg-[var(--surface-muted)] p-3"><p className="text-xs text-[var(--muted)]">{label}</p><p className="mt-1 text-sm font-bold">{value}</p></div>;
}
