import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import { AlertTriangle, LockKeyhole, Save } from 'lucide-react';

type Feature = { feature_key: string; label: string; category: string; owner: string; description: string; dependencies: string[]; default_enabled: boolean; is_enabled: boolean; is_core: boolean; is_implemented: boolean };
export default function FeatureControls({ features }: { features: Feature[] }) {
  const [values, setValues] = useState<Record<string,{enabled:boolean;reason:string}>>(() => Object.fromEntries(features.map(f=>[f.feature_key,{enabled:f.is_enabled,reason:''}])));
  const [busy,setBusy] = useState('');
  const [error,setError] = useState('');
  const save = (feature: Feature) => {
    const value=values[feature.feature_key];
    if(value.reason.trim().length<8){setError('Every rollout change needs a reason of at least 8 characters.');return;}
    setError('');setBusy(feature.feature_key);
    router.put('/admin/feature-controls/'+encodeURIComponent(feature.feature_key),{is_enabled:value.enabled,reason:value.reason},{preserveScroll:true,onFinish:()=>setBusy('')});
  };
  const categories=[...new Set(features.map(f=>f.category))];
  return <DashboardLayout title="Feature control registry" description="Dependency-aware rollout controls with ownership, prerequisites and audit history." admin>
    <Head title="Feature control registry · Semizzy"/>
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"><div className="flex items-center gap-2 font-semibold"><AlertTriangle size={17}/>Safe rollout rules</div><p className="mt-1 text-slate-600 dark:text-slate-300">Core foundations cannot be disabled. Roadmap-only features stay locked until their end-to-end workflow and required provider/legal gates are implemented. Enabling a feature requires all dependencies; disabling a prerequisite is blocked while a dependent is active.</p></div>
      {error&&<p role="alert" className="rounded-xl bg-rose-500/10 p-3 text-sm text-rose-700">{error}</p>}
      {categories.map(category=><section key={category} className="space-y-3"><h2 className="text-lg font-semibold">{category}</h2><div className="grid gap-3 lg:grid-cols-2">{features.filter(f=>f.category===category).map(feature=>{const v=values[feature.feature_key];const locked=feature.is_core||!feature.is_implemented;return <article key={feature.feature_key} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950"><div className="flex items-start justify-between gap-3"><div><h3 className="font-semibold">{feature.label}</h3><p className="mt-1 text-xs text-slate-500">{feature.feature_key} · Owner: {feature.owner}</p></div><span className={'rounded-full px-2.5 py-1 text-[11px] font-semibold '+(feature.is_core?'bg-slate-500/10 text-slate-600':feature.is_implemented?'bg-emerald-500/10 text-emerald-700':'bg-amber-500/10 text-amber-800')}>{feature.is_core?'Core locked':feature.is_implemented?(v.enabled?'Enabled':'Disabled'):'Roadmap locked'}</span></div><p className="text-sm leading-5 text-slate-500">{feature.description}</p><p className="text-xs text-slate-500">Dependencies: {feature.dependencies.length?feature.dependencies.join(', '):'None'}</p>{locked?<div className="flex items-center gap-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-900"><LockKeyhole size={15}/>{feature.is_core?'Required platform foundation':'Activation locked until implementation and approval gates are complete.'}</div>:<><label className="flex items-center justify-between rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900"><span className="font-medium">Feature enabled</span><input type="checkbox" checked={v.enabled} onChange={e=>setValues(old=>({...old,[feature.feature_key]:{...old[feature.feature_key],enabled:e.target.checked}}))}/></label><label className="block text-sm font-medium">Reason for change<textarea className="mt-1 w-full rounded-xl border bg-transparent p-3 dark:border-slate-700" rows={2} value={v.reason} onChange={e=>setValues(old=>({...old,[feature.feature_key]:{...old[feature.feature_key],reason:e.target.value}}))} minLength={8} maxLength={500}/></label><button disabled={busy===feature.feature_key} onClick={()=>save(feature)} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><Save size={15}/>{busy===feature.feature_key?'Saving…':'Save rollout setting'}</button></>}</article>})}</div></section>)}
    </div>
  </DashboardLayout>;
}
