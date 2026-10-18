import { Activity, CreditCard, PlugZap, ShieldCheck, Smartphone, Users, WalletCards } from 'lucide-react';
import DashboardLayout from '../../Components/DashboardLayout';

type Metrics = {
  users: number | null;
  wallets: number;
  pendingFunding: number;
  pendingManualDeposits: number | null;
  refundsNeedingAction: number | null;
  unresolvedVtuOrders: number;
};
type Capabilities = { manageStaff: boolean; approveRefunds: boolean };

export default function AdminDashboard({ metrics, capabilities }: { metrics: Metrics; capabilities: Capabilities }) {
  const cards = [
    ...(capabilities.manageStaff ? [{ label: 'Registered Users', value: metrics.users, detail: 'All customer and staff accounts', icon: <Users size={18} />, href: '/admin/users' }] : []),
    ...(capabilities.manageStaff ? [{ label: 'Wallet Accounts', value: metrics.wallets, detail: 'Wallet records across currencies', icon: <WalletCards size={18} /> }] : []),
    ...(capabilities.manageStaff ? [{ label: 'Pending Funding', value: metrics.pendingFunding, detail: 'Funding intents awaiting completion', icon: <CreditCard size={18} />, href: '/admin/payment-gateway' }] : []),
    ...(capabilities.manageStaff ? [{ label: 'Manual Deposits', value: metrics.pendingManualDeposits, detail: 'Transfer evidence awaiting review', icon: <WalletCards size={18} />, href: '/admin/manual-deposits' }] : []),
    ...(capabilities.approveRefunds ? [{ label: 'Refunds Needing Action', value: metrics.refundsNeedingAction, detail: 'Refund requests requiring review', icon: <ShieldCheck size={18} />, href: '/admin/refunds' }] : []),
    ...(capabilities.manageStaff ? [{ label: 'Unresolved VTU Orders', value: metrics.unresolvedVtuOrders, detail: 'Processing or awaiting reconciliation', icon: <Smartphone size={18} />, href: '/admin/vtu-orders' }] : []),
  ];

  return (
    <DashboardLayout title="Admin Dashboard" description="Platform overview" admin canManageStaff={capabilities.manageStaff} canApproveRefunds={capabilities.approveRefunds}>
      <section aria-label="Platform overview" className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
        {cards.map(card => {
          const body = <><p className="text-[11px] text-gray-500">{card.label}</p><p className="mt-1 break-words text-lg font-bold tabular-nums">{card.value === null ? '—' : card.value.toLocaleString('en-NG')}</p><p className="mt-1 text-[11px] text-gray-500">{card.detail}</p></>;
          return card.href
            ? <a key={card.label} href={card.href} className="min-w-0 rounded-xl border bg-white p-4 shadow-sm transition hover:border-[#0f8a4f]">{body}</a>
            : <article key={card.label} className="min-w-0 rounded-xl border bg-white p-4 shadow-sm">{body}</article>;
        })}
      </section>

      <section className="mt-6 rounded-xl border bg-white p-5 shadow-sm">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <h2 className="font-bold">API Provider Balance - Enabled Only</h2>
          <span className="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-500">Monitoring not connected</span>
        </div>
        <p className="text-sm leading-6 text-gray-500">Provider balance amounts are not available from verified monitoring data. Open provider settings to review configuration; saved credentials alone do not confirm a live balance.</p>
        <a href="/admin/api-providers" className="mt-4 inline-flex min-h-10 items-center gap-2 rounded-lg bg-[#0f8a4f] px-4 py-2 text-xs font-bold text-white"><PlugZap size={15}/> Manage API providers</a>
      </section>

      <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section className="rounded-xl border bg-white p-5 shadow-sm lg:col-span-2">
          <h2 className="mb-4 font-bold">API Provider Success Rate (24h)</h2>
          <div className="flex min-h-28 flex-col items-center justify-center rounded-lg border border-dashed p-5 text-center">
            <Activity size={22} className="text-gray-400"/>
            <p className="mt-2 text-sm font-semibold text-gray-600">No verified telemetry available</p>
            <p className="mt-1 max-w-lg text-xs leading-5 text-gray-500">Success percentages and response times will appear here when provider monitoring data is available.</p>
          </div>
        </section>
        <section className="rounded-xl border bg-white p-5 shadow-sm">
          <h2 className="mb-4 font-bold">Revenue by Service</h2>
          <div className="flex min-h-28 flex-col items-center justify-center rounded-lg border border-dashed p-4 text-center">
            <CreditCard size={22} className="text-gray-400"/>
            <p className="mt-2 text-sm font-semibold text-gray-600">Report not available</p>
            <p className="mt-1 text-xs leading-5 text-gray-500">No verified service revenue breakdown is provided to this dashboard yet.</p>
          </div>
        </section>
      </div>

      <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section className="rounded-xl border bg-white p-5 shadow-sm">
          <h2 className="mb-4 font-bold">New Users (Last 7 days)</h2>
          <p className="rounded-lg border border-dashed p-5 text-sm leading-6 text-gray-500">A recent-user list is not available in the current dashboard data. Open user management to review accounts.</p>
          {capabilities.manageStaff && <a href="/admin/users" className="mt-3 inline-flex min-h-9 items-center rounded-lg border px-3 text-xs font-bold text-[#0f8a4f]">Open user management</a>}
        </section>
        <section className="rounded-xl border bg-white p-5 shadow-sm">
          <h2 className="mb-4 font-bold">High-Value Users (Top Spenders)</h2>
          <p className="rounded-lg border border-dashed p-5 text-sm leading-6 text-gray-500">Verified lifetime-spend ranking is not available in the current dashboard data.</p>
          {capabilities.manageStaff && <a href="/admin/users" className="mt-3 inline-flex min-h-9 items-center rounded-lg border px-3 text-xs font-bold text-[#0f8a4f]">Open user management</a>}
        </section>
      </div>
    </DashboardLayout>
  );
}
