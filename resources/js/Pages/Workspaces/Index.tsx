import { useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { BriefcaseBusiness, Plus, Store, Users, ArrowUpRight } from 'lucide-react';

type Workspace = {
  id: number;
  name: string;
  slug: string;
  type: 'business' | 'reseller' | string;
  owner: string | null;
  activeMembers: number;
  isOwner: boolean;
  canManage: boolean;
  canViewTeam: boolean;
  canManageStorefront: boolean;
};

export default function WorkspacesIndex({ workspaces, canCreate }: { workspaces: Workspace[]; canCreate: boolean }) {
  const [name, setName] = useState('');
  const [type, setType] = useState<'business' | 'reseller'>('business');
  const [busy, setBusy] = useState(false);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    router.post('/workspaces', { name, type }, {
      preserveScroll: true,
      onSuccess: () => setName(''),
      onFinish: () => setBusy(false),
    });
  };

  const label = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());

  return <DashboardLayout title="Business workspaces" description="Keep your business operations and reseller activity organised in separate workspaces.">
    <Head title="Business workspaces · Semizzy" />
    <div className="mx-auto max-w-5xl space-y-6">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 sm:p-6">
        <div className="mb-5 flex items-start gap-3">
          <span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><BriefcaseBusiness size={22}/></span>
          <div><h2 className="font-semibold">Create a workspace</h2><p className="mt-1 text-sm text-slate-500">You will own the workspace. Team invitations and storefront configuration will be enabled in subsequent slices.</p></div>
        </div>
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-[1fr_220px_auto] sm:items-end">
          <label className="block text-sm font-medium">Workspace name
            <input value={name} onChange={event => setName(event.target.value)} minLength={2} maxLength={120} required placeholder="e.g. Semizzy Reseller Hub" className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 outline-none focus:border-emerald-700 dark:border-slate-700"/>
          </label>
          <label className="block text-sm font-medium">Workspace type
            <select value={type} onChange={event => setType(event.target.value as 'business' | 'reseller')} className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700">
              <option value="business">Business</option><option value="reseller">Reseller</option>
            </select>
          </label>
          <button disabled={!canCreate || busy || name.trim().length < 2} className="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50"><Plus size={18}/>{busy ? 'Creating…' : 'Create workspace'}</button>
        </form>
      </section>

      <section>
        <div className="mb-3 flex items-center justify-between"><h2 className="text-lg font-semibold">Your workspaces</h2><span className="text-sm text-slate-500">{workspaces.length} active</span></div>
        {workspaces.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-950"><span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-900"><Store size={22}/></span><h3 className="font-semibold">No active workspaces yet</h3><p className="mt-1 text-sm text-slate-500">Create one above to establish a separate space for your business or reseller activity.</p></div> :
          <div className="grid gap-4 md:grid-cols-2">{workspaces.map(workspace => <article key={workspace.id} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-start justify-between gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><Store size={21}/></span><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-900 dark:text-slate-300">{label(workspace.type)}</span></div>
            <h3 className="mt-4 text-lg font-semibold">{workspace.name}</h3><p className="mt-1 break-all text-xs text-slate-500">/{workspace.slug}</p>
            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500"><span className="inline-flex items-center gap-1.5"><Users size={16}/>{workspace.activeMembers} active memberships</span><span>{workspace.isOwner ? 'Owner' : workspace.canManage ? 'Workspace manager' : 'Member'}</span></div>
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm dark:border-slate-800"><span className="text-slate-500">{workspace.canManage ? "You can manage this workspace" : "Workspace membership active"}</span><div className="flex flex-wrap gap-3">{workspace.canViewTeam && <Link href={`/workspaces/${workspace.id}/team`} className="inline-flex items-center gap-1 font-medium text-emerald-700 hover:underline dark:text-emerald-300">Team <ArrowUpRight size={16}/></Link>}{workspace.type === "reseller" && workspace.canManageStorefront && <Link href={`/workspaces/${workspace.id}/storefront`} className="inline-flex items-center gap-1 font-medium text-emerald-700 hover:underline dark:text-emerald-300">Storefront <ArrowUpRight size={16}/></Link>}</div></div>
          </article>)}</div>}
      </section>
    </div>
  </DashboardLayout>;
}
