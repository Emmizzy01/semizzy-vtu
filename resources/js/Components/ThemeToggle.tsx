import { useEffect, useState } from 'react';
import { Monitor, Moon, Sun } from 'lucide-react';
import {
  applyThemePreference,
  nextThemePreference,
  readThemePreference,
  saveThemePreference,
  type ThemePreference,
} from './theme';

const labels: Record<ThemePreference, string> = {
  system: 'System theme',
  light: 'Light theme',
  dark: 'Dark theme',
};

export default function ThemeToggle() {
  const [preference, setPreference] = useState<ThemePreference>(() => readThemePreference());

  useEffect(() => {
    applyThemePreference(preference);

    if (preference !== 'system' || typeof window.matchMedia !== 'function') return;

    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const updateSystemTheme = () => applyThemePreference('system');
    media.addEventListener('change', updateSystemTheme);
    return () => media.removeEventListener('change', updateSystemTheme);
  }, [preference]);

  function cycleTheme() {
    const next = nextThemePreference(preference);
    saveThemePreference(next);
    applyThemePreference(next);
    setPreference(next);
  }

  return (
    <button
      type="button"
      onClick={cycleTheme}
      aria-label={`Current appearance: ${labels[preference]}. Change appearance.`}
      title={`Appearance: ${labels[preference]} — tap to change`}
      className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-sm font-semibold transition hover:border-[var(--brand)]"
    >
      {preference === 'system' ? <Monitor size={16} aria-hidden="true" /> : preference === 'light' ? <Sun size={16} aria-hidden="true" /> : <Moon size={16} aria-hidden="true" />}
      <span className="hidden sm:inline"> {preference === 'system' ? 'System' : preference === 'light' ? 'Light' : 'Dark'}</span>
    </button>
  );
}
