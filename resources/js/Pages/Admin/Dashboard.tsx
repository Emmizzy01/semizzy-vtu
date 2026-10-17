import { useMemo, useState } from 'react';
import { Activity, ArrowUpRight, BadgeDollarSign, CheckCircle2, CircleAlert, CreditCard, LockKeyhole, PlugZap, Search, ShieldCheck, Smartphone, Users, WalletCards } from 'lucide-react';
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
  const [workspaceQuery, setWorkspaceQuery] = useState('');
  const [workspaceCategory, setWorkspaceCategory] = useState('all');

  const workspaces = useMemo(() => [
    ...(capabilities.approveRefunds ? [{ href: '/admin/refunds', icon: <CreditCard size={22} />, title: 'Refund operations', description: 'Review refund requests, monitor provider outcomes, and approve eligible requests.', status: 'Permission-gated route', category: 'operations' }] : []),
    ...(capabilities.manageStaff ? [{ href: '/admin/vtu-orders', icon: <Smartphone size={22} />, title: 'VTU order operations', description: 'Inspect pending and ambiguous VTU orders, provider references, and reconciliation context without changing wallet balances.', status: 'Read-only · restricted access', category: 'operations' }] : []),
    ...(capabilities.manageStaff ? [{ href: '/admin/manual-deposits', icon: <WalletCards size={22} />, title: 'Manual deposit review', description: 'Inspect submitted transfer evidence and review pending deposits. Wallet credits require an audited, verified approval.', status: 'Permission-gated route', category: 'operations' }] : []),
    ...(capabilities.manageStaff ? [{ href: '/admin/storefront-commissions', icon: <BadgeDollarSign size={22} />, title: 'Reseller commissions', description: 'Configure per-store commission rates and settle accrued commissions from verified successful orders.', status: 'Permission-gated route', category: 'operations' }] : []),
    ...(capabilities.manageStaff ? [{ href: '/admin/staff', icon: <Users size={22} />, title: 'Staff & permissions', description: 'Manage approved staff permissions and review access audit records.', status: 'Permission-gated route', category: 'operations' }] : []),
    ...(capabilities.manageStaff ? [{ href: '/admin/payment-gateway', icon: <CreditCard size={22} />, title: 'Payment gateway settings', description: 'Inspect the saved gateway configuration and callback setup. Provider payments remain subject to separate verification.', status: 'Configuration view', category: 'operations' }] : []),
    ...(capabilities.manageStaff ? [{ href: '/admin/api-providers', icon: <PlugZap size={22} />, title: 'API provider settings', description: 'Review provider configuration and environment selection. Live service readiness is not inferred from saved settings.', status: 'Configuration view', category: 'operations' }] : []),
    ...(capabilities.manageStaff ? [
      { href: '/admin/platform-settings', icon: <ShieldCheck size={22} />, title: 'Site control & settings', description: 'Site identity, support contacts, appearance defaults and global platform settings.', status: 'Permission-gated route', category: 'platform' },
      { href: '/admin/feature-controls', icon: <LockKeyhole size={22} />, title: 'Feature controls', description: 'Review implemented service availability and feature switches with dependency and audit safeguards.', status: 'Permission-gated route', category: 'platform' },
      { href: '/admin/user-levels', icon: <ShieldCheck size={22} />, title: 'User types & level policies', description: 'Review account classifications and transaction-level policy controls.', status: 'Permission-gated route', category: 'platform' },
      { href: '/admin/users', icon: <Users size={22} />, title: 'User management', description: 'Account state, account type and level administration, subject to assigned access.', status: 'Permission-gated route', category: 'platform' },
    ] : []),
    { href: '/wallet/vtu/airtime', icon: <Smartphone size={22} />, title: 'Airtime purchase page', description: 'Open the dedicated signed-in airtime purchase experience.', status: 'Customer route', category: 'customer' },
    { href: '/customer/dashboard', icon: <WalletCards size={22} />, title: 'Customer dashboard', description: 'Review the customer-facing wallet, funding, and service links.', status: 'Customer route', category: 'customer' },
  ], [capabilities.approveRefunds, capabilities.manageStaff]);

  const filteredWorkspaces = useMemo(() => {
    const query = workspaceQuery.trim().toLocaleLowerCase();
    return workspaces.filter((workspace) => {
      const matchesCategory = workspaceCategory === 'all' || workspace.category === workspaceCategory;
      const searchable = [workspace.title, workspace.description, workspace.status, workspace.category].join(' ').toLocaleLowerCase();
      return matchesCategory && (!query || searchable.includes(query));
    });
  }, [workspaces, workspaceCategory, workspaceQuery]);

  return (
    <DashboardLayout title="Admin Command Center" description="A secure operational overview of SEMIZZY. Implemented administrative sections are listed for discoverability; each protected action still checks your assigned permissions on the server." admin canManageStaff={capabilities.manageStaff} canApproveRefunds={capabilities.approveRefunds}>
      <section aria-label="Operational metrics" className="grid grid-cols-1 gap-3 sm:grid-cols-2 2xl:grid-cols-4">
        <p className="col-span-full text-xs leading-5 text-[var(--muted)]">Snapshot of saved application records; these counts are not live provider health checks.</p>
        {capabilities.manageStaff && <Metric icon={<Users size={19} />} label="Registered accounts" value={metrics.users} detail={metrics.users === null ? "Account count unavailable" : "All customer and staff accounts"} href="/admin/users" />}
        {capabilities.manageStaff && <Metric icon={<WalletCards size={19} />} label="Wallet accounts" value={metrics.wallets} detail="Wallet records across currencies" />}
        {capabilities.manageStaff && <Metric icon={<CreditCard size={19} />} label="Funding in progress" value={metrics.pendingFunding} detail="Pending or processing intents" status={metrics.pendingFunding > 0 ? "in-progress" : "clear"} />}
        {capabilities.manageStaff && <Metric icon={<WalletCards size={19} />} label="Manual deposits to review" value={metrics.pendingManualDeposits} detail="Pending bank-transfer evidence reviews" status={metrics.pendingManualDeposits === null ? "unavailable" : metrics.pendingManualDeposits > 0 ? "attention" : "clear"} href="/admin/manual-deposits" />}
        {capabilities.approveRefunds && <Metric icon={<ShieldCheck size={19} />} label="Refunds needing action" value={metrics.refundsNeedingAction} detail={metrics.refundsNeedingAction === null ? "Refund count unavailable" : "Requested, blocked, or review required"} status={metrics.refundsNeedingAction === null ? "unavailable" : metrics.refundsNeedingAction > 0 ? "attention" : "clear"} href="/admin/payment-refunds" />}
        {capabilities.manageStaff && <Metric icon={<Smartphone size={19} />} label="Unresolved VTU orders" value={metrics.unresolvedVtuOrders} detail="Processing or awaiting reconciliation" status={metrics.unresolvedVtuOrders > 0 ? "attention" : "clear"} href="/admin/vtu-orders" />}
      </section>

      <section aria-labelledby="workspace-title" className="mt-8">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div className="min-w-0"><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--brand)]">Operations</p><h2 id="workspace-title" className="mt-2 font-display text-2xl font-extrabold">Workspaces</h2><p className="mt-1 max-w-2xl text-sm leading-6 text-[var(--muted)]">Choose an operational area. Available shortcuts follow your assigned permissions.</p></div><span role="status" aria-label="Data status: current saved application records; provider health is not checked" className="inline-flex w-fit items-center gap-2 rounded-full border border-[var(--line)] px-3 py-2 text-xs font-semibold text-[var(--muted)]"><Activity size={14} aria-hidden="true" /> Current data snapshot</span></div>
        <div className="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
          <label className="relative block min-w-0">
            <span className="sr-only">Search admin workspaces</span>
            <Search size={16} aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" />
            <input type="search" value={workspaceQuery} onChange={(event) => setWorkspaceQuery(event.target.value)} placeholder="Search workspaces or tasks" className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] py-2 pl-9 pr-3 text-sm outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)]" />
          </label>
          <label className="block">
            <span className="sr-only">Filter workspaces by category</span>
            <select value={workspaceCategory} onChange={(event) => setWorkspaceCategory(event.target.value)} className="min-h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-sm outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)]">
              <option value="all">All workspaces</option>
              <option value="operations">Operations</option>
              <option value="customer">Customer experience</option><option value="platform">Platform settings</option>
            </select>
          </label>
        </div>
        <p className="mt-3 text-xs text-[var(--muted)]" aria-live="polite">Showing {filteredWorkspaces.length} of {workspaces.length} available workspaces</p>
        {filteredWorkspaces.length > 0
          ? <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 2xl:grid-cols-3">
              {filteredWorkspaces.map((workspace) => <Workspace key={workspace.href} {...workspace} />)}
            </div>
          : <p className="mt-3 rounded-2xl border border-dashed border-[var(--line)] p-6 text-sm text-[var(--muted)]">No workspaces match this search. Try a different term or category.</p>}
      </section>

      <section aria-labelledby="security-scope-title" className="mt-8 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:p-6">
        <div className="flex items-start gap-3">
          <span aria-hidden="true" className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><LockKeyhole size={20} /></span>
          <div className="min-w-0">
            <p className="text-xs font-extrabold uppercase tracking-[0.16em] text-[var(--brand)]">Access governance</p>
            <h2 id="security-scope-title" className="mt-1 font-display text-lg font-extrabold">Security and operational scope</h2>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">Dashboard visibility is filtered by the capabilities supplied by the server. Each administrative route must still enforce its own authorization; hiding a link is not a security boundary.</p>
            <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
              <ScopeItem icon={<CheckCircle2 size={17} />} title="Permission-aware navigation" detail="Staff and refund workspaces appear only when the relevant capability is present." />
              <ScopeItem icon={<CircleAlert size={17} />} title="Integrations not presumed live" detail="A configuration page or saved credential does not prove a provider is connected, healthy, or enabled for live transactions." />
            </div>
            <p className="mt-4 text-xs leading-5 text-[var(--muted)]">Not represented as completed here: comprehensive KYC review, full security analytics, provider health checks, and live reconciliation monitoring unless separately implemented and verified.</p>
          </div>
        </div>
      </section>
    </DashboardLayout>
  );
}

