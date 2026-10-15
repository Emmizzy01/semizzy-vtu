import { router } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';

type ThemeMode = 'light' | 'dark';

export default function ThemeToggle({ themeMode }: { themeMode: ThemeMode }) {
  const nextMode: ThemeMode = themeMode === 'dark' ? 'light' : 'dark';
  function toggleTheme() {
    router.put('/admin/platform-theme', { theme_mode: nextMode }, { preserveScroll: true, preserveState: true });
  }
  return (
    <button type="button" onClick={toggleTheme}
      aria-label={'Switch all users to ' + (nextMode === 'dark' ? 'night' : 'day') + ' mode'}
      title={'Global appearance: ' + (themeMode === 'dark' ? 'night' : 'day') + ' — tap to switch for everyone'}
      className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-sm font-semibold transition hover:border-[var(--brand)]">
      {themeMode === 'dark' ? <Sun size={16} aria-hidden="true" /> : <Moon size={16} aria-hidden="true" />}
      <span className="hidden sm:inline">{themeMode === 'dark' ? 'Day mode' : 'Night mode'}</span>
    </button>
  );
}
