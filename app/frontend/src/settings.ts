// Local, per-browser app preferences. Server-side migration config still lives
// in the backend .env / config; these are convenience defaults for the UI.

export interface AppSettings {
  defaultVendor: string;
  defaultTags: string;
  emailNotifications: boolean;
  pollSeconds: number;
  /** Tutorial video embed URL shown on the dashboard (YouTube/Vimeo embed link). */
  tutorialVideoUrl: string;
  /** Where the Support links point (mailto: or a help-desk URL). */
  supportUrl: string;
  /** Documentation link. */
  docsUrl: string;
}

const KEY = "store_shift_settings";

const DEFAULTS: AppSettings = {
  defaultVendor: "",
  defaultTags: "",
  emailNotifications: false,
  pollSeconds: 3,
  tutorialVideoUrl: "",
  supportUrl: "mailto:support@example.com",
  docsUrl: "https://woocommerce.com/document/woocommerce-rest-api/",
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
