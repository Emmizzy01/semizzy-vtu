import { useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { ArrowLeft, Eye, Globe, Save, Store } from 'lucide-react';

type Storefront = {
  id: number;
  slug: string;
  displayName: string;
  tagline: string | null;
  description: string | null;
  status: 'draft' | 'published';
  enabledServices: string[];
  accentColor: string;
  publicUrl: string;
};

export default function StorefrontEdit({ workspace, storefront, availableServices }: {
  workspace: { id: number; name: string };
  storefront: Storefront;
  availableServices: string[];
}) {
  const [slug, setSlug] = useState(storefront.slug);
  const [displayName, setDisplayName] = useState(storefront.displayName);
  const [tagline, setTagline] = useState(storefront.tagline || '');
  const [description, setDescription] = useState(storefront.description || '');
  const [status, setStatus] = useState<'draft' | 'published'>(storefront.status);
  const [enabledServices, setEnabledServices] = useState(storefront.enabledServices);
  const [accentColor, setAccentColor] = useState(storefront.accentColor || '#087443');
  const [busy, setBusy] = useState(false);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    router.put(`/workspaces/${workspace.id}/storefront`, {
      slug: slug.trim().toLowerCase(),
      display_name: displayName,
      tagline: tagline || null,
      description: description || null,
      status,
      enabled_services: enabledServices,
      accent_color: accentColor,
    }, { preserveScroll: true, onFinish: () => setBusy(false) });
  };

  const toggleService = (service: string) => setEnabledServices(current =>
    current.includes(service) ? current.filter(item => item !== service) : [...current, service]
  );

  return <DashboardLayout title="Reseller storefront" description={`Configure the public mini-store for ${workspace.name}.`}>
    <Head title={`Storefront settings · ${workspace.name} · Semizzy`} />
    <div className="mx-auto max-w-4xl space-y-5">
      <Link href={`/workspaces/${workspace.id}/team`} className="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-300"><ArrowLeft size={16}/> Team & workspace</Link>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 sm:p-7">
        <div className="mb-6 flex items-start gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><Store size={23}/></span><div><h2 className="font-semibold">Store identity</h2><p className="mt-1 text-sm text-slate-500">Create a public catalogue page for this reseller workspace. Customer purchases still use Semizzy's protected account, wallet and transaction-PIN flow.</p></div></div>
        <form onSubmit={submit} className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block text-sm font-medium">Store display name<input required minLength={2} maxLength={120} value={displayName} onChange={event => setDisplayName(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700"/></label>
            <label className="block text-sm font-medium">Public URL slug<div className="mt-1 flex items-center rounded-xl border border-slate-300 dark:border-slate-700"><span className="whitespace-nowrap border-r border-slate-200 px-3 py-3 text-xs text-slate-500 dark:border-slate-700">/store/</span><input required maxLength={80} pattern="[a-z0-9]+(-[a-z0-9]+)*" value={slug} onChange={event => setSlug(event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} className="min-w-0 flex-1 bg-transparent p-3 outline-none"/></div></label>
          </div>
          <label className="block text-sm font-medium">Tagline <span className="font-normal text-slate-500">(optional)</span><input maxLength={160} value={tagline} onChange={event => setTagline(event.target.value)} placeholder="Fast, reliable airtime and data" className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700"/></label>
          <label className="block text-sm font-medium">Store description <textarea maxLength={1000} rows={4} value={description} onChange={event => setDescription(event.target.value)} placeholder="Tell customers what your store offers." className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700"/></label>
          <div><p className="mb-2 text-sm font-medium">Catalogue categories</p><div className="grid gap-3 sm:grid-cols-2">{availableServices.map(service => <label key={service} className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800"><input type="checkbox" checked={enabledServices.includes(service)} onChange={() => toggleService(service)} className="mt-1"/><span><span className="block font-medium capitalize">{service}</span><span className="text-xs text-slate-500">{service === 'airtime' ? 'Flexible top-up amounts' : 'Available data bundles from the approved catalogue'}</span></span></label>)}</div></div>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block text-sm font-medium">Brand accent colour<div className="mt-1 flex items-center gap-3 rounded-xl border border-slate-300 p-2 dark:border-slate-700"><input type="color" value={accentColor} onChange={event => setAccentColor(event.target.value)} className="h-10 w-14 cursor-pointer rounded border-0 bg-transparent"/><span className="font-mono text-sm">{accentColor.toUpperCase()}</span></div></label>
            <label className="block text-sm font-medium">Store status<select value={status} onChange={event => setStatus(event.target.value as typeof status)} className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700"><option value="draft">Draft — private</option><option value="published">Published — public</option></select></label>
          </div>
          <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5 dark:border-slate-800"><button disabled={busy || !displayName.trim() || enabledServices.length === 0} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50"><Save size={17}/>{busy ? 'Saving…' : 'Save storefront'}</button>{storefront.status === 'published' && <a href={storefront.publicUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold dark:border-slate-700"><Eye size={17}/> Open published store</a>}</div>
        </form>
      </section>
      <div className="flex items-start gap-3 rounded-xl bg-slate-100 p-4 text-sm text-slate-600 dark:bg-slate-900 dark:text-slate-300"><Globe size={18} className="mt-0.5 shrink-0"/><p>Publishing requires at least one active, approved catalogue product. Draft stores remain private. Only airtime and data are available in this first storefront slice; no separate checkout or reseller commission is activated by publishing.</p></div>
    </div>
  </DashboardLayout>;
}
