import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';

type Addon = {
  id: number; key: string; name: string; version: string; minimumCoreVersion: string;
  description: string | null; dependencies: string[]; status: string; isCoreOwned: boolean;
  compatibilityStatus: string; compatibilityMessage: string | null;
};

export default function PlatformAddons({ coreVersion, addons }: { coreVersion: string; addons: Addon[] }) {
  const form = useForm({
    addon_key: '', name: '', version: '1.0.0', minimum_core_version: coreVersion,
    description: '', dependencies: '',
  });
  const toggle = useForm({ enabled: false, reason: '' });

  function register(event: FormEvent) {
    event.preventDefault();
    form.transform(data => ({
      ...data,
      dependencies: data.dependencies.split(',').map(value => value.trim()).filter(Boolean),
    })).post('/admin/addons', { preserveScroll: true, onSuccess: () => form.reset('addon_key', 'name', 'description', 'dependencies') });
  }

  function changeStatus(addon: Addon, enabled: boolean) {
    const reason = window.prompt(enabled ? 'Why are you enabling this registry entry?' : 'Why are you disabling this registry entry?');
    if (!reason || reason.trim().length < 8) return;
    toggle.setData({ enabled, reason: reason.trim() });
    toggle.transform(() => ({ enabled, reason: reason.trim() })).put(`/admin/addons/${addon.id}/status`, { preserveScroll: true });
  }

  return <>
    <Head title="Add-on registry" />
    <DashboardLayout title="Add-on registry" description="Register manifests and validate dependencies and core compatibility." admin>
      <div className="space-y-6">
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div><h2 className="text-lg font-bold">Core compatibility</h2><p className="mt-1 text-sm text-slate-500">Current configured core version: {coreVersion}</p></div>
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold dark:bg-slate-800">Registry only</span>
          </div>
          <p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">This screen records add-on manifests and dependency state. It does not upload, install, or execute third-party code, and it never creates separate auth, wallet, pricing, or settings systems.</p>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-lg font-bold">Register add-on manifest</h2>
          <form onSubmit={register} className="mt-4 grid gap-4 md:grid-cols-2">
            <label className="text-sm font-medium">Add-on key<input required maxLength={100} value={form.data.addon_key} onChange={e => form.setData('addon_key', e.target.value)} placeholder="service.example" className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            <label className="text-sm font-medium">Display name<input required maxLength={160} value={form.data.name} onChange={e => form.setData('name', e.target.value)} placeholder="Example service" className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            <label className="text-sm font-medium">Manifest version<input required value={form.data.version} onChange={e => form.setData('version', e.target.value)} className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            <label className="text-sm font-medium">Minimum core version<input required value={form.data.minimum_core_version} onChange={e => form.setData('minimum_core_version', e.target.value)} className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            <label className="text-sm font-medium md:col-span-2">Dependencies (comma-separated add-on keys)<input value={form.data.dependencies} onChange={e => form.setData('dependencies', e.target.value)} placeholder="service.catalog, integration.provider" className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            <label className="text-sm font-medium md:col-span-2">Description<textarea rows={3} maxLength={2000} value={form.data.description} onChange={e => form.setData('description', e.target.value)} className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            {Object.values(form.errors).length > 0 && <p className="text-sm text-red-600 md:col-span-2">{Object.values(form.errors).join(' ')}</p>}
            <div className="md:col-span-2"><button disabled={form.processing} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Saving…' : 'Save manifest'}</button></div>
          </form>
        </section>

        <section className="space-y-3">
          <h2 className="text-lg font-bold">Registered add-ons ({addons.length})</h2>
          {addons.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">No add-on manifests are registered yet.</div> : addons.map(addon => <article key={addon.id} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div><h3 className="font-bold">{addon.name}</h3><p className="mt-1 font-mono text-xs text-slate-500">{addon.key} · v{addon.version}</p></div>
              <div className="flex flex-wrap gap-2"><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold dark:bg-slate-800">{addon.status}</span><span className={`rounded-full px-3 py-1 text-xs font-semibold ${addon.compatibilityStatus === 'compatible' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>{addon.compatibilityStatus}</span></div>
            </div>
            {addon.description && <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">{addon.description}</p>}
            <p className="mt-3 text-sm text-slate-500">Requires core ≥ {addon.minimumCoreVersion}. Dependencies: {addon.dependencies.length ? addon.dependencies.join(', ') : 'none'}</p>
            {addon.compatibilityMessage && <p className="mt-2 text-sm">{addon.compatibilityMessage}</p>}
            {!addon.isCoreOwned && <div className="mt-4"><button onClick={() => changeStatus(addon, addon.status !== 'enabled')} disabled={toggle.processing} className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold dark:border-slate-700">{addon.status === 'enabled' ? 'Disable registry entry' : 'Enable registry entry'}</button></div>}
          </article>)}
        </section>
      </div>
    </DashboardLayout>
  </>;
}
