export type ThemePreference = 'system' | 'light' | 'dark';

const STORAGE_KEY = 'semizzy-theme';

export function readThemePreference(): ThemePreference {
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);
    if (saved === 'light' || saved === 'dark' || saved === 'system') return saved;
  } catch {
    // Browser storage is optional; system preference remains the fallback.
  }
  return 'system';
}

export function applyThemePreference(preference: ThemePreference): void {
  if (typeof document === 'undefined') return;
  if (preference === 'system') {
    document.documentElement.removeAttribute('data-theme');
  } else {
    document.documentElement.dataset.theme = preference;
  }
}

export function saveThemePreference(preference: ThemePreference): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, preference);
  } catch {
    // The selected theme still applies for this page if storage is unavailable.
  }
}

export function nextThemePreference(preference: ThemePreference): ThemePreference {
  if (preference === 'system') return 'light';
  if (preference === 'light') return 'dark';
  return 'system';
}
