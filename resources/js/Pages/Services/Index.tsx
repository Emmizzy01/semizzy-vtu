import { Head } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import {
  Activity, BookOpen, BriefcaseBusiness, Building2, CalendarDays, Code2, Cpu, GraduationCap,
  HeartHandshake, Home, LifeBuoy, Megaphone, Package, PiggyBank, ShieldCheck,
  Shirt, ShoppingBag, Smartphone, Sparkles, Store, Truck, WalletCards, Wifi, Zap, Tv,
  type LucideIcon,
} from 'lucide-react';

type Service = {
  title: string;
  description: string;
  icon: LucideIcon;
  status: 'available' | 'in-progress' | 'gated';
  href?: string;
  group: string;
};

const services: Service[] = [
  { title: 'Airtime & data', description: 'Browse supported mobile networks and place supported VTU orders.', icon: Smartphone, status: 'available', href: '/wallet/vtu', group: 'Everyday services' },
  { title: 'Wallet funding', description: 'Fund your NGN wallet through the available payment or manual-deposit methods.', icon: WalletCards, status: 'available', href: '/wallet/manual-deposits', group: 'Everyday services' },
  { title: 'Transactions & orders', description: 'Review wallet activity, funding records and your order history.', icon: Activity, status: 'available', href: '/dashboard#transactions', group: 'Everyday services' },
  { title: 'Electricity bills', description: 'Utility-bill catalogue and provider coverage are being verified before activation.', icon: Zap, status: 'in-progress', group: 'Everyday services' },
  { title: 'TV subscriptions', description: 'Supported providers, plans and fulfilment checks are still being completed.', icon: Tv, status: 'in-progress', group: 'Everyday services' },
  { title: 'Internet bills', description: 'Broadband and internet-provider catalogue is not yet enabled.', icon: Wifi, status: 'in-progress', group: 'Everyday services' },
  { title: 'Unified Marketplace', description: 'One marketplace for physical goods, digital products and eligible services.', icon: ShoppingBag, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Electronics & gadgets', description: 'Marketplace category for devices, accessories and gadgets.', icon: Cpu, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Fashion & accessories', description: 'Marketplace category for clothing, shoes and accessories.', icon: Shirt, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Beauty & personal care', description: 'Marketplace category for beauty, personal care and wellness products.', icon: Sparkles, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Home & appliances', description: 'Furniture, household appliances and home essentials.', icon: Home, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Food & groceries', description: 'Groceries, food products and household essentials.', icon: Package, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Vehicles & auto', description: 'Vehicles, parts and automotive accessories.', icon: Truck, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Building & industrial', description: 'Hardware, building materials and industrial supplies.', icon: Building2, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Books & learning materials', description: 'Books, stationery and educational materials.', icon: BookOpen, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Digital products', description: 'Eligible digital downloads and products.', icon: Package, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Freelancing & professional services', description: 'Service listings, bookings, fulfilment evidence and dispute workflows.', icon: BriefcaseBusiness, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Business supplies', description: 'Commercial supplies, wholesale and bulk-purchase listings.', icon: Store, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Used goods & classifieds', description: 'Classified listings with safety and dispute controls.', icon: ShoppingBag, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Events & bookings', description: 'Event discovery, booking and ticketing workflows.', icon: CalendarDays, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Agriculture & handmade goods', description: 'Farm products, handmade items, art and collectibles.', icon: HeartHandshake, status: 'in-progress', group: 'Marketplace & business' },
  { title: 'Reseller & agent tools', description: 'Reseller storefronts, tiered pricing, subagents, commissions and reporting.', icon: Store, status: 'in-progress', group: 'Business tools' },
  { title: 'Developer APIs', description: 'Credential management, API usage, quotas and audit-friendly integration tools.', icon: Code2, status: 'in-progress', group: 'Business tools' },
  { title: 'Social media promotion', description: 'Provider-backed SMM packages with wallet holds and verified settlement controls.', icon: Megaphone, status: 'in-progress', group: 'Business tools' },
  { title: 'Education & exam preparation', description: 'Institution directory, approved learning resources and authorized education workflows.', icon: GraduationCap, status: 'in-progress', group: 'Learning & engagement' },
  { title: 'Savings & group contributions', description: 'Savings goals and Ajo/Esusu workflows require additional safeguards before activation.', icon: PiggyBank, status: 'gated', group: 'Learning & engagement' },
  { title: 'Rewards & promotions', description: 'Eligibility, reward ledgers and campaign controls are being prepared.', icon: Sparkles, status: 'in-progress', group: 'Learning & engagement' },
  { title: 'Support & disputes', description: 'Help, service issues and transaction dispute handling.', icon: LifeBuoy, status: 'in-progress', group: 'Trust & support' },
  { title: 'Security & privacy', description: 'Account protection, privacy requests and security settings.', icon: ShieldCheck, status: 'in-progress', group: 'Trust & support' },
  { title: 'Websites & domains', description: 'Website builder, reseller storefront templates and domain tools.', icon: Building2, status: 'in-progress', group: 'Business tools' },
];

const statusLabel = (status: Service['status']) => status === 'available' ? 'Available now' : status === 'gated' ? 'Approval required' : 'In development';

export default function ServicesIndex() {
  const groups = [...new Set(services.map(service => service.group))];
  return <DashboardLayout title="Services" description="Explore Semizzy services. Availability labels reflect the current implementation, not the full roadmap.">
    <Head title="Services · Semizzy" />
    <div className="mx-auto max-w-7xl space-y-8">
      <section className="rounded-2xl border border-emerald-700/20 bg-emerald-700/[0.06] p-5 sm:p-7">
        <p className="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">One platform · Connected services</p>
        <h2 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">What would you like to do today?</h2>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">Use available services directly. Other blueprint categories remain clearly marked while their end-to-end workflows and provider coverage are completed and verified.</p>
      </section>
      {groups.map(group => <section key={group} className="space-y-3">
        <h2 className="text-lg font-semibold">{group}</h2>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {services.filter(service => service.group === group).map(service => {
            const Icon = service.icon;
            const content = <><div className="flex items-start justify-between gap-3"><span className="rounded-xl bg-emerald-700/10 p-3 text-emerald-700 dark:text-emerald-300"><Icon size={22} strokeWidth={1.8} /></span><span className={'rounded-full px-2.5 py-1 text-[11px] font-semibold ' + (service.status === 'available' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : service.status === 'gated' ? 'bg-amber-500/10 text-amber-800 dark:text-amber-300' : 'bg-slate-500/10 text-slate-600 dark:text-slate-300')}>{statusLabel(service.status)}</span></div><h3 className="mt-4 font-semibold">{service.title}</h3><p className="mt-1 text-sm leading-5 text-slate-500">{service.description}</p>{service.href && <span className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">Open service <span aria-hidden="true">→</span></span>}</>;
            return service.href ? <a key={service.title} href={service.href} className="rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-emerald-600/50 hover:shadow-sm dark:border-slate-800 dark:bg-slate-950">{content}</a> : <div key={service.title} className="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-800">{content}</div>;
          })}
        </div>
      </section>)}
    </div>
  </DashboardLayout>;
}
