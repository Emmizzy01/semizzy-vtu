import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { Image, Save, ShieldCheck } from 'lucide-react';

type HelpPage = { slug: string; label: string; icon: string; title: string; intro: string; body: string; image: string };
export default function SupportPages({ pages }: { pages: HelpPage[] }) {
  const [form, setForm] = useState(pages);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const update = (index: number, key: keyof HelpPage, value: string) => setForm(current => current.map((page, i) => i === index ? { ...page, [key]: value } : page));
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    router.put('/admin/support-pages', { pages: form, reason }, { preserveScroll: true, onFinish: () => setBusy(false) });
  };
  return <DashboardLayout title="Support page editor" description="Edit the content, icons and images used by the eight public support pages." admin>
    <Head title="Support page editor · Semizzy" />
    <form onSubmit={submit} className="mx-auto max-w-5xl space-y-5">
      <div className="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 text-sm text-slate-700 dark:text-slate-200">
        <div className="flex items-center gap-2 font-semibold"><ShieldCheck size={18} /> Blueprint-aligned support content</div>
        <p className="mt-1">Edit the eight SUPPORT pages below. Their URLs remain stable; saved copy, icons and HTTPS image URLs appear on the public pages. Changes are recorded in the platform audit trail.</p>
      </div>
      {form.map((page, index) => <section key={page.slug} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <div className="flex items-center gap-3"><span className="grid h-11 w-11 place-items-center rounded-xl bg-emerald-50 text-2xl dark:bg-emerald-950">{page.icon}</span><div><h2 className="font-bold text-slate-900 dark:text-white">{page.label}</h2><p className="text-xs text-slate-500">Public page: /help/{page.slug}</p></div></div>
        <div className="grid gap-4 md:grid-cols-2">
          <label className="block text-sm font-medium">Page title<input required maxLength={140} className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={page.title} onChange={e => update(index, 'title', e.target.value)} /></label>
          <label className="block text-sm font-medium">Icon / emoji<input required maxLength={16} className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={page.icon} onChange={e => update(index, 'icon', e.target.value)} /></label>
        </div>
        <label className="block text-sm font-medium">Short introduction<textarea required maxLength={280} rows={2} className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={page.intro} onChange={e => update(index, 'intro', e.target.value)} /></label>
        <label className="block text-sm font-medium">Page content<textarea required maxLength={6000} rows={6} className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={page.body} onChange={e => update(index, 'body', e.target.value)} /></label>
        <label className="block text-sm font-medium"><span className="inline-flex items-center gap-2"><Image size={16} /> HTTPS image URL</span><input required type="url" maxLength={2048} className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={page.image} onChange={e => update(index, 'image', e.target.value)} /></label>
        <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800"><img src={page.image} alt="" className="h-36 w-full object-cover" onError={e => { e.currentTarget.style.visibility = 'hidden'; }} /></div>
      </section>)}
      <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <label className="block text-sm font-medium">Reason for change (audit record)<textarea required minLength={8} maxLength={500} rows={2} className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={reason} onChange={e => setReason(e.target.value)} placeholder="Explain why these support pages are being updated" /></label>
        <button disabled={busy} className="inline-flex items-center gap-2 rounded-full bg-emerald-700 px-5 py-3 text-sm font-bold text-white disabled:opacity-50"><Save size={16} />{busy ? 'Saving…' : 'Save all support pages'}</button>
      </section>
    </form>
  </DashboardLayout>;
}
