import {
  getSiteThemeDefinition,
  isSiteThemeId,
  type SiteThemeId,
} from './catalog.ts';

type SiteThemeDataset = {
  siteTheme?: string;
  [key: string]: string | undefined;
};

type SiteThemeRoot = {
  dataset: SiteThemeDataset;
};

type SiteThemeDocument = {
  documentElement: SiteThemeRoot | null;
};

export function resolveSiteTheme(value: unknown): SiteThemeId {
  return isSiteThemeId(value) ? value : 'default';
}

/**
 * Read the marker emitted by the Blade shell. The optional document argument
 * keeps the resolver deterministic and easy to exercise without a browser.
 */
export function readServerSiteTheme(
  root: SiteThemeDocument = document,
): SiteThemeId {
  return resolveSiteTheme(root.documentElement?.dataset.siteTheme);
}

/**
 * Normalize the marker again at runtime so malformed server markup can never
 * select an unscoped stylesheet or an unknown Naive UI palette.
 */
export function applySiteTheme(
  value: unknown,
  root: SiteThemeRoot = document.documentElement,
): SiteThemeId {
  const resolved = resolveSiteTheme(value);
  root.dataset.siteTheme = resolved;

  return resolved;
}

export {getSiteThemeDefinition};
