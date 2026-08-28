/**
 * The frontend runtime is intentionally limited to the built-in themes.
 * Directory themes selected on the server are still allowed to provide their
 * own Blade shell, but use the default runtime marker unless they are built-in.
 */
export const SITE_THEME_IDS = [
  'default',
  'anime',
  'technology',
  'elegant',
] as const;

export type SiteThemeId = (typeof SITE_THEME_IDS)[number];
export type SiteThemeAssetName = 'hero' | 'auth' | 'empty' | 'avatar' | 'brand';

export interface SiteThemeAssets {
  readonly hero: string;
  readonly auth: string;
  readonly empty: string;
  readonly avatar: string;
  readonly brand: string;
}

export interface SiteThemeNaiveOverrides {
  primaryColor?: string;
  primaryColorHover?: string;
  primaryColorPressed?: string;
  primaryColorSuppl?: string;
}

export interface SiteThemeNaiveButtonOverrides {
  textColorPrimary: string;
  textColorHoverPrimary: string;
  textColorPressedPrimary: string;
  textColorFocusPrimary: string;
}

export interface SiteThemeDefinition {
  readonly id: SiteThemeId;
  readonly label: string;
  readonly assets: SiteThemeAssets;
  readonly naiveThemeOverrides: SiteThemeNaiveOverrides;
  readonly naiveButtonOverrides?: SiteThemeNaiveButtonOverrides;
}

const LEGACY_BACKGROUND = new URL('../assets/bg.jpg', import.meta.url).href;
const LEGACY_EMPTY_IMAGE = new URL('../assets/empty-image.png', import.meta.url).href;
const BRAND_MARK = '/branding/brand-mark.png';
const DEFAULT_AVATAR = '/branding/default-avatar.webp';

const themeAssets = (theme: Exclude<SiteThemeId, 'default'>): SiteThemeAssets => ({
  hero: `/branding/themes/${theme}/hero.webp`,
  auth: `/branding/themes/${theme}/auth.webp`,
  empty: `/branding/themes/${theme}/empty.webp`,
  avatar: DEFAULT_AVATAR,
  brand: BRAND_MARK,
});

const WHITE_PRIMARY_BUTTON_TEXT = {
  textColorPrimary: '#ffffff',
  textColorHoverPrimary: '#ffffff',
  textColorPressedPrimary: '#ffffff',
  textColorFocusPrimary: '#ffffff',
} as const;

export const SITE_THEME_CATALOG: Readonly<Record<SiteThemeId, SiteThemeDefinition>> = {
  default: {
    id: 'default',
    label: 'Default',
    assets: {
      hero: LEGACY_BACKGROUND,
      auth: LEGACY_BACKGROUND,
      empty: LEGACY_EMPTY_IMAGE,
      avatar: DEFAULT_AVATAR,
      brand: BRAND_MARK,
    },
    naiveThemeOverrides: {
      primaryColor: '#0094c5',
    },
  },
  anime: {
    id: 'anime',
    label: 'Anime',
    assets: themeAssets('anime'),
    naiveThemeOverrides: {
      primaryColor: '#b42362',
      primaryColorHover: '#c0266d',
      primaryColorPressed: '#921647',
      primaryColorSuppl: '#b42362',
    },
    naiveButtonOverrides: WHITE_PRIMARY_BUTTON_TEXT,
  },
  technology: {
    id: 'technology',
    label: 'Technology',
    assets: themeAssets('technology'),
    naiveThemeOverrides: {
      primaryColor: '#1d4ed8',
      primaryColorHover: '#2563eb',
      primaryColorPressed: '#1e40af',
      primaryColorSuppl: '#1d4ed8',
    },
    naiveButtonOverrides: WHITE_PRIMARY_BUTTON_TEXT,
  },
  elegant: {
    id: 'elegant',
    label: 'Elegant',
    assets: themeAssets('elegant'),
    naiveThemeOverrides: {
      primaryColor: '#795548',
      primaryColorHover: '#8a624f',
      primaryColorPressed: '#5d4037',
      primaryColorSuppl: '#795548',
    },
    naiveButtonOverrides: WHITE_PRIMARY_BUTTON_TEXT,
  },
};

export function isSiteThemeId(value: unknown): value is SiteThemeId {
  return typeof value === 'string'
    && (SITE_THEME_IDS as readonly string[]).includes(value);
}

export function getSiteThemeDefinition(value: unknown): SiteThemeDefinition {
  const id = isSiteThemeId(value) ? value : 'default';

  return SITE_THEME_CATALOG[id];
}

export function getSiteThemeAssets(value: unknown): SiteThemeAssets {
  return getSiteThemeDefinition(value).assets;
}
