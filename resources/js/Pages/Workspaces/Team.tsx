import { useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { ArrowLeft, MailPlus, ShieldCheck, UserRound, UserMinus } from 'lucide-react';

type Member = {
  id: number;
  name: string | null;
  email: string | null;
  role: string;
  status: string;
  permissions: string[];
  canManage: boolean;
};

export default function WorkspaceTeam({ workspace, members, canManage, isOwner, availablePermissions }: {
  workspace: { id: number; name: string; type: string };
  members: Member[];
  canManage: boolean;
  isOwner: boolean;
  availablePermissions: string[];
}) {
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<'agent' | 'manager' | 'admin'>('agent');
  const [permissions, setPermissions] = useState<string[]>(['orders.view']);
  const [busy, setBusy] = useState(false);

  const invite = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    router.post(`/workspaces/${workspace.id}/team/invitations`, { email, role, permissions }, {
      preserveScroll: true,
      onSuccess: () => setEmail(''),
      onFinish: () => setBusy(false),
    });
  };

  const update = (member: Member, nextRole: string, nextPermissions: string[]) => {
    router.put(`/workspaces/${workspace.id}/team/${member.id}`, { role: nextRole, permissions: nextPermissions }, { preserveScroll: true });
  };

  const remove = (member: Member) => {
    router.delete(`/workspaces/${workspace.id}/team/${member.id}`, { preserveScroll: true });
  };

  const togglePermission = (permission: string) => setPermissions(current =>
    current.includes(permission) ? current.filter(item => item !== permission) : [...current, permission]
  );

  return <DashboardLayout title="Team & agents" description={`Manage membership and access for ${workspace.name}.`}>
    <Head title={`Team · ${workspace.name} · Semizzy`} />
    <div className="mx-auto max-w-5xl space-y-6">
      <Link href="/workspaces" className="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-300"><ArrowLeft size={16}/> All workspaces</Link>

      {canManage && <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 sm:p-6">
        <div className="mb-5 flex items-start gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><MailPlus size={22}/></span><div><h2 className="font-semibold">Invite an existing Semizzy user</h2><p className="mt-1 text-sm text-slate-500">Invitations expire after 7 days. The invited account must sign in to accept.</p></div></div>
        <form onSubmit={invite} className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-[1fr_220px]">
            <label className="block text-sm font-medium">Account email<input type="email" required maxLength={255} value={email} onChange={event => setEmail(event.target.value)} placeholder="agent@example.com" className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700"/></label>
            <label className="block text-sm font-medium">Workspace role<select value={role} onChange={event => setRole(event.target.value as typeof role)} className="mt-1 w-full rounded-xl border border-slate-300 bg-transparent p-3 dark:border-slate-700"><option value="agent">Agent</option>{isOwner && <><option value="manager">Manager</option><option value="admin">Admin</option></>}</select></label>
          </div>
          <div><p className="mb-2 text-sm font-medium">Agent permissions</p><div className="grid gap-2 sm:grid-cols-2">{availablePermissions.map(permission => <label key={permission} className="flex items-center gap-2 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-800"><input type="checkbox" checked={permissions.includes(permission)} onChange={() => togglePermission(permission)} />{permission}</label>)}</div></div>
          <button disabled={busy || !email.trim()} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50"><MailPlus size={17}/>{busy ? 'Sending…' : 'Send invitation'}</button>
        </form>
      </section>}

      <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
        <div className="flex items-center justify-between border-b border-slate-100 p-5 dark:border-slate-800"><div><h2 className="font-semibold">Workspace members</h2><p className="mt-1 text-sm text-slate-500">{members.length} invitation(s) and membership record(s)</p></div><ShieldCheck className="text-emerald-700 dark:text-emerald-300" size={22}/></div>
        {members.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">No team members yet. Invite a colleague to get started.</p> :
          <div className="divide-y divide-slate-100 dark:divide-slate-800">{members.map(member => <article key={member.id} className="space-y-3 p-5">
            <div className="flex items-start gap-3"><span className="rounded-xl bg-slate-100 p-3 dark:bg-slate-900"><UserRound size={20}/></span><div className="min-w-0 flex-1"><p className="font-medium">{member.name || 'Semizzy user'}</p><p className="break-all text-sm text-slate-500">{member.email}</p><div className="mt-2 flex flex-wrap gap-2"><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs dark:bg-slate-900">{member.role}</span><span className={`rounded-full px-2.5 py-1 text-xs ${member.status === 'active' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200'}`}>{member.status}</span></div></div></div>
            {member.status === 'active' && <div className="flex flex-wrap gap-2 text-xs text-slate-500">{member.permissions.map(permission => <span key={permission} className="rounded-md border border-slate-200 px-2 py-1 dark:border-slate-800">{permission}</span>)}</div>}
            {canManage && member.canManage && <div className="flex flex-col gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-900 sm:flex-row sm:items-end">
              <label className="text-sm font-medium">Role<select defaultValue={member.role} onChange={event => update(member, event.target.value, member.permissions)} className="mt-1 block w-full rounded-lg border border-slate-300 bg-transparent p-2 dark:border-slate-700"><option value="agent">Agent</option>{isOwner && <><option value="manager">Manager</option><option value="admin">Admin</option></>}</select></label>
              <div className="flex-1"><p className="mb-2 text-sm font-medium">Permissions</p><div className="flex flex-wrap gap-2">{availablePermissions.map(permission => <label key={permission} className="flex items-center gap-1.5 text-xs"><input type="checkbox" checked={member.permissions.includes(permission)} onChange={event => update(member, member.role, event.target.checked ? [...member.permissions, permission] : member.permissions.filter(item => item !== permission))}/>{permission}</label>)}</div></div>
              <button type="button" onClick={() => remove(member)} className="inline-flex items-center gap-2 self-start rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:text-red-300"><UserMinus size={15}/> Remove</button>
            </div>}
          </article>)}</div>}
      </section>
    </div>
  </DashboardLayout>;
}
