// Local, per-browser app preferences. Server-side migration config still lives
// in the backend .env / config; these are convenience defaults for the UI.

export interface AppSettings {
  defaultVendor: string;
  defaultTags: string;
  emailNotifications: boolean;
  pollSeconds: number;
}

const KEY = "w2s_settings";

const DEFAULTS: AppSettings = {
  defaultVendor: "",
  defaultTags: "",
  emailNotifications: false,
  pollSeconds: 3,
};

export function loadSettings(): AppSettings {
  try {
    return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(KEY) ?? "{}") };
  } catch {
    return { ...DEFAULTS };
  }
}

export function saveSettings(s: AppSettings): void {
  localStorage.setItem(KEY, JSON.stringify(s));
}
