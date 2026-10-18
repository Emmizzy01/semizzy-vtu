import { Activity, PlugZap, Users } from 'lucide-react';
import DashboardLayout from '../../Components/DashboardLayout';

type PrototypeMetrics = {
  totalRevenueMinor: number | null;
  activeUsers: number | null;
  newUsersToday: number;
  highValueUsers: number | null;
  apiSuccessRate: number | null;
  pendingTransactions: number;
};
type RecentUser = { id: number; name: string; phone: string; kycStatus: string; createdAt: string | null };
type Capabilities = { manageStaff: boolean; approveRefunds: boolean };

function nairaFromMinor(minor: number) {
  return new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(minor / 100);
}

export default function AdminDashboard({ prototypeMetrics, newUsersLastSevenDays, recentUsers, capabilities }: {
  metrics: { users: number | null; wallets: number; pendingFunding: number; pendingManualDeposits: number | null; refundsNeedingAction: number | null; unresolvedVtuOrders: number };
  prototypeMetrics: PrototypeMetrics;
  newUsersLastSevenDays: number;
  recentUsers: RecentUser[];
  capabilities: Capabilities;
}) {
  const cards = [
    { label: 'Total Revenue', value: prototypeMetrics.totalRevenueMinor === null ? '—' : nairaFromMinor(prototypeMetrics.totalRevenueMinor), detail: 'Verified revenue reporting pending', href: '/admin/payment-gateway' },
    { label: 'Active Users', value: prototypeMetrics.activeUsers === null ? '—' : prototypeMetrics.activeUsers.toLocaleString('en-NG'), detail: 'Activity reporting pending', href: '/admin/users' },
    { label: 'New Users Today', value: prototypeMetrics.newUsersToday.toLocaleString('en-NG'), detail: 'Registered today', icon: <Users size={18} />, href: '/admin/users' },
    { label: 'High-Value Users', value: prototypeMetrics.highValueUsers === null ? '—' : prototypeMetrics.highValueUsers.toLocaleString('en-NG'), detail: 'Verified spend ranking pending', href: '/admin/users' },
    { label: 'API Success Rate', value: prototypeMetrics.apiSuccessRate === null ? '—' : prototypeMetrics.apiSuccessRate.toFixed(1) + '%', detail: 'Provider telemetry pending', href: '/admin/api-providers' },
    { label: 'Pending Txns', value: prototypeMetrics.pendingTransactions.toLocaleString('en-NG'), detail: 'Wallet transactions awaiting resolution', href: '/admin/vtu-orders' },
  ];

  return (
    <DashboardLayout title="Admin Dashboard" description="" admin canManageStaff={capabilities.manageStaff} canApproveRefunds={capabilities.approveRefunds}>
      <section aria-label="Platform overview" className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
        {cards.map(card => {
          const body = <><p className="text-[11px] text-gray-500 dark:text-gray-400">{card.label}</p><p className="mt-1 break-words text-lg font-bold tabular-nums">{card.value}</p><p className="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{card.detail}</p>{card.label === 'API Success Rate' && <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800"><div className="h-full rounded-full bg-[#0f8a4f]" style={{ width: prototypeMetrics.apiSuccessRate === null ? '0%' : Math.max(0, Math.min(100, prototypeMetrics.apiSuccessRate)) + '%' }}/></div>}</>;
          return <a key={card.label} href={card.href} className="min-w-0 rounded-xl border bg-white p-4 shadow-sm transition hover:border-[#0f8a4f] dark:border-white/10 dark:bg-slate-900">{body}</a>;
        })}
      </section>

      <section className="mt-6 rounded-xl border bg-white dark:bg-slate-900 p-5 shadow-sm" aria-labelledby="provider-balance-heading">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 id="provider-balance-heading" className="font-bold">API Provider Balance - Enabled Only</h2><span className="rounded-full bg-gray-100 dark:bg-slate-800 px-2 py-1 text-xs text-gray-500 dark:text-gray-400">Monitoring not connected</span></div>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
          {['VTU.ng', 'ClubKonnect', 'DataHouse', 'EasyAccess'].map((provider, index) => <article key={provider} className={`rounded-xl border p-4 ${index === 3 ? 'border-yellow-200 bg-yellow-50/50' : ''}`}>
            <div className="flex items-center justify-between"><span className={`grid h-8 w-8 place-items-center rounded-full text-xs font-bold ${index === 0 ? 'bg-yellow-400' : index === 1 ? 'bg-red-500 text-white' : index === 2 ? 'bg-green-600 text-white' : 'bg-gray-800 text-white'}`}>{provider.slice(0, 1)}</span><span className="rounded-full bg-gray-100 dark:bg-slate-800 px-2 py-1 text-[10px] text-gray-600 dark:text-gray-300">Unverified</span></div>
            <p className="mt-3 font-bold">{provider}</p><p className="mt-1 text-sm font-bold text-gray-500 dark:text-gray-400">Balance unavailable</p>
            <div className="mt-3"><p className="flex justify-between text-[11px] text-gray-500 dark:text-gray-400"><span>Success rate</span><span>Not available</span></p><div className="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-slate-800" /></div>
          </article>)}
        </div>
        <p className="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">Provider balances and success rates are not supplied by verified monitoring data. This panel deliberately avoids displaying prototype sample figures as live production values.</p>
        <a href="/admin/api-providers" className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-lg bg-[#0f8a4f] px-4 py-2 text-xs font-bold text-white"><PlugZap size={15}/> Manage API providers</a>
      </section>

      <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section className="rounded-xl border bg-white dark:bg-slate-900 p-5 shadow-sm lg:col-span-2" aria-labelledby="provider-success-heading">
          <h2 id="provider-success-heading" className="mb-4 font-bold">API Provider Success Rate (24h)</h2>
          <div className="space-y-3">
            {['VTU.ng', 'ClubKonnect', 'DataHouse', 'EasyAccess', 'UWS'].map(provider => <div key={provider} className="flex items-center gap-3"><span className="w-24 shrink-0 text-xs">{provider}</span><div className="h-3 flex-1 rounded-full bg-gray-100 dark:bg-slate-800" /><span className="w-24 text-right text-xs text-gray-500 dark:text-gray-400">No telemetry</span></div>)}
          </div>
          <div className="mt-6 overflow-x-auto"><table className="w-full text-xs"><thead><tr className="border-b dark:border-slate-700 text-gray-400"><th className="py-2 text-left">Provider</th><th className="py-2 text-right">Total</th><th className="py-2 text-right">Success</th><th className="py-2 text-right">Failed</th><th className="py-2 text-right">Avg Resp</th></tr></thead><tbody>{['VTU.ng', 'ClubKonnect', 'DataHouse'].map(provider => <tr key={provider} className="border-b dark:border-slate-700 last:border-0"><td className="py-2">{provider}</td><td className="py-2 text-right text-gray-500 dark:text-gray-400">—</td><td className="py-2 text-right text-gray-500 dark:text-gray-400">—</td><td className="py-2 text-right text-gray-500 dark:text-gray-400">—</td><td className="py-2 text-right text-gray-500 dark:text-gray-400">—</td></tr>)}</tbody></table></div>
        </section>
        <section className="rounded-xl border bg-white dark:bg-slate-900 p-5 shadow-sm" aria-labelledby="revenue-service-heading">
          <h2 id="revenue-service-heading" className="mb-4 font-bold">Revenue by Service</h2>
          <div className="space-y-3 text-xs">{[['Airtime', 'bg-[#0f8a4f]'], ['Data', 'bg-[#12a15a]'], ['Bills', 'bg-[#0a5c32]'], ['TV', 'bg-[#c8f0d9]']].map(([label, color]) => <div key={label} className="flex items-center gap-2"><span className={`h-3 w-3 rounded-full ${color}`}/><span>{label}</span><span className="ml-auto text-gray-400">Unavailable</span></div>)}</div>
          <p className="mt-4 text-xs leading-5 text-gray-500 dark:text-gray-400">Service revenue breakdown requires verified transaction aggregation and is not available in the current dashboard data.</p>
        </section>
      </div>

      <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section className="rounded-xl border bg-white p-5 shadow-sm dark:border-white/10 dark:bg-slate-900" aria-labelledby="new-users-heading">
          <h2 id="new-users-heading" className="mb-4 font-bold">New Users (Last 7 days) - {newUsersLastSevenDays.toLocaleString('en-NG')}</h2>
          {recentUsers.length > 0 ? <div className="space-y-3 text-sm">
            {recentUsers.map(user => <div key={user.id} className="flex items-center justify-between gap-3">
              <div className="flex min-w-0 items-center gap-2"><div className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-200 text-xs dark:bg-slate-700">{user.name.split(/\\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase()}</div><div className="min-w-0"><p className="truncate text-xs font-medium">{user.name}</p><p className="text-[11px] text-gray-500 dark:text-gray-400">{user.phone}{user.createdAt ? ' • ' + new Date(user.createdAt).toLocaleString('en-NG', { dateStyle: 'short', timeStyle: 'short' }) : ''}</p></div></div>
              {user.kycStatus === 'pending' || user.kycStatus === 'manual_review' ? <a href="/admin/kyc-reviews" className="shrink-0 rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-800 dark:bg-green-950 dark:text-green-200">Verify</a> : <a href={'/admin/users?search=' + encodeURIComponent(user.name)} className="shrink-0 rounded-full border px-3 py-1 text-xs font-semibold text-[#0f8a4f]">View</a>}
            </div>)}
          </div> : <div className="flex min-h-28 flex-col items-center justify-center rounded-lg border border-dashed p-5 text-center dark:border-slate-700"><Users size={22} className="text-gray-400"/><p className="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">No recent users to display</p><p className="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">New registrations from the last seven days will appear here.</p></div>}
          {capabilities.manageStaff && <a href="/admin/users" className="mt-3 inline-flex min-h-9 items-center rounded-lg border px-3 text-xs font-bold text-[#0f8a4f]">View all users</a>}
        </section>
        <section className="rounded-xl border bg-white p-5 shadow-sm dark:border-white/10 dark:bg-slate-900" aria-labelledby="high-value-users-heading">
          <h2 id="high-value-users-heading" className="mb-4 font-bold">High-Value Users (Top Spenders)</h2>
          <div className="flex min-h-28 flex-col items-center justify-center rounded-lg border border-dashed p-5 text-center dark:border-slate-700"><Activity size={22} className="text-gray-400"/><p className="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">Verified spend ranking unavailable</p><p className="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">This panel is ready for the verified cross-service spend report. No sample users or spending totals are shown.</p></div>
          {capabilities.manageStaff && <a href="/admin/users" className="mt-3 inline-flex min-h-9 items-center rounded-lg border px-3 text-xs font-bold text-[#0f8a4f]">View users</a>}
        </section>
      </div>
    </DashboardLayout>
  );
}