function Metric({ icon, label, value, detail, status, href }: { icon: React.ReactNode; label: string; value: number | null; detail: string; href?: string; status?: 'attention' | 'in-progress' | 'clear' | 'unavailable' }) {
  const displayValue = value === null ? '—' : value.toLocaleString('en-NG');
  const statusLabel = value === null || status === 'unavailable'
    ? 'Status unavailable'
    : status === 'attention'
      ? 'Action may be required'
      : status === 'in-progress'
        ? 'Work in progress'
        : 'No outstanding items';
  const statusClass = status === 'attention'
    ? 'border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-300'
    : status === 'unavailable' || value === null
      ? 'border-[var(--line)] text-[var(--muted)]'
      : status === 'in-progress'
        ? 'border-sky-500/30 bg-sky-500/10 text-sky-800 dark:text-sky-300'
        : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300';
  const content = <><div className="flex min-w-0 items-start justify-between gap-3"><h2 className="min-w-0 text-xs font-bold leading-5 text-[var(--muted)]">{label}</h2><span aria-hidden="true" className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]">{icon}</span></div><p className="mt-4 break-words font-display text-3xl font-extrabold tabular-nums">{displayValue}</p><p className="mt-2 text-xs leading-5 text-[var(--muted)]">{detail}</p>{status && <p role="status" className={`mt-3 inline-flex w-fit max-w-full rounded-full border px-2.5 py-1 text-[10px] font-bold leading-4 ${statusClass}`}>{statusLabel}</p>}</>;
  const className = "flex min-w-0 flex-col rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:p-5";
  return href
    ? <a href={href} aria-label={`${label}: ${value === null ? 'unavailable' : displayValue}. Open details`} className={`${className} transition hover:border-[var(--brand)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]`}>{content}</a>
    : <article aria-label={`${label}: ${value === null ? 'unavailable' : displayValue}`} className={className}>{content}</article>;
}
function Workspace({ href, icon, title, description, status }: { href: string; icon: React.ReactNode; title: string; description: string; status: string }) {
  return <a href={href} className="group flex min-w-0 flex-col rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 transition hover:-translate-y-0.5 hover:border-[var(--brand)] hover:shadow-lg hover:shadow-slate-950/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"><span aria-hidden="true" className="grid h-12 w-12 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]">{icon}</span><div className="mt-5 flex items-center justify-between gap-3"><h3 className="min-w-0 font-display text-lg font-extrabold">{title}</h3><ArrowUpRight size={17} aria-hidden="true" className="shrink-0 text-[var(--muted)] transition group-hover:text-[var(--brand)]" /></div><p className="mt-2 flex-1 text-sm leading-6 text-[var(--muted)]">{description}</p><span className="mt-4 inline-flex w-fit max-w-full items-center gap-1.5 rounded-full border border-[var(--line)] px-2.5 py-1 text-[10px] font-bold text-[var(--muted)]"><span aria-hidden="true" className="inline-flex shrink-0">{status === "Permission-gated route" ? <ShieldCheck size={12} /> : status === "Configuration view" ? <PlugZap size={12} /> : <Smartphone size={12} />}</span><span>{status}</span></span></a>;
}
function ScopeItem({ icon, title, detail }: { icon: React.ReactNode; title: string; detail: string }) {
  return <div className="flex min-w-0 items-start gap-3 rounded-2xl border border-[var(--line)] bg-[var(--page)] p-4"><span aria-hidden="true" className="mt-0.5 shrink-0 text-[var(--brand)]">{icon}</span><div className="min-w-0"><h3 className="text-sm font-extrabold">{title}</h3><p className="mt-1 text-xs leading-5 text-[var(--muted)]">{detail}</p></div></div>;
}
