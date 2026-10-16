import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { AlertTriangle, Globe, Mail, Phone, Save, ShieldCheck, Smartphone, WalletCards } from 'lucide-react';

type Settings = { theme_mode: 'light' | 'dark'; site_name: string; support_email: string; support_phone: string; theme_skin: string; maintenance_mode: boolean; airtime_data_enabled: boolean; manual_deposit_enabled: boolean };
export default function PlatformSettings({ settings }: { settings: Settings }) {
  const [form, setForm] = useState(settings);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const update = (key: keyof Settings, value: string | boolean) => setForm(current => ({ ...current, [key]: value }));
  const submit = (e: FormEvent) => { e.preventDefault(); setBusy(true); router.put('/admin/platform-settings', { ...form, reason }, { preserveScroll: true, onFinish: () => setBusy(false) }); };
  return <DashboardLayout title="Site control" description="Manage platform availability, support identity and implemented service switches." admin>
    <Head title="Site control · Semizzy" />
    <form onSubmit={submit} className="mx-auto max-w-4xl space-y-5">
      <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"><div className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Global maintenance control</div><p className="mt-1 text-slate-600 dark:text-slate-300">When enabled, customers see a maintenance page and customer API requests are blocked with HTTP 503. Staff with platform-management permission can still access the admin workspace.</p></div>
      <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <h2 className="flex items-center gap-2 font-semibold"><Globe size={18} />Site identity</h2>
        <label className="block text-sm font-medium">Global day/night mode<select className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={form.theme_mode} onChange={e => update('theme_mode', e.target.value)}><option value="light">Day mode</option><option value="dark">Night mode</option></select><span className="mt-1 block text-xs font-normal text-slate-500">This is the platform-wide appearance for all users. Only admins can change it.</span></label>
        <label className="block text-sm font-medium">Platform name<input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={form.site_name} onChange={e => update('site_name', e.target.value)} minLength={2} maxLength={120} required /></label>
        <label className="block text-sm font-medium">Global brand skin<select className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={form.theme_skin} onChange={e => update('theme_skin', e.target.value)}><option value="modern-green">Modern Green &amp; White</option><option value="navy-teal">Premium Navy &amp; Teal</option><option value="minimal-blue">Minimal Blue &amp; White</option><option value="obsidian-luxe">Obsidian Luxe</option><option value="arctic-light">Arctic Light</option></select><span className="mt-1 block text-xs font-normal text-slate-500">Uses approved color tokens only; the selected brand colors apply platform-wide.</span></label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block text-sm font-medium"><span className="inline-flex items-center gap-2"><Mail size={15} />Support email</span><input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" type="email" value={form.support_email} onChange={e => update('support_email', e.target.value)} maxLength={255} /></label>
          <label className="block text-sm font-medium"><span className="inline-flex items-center gap-2"><Phone size={15} />Support phone</span><input className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" value={form.support_phone} onChange={e => update('support_phone', e.target.value)} maxLength={32} /></label>
        </div>
      </section>
      <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <h2 className="flex items-center gap-2 font-semibold"><ShieldCheck size={18} />Platform availability</h2>
        <label className="flex items-center justify-between gap-4 rounded-xl bg-slate-50 p-4 dark:bg-slate-900"><span><span className="block font-semibold">Maintenance mode</span><span className="text-sm text-slate-500">Blocks customer web/API activity until turned off.</span></span><input type="checkbox" checked={form.maintenance_mode} onChange={e => update('maintenance_mode', e.target.checked)} /></label>
        <label className="flex items-center justify-between gap-4 rounded-xl bg-slate-50 p-4 dark:bg-slate-900"><span className="flex items-center gap-3"><Smartphone className="text-emerald-700" /><span><span className="block font-semibold">Airtime & data purchases</span><span className="text-sm text-slate-500">Stops catalogue and new order API requests when disabled.</span></span></span><input type="checkbox" checked={form.airtime_data_enabled} onChange={e => update('airtime_data_enabled', e.target.checked)} /></label>
        <label className="flex items-center justify-between gap-4 rounded-xl bg-slate-50 p-4 dark:bg-slate-900"><span className="flex items-center gap-3"><WalletCards className="text-emerald-700" /><span><span className="block font-semibold">Manual wallet deposits</span><span className="text-sm text-slate-500">Stops new submissions; existing pending reviews remain available.</span></span></span><input type="checkbox" checked={form.manual_deposit_enabled} onChange={e => update('manual_deposit_enabled', e.target.checked)} /></label>
      </section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950"><label className="block text-sm font-medium">Reason for change (required audit note)<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" rows={3} value={reason} onChange={e => setReason(e.target.value)} minLength={8} maxLength={500} required /></label><button disabled={busy} className="mt-4 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-50"><Save size={17} />{busy ? 'Saving…' : 'Save platform settings'}</button></section>
    </form>
  </DashboardLayout>;
}
