import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import DashboardLayout from '../../Components/DashboardLayout';
import {
  Activity, BookOpen, BriefcaseBusiness, Building2, Boxes, CalendarDays, Code2, Cpu,
  GraduationCap, HeartHandshake, Home, Leaf, LifeBuoy, Megaphone, Package, PiggyBank,
  ShieldCheck, Shirt, ShoppingBag, Smartphone, Sparkles, Store, Truck, WalletCards,
  Wifi, Zap, Tv, Wrench, Search, SlidersHorizontal, type LucideIcon,
} from 'lucide-react';

type Service = {
  title: string;
  description: string;
  icon: LucideIcon;
  status: 'available' | 'in-progress' | 'gated' | 'disabled';
  feature?: 'airtimeData' | 'manualDeposit';
  href?: string;
  group: string;
};
const services: Service[] = [
  { title: 'Airtime & data', description: 'Browse supported networks and data plans, with order status and recovery.', icon: Smartphone, status: 'available', feature: 'airtimeData', href: '/wallet/vtu', group: 'Telecoms & mobile top-ups' },
  { title: 'Manual bank deposit', description: 'Submit a completed NGN transfer and evidence for independent review; uploads never credit a wallet by themselves.', icon: WalletCards, status: 'available', feature: 'manualDeposit', href: '/wallet/manual-deposits', group: 'Wallet funding & payments' },
  { title: 'Transactions & orders', description: 'Review wallet activity, funding records and your order history.', icon: Activity, status: 'available', href: '/wallet/manual-deposits', group: 'Wallet funding & payments' },
  { title: 'Electricity bills', description: 'Provider-backed biller catalogue and payment verification are being completed.', icon: Zap, status: 'in-progress', group: 'Bills & subscriptions' },
  { title: 'Cable TV subscriptions', description: 'Supported providers, plans and fulfilment verification are being completed.', icon: Tv, status: 'in-progress', group: 'Bills & subscriptions' },
  { title: 'Internet bills', description: 'Authorized internet and broadband provider catalogue is not yet enabled.', icon: Wifi, status: 'in-progress', group: 'Bills & subscriptions' },
  { title: 'Remita payments', description: 'Authorized RRR/biller verification and settlement reconciliation are still being completed.', icon: Building2, status: 'in-progress', group: 'Bills & subscriptions' },
  { title: 'Transaction security', description: 'Set a separate six-digit transaction PIN; repeated failures trigger a temporary lock and are audited.', icon: ShieldCheck, status: 'available', href: '/security/transaction-pin', group: 'Account & security' },
  { title: 'Electronics & Gadgets', description: 'Devices, phones, computing equipment and accessories.', icon: Cpu, status: 'in-progress', group: 'Marketplace · Electronics & gadgets' },
  { title: 'Fashion, Clothing & Accessories', description: 'Clothing, footwear, bags and fashion accessories.', icon: Shirt, status: 'in-progress', group: 'Marketplace · Fashion & accessories' },
  { title: 'Beauty, Personal Care & Wellness Products', description: 'Beauty, grooming, personal care and wellness products.', icon: Sparkles, status: 'in-progress', group: 'Marketplace · Beauty & personal care' },
  { title: 'Home, Furniture & Appliances', description: 'Furniture, home essentials and appliances.', icon: Home, status: 'in-progress', group: 'Marketplace · Home & appliances' },
  { title: 'Food, Groceries & Household Essentials', description: 'Food, groceries and everyday household supplies.', icon: Package, status: 'in-progress', group: 'Marketplace · Food & household' },
  { title: 'Vehicles, Auto Parts & Accessories', description: 'Vehicles, auto parts and vehicle accessories.', icon: Truck, status: 'in-progress', group: 'Marketplace · Vehicles & auto parts' },
  { title: 'Building, Hardware & Industrial Supplies', description: 'Building materials, hardware and industrial supplies.', icon: Building2, status: 'in-progress', group: 'Marketplace · Building & industrial' },
  { title: 'Books, Stationery & Educational Materials', description: 'Books, stationery, study resources and learning materials.', icon: BookOpen, status: 'in-progress', group: 'Marketplace · Books & stationery' },
  { title: 'Digital Products & Downloads', description: 'Eligible digital products and downloadable items.', icon: Package, status: 'in-progress', group: 'Marketplace · Digital products' },
  { title: 'Courses, Training & Knowledge Products', description: 'Courses, training materials and knowledge products.', icon: GraduationCap, status: 'in-progress', group: 'Marketplace · Courses & training' },
  { title: 'Freelancing & Digital Services', description: 'Service listings, milestones, delivery evidence and dispute workflows.', icon: BriefcaseBusiness, status: 'in-progress', group: 'Marketplace · Freelance & digital services' },
  { title: 'Home, Repair & Professional Services', description: 'Home repair, maintenance and professional service bookings.', icon: Wrench, status: 'in-progress', group: 'Marketplace · Repair & professional services' },
  { title: 'Business & Commercial Supplies', description: 'Business equipment, consumables and commercial supplies.', icon: Store, status: 'in-progress', group: 'Marketplace · Business supplies' },
  { title: 'Used Products & Classifieds', description: 'Used goods and classified listings with moderation and dispute controls.', icon: ShoppingBag, status: 'in-progress', group: 'Marketplace · Used goods & classifieds' },
  { title: 'Tickets, Events & Bookings', description: 'Event discovery, ticket inventory, booking and check-in workflows.', icon: CalendarDays, status: 'in-progress', group: 'Marketplace · Events & bookings' },
  { title: 'Agriculture & Farm Products', description: 'Farm products, agricultural supplies and produce listings.', icon: Leaf, status: 'in-progress', group: 'Marketplace · Agriculture & farming' },
  { title: 'Handmade, Art & Collectibles', description: 'Handmade goods, art, craft and collectible listings.', icon: HeartHandshake, status: 'in-progress', group: 'Marketplace · Handmade & collectibles' },
  { title: 'Wholesale & Bulk Purchases', description: 'Wholesale listings, bulk ordering and business purchase workflows.', icon: Boxes, status: 'in-progress', group: 'Marketplace · Wholesale & bulk' },
  { title: 'Social Media Marketing & Online Promotion', description: 'Provider-backed catalogue, pricing, delivery status and safe wallet reservation are required before activation.', icon: Megaphone, status: 'in-progress', group: 'Social media & online promotion' },
  { title: 'Reseller & agent tools', description: 'Mini-stores, controlled pricing, subagents, commissions and transaction reporting.', icon: Store, status: 'in-progress', group: 'Business & developer tools' },
  { title: 'Developer APIs', description: 'API credentials, scopes, quotas, provider mapping and usage reporting.', icon: Code2, status: 'in-progress', group: 'Business & developer tools' },
  { title: 'Communications', description: 'In-app, email, SMS, WhatsApp and push channels with consent and delivery tracking.', icon: Smartphone, status: 'in-progress', group: 'Business & developer tools' },
  { title: 'Education & exam services', description: 'Approved learning materials, official exam support and verified institution information.', icon: GraduationCap, status: 'in-progress', group: 'Education & exam services' },
  { title: 'Savings & group contributions', description: 'Savings goals and Ajo/Esusu require separate operational, legal and safeguarding review before activation.', icon: PiggyBank, status: 'gated', group: 'Savings & group contributions' },
  { title: 'Rewards & promotions', description: 'Eligibility, campaign budgets, reward ledgers, expiry and fraud controls.', icon: Sparkles, status: 'in-progress', group: 'Rewards & engagement' },
  { title: 'Support & disputes', description: 'Help, transaction issues, disputes and escalation workflows.', icon: LifeBuoy, status: 'in-progress', group: 'Trust & support' },
];
const statusLabel = (status: Service['status']) => status === 'available' ? 'Available now' : status === 'disabled' ? 'Temporarily unavailable' : status === 'gated' ? 'Approval required' : 'In development';

