import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { ShieldCheck, Users } from 'lucide-react';

export default function AcceptWorkspaceInvitation({ workspace, membershipId, token, role, expiresAt }: {
  workspace: { name: string; type: string };
  membershipId: number;
  token: string;
  role: string;
  expiresAt: string | null;
}) {
  const accept = () => router.post(`/workspace-invitations/${membershipId}/${encodeURIComponent(token)}`, {}, { preserveScroll: true });

  return <DashboardLayout title="Workspace invitation" description="Review and accept your workspace invitation.">
    <Head title={`Invitation · ${workspace.name} · Semizzy`} />
    <div className="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950 sm:p-8">
      <span className="mb-4 inline-flex rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><Users size={24}/></span>
      <h2 className="text-xl font-semibold">Join {workspace.name}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-500">You have been invited to join this Semizzy {workspace.type} workspace as a <strong className="text-slate-800 dark:text-slate-100">{role}</strong>. Accepting gives this account only the workspace role and permissions configured by its owner.</p>
      {expiresAt && <p className="mt-4 text-xs text-slate-500">Invitation expiry: {new Date(expiresAt).toLocaleString()}</p>}
      <button type="button" onClick={accept} className="mt-6 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white"><ShieldCheck size={18}/> Accept invitation</button>
    </div>
  </DashboardLayout>;
}
