import { usePage } from '@inertiajs/react';
import { LifeBuoy, LogOut } from 'lucide-react';

type MobileAppHeaderProps = {
  admin?: boolean;
  customerPreview?: boolean;
};

export default function MobileAppHeader({ admin = false, customerPreview = false }: MobileAppHeaderProps) {
  const { platform, auth } = usePage<{
    platform?: { name?: string };
    auth?: { user?: { name?: string; tier?: string; level?: string; user_level?: string; user_type?: string; avatar_url?: string | null; avatarUrl?: string | null } | null };
  }>().props;
  const user = auth?.user;
  const userName = user?.name?.trim() || (admin ? 'Semizzy admin' : 'Semizzy user');
  const userTier = user?.tier || user?.user_level || user?.level || user?.user_type || (admin ? 'Administrator' : 'Customer');
  const avatarUrl = user?.avatar_url || user?.avatarUrl || null;
  const initials = userName.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'S';
  const platformName = platform?.name || 'SEMIZZY';
  const home = admin ? '/admin/dashboard' : customerPreview ? '/customer/dashboard' : '/dashboard';

  return (
    <header className="mobile-app-header sticky top-0 z-40 border-b border-[var(--line)] bg-[var(--surface)]/95 px-4 py-3 backdrop-blur sm:hidden">
      <div className="flex min-w-0 items-center justify-between gap-3">
        <a href={admin ? home : '/profile'} className="flex min-w-0 items-center gap-2.5" aria-label={admin ? 'Open admin dashboard' : 'Open profile and account level'}>
          <span className="relative grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full border-2 border-white bg-emerald-100 text-sm font-extrabold text-emerald-800 shadow-sm dark:border-slate-800 dark:bg-emerald-950 dark:text-emerald-200">
            {avatarUrl ? <img src={avatarUrl} alt="" className="h-full w-full object-cover" /> : initials}
            <span className="absolute bottom-0 right-0 grid h-4 min-w-4 place-items-center rounded-full border border-white bg-amber-100 px-1 text-[8px] font-black text-amber-900 dark:border-slate-900" title={userTier}>{userTier.slice(0, 1).toUpperCase()}</span>
          </span>
          <span className="min-w-0">
            <span className="block text-[10px] font-semibold uppercase tracking-wide text-[var(--muted)]">{admin ? `${platformName} · Command Center` : 'Welcome back'}</span>
            <span className="block max-w-[55vw] truncate text-sm font-extrabold text-[var(--ink)]">{userName}</span>
            <span className="block truncate text-[10px] font-bold text-[var(--brand)]">{admin ? userTier : `Tier / level: ${userTier}`}</span>
          </span>
        </a>
        <div className="flex shrink-0 items-center gap-2">
          <a href="/support" aria-label="Help and support" title="Help and support" className="grid h-10 w-10 place-items-center rounded-xl border border-[var(--line)] text-[var(--brand)]"><LifeBuoy size={18} /></a>
          <form method="post" action="/logout">
            <input type="hidden" name="_token" value={typeof document === 'undefined' ? '' : (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '')} />
            <button type="submit" aria-label="Sign out" title="Sign out" className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--brand)] text-white"><LogOut size={17} /></button>
          </form>
        </div>
      </div>
    </header>
  );
}
