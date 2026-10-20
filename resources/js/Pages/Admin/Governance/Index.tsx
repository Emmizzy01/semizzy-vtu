import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import DashboardLayout from '../../../Components/DashboardLayout';

type PrivacyItem = { id: number; user: string; email: string | null; type: string; reason: string | null; status: string; reviewNote: string | null; createdAt: string };
type Policy = { id: number; category: string; days: number; enabled: boolean; requiresLegalReview: boolean; notes: string | null };
type Incident = { id: number; reference: string; title: string; description: string; severity: string; status: string; category: string; reporter: string; assignee: string | null; resolutionNotes: string | null; createdAt: string };

export default function Governance({ privacyRequests, retentionPolicies, incidents }: { privacyRequests: PrivacyItem[]; retentionPolicies: Policy[]; incidents: Incident[] }) {
  const incidentForm = useForm({ title: '', description: '', severity: 'medium', category: 'account_security' });
  const reviewForm = useForm({ status: 'in_review', review_note: '' });
  const policyForm = useForm({ retention_days: 365, enabled: false, requires_legal_review: true, notes: '', reason: '' });
  const incidentUpdate = useForm({ status: 'investigating', resolution_notes: '' });

  function createIncident(event: FormEvent) {
    event.preventDefault();
    incidentForm.post('/admin/security-incidents', { preserveScroll: true, onSuccess: () => incidentForm.reset('title', 'description') });
  }
  function review(item: PrivacyItem) {
    const status = window.prompt('New status: in_review, approved, rejected, fulfilled', item.status);
    if (!status || !['in_review', 'approved', 'rejected', 'fulfilled'].includes(status)) return;
    const note = window.prompt('Required review note (at least 8 characters)');
    if (!note || note.trim().length < 8) return;
    reviewForm.transform(() => ({ status, review_note: note.trim() })).put(`/admin/privacy-requests/${item.id}`, { preserveScroll: true });
  }
  function editPolicy(policy: Policy) {
    const days = window.prompt('Retention period in days (configuration only; no automatic deletion)', String(policy.days));
    if (!days || !/^\d+$/.test(days) || Number(days) < 1 || Number(days) > 36500) return;
    const reason = window.prompt('Reason for this policy change (at least 8 characters)');
    if (!reason || reason.trim().length < 8) return;
    policyForm.transform(() => ({ retention_days: Number(days), enabled: false, requires_legal_review: true, notes: policy.notes ?? '', reason: reason.trim() }))
      .put(`/admin/retention-policies/${policy.id}`, { preserveScroll: true });
  }
  function changeIncident(incident: Incident) {
    const status = window.prompt('Status: open, investigating, contained, resolved, closed', incident.status);
    if (!status || !['open', 'investigating', 'contained', 'resolved', 'closed'].includes(status)) return;
    const notes = window.prompt('Required incident timeline / resolution note');
    if (!notes || notes.trim().length < 8) return;
    incidentUpdate.transform(() => ({ status, resolution_notes: notes.trim() }))
      .put(`/admin/security-incidents/${incident.id}`, { preserveScroll: true });
  }

  return <>
    <Head title="Privacy, retention and incidents" />
    <DashboardLayout title="Privacy & security governance" description="Review data requests, maintain retention policy records and track security incidents." admin>
      <div className="space-y-8">
        <section className="space-y-3">
          <div><h2 className="text-lg font-bold">Privacy requests</h2><p className="text-sm text-slate-500">Deletion and correction are not automatically executed. Review and record the approved fulfilment process.</p></div>
          {privacyRequests.length === 0 ? <p className="rounded-xl border border-dashed p-5 text-sm text-slate-500">No requests awaiting review.</p> : privacyRequests.map(item => <article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap justify-between gap-2"><div><h3 className="font-semibold">#{item.id} · {item.type.replaceAll('_', ' ')}</h3><p className="text-sm text-slate-500">{item.user} · {item.email ?? 'No email'} · {new Date(item.createdAt).toLocaleString()}</p></div><span className="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">{item.status.replaceAll('_', ' ')}</span></div>
            {item.reason && <p className="mt-3 whitespace-pre-wrap text-sm">{item.reason}</p>}{item.reviewNote && <p className="mt-2 text-sm text-slate-500">Latest note: {item.reviewNote}</p>}
            {!['fulfilled', 'rejected'].includes(item.status) && <button onClick={() => review(item)} disabled={reviewForm.processing} className="mt-3 rounded-xl border px-3 py-2 text-sm font-semibold dark:border-slate-700">Review request</button>}
          </article>)}
        </section>

        <section className="space-y-3">
          <div><h2 className="text-lg font-bold">Data retention policies</h2><p className="text-sm text-slate-500">Configuration and audit only. Automatic deletion is deliberately disabled until legal review, record-specific holds and a tested retention executor are approved.</p></div>
          {retentionPolicies.map(policy => <article key={policy.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div><h3 className="font-semibold">{policy.category.replaceAll('_', ' ')}</h3><p className="text-sm text-slate-500">{policy.days} days · automation disabled · legal review required</p>{policy.notes && <p className="mt-1 text-xs text-slate-500">{policy.notes}</p>}</div>
            <button onClick={() => editPolicy(policy)} disabled={policyForm.processing} className="rounded-xl border px-3 py-2 text-sm font-semibold dark:border-slate-700">Edit period</button>
          </article>)}
        </section>

        <section className="space-y-4">
          <div><h2 className="text-lg font-bold">Report a security incident</h2><p className="text-sm text-slate-500">Do not include passwords, OTPs, PINs, API keys or unnecessary personal data in incident descriptions.</p></div>
          <form onSubmit={createIncident} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-2">
            <label className="text-sm font-medium md:col-span-2">Title<input required maxLength={180} value={incidentForm.data.title} onChange={e => incidentForm.setData('title', e.target.value)} className="mt-1 block w-full rounded-xl border bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            <label className="text-sm font-medium">Severity<select value={incidentForm.data.severity} onChange={e => incidentForm.setData('severity', e.target.value)} className="mt-1 block w-full rounded-xl border bg-transparent px-3 py-2 dark:border-slate-700">{['low','medium','high','critical'].map(v => <option key={v}>{v}</option>)}</select></label>
            <label className="text-sm font-medium">Category<select value={incidentForm.data.category} onChange={e => incidentForm.setData('category', e.target.value)} className="mt-1 block w-full rounded-xl border bg-transparent px-3 py-2 dark:border-slate-700">{['privacy','account_security','financial','provider','availability','other'].map(v => <option key={v}>{v}</option>)}</select></label>
            <label className="text-sm font-medium md:col-span-2">Description<textarea required minLength={10} maxLength={10000} rows={3} value={incidentForm.data.description} onChange={e => incidentForm.setData('description', e.target.value)} className="mt-1 block w-full rounded-xl border bg-transparent px-3 py-2 dark:border-slate-700"/></label>
            {Object.values(incidentForm.errors).length > 0 && <p className="text-sm text-red-600 md:col-span-2">{Object.values(incidentForm.errors).join(' ')}</p>}
            <div className="md:col-span-2"><button disabled={incidentForm.processing} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white">Record incident</button></div>
          </form>
          <div className="space-y-3"><h2 className="text-lg font-bold">Incident register ({incidents.length})</h2>
            {incidents.map(item => <article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
              <div className="flex flex-wrap justify-between gap-2"><div><h3 className="font-semibold">{item.reference} · {item.title}</h3><p className="text-sm text-slate-500">{item.category.replaceAll('_', ' ')} · {item.severity} · reported by {item.reporter}</p></div><span className="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">{item.status}</span></div>
              <p className="mt-3 whitespace-pre-wrap text-sm">{item.description}</p>{item.resolutionNotes && <p className="mt-2 text-sm text-slate-500">Latest note: {item.resolutionNotes}</p>}
              {item.status !== 'closed' && <button onClick={() => changeIncident(item)} disabled={incidentUpdate.processing} className="mt-3 rounded-xl border px-3 py-2 text-sm font-semibold dark:border-slate-700">Update incident</button>}
            </article>)}
          </div>
        </section>
      </div>
    </DashboardLayout>
  </>;
}
