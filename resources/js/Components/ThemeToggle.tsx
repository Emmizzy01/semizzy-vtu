import { useEffect, useState } from 'react';
import { Moon, Sun } from 'lucide-react';
import { applyThemePreference, readThemePreference, saveThemePreference } from './theme';

type DayNight = 'light' | 'dark';

export default function ThemeToggle() {
  const [theme, setTheme] = useState<DayNight>(() => readThemePreference() === 'dark' ? 'dark' : 'light');

  useEffect(() => {
    applyThemePreference(theme);
  }, [theme]);

  function toggleTheme() {
    const next: DayNight = theme === 'dark' ? 'light' : 'dark';
    saveThemePreference(next);
    applyThemePreference(next);
    setTheme(next);
  }

  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label={`Switch to ${theme === 'dark' ? 'day' : 'night'} mode`}
      title={`Current mode: ${theme === 'dark' ? 'night' : 'day'} — tap to switch`}
      className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-sm font-semibold transition hover:border-[var(--brand)]"
    >
      {theme === 'dark' ? <Sun size={16} aria-hidden="true" /> : <Moon size={16} aria-hidden="true" />}
      <span className="hidden sm:inline">{theme === 'dark' ? 'Day mode' : 'Night mode'}</span>
    </button>
  );
}