export default function ServicesIndex({ availability }: { availability: { airtimeData: boolean; manualDeposit: boolean } }) {
  const [search, setSearch] = useState('');
  const [selectedGroup, setSelectedGroup] = useState('all');
  const groups = [...new Set(services.map(service => service.group))];
  const filteredServices = useMemo(() => {
    const term = search.trim().toLocaleLowerCase();
    return services.filter(service => (selectedGroup === 'all' || service.group === selectedGroup)
      && (!term || [service.title, service.description, service.group].some(value => value.toLocaleLowerCase().includes(term))));
  }, [search, selectedGroup]);
  return <DashboardLayout title="Services" description="Explore Semizzy services. Status labels reflect the current implementation and approval gates, not just roadmap scope.">
    <Head title="Services · Semizzy" />
    <div className="mx-auto max-w-7xl space-y-8">
      <section className="grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-3 sm:grid-cols-[minmax(0,1fr)_minmax(220px,auto)] dark:border-slate-800 dark:bg-slate-950">
        <label className="flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 dark:border-slate-700"><Search size={17} className="shrink-0 text-slate-500" aria-hidden="true" /><span className="sr-only">Search services</span><input value={search} onChange={event => setSearch(event.target.value)} maxLength={100} placeholder="Search services, categories or capabilities…" className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-slate-500" />{search && <button type="button" onClick={() => setSearch('')} className="rounded-lg px-2 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">Clear</button>}</label>
        <label className="flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 dark:border-slate-700"><SlidersHorizontal size={17} className="shrink-0 text-slate-500" aria-hidden="true" /><span className="sr-only">Filter service group</span><select value={selectedGroup} onChange={event => setSelectedGroup(event.target.value)} className="min-w-0 flex-1 bg-transparent text-sm outline-none"><option value="all">All service groups</option>{groups.map(group => <option key={group} value={group}>{group}</option>)}</select></label>
        <p className="text-xs text-slate-500 sm:col-span-2" aria-live="polite">Showing {filteredServices.length} of {services.length} services</p>
      </section>
      {groups.filter(group => filteredServices.some(service => service.group === group)).map(group => <section key={group} className="space-y-3">
        <h2 className="text-lg font-semibold">{group}</h2>
        <div className="grid grid-cols-4 gap-2 sm:grid-cols-4 sm:gap-3 lg:grid-cols-5 xl:grid-cols-6 xl:gap-4">
          {filteredServices.filter(service => service.group === group).map(service => {
            const Icon = service.icon;
            const effectiveStatus = service.feature ? (availability[service.feature] ? service.status : 'disabled') : service.status;
            const content = <><div className="flex flex-col items-start justify-between gap-2 sm:flex-row sm:items-start sm:gap-3"><span className="rounded-lg bg-emerald-700/10 p-2 text-emerald-700 sm:rounded-xl sm:p-3 dark:text-emerald-300"><Icon size={18} strokeWidth={1.8} /></span><span className={'max-w-full rounded-full px-1.5 py-1 text-[9px] font-semibold leading-tight sm:px-2.5 sm:text-[11px] ' + (effectiveStatus === 'available' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : effectiveStatus === 'disabled' ? 'bg-rose-500/10 text-rose-700 dark:text-rose-300' : effectiveStatus === 'gated' ? 'bg-amber-500/10 text-amber-800 dark:text-amber-300' : 'bg-slate-500/10 text-slate-600 dark:text-slate-300')}>{statusLabel(effectiveStatus)}</span></div><h3 className="mt-3 break-words text-xs font-semibold leading-tight sm:mt-4 sm:text-sm">{service.title}</h3><p className="mt-1 hidden text-sm leading-5 text-slate-500 sm:block">{service.description}</p>{service.href && <span className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">Open service <span aria-hidden="true">→</span></span>}</>;
            return service.href && effectiveStatus !== 'disabled' ? <a key={service.title} href={service.href} className="min-w-0 rounded-xl border border-slate-200 bg-white p-2.5 transition hover:border-emerald-600/50 hover:shadow-sm sm:rounded-2xl sm:p-4 dark:border-slate-800 dark:bg-slate-950">{content}</a> : <div key={service.title} className="min-w-0 rounded-xl border border-slate-200/80 p-2.5 sm:rounded-2xl sm:p-4 dark:border-slate-800">{content}</div>;
          })}
        </div>
      </section>)}
      {filteredServices.length === 0 && <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center dark:border-slate-700"><Search size={24} className="mx-auto text-slate-400" /><h2 className="mt-3 font-semibold">No matching services</h2><p className="mt-1 text-sm text-slate-500">Try another search term or select a different group.</p><button type="button" onClick={() => { setSearch(''); setSelectedGroup('all'); }} className="mt-4 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Reset filters</button></div>}
    </div>
  </DashboardLayout>;
}
