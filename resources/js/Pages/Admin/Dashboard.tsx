import { Activity, ArrowUpRight, CreditCard, PlugZap, ShieldCheck, Smartphone, Users, WalletCards } from 'lucide-react';
import DashboardLayout from '../../Components/DashboardLayout';

type Metrics = {
  users: number | null;
  wallets: number;
  pendingFunding: number;
  refundsNeedingAction: number | null;
  unresolvedVtuOrders: number;
};
type Capabilities = { manageStaff: boolean; approveRefunds: boolean };

export default function AdminDashboard({ metrics, capabilities }: { metrics: Metrics; capabilities: Capabilities }) {
  return (
    <DashboardLayout title="Admin Command Center" description="A secure operational overview of SEMIZZY. Administrative sections appear according to your assigned permissions." admin canManageStaff={capabilities.manageStaff} canApproveRefunds={capabilities.approveRefunds}>
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {capabilities.manageStaff && <Metric icon={<Users size={19} />} label="Registered accounts" value={metrics.users ?? 0} detail="All customer and staff accounts" />}
        <Metric icon={<WalletCards size={19} />} label="Wallet accounts" value={metrics.wallets} detail="Wallet records across currencies" />
        <Metric icon={<CreditCard size={19} />} label="Funding in progress" value={metrics.pendingFunding} detail="Pending or processing intents" />
        {capabilities.approveRefunds && <Metric icon={<ShieldCheck size={19} />} label="Refunds needing action" value={metrics.refundsNeedingAction ?? 0} detail="Requested, blocked, or review required" />}
        <Metric icon={<Smartphone size={19} />} label="Unresolved VTU orders" value={metrics.unresolvedVtuOrders} detail="Processing or awaiting reconciliation" />
      </section>

      <section className="mt-8">
        <div className="flex items-end justify-between gap-3"><div><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">Operations</p><h2 className="mt-2 font-display text-2xl font-extrabold">Workspaces</h2><p className="mt-1 text-sm text-[var(--muted)]">Open the operational tools your account is allowed to use.</p></div><span className="hidden items-center gap-2 rounded-full border border-[var(--line)] px-3 py-2 text-xs font-semibold text-[var(--muted)] sm:inline-flex"><Activity size={14} /> Live database metrics</span></div>
        <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {capabilities.approveRefunds && <Workspace href="/admin/refunds" icon={<CreditCard size={22} />} title="Refund operations" description="Review refund requests, monitor provider outcomes, and approve eligible requests." />}
          {capabilities.manageStaff && <Workspace href="/admin/staff" icon={<Users size={22} />} title="Staff & permissions" description="Manage approved staff permissions and review access audit records." />}
          <Workspace href="/admin/payment-gateway" icon={<CreditCard size={22} />} title="Payment gateway" description="Review Paystack configuration status and the wallet funding callback and webhook setup." />
          <Workspace href="/admin/api-providers" icon={<PlugZap size={22} />} title="API providers" description="Check the selected VTU provider, credential readiness, and sandbox/live configuration." />
          <Workspace href="/wallet/vtu" icon={<Smartphone size={22} />} title="VTU service" description="Open the airtime and data purchase experience available to signed-in customers." />
          <Workspace href="/customer/dashboard" icon={<WalletCards size={22} />} title="Customer experience" description="Preview the customer dashboard and its wallet, funding, and VTU links." />
        </div>
      </section>

      <section className="mt-8 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
        <div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><ShieldCheck size={20} /></span><div><h2 className="font-display text-lg font-extrabold">Security and scope</h2><p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">This command center only links to implemented, permission-protected tools. User management beyond staff access, credential editing, KYC review, and wider security reporting are not presented as complete until their backend workflows are implemented and tested.</p></div></div>
      </section>
    </DashboardLayout>
  );
}

function Metric({ icon, label, value, detail }: { icon: React.ReactNode; label: string; value: number; detail: string }) {
  return <article className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"><div className="flex items-center justify-between gap-2"><p className="text-xs font-bold text-[var(--muted)]">{label}</p><span className="text-[var(--brand)]">{icon}</span></div><p className="mt-4 font-display text-3xl font-extrabold tabular-nums">{value.toLocaleString('en-NG')}</p><p className="mt-2 text-xs leading-5 text-[var(--muted)]">{detail}</p></article>;
}
function Workspace({ href, icon, title, description }: { href: string; icon: React.ReactNode; title: string; description: string }) {
  return <a href={href} className="group rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 transition hover:-translate-y-0.5 hover:border-[var(--brand)] hover:shadow-lg hover:shadow-slate-950/5"><span className="grid h-12 w-12 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]">{icon}</span><div className="mt-5 flex items-center justify-between gap-3"><h3 className="font-display text-lg font-extrabold">{title}</h3><ArrowUpRight size={17} className="text-[var(--muted)] transition group-hover:text-[var(--brand)]" /></div><p className="mt-2 text-sm leading-6 text-[var(--muted)]">{description}</p></a>;
}
