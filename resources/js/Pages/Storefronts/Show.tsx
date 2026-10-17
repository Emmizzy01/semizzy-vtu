import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BadgeCheck, Database, Smartphone, Store } from 'lucide-react';

type Product = {
  code: string;
  serviceType: string;
  network: string;
  name: string;
  priceMinor: number | null;
  faceValueMinor: number | null;
  currency: string;
};

export default function PublicStorefront({ storefront, products, servicesAvailable }: {
  storefront: {
    slug: string;
    displayName: string;
    tagline: string | null;
    description: string | null;
    accentColor: string;
    services: string[];
    ownerName: string | null;
  };
  products: Product[];
  servicesAvailable: boolean;
}) {
  const money = (minor: number) => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 2 }).format(minor / 100);

  return <>
    <Head title={`${storefront.displayName} · Semizzy reseller store`} />
    <main className="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
      <header className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4">
          <Link href="/" className="flex items-center gap-2 font-bold tracking-tight"><span className="rounded-lg p-2 text-white" style={{ backgroundColor: storefront.accentColor }}><Store size={20}/></span>SEMIZZY</Link>
          <Link href="/login" className="rounded-xl px-4 py-2 text-sm font-semibold text-white" style={{ backgroundColor: storefront.accentColor }}>Sign in to order</Link>
        </div>
      </header>
      <section className="relative overflow-hidden" style={{ background: `linear-gradient(135deg, ${storefront.accentColor}18, transparent 65%)` }}>
        <div className="mx-auto max-w-6xl px-5 py-16 sm:py-24">
          <div className="max-w-3xl">
            <span className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/80 px-3 py-1.5 text-xs font-semibold dark:border-slate-700 dark:bg-slate-900/80"><BadgeCheck size={14} style={{ color: storefront.accentColor }}/> Semizzy reseller storefront</span>
            <h1 className="mt-6 text-4xl font-black tracking-tight sm:text-6xl">{storefront.displayName}</h1>
            {storefront.tagline && <p className="mt-4 text-xl font-medium" style={{ color: storefront.accentColor }}>{storefront.tagline}</p>}
            {storefront.description && <p className="mt-5 max-w-2xl whitespace-pre-line text-base leading-7 text-slate-600 dark:text-slate-300">{storefront.description}</p>}
            <div className="mt-7 flex flex-wrap gap-2">{storefront.services.map(service => <span key={service} className="rounded-full px-3 py-1.5 text-sm font-medium" style={{ color: storefront.accentColor, backgroundColor: `${storefront.accentColor}18` }}>{service === 'airtime' ? 'Airtime top-up' : 'Data bundles'}</span>)}</div>
            {storefront.ownerName && <p className="mt-6 text-sm text-slate-500">Operated by {storefront.ownerName}</p>}
          </div>
        </div>
      </section>
      <section className="mx-auto max-w-6xl px-5 py-12">
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p className="text-sm font-semibold uppercase tracking-wider" style={{ color: storefront.accentColor }}>Store catalogue</p><h2 className="mt-2 text-2xl font-bold sm:text-3xl">Available services</h2><p className="mt-2 text-sm text-slate-500">Live catalogue entries that are active and approved on Semizzy.</p></div><span className="rounded-full bg-white px-3 py-2 text-sm text-slate-500 shadow-sm dark:bg-slate-900">{products.length} product{products.length === 1 ? '' : 's'}</span></div>
        {!servicesAvailable ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900"><Database size={28} className="mx-auto text-slate-400"/><h3 className="mt-3 font-semibold">Catalogue temporarily unavailable</h3><p className="mt-1 text-sm text-slate-500">The selected services are currently unavailable. Please check back later.</p></div> :
          products.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900"><Database size={28} className="mx-auto text-slate-400"/><h3 className="mt-3 font-semibold">No products listed yet</h3><p className="mt-1 text-sm text-slate-500">The store owner has not added any currently available products.</p></div> :
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{products.map(product => <article key={product.code} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-start justify-between gap-3"><span className="rounded-xl p-3" style={{ color: storefront.accentColor, backgroundColor: `${storefront.accentColor}18` }}><Smartphone size={21}/></span><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium capitalize text-slate-600 dark:bg-slate-800 dark:text-slate-300">{product.serviceType}</span></div>
            <p className="mt-4 text-xs font-semibold uppercase tracking-wider text-slate-500">{product.network}</p><h3 className="mt-1 text-lg font-semibold">{product.name}</h3>
            <div className="mt-4 flex items-center justify-between gap-3"><p className="font-bold" style={{ color: storefront.accentColor }}>{product.priceMinor === null ? 'Flexible amount' : money(product.priceMinor)}</p><Link href="/login" className="inline-flex items-center gap-1 text-sm font-semibold hover:underline" style={{ color: storefront.accentColor }}>Continue <ArrowRight size={15}/></Link></div>
          </article>)}</div>}
      </section>
      <footer className="border-t border-slate-200 dark:border-slate-800"><div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 py-6 text-xs text-slate-500"><span>Powered by Semizzy · We Design. We Develop. We Deliver.</span><Link href="/register" className="font-semibold hover:underline" style={{ color: storefront.accentColor }}>Create your Semizzy account</Link></div></footer>
    </main>
  </>;
}
