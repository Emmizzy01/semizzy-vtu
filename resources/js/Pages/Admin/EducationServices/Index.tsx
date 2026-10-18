import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { GraduationCap, ShieldCheck } from 'lucide-react';
import DashboardLayout from '../../../Components/DashboardLayout';

type Product = { id: number; service_type: string; exam_body: string; name: string; code: string; provider: string | null; provider_product_code: string | null; provider_cost_minor: number | null; price_minor: number; currency: string; requires_candidate_validation: boolean; is_active: boolean; purchase_approved: boolean };
type Props = { products: Product[]; pagination: { currentPage: number; lastPage: number; total: number }; filters: { search: string; service_type: string }; feature: { is_enabled: boolean; is_implemented: boolean }; purchaseGate: boolean; gateReason: string };

export default function EducationServicesAdmin({ products, pagination, feature, gateReason }: Props) {
  const [editingId, setEditingId] = useState<number | null>(null);
  const form = useForm({ service_type: 'exam_pin', exam_body: 'WAEC', name: '', code: '', provider: '', provider_product_code: '', provider_cost_minor: '', price_minor: '', requires_candidate_validation: true, reason: 'Add education product to catalogue pending provider verification' });
  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    if (editingId !== null) {
      form.put('/admin/education-services/' + editingId, { onSuccess: () => { setEditingId(null); form.reset(); form.setData('service_type', 'exam_pin'); form.setData('exam_body', 'WAEC'); form.setData('requires_candidate_validation', true); } });
      return;
    }
    form.post('/admin/education-services', { onSuccess: () => { form.reset(); form.setData('service_type', 'exam_pin'); form.setData('exam_body', 'WAEC'); form.setData('requires_candidate_validation', true); } });
  };
  const editProduct = (product: Product) => {
    setEditingId(product.id);
    form.setData({
      service_type: product.service_type, exam_body: product.exam_body, name: product.name, code: product.code,
      provider: product.provider ?? '', provider_product_code: product.provider_product_code ?? '',
      provider_cost_minor: product.provider_cost_minor === null ? '' : String(product.provider_cost_minor),
      price_minor: String(product.price_minor), requires_candidate_validation: product.requires_candidate_validation,
      reason: 'Update education catalogue product pending provider verification',
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  const cancelEdit = () => {
    setEditingId(null);
    form.reset();
    form.setData('service_type', 'exam_pin');
    form.setData('exam_body', 'WAEC');
    form.setData('requires_candidate_validation', true);
  };
  return <DashboardLayout title="Education catalogue" description="Administer education products" admin>
    <Head title="Education catalogue · Semizzy Admin" />
    <div className="mx-auto max-w-6xl space-y-5">
      <section className="rounded-2xl bg-white p-5 shadow-sm dark:bg-slate-900 sm:p-7"><div className="flex items-center gap-3"><GraduationCap className="text-emerald-700" size={28}/><div><h1 className="text-2xl font-extrabold">Education catalogue</h1><p className="mt-1 text-sm text-slate-500">WAEC, NECO, JAMB and other authorized education products.</p></div></div>
        <div className="mt-5 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/30"><ShieldCheck className="shrink-0 text-amber-700"/><div><p className="font-bold">Purchases are locked</p><p className="mt-1">{gateReason} Catalogue entries created here are forced inactive and unapproved.</p><p className="mt-2 text-xs">Feature toggle: {feature.is_enabled ? 'enabled' : 'disabled'} · Implemented: {feature.is_implemented ? 'yes' : 'no'}</p></div></div>
      </section>
      <section className="rounded-2xl bg-white p-5 shadow-sm dark:bg-slate-900"><h2 className="text-lg font-bold">{editingId === null ? "Add catalogue product" : "Edit catalogue product #" + editingId}</h2><form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label className="text-sm">Service type<select value={form.data.service_type} onChange={e=>form.setData('service_type',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"><option value="exam_pin">Examination PIN</option><option value="registration">Registration</option><option value="verification">Verification</option><option value="other">Other</option></select></label>
        <label className="text-sm">Exam body / service<input required maxLength={60} value={form.data.exam_body} onChange={e=>form.setData('exam_body',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950" placeholder="WAEC, NECO, JAMB"/></label>
        <label className="text-sm">Product name<input required maxLength={160} value={form.data.name} onChange={e=>form.setData('name',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"/></label>
        <label className="text-sm">Internal product code<input required maxLength={100} value={form.data.code} onChange={e=>form.setData('code',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"/></label>
        <label className="text-sm">Provider key<input maxLength={60} value={form.data.provider} onChange={e=>form.setData('provider',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"/></label>
        <label className="text-sm">Provider product code<input maxLength={120} value={form.data.provider_product_code} onChange={e=>form.setData('provider_product_code',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"/></label>
        <label className="text-sm">Provider cost (kobo)<input type="number" min="0" value={form.data.provider_cost_minor} onChange={e=>form.setData('provider_cost_minor',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"/></label>
        <label className="text-sm">Selling price (kobo)<input required type="number" min="1" value={form.data.price_minor} onChange={e=>form.setData('price_minor',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"/></label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.requires_candidate_validation} onChange={e=>form.setData('requires_candidate_validation',e.target.checked)}/> Require candidate validation</label>
        <label className="text-sm sm:col-span-2 lg:col-span-3">Reason for audit log<textarea required minLength={8} maxLength={500} value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)} className="mt-1 w-full rounded-lg border p-3 dark:bg-slate-950"/></label>
        <button disabled={form.processing} className="rounded-xl bg-emerald-700 px-5 py-3 font-bold text-white disabled:opacity-50 sm:col-span-2 lg:col-span-3">{form.processing ? 'Saving…' : editingId === null ? 'Save inactive catalogue product' : 'Update and keep product locked'}</button>
        {editingId !== null && <button type="button" onClick={cancelEdit} className="rounded-xl border px-5 py-3 font-bold sm:col-span-2 lg:col-span-3">Cancel editing</button>}
        {Object.values(form.errors).length > 0 && <p className="text-sm text-red-600 sm:col-span-2 lg:col-span-3">{Object.values(form.errors).join(' ')}</p>}
      </form></section>
      <section className="rounded-2xl bg-white p-5 shadow-sm dark:bg-slate-900"><div className="flex items-center justify-between"><h2 className="text-lg font-bold">Catalogue products</h2><span className="text-sm text-slate-500">{pagination.total} total</span></div>
        {products.length === 0 ? <p className="py-8 text-center text-sm text-slate-500">No education products have been added.</p> : <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead><tr className="border-b text-xs uppercase text-slate-500"><th className="p-3">Service</th><th className="p-3">Product</th><th className="p-3">Provider</th><th className="p-3">Cost</th><th className="p-3">Selling price</th><th className="p-3">Status</th><th className="p-3">Action</th></tr></thead><tbody>{products.map(p=><tr key={p.id} className="border-b border-slate-100 dark:border-slate-800"><td className="p-3">{p.exam_body}<div className="text-xs text-slate-500">{p.service_type}</div></td><td className="p-3 font-semibold">{p.name}<div className="text-xs text-slate-500">{p.code}</div></td><td className="p-3">{p.provider || 'Not assigned'}<div className="text-xs text-slate-500">{p.provider_product_code || 'No provider SKU'}</div></td><td className="p-3">{p.provider_cost_minor == null ? 'Unknown' : '₦' + (p.provider_cost_minor/100).toLocaleString('en-NG',{minimumFractionDigits:2})}</td><td className="p-3">₦{(p.price_minor/100).toLocaleString('en-NG',{minimumFractionDigits:2})}</td><td className="p-3"><span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800">Locked</span></td><td className="p-3"><button type="button" onClick={() => editProduct(p)} className="rounded-lg border px-3 py-2 font-semibold">Edit</button></td></tr>)}</tbody></table></div>}
      </section>
    </div>
  </DashboardLayout>;
}
