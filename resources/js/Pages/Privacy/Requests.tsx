import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';

type PrivacyRequest = { id: number; request_type: string; reason: string | null; status: string; review_note: string | null; created_at: string };

export default function PrivacyRequests({ requests }: { requests: PrivacyRequest[] }) {
  const form = useForm({ request_type: 'access', reason: '' });
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/privacy/requests', { preserveScroll: true, onSuccess: () => form.reset('reason') });
  }
  const labels: Record<string, string> = { access: 'Access my data', export: 'Export my data', correction: 'Correct my data', deletion: 'Request account/data deletion' };
  return <>
    <Head title="Privacy and data requests" />
    <DashboardLayout title="Privacy & data requests" description="Submit and track requests about your personal information.">
      <div className="space-y-6">
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-lg font-bold">Request personal data support</h2>
          <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">Requests are reviewed by authorized staff. Submitting a deletion or correction request does not automatically change or delete account records. Financial, identity-verification and legally required records may need additional review.</p>
          <form onSubmit={submit} className="mt-4 space-y-4">
            <label className="block text-sm font-medium">Request type
              <select value={form.data.request_type} onChange={e => form.setData('request_type', e.target.value)} className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700">
                {Object.entries(labels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
            <label className="block text-sm font-medium">Details (optional)
              <textarea rows={3} maxLength={2000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} className="mt-1 block w-full rounded-xl border border-slate-300 bg-transparent px-3 py-2 dark:border-slate-700" placeholder="Tell us what you need help with." />
            </label>
            {Object.values(form.errors).length > 0 && <p className="text-sm text-red-600">{Object.values(form.errors).join(' ')}</p>}
            <div className="flex flex-wrap gap-3">
              <button disabled={form.processing} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Submitting…' : 'Submit request'}</button>
              <a href="/privacy/export" className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold dark:border-slate-700">Download basic account-data export</a>
            </div>
          </form>
        </section>
        <section className="space-y-3">
          <h2 className="text-lg font-bold">Your requests</h2>
          {requests.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">You have not submitted a privacy request.</div> : requests.map(item => <article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-semibold">{labels[item.request_type] ?? item.request_type}</h3><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold dark:bg-slate-800">{item.status.replaceAll('_', ' ')}</span></div>
            <p className="mt-2 text-sm text-slate-500">Submitted {new Date(item.created_at).toLocaleString()}</p>
            {item.reason && <p className="mt-3 whitespace-pre-wrap text-sm">{item.reason}</p>}
            {item.review_note && <p className="mt-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800"><strong>Review note:</strong> {item.review_note}</p>}
          </article>)}
        </section>
      </div>
    </DashboardLayout>
  </>;
}
