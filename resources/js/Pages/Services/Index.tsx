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
  { title: 'Airtime', description: 'Buy airtime for a supported mobile network from its own dedicated purchase page.', icon: Smartphone, status: 'available', feature: 'airtimeData', href: '/wallet/vtu/airtime', group: 'Telecoms & mobile top-ups' },
  { title: 'Mobile data', description: 'Choose a data plan, validity and network on a separate data purchase page.', icon: Wifi, status: 'available', feature: 'airtimeData', href: '/wallet/vtu/data', group: 'Telecoms & mobile top-ups' },
  { title: 'Manual bank deposit', description: 'Submit a completed NGN transfer and evidence for independent review; uploads never credit a wallet by themselves.', icon: WalletCards, status: 'available', feature: 'manualDeposit', href: '/wallet/manual-deposits', group: 'Wallet funding & payments' },
  { title: 'Transactions & orders', description: 'Review the latest wallet activity and order information on your customer dashboard.', icon: Activity, status: 'available', href: '/dashboard#activity', group: 'Wallet funding & payments' },
  { title: 'Electricity bills', description: 'Dedicated electricity page for meter details, customer verification and payment amount. Purchases remain unavailable until the provider flow is approved.', icon: Zap, status: 'in-progress', href: '/wallet/vtu/electricity', group: 'Bills & subscriptions' },
  { title: 'Cable TV subscriptions', description: 'Dedicated TV page for smartcard verification and package selection. Purchases remain unavailable until the provider flow is approved.', icon: Tv, status: 'in-progress', href: '/wallet/vtu/tv', group: 'Bills & subscriptions' },
  { title: 'Internet bills', description: 'Authorized internet and broadband provider catalogue is not yet enabled.', icon: Wifi, status: 'in-progress', group: 'Bills & subscriptions' },
  { title: 'Remita payments', description: 'Authorized RRR/biller verification and settlement reconciliation are still being completed.', icon: Building2, status: 'in-progress', group: 'Bills & subscriptions' },
  { title: 'Transaction security', description: 'Set a separate four-digit transaction PIN; repeated failures trigger a temporary lock and are audited.', icon: ShieldCheck, status: 'available', href: '/security/transaction-pin', group: 'Account & security' },
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
        <div className="grid grid-cols-4 gap-x-2 gap-y-4" aria-label={`${group} services`}>
          {filteredServices.filter(service => service.group === group).map(service => {
            const Icon = service.icon;
            const effectiveStatus = service.feature ? (availability[service.feature] ? service.status : 'disabled') : service.status;
            const content = <><span className="grid h-12 w-12 place-items-center rounded-[19px] bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300"><Icon size={23} strokeWidth={1.8} /></span><span className="line-clamp-2 min-h-[2rem] w-full break-words text-[10px] font-bold leading-tight text-[var(--ink)]">{service.title}</span><span className={'max-w-full rounded-full px-1.5 py-1 text-center text-[9px] font-semibold leading-tight ' + (effectiveStatus === 'available' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : effectiveStatus === 'disabled' ? 'bg-rose-500/10 text-rose-700 dark:text-rose-300' : effectiveStatus === 'gated' ? 'bg-amber-500/10 text-amber-800 dark:text-amber-300' : 'bg-slate-500/10 text-slate-600 dark:text-slate-300')}>{statusLabel(effectiveStatus)}</span></>;
            const tileClass = "flex min-h-[116px] min-w-0 flex-col items-center justify-start gap-2 rounded-xl px-1 py-2 text-center transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]";
            return service.href && effectiveStatus !== 'disabled' ? <a key={service.title} href={service.href} aria-label={`${service.title}: ${statusLabel(effectiveStatus)}`} className={`${tileClass} hover:bg-emerald-50/60 dark:hover:bg-emerald-950/40`}>{content}</a> : <div key={service.title} aria-label={`${service.title}: ${statusLabel(effectiveStatus)}`} className={tileClass}>{content}</div>;
          })}
        </div>
      </section>)}
      {filteredServices.length === 0 && <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center dark:border-slate-700"><Search size={24} className="mx-auto text-slate-400" /><h2 className="mt-3 font-semibold">No matching services</h2><p className="mt-1 text-sm text-slate-500">Try another search term or select a different group.</p><button type="button" onClick={() => { setSearch(''); setSelectedGroup('all'); }} className="mt-4 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Reset filters</button></div>}
    </div>
  </DashboardLayout>;
}
