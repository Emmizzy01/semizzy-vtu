import { Head } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Clock3, Search, ShieldCheck, ShieldOff, Users, RefreshCw, History, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type StaffUser = {
  id: number;
  name: string;
  email: string;
  created_at: string | null;
  can_approve_refunds: boolean;
  permission_granted_at: string | null;
};

type Audit = {
  id: number;
  actor: string;
  actor_email: string | null;
  target: string;
  target_email: string | null;
  permission: string;
  action: 'granted' | 'revoked';
  reason: string;
  created_at: string | null;
};

type PageResponse<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

function dateLabel(value: string | null) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-NG', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

export default function StaffAccessDashboard() {
  const [users, setUsers] = useState<StaffUser[]>([]);
  const [audits, setAudits] = useState<Audit[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [activeUser, setActiveUser] = useState<StaffUser | null>(null);
  const [action, setAction] = useState<'granted' | 'revoked'>('granted');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [auditOpen, setAuditOpen] = useState(true);

  const load = useCallback(async (nextPage = page, nextSearch = search) => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams({ page: String(nextPage), per_page: '20' });
      if (nextSearch.trim()) params.set('search', nextSearch.trim());
      const [usersResponse, auditResponse] = await Promise.all([
        fetch('/admin/staff/users?' + params.toString(), {
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        }),
        fetch('/admin/staff/permission-audits', {
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        }),
      ]);
      if (!usersResponse.ok || !auditResponse.ok) {
        throw new Error(usersResponse.status === 403 || auditResponse.status === 403
          ? 'Your account is not authorized to manage staff permissions.'
          : 'Could not load staff access records.');
      }
      const userPayload = await usersResponse.json() as PageResponse<StaffUser>;
      const auditPayload = await auditResponse.json() as { data: Audit[] };
      setUsers(userPayload.data);
      setTotal(userPayload.meta.total);
      setLastPage(userPayload.meta.last_page);
      setPage(userPayload.meta.current_page);
      setAudits(auditPayload.data);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Could not load staff access records.');
    } finally {
      setLoading(false);
    }
  }, [page, search]);

  useEffect(() => { void load(1, ''); }, []);

  const enabledCount = useMemo(() => users.filter((user) => user.can_approve_refunds).length, [users]);

  function openChange(user: StaffUser, nextAction: 'granted' | 'revoked') {
    setActiveUser(user);
    setAction(nextAction);
    setReason('');
    setError('');
    setNotice('');
  }

  async function submitChange() {
    if (!activeUser || reason.trim().length < 8) return;
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      const response = await fetch('/admin/staff/users/' + activeUser.id + '/permissions', {
        method: action === 'granted' ? 'POST' : 'DELETE',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify({ permission: 'refunds.approve', reason: reason.trim() }),
      });
      const payload = await response.json().catch(() => ({})) as { message?: string; errors?: Record<string, string[]> };
      if (!response.ok) {
        const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(validation || payload.message || 'Permission change was rejected.');
      }
      setNotice(payload.message || 'Staff access updated.');
      setActiveUser(null);
      await load(page, search);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Permission change was rejected.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Head title="Staff access management" />
      <main className="min-h-screen bg-[var(--page)] px-4 py-5 text-[var(--ink)] sm:px-6 lg:px-10 lg:py-8">
        <div className="mx-auto max-w-7xl">
          <header className="flex flex-wrap items-center justify-between gap-4">
            <a href="/" className="flex items-center gap-3" aria-label="SEMIZZY home">
              <span className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span><span className="block font-display text-lg font-extrabold tracking-tight">SEMIZZY</span><span className="block text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--muted)]">Security & access</span></span>
            </a>
            <div className="flex items-center gap-2">
              <span className="hidden items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-xs font-semibold text-[var(--muted)] sm:inline-flex"><ShieldCheck size={15} /> Restricted workspace</span>
              <button type="button" onClick={() => { const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = next; localStorage.setItem('semizzy-theme', next); }} className="rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-sm font-semibold hover:border-[var(--brand)]">Toggle theme</button>
              <button type="button" onClick={() => void load(page, search)} disabled={loading} className="inline-flex items-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60"><RefreshCw size={15} className={loading ? 'animate-spin' : ''} /> Refresh</button>
            </div>
          </header>

          <section className="mt-9 flex flex-col justify-between gap-4 md:flex-row md:items-end">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-[var(--brand)]">Administration · Least privilege</p>
              <h1 className="mt-2 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Staff access</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">Grant or remove refund-approval access with a documented reason. Access-manager privileges remain CLI-controlled.</p>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Stat label="Registered accounts" value={total} />
              <Stat label="Refund approvers on page" value={enabledCount} />
            </div>
          </section>

          {error && <div role="alert" className="mt-5 flex items-start gap-3 rounded-2xl border border-rose-500/25 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-300"><AlertCircle size={18} className="mt-0.5 shrink-0" /><span>{error}</span></div>}
          {notice && <div role="status" className="mt-5 flex items-start gap-3 rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-4 text-sm text-emerald-700 dark:text-emerald-300"><CheckCircle2 size={18} className="mt-0.5 shrink-0" /><span>{notice}</span></div>}

          <section className="mt-6 overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)] shadow-sm">
            <div className="flex flex-col gap-4 border-b border-[var(--line)] p-4 sm:p-5 md:flex-row md:items-center md:justify-between">
              <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand)]"><Users size={20} /></span><div><h2 className="font-display text-lg font-extrabold">User directory</h2><p className="text-xs text-[var(--muted)]">Search accounts and manage the refund approver permission</p></div></div>
              <form className="flex gap-2" onSubmit={(event) => { event.preventDefault(); setPage(1); void load(1, search); }}>
                <label className="relative block"><Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" /><span className="sr-only">Search users</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, email or user ID" className="w-full rounded-xl border border-[var(--line)] bg-[var(--page)] py-2.5 pl-9 pr-3 text-sm outline-none focus:border-[var(--brand)] sm:w-72" /></label>
                <button type="submit" disabled={loading} className="rounded-xl border border-[var(--line)] px-4 py-2.5 text-sm font-bold hover:border-[var(--brand)] disabled:opacity-50">Search</button>
              </form>
            </div>

            {loading ? <div className="flex items-center justify-center gap-3 px-6 py-16 text-sm text-[var(--muted)]"><RefreshCw size={17} className="animate-spin" /> Loading user records…</div>
              : users.length === 0 ? <div className="px-6 py-16 text-center"><Users size={24} className="mx-auto text-[var(--muted)]" /><p className="mt-3 font-bold">No matching accounts</p><p className="mt-1 text-sm text-[var(--muted)]">Try another name, email, or user ID.</p></div>
              : <div className="divide-y divide-[var(--line)]">
                {users.map((user) => (
                  <article key={user.id} className="flex flex-col gap-4 p-4 sm:p-5 md:flex-row md:items-center md:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                      <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-[var(--page)] font-display font-extrabold text-[var(--brand)]">{(user.name || user.email).slice(0, 1).toUpperCase()}</span>
                      <div className="min-w-0"><p className="break-words font-bold">{user.name}</p><p className="break-all text-sm text-[var(--muted)]">{user.email}</p><p className="mt-1 text-xs text-[var(--muted)]">User #{user.id} · Joined {dateLabel(user.created_at)}</p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                          <span className={user.can_approve_refunds ? 'rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-bold text-emerald-700 dark:text-emerald-300' : 'rounded-full border border-[var(--line)] px-2.5 py-1 text-[11px] font-bold text-[var(--muted)]'}>{user.can_approve_refunds ? 'Refund approver' : 'No refund approval access'}</span>
                          {user.permission_granted_at && <span className="text-[11px] text-[var(--muted)]">Granted {dateLabel(user.permission_granted_at)}</span>}
                        </div>
                      </div>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                      {user.can_approve_refunds
                        ? <button type="button" onClick={() => openChange(user, 'revoked')} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-rose-500/25 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-500/10 dark:text-rose-300"><ShieldOff size={16} /> Revoke access</button>
                        : <button type="button" onClick={() => openChange(user, 'granted')} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-3 py-2 text-sm font-bold text-white hover:opacity-90"><ShieldCheck size={16} /> Grant access</button>}
                    </div>
                  </article>
                ))}
              </div>}
            <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--line)] bg-[var(--page)]/60 px-4 py-3 text-xs text-[var(--muted)] sm:px-5">
              <span>Page {page} of {lastPage} · {total} accounts</span>
              <div className="flex gap-2"><button type="button" disabled={page <= 1 || loading} onClick={() => void load(page - 1, search)} className="rounded-lg border border-[var(--line)] px-3 py-2 font-semibold disabled:opacity-40">Previous</button><button type="button" disabled={page >= lastPage || loading} onClick={() => void load(page + 1, search)} className="rounded-lg border border-[var(--line)] px-3 py-2 font-semibold disabled:opacity-40">Next</button></div>
            </footer>
          </section>

          <section className="mt-6 overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
            <button type="button" onClick={() => setAuditOpen((open) => !open)} className="flex w-full items-center justify-between gap-3 p-4 text-left sm:p-5"><span className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--page)] text-[var(--brand)]"><History size={20} /></span><span><span className="block font-display text-lg font-extrabold">Permission audit trail</span><span className="mt-0.5 block text-xs text-[var(--muted)]">Latest 50 changes, including who changed access and why</span></span></span><span className="text-sm font-bold text-[var(--brand)]">{auditOpen ? 'Hide' : 'Show'}</span></button>
            {auditOpen && <div className="border-t border-[var(--line)]">
              {audits.length === 0 ? <p className="px-5 py-8 text-sm text-[var(--muted)]">No permission changes have been recorded yet.</p> : <div className="divide-y divide-[var(--line)]">{audits.map((entry) => <article key={entry.id} className="grid gap-2 p-4 sm:p-5 md:grid-cols-[minmax(0,1fr)_auto]"><div><div className="flex flex-wrap items-center gap-2"><span className={entry.action === 'granted' ? 'rounded-full bg-emerald-500/10 px-2 py-1 text-[11px] font-bold text-emerald-700 dark:text-emerald-300' : 'rounded-full bg-rose-500/10 px-2 py-1 text-[11px] font-bold text-rose-700 dark:text-rose-300'}>{entry.action === 'granted' ? 'Access granted' : 'Access revoked'}</span><span className="text-sm font-bold">{entry.target}</span><span className="break-all text-xs text-[var(--muted)]">{entry.target_email}</span></div><p className="mt-2 text-sm leading-5">{entry.reason}</p><p className="mt-2 text-xs text-[var(--muted)]">By {entry.actor}{entry.actor_email ? ' (' + entry.actor_email + ')' : ''} · {entry.permission}</p></div><p className="flex items-center gap-1.5 text-xs text-[var(--muted)]"><Clock3 size={13} /> {dateLabel(entry.created_at)}</p></article>)}</div>}
            </div>}
          </section>
          <p className="mt-5 text-center text-xs text-[var(--muted)]">Privileged access changes require a reason and are recorded atomically with the permission update.</p>
        </div>
      </main>

      {activeUser && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" role="presentation">
        <section role="dialog" aria-modal="true" aria-labelledby="permission-dialog-title" className="w-full max-w-lg rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 text-[var(--ink)] shadow-2xl sm:p-6">
          <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[0.18em] text-[var(--brand)]">Privileged change</p><h2 id="permission-dialog-title" className="mt-2 font-display text-xl font-extrabold">{action === 'granted' ? 'Grant refund approval?' : 'Revoke refund approval?'}</h2><p className="mt-2 break-words text-sm text-[var(--muted)]">{activeUser.name} · {activeUser.email}</p></div><button type="button" onClick={() => setActiveUser(null)} aria-label="Close dialog" className="rounded-xl border border-[var(--line)] p-2"><X size={17} /></button></div>
          <div className="mt-5 rounded-2xl border border-[var(--line)] bg-[var(--page)] p-3"><p className="text-sm font-bold">Permission</p><p className="mt-1 font-mono text-sm">refunds.approve</p><p className="mt-2 text-xs leading-5 text-[var(--muted)]">This permits approving eligible customer refund requests. It does not grant access-management privileges.</p></div>
          <label className="mt-5 block text-sm font-bold" htmlFor="permission-reason">Reason for this change <span className="text-rose-600">*</span></label>
          <textarea id="permission-reason" value={reason} onChange={(event) => setReason(event.target.value)} minLength={8} maxLength={500} rows={3} placeholder="Example: Finance duty assignment approved by operations lead." className="mt-2 w-full resize-y rounded-xl border border-[var(--line)] bg-[var(--page)] p-3 text-sm outline-none focus:border-[var(--brand)]" />
          <div className="mt-2 flex items-center justify-between gap-3 text-xs text-[var(--muted)]"><span>Required for the audit trail; 8–500 characters.</span><span>{reason.trim().length}/500</span></div>
          <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" disabled={busy} onClick={() => setActiveUser(null)} className="rounded-xl border border-[var(--line)] px-4 py-2.5 text-sm font-bold">Cancel</button><button type="button" disabled={busy || reason.trim().length < 8} onClick={() => void submitChange()} className={action === 'granted' ? 'inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50' : 'inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50'}>{busy ? <RefreshCw size={15} className="animate-spin" /> : action === 'granted' ? <ShieldCheck size={16} /> : <ShieldOff size={16} />}{busy ? 'Saving…' : action === 'granted' ? 'Grant permission' : 'Revoke permission'}</button></div>
        </section>
      </div>}
    </>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return <div className="min-w-32 rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-4 py-3"><p className="text-xs font-semibold text-[var(--muted)]">{label}</p><p className="mt-1 font-display text-2xl font-extrabold tabular-nums">{value}</p></div>;
}
