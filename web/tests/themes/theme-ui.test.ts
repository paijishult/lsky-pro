import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {
  SITE_THEME_CATALOG,
  SITE_THEME_IDS,
  getSiteThemeAssets,
} from '../../src/themes/catalog.ts';

const TEST_DIR = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(TEST_DIR, '../../..');
const THEMES_DIR = path.join(PROJECT_ROOT, 'web/src/themes');
const GENERATED_ASSETS_DIR = path.join(PROJECT_ROOT, 'web/src/assets/generated');
const FILAMENT_THEME_CSS = path.join(PROJECT_ROOT, 'resources/css/filament/admin/theme.css');
const FILAMENT_BRAND_VIEW = path.join(PROJECT_ROOT, 'resources/views/filament/theme/brand.blade.php');
const FILAMENT_PROVIDER = path.join(PROJECT_ROOT, 'app/Providers/Filament/AdminPanelProvider.php');
const FRONTEND_INDEX = path.join(PROJECT_ROOT, 'web/index.html');
const GENERATED_INDEX = path.join(PROJECT_ROOT, 'resources/views/themes/default/index.blade.php');
const BRANDING_DIR = path.join(PROJECT_ROOT, 'public/branding');
const WEB_ROUTES = path.join(PROJECT_ROOT, 'routes/web.php');
const BRANDING_CONTROLLER = path.join(PROJECT_ROOT, 'app/Http/Controllers/BrandingAssetController.php');
const PUBLIC_NGINX = path.join(PROJECT_ROOT, 'public/nginx.conf');
const DOCKER_NGINX = path.join(PROJECT_ROOT, 'docker/config/nginx.conf');
const DOCKERFILE = path.join(PROJECT_ROOT, 'Dockerfile');
const DOCKERIGNORE = path.join(PROJECT_ROOT, '.dockerignore');
const VITE_CONFIG = path.join(PROJECT_ROOT, 'web/vite.config.ts');
const NON_DEFAULT_SCOPE = /data-site-theme\s*=\s*["'](?:anime|technology|elegant)["']/;
const PNG_SIGNATURE = [137, 80, 78, 71, 13, 10, 26, 10];

function stripComments(content) {
  return content.replace(/\/\*[\s\S]*?\*\//g, '');
}

function balancedCss(content) {
  const source = stripComments(content);
  let depth = 0;

  for (const character of source) {
    if (character === '{') depth += 1;
    if (character === '}') depth -= 1;
    if (depth < 0) return false;
  }

  return depth === 0;
}

function relativeLuminance(hex) {
  const channels = hex.slice(1).match(/.{2}/g).map(channel => Number.parseInt(channel, 16) / 255);
  const [red, green, blue] = channels.map(channel => (
    channel <= 0.04045
      ? channel / 12.92
      : ((channel + 0.055) / 1.055) ** 2.4
  ));

  return (0.2126 * red) + (0.7152 * green) + (0.0722 * blue);
}

function contrastRatio(first, second) {
  const firstLuminance = relativeLuminance(first);
  const secondLuminance = relativeLuminance(second);
  const lighter = Math.max(firstLuminance, secondLuminance);
  const darker = Math.min(firstLuminance, secondLuminance);

  return (lighter + 0.05) / (darker + 0.05);
}

function splitTopLevelSelectors(prelude) {
  const selectors = [];
  let start = 0;
  let parentheses = 0;
  let brackets = 0;
  let quote = null;

  for (let index = 0; index < prelude.length; index += 1) {
    const character = prelude[index];

    if (quote) {
      if (character === quote && prelude[index - 1] !== '\\') quote = null;
      continue;
    }

    if (character === '"' || character === "'") {
      quote = character;
    } else if (character === '(') {
      parentheses += 1;
    } else if (character === ')') {
      parentheses -= 1;
    } else if (character === '[') {
      brackets += 1;
    } else if (character === ']') {
      brackets -= 1;
    } else if (character === ',' && parentheses === 0 && brackets === 0) {
      selectors.push(prelude.slice(start, index).trim());
      start = index + 1;
    }
  }

  selectors.push(prelude.slice(start).trim());
  return selectors.filter(Boolean);
}

function extractStyleSelectors(content) {
  const source = stripComments(content);
  const selectors = [];
  let start = 0;

  for (let index = 0; index < source.length; index += 1) {
    if (source[index] === '{') {
      const prelude = source.slice(start, index).trim();
      if (prelude && !prelude.startsWith('@')) {
        const isKeyframeStep = /^(?:from|to|(?:\d+(?:\.\d+)?%)(?:\s*,\s*(?:\d+(?:\.\d+)?%|from|to))*)$/.test(prelude);
        if (!isKeyframeStep) selectors.push(...splitTopLevelSelectors(prelude));
      }
      start = index + 1;
    } else if (source[index] === '}') {
      start = index + 1;
    }
  }

  return selectors;
}

function readPngDimensions(buffer) {
  assert.deepEqual([...buffer.subarray(0, 8)], PNG_SIGNATURE);
  return {
    width: buffer.readUInt32BE(16),
    height: buffer.readUInt32BE(20),
  };
}

function readWebpDimensionsFromBuffer(buffer) {
  assert.equal(buffer.subarray(0, 4).toString('ascii'), 'RIFF');
  assert.equal(buffer.subarray(8, 12).toString('ascii'), 'WEBP');

  let offset = 12;
  while (offset + 8 <= buffer.length) {
    const chunk = buffer.subarray(offset, offset + 4).toString('ascii');
    const chunkSize = buffer.readUInt32LE(offset + 4);
    const dataOffset = offset + 8;

    if (chunk === 'VP8X') {
      return {
        width: 1 + buffer.readUIntLE(dataOffset + 4, 3),
        height: 1 + buffer.readUIntLE(dataOffset + 7, 3),
      };
    }

    if (chunk === 'VP8 ') {
      assert.deepEqual([...buffer.subarray(dataOffset + 3, dataOffset + 6)], [157, 1, 42]);
      return {
        width: buffer.readUInt16LE(dataOffset + 6) & 0x3fff,
        height: buffer.readUInt16LE(dataOffset + 8) & 0x3fff,
      };
    }

    if (chunk === 'VP8L') {
      assert.equal(buffer[dataOffset], 0x2f);
      const bits = buffer.readUInt32LE(dataOffset + 1);
      return {
        width: 1 + (bits & 0x3fff),
        height: 1 + ((bits >>> 14) & 0x3fff),
      };
    }

    offset = dataOffset + chunkSize + (chunkSize % 2);
  }

  assert.fail('WebP dimensions were not found');
}

function readWebpDimensions(filePath) {
  return readWebpDimensionsFromBuffer(fs.readFileSync(filePath));
}

function extractEncodedConstant(content, name) {
  return content.match(new RegExp(`\\b${name} = '([^']+)'`))?.[1];
}

test('all built-in theme CSS files exist and are non-empty', () => {
  for (const file of ['tokens.css', 'anime.css', 'technology.css', 'elegant.css', 'components.css', 'site-theme.css']) {
    const filePath = path.join(THEMES_DIR, file);
    assert.ok(fs.existsSync(filePath), `${file} should exist`);
    assert.ok(fs.statSync(filePath).size > 50, `${file} should not be empty`);
  }
});

test('site-theme.css imports all theme modules in the intended order', () => {
  const content = fs.readFileSync(path.join(THEMES_DIR, 'site-theme.css'), 'utf8');
  const imports = [...content.matchAll(/@import\s+["']([^"']+)["'];/g)].map(match => match[1]);

  assert.deepEqual(imports, ['./tokens.css', './anime.css', './technology.css', './elegant.css', './components.css']);
});

test('every theme rule is scoped to a non-default runtime theme', () => {
  for (const file of ['tokens.css', 'anime.css', 'technology.css', 'elegant.css', 'components.css']) {
    const content = fs.readFileSync(path.join(THEMES_DIR, file), 'utf8');
    assert.ok(balancedCss(content), `Braces must be balanced in ${file}`);

    for (const selector of extractStyleSelectors(content)) {
      assert.match(selector, NON_DEFAULT_SCOPE, `${file} contains an unscoped selector: ${selector}`);
      assert.doesNotMatch(selector, /data-site-theme\s*=\s*["']default["']/, `${file} must not style default explicitly`);
    }
  }
});

test('Filament theme additions are scoped without overriding the configured primary palette', () => {
  const content = fs.readFileSync(FILAMENT_THEME_CSS, 'utf8');
  const marker = content.indexOf('Filament Theme Scopes');
  assert.notEqual(marker, -1, 'Filament theme scope marker should exist');

  const themedSection = content.slice(marker);
  assert.ok(balancedCss(themedSection), 'Filament themed CSS braces must be balanced');
  assert.doesNotMatch(
    themedSection,
    /--primary-(?:50|100|200|300|400|500|600|700|800|900|950)\s*:/,
    'Site themes must not replace AdminSettings.primary_color variables',
  );
  assert.match(themedSection, /--site-admin-accent:/);
  assert.match(themedSection, /--site-admin-accent-rgb:/);

  const focusBlocks = [...themedSection.matchAll(/\.fi-input-wrp:focus-within\s*\{([^}]*)\}/g)];
  assert.equal(focusBlocks.length, 3);
  for (const [, declarations] of focusBlocks) {
    assert.match(declarations, /var\(--primary-(?:500|600)\)/, 'Focus styling must use Filament primary tokens');
    assert.doesNotMatch(declarations, /var\(--site-admin-accent/, 'Focus styling must not replace the configured primary color');
  }

  for (const selector of extractStyleSelectors(themedSection)) {
    assert.match(selector, NON_DEFAULT_SCOPE, `Filament contains an unscoped selector: ${selector}`);
  }
});

test('non-default Naive primary button states meet WCAG AA contrast', () => {
  for (const theme of ['anime', 'technology', 'elegant']) {
    const definition = SITE_THEME_CATALOG[theme];
    const colors = definition.naiveThemeOverrides;
    const text = definition.naiveButtonOverrides;
    assert.ok(text, `${theme} must declare primary button text colors`);

    const states = [
      ['normal', colors.primaryColor, text.textColorPrimary],
      ['hover', colors.primaryColorHover, text.textColorHoverPrimary],
      ['pressed', colors.primaryColorPressed, text.textColorPressedPrimary],
    ];

    for (const [state, background, foreground] of states) {
      assert.equal(typeof background, 'string', `${theme} ${state} background should exist`);
      const ratio = contrastRatio(background, foreground);
      assert.ok(
        ratio >= 4.5,
        `${theme} ${state} contrast ${ratio.toFixed(2)} must be at least 4.5:1`,
      );
    }
  }
});

test('four-theme asset catalog is complete and resolves only the selected theme', () => {
  assert.deepEqual([...SITE_THEME_IDS], ['default', 'anime', 'technology', 'elegant']);

  for (const theme of SITE_THEME_IDS) {
    const definition = SITE_THEME_CATALOG[theme];
    assert.equal(definition.id, theme);
    assert.deepEqual(Object.keys(definition.assets).sort(), ['auth', 'avatar', 'brand', 'empty', 'hero']);
    for (const asset of Object.values(definition.assets)) {
      assert.equal(typeof asset, 'string');
      assert.ok(asset.length > 0);
    }
  }

  assert.ok(SITE_THEME_CATALOG.default.assets.hero.endsWith('/web/src/assets/bg.jpg'));
  assert.equal(SITE_THEME_CATALOG.default.assets.auth, SITE_THEME_CATALOG.default.assets.hero);
  assert.ok(SITE_THEME_CATALOG.default.assets.empty.endsWith('/web/src/assets/empty-image.png'));
  assert.equal(SITE_THEME_CATALOG.default.assets.avatar, '/branding/default-avatar.webp');
  assert.equal(SITE_THEME_CATALOG.default.assets.brand, '/branding/brand-mark.png');

  for (const theme of ['anime', 'technology', 'elegant']) {
    const assets = getSiteThemeAssets(theme);
    const serialized = JSON.stringify(assets);
    assert.equal(assets.hero, `/branding/themes/${theme}/hero.webp`);
    assert.equal(assets.auth, `/branding/themes/${theme}/auth.webp`);
    assert.equal(assets.empty, `/branding/themes/${theme}/empty.webp`);

    for (const otherTheme of ['anime', 'technology', 'elegant']) {
      if (otherTheme !== theme) {
        assert.doesNotMatch(serialized, new RegExp(`/branding/themes/${otherTheme}/`));
      }
    }
  }

  assert.doesNotMatch(JSON.stringify(getSiteThemeAssets('default')), /\/branding\/themes\//);
});

test('optimized theme assets and shared branding files meet format, dimension, and size budgets', () => {
  const budgets = {
    hero: {width: 1600, height: 900, bytes: 400 * 1024},
    auth: {width: 1280, height: 960, bytes: 300 * 1024},
    empty: {width: 720, height: 720, bytes: 150 * 1024},
  };

  for (const theme of ['anime', 'technology', 'elegant']) {
    for (const [asset, budget] of Object.entries(budgets)) {
      const file = path.join(BRANDING_DIR, 'themes', theme, `${asset}.webp`);
      assert.ok(fs.existsSync(file), `${theme}/${asset}.webp should exist`);
      assert.ok(fs.statSync(file).size <= budget.bytes, `${theme}/${asset}.webp exceeds its budget`);
      assert.deepEqual(readWebpDimensions(file), {width: budget.width, height: budget.height});
    }
  }

  const brandMark = fs.readFileSync(path.join(BRANDING_DIR, 'brand-mark.png'));
  assert.deepEqual(readPngDimensions(brandMark), {width: 512, height: 512});
  assert.equal(brandMark[25], 6, 'brand mark should retain an alpha channel');

  const appleTouchIcon = fs.readFileSync(path.join(BRANDING_DIR, 'apple-touch-icon.png'));
  assert.deepEqual(readPngDimensions(appleTouchIcon), {width: 180, height: 180});

  assert.deepEqual(
    readWebpDimensions(path.join(BRANDING_DIR, 'default-avatar.webp')),
    {width: 512, height: 512},
  );

  const favicon = fs.readFileSync(path.join(BRANDING_DIR, 'favicon.ico'));
  assert.equal(favicon.readUInt16LE(0), 0);
  assert.equal(favicon.readUInt16LE(2), 1);
  assert.equal(favicon.readUInt16LE(4), 4);
  const faviconSizes = [];
  for (let index = 0; index < 4; index += 1) {
    const entry = 6 + (index * 16);
    const width = favicon[entry] || 256;
    const height = favicon[entry + 1] || 256;
    const size = favicon.readUInt32LE(entry + 8);
    const offset = favicon.readUInt32LE(entry + 12);
    const payload = favicon.subarray(offset, offset + size);
    assert.deepEqual(readPngDimensions(payload), {width, height});
    faviconSizes.push(width);
  }
  assert.deepEqual(faviconSizes, [16, 32, 48, 64]);

  assert.equal(fs.existsSync(GENERATED_ASSETS_DIR), false, 'large generated source PNGs must not ship');
});

test('runtime UI keeps custom backgrounds authoritative and centralizes theme placeholders', () => {
  const home = fs.readFileSync(path.join(PROJECT_ROOT, 'web/src/views/HomeView.vue'), 'utf8');
  const auth = fs.readFileSync(path.join(PROJECT_ROOT, 'web/src/components/Auth/AuthFormCard.vue'), 'utf8');
  const app = fs.readFileSync(path.join(PROJECT_ROOT, 'web/src/App.vue'), 'utf8');
  const notFound = fs.readFileSync(path.join(PROJECT_ROOT, 'web/src/views/NotFoundView.vue'), 'utf8');
  const avatarUtil = fs.readFileSync(path.join(PROJECT_ROOT, 'web/src/utils/app.ts'), 'utf8');
  const exploreAlbum = fs.readFileSync(path.join(PROJECT_ROOT, 'web/src/components/Explore/ExploreAlbum.vue'), 'utf8');

  assert.ok(home.indexOf('homepage_background_image_url') < home.indexOf('homepage_background_images'));
  assert.ok(home.indexOf('homepage_background_images') < home.indexOf('siteThemeAssets.hero'));
  assert.ok(auth.indexOf('auth_page_background_image_url') < auth.indexOf('auth_page_background_images'));
  assert.ok(auth.indexOf('auth_page_background_images') < auth.indexOf('siteThemeAssets.auth'));
  assert.doesNotMatch(home, /branding\/themes\/(?:anime|technology|elegant)/);
  assert.doesNotMatch(auth, /branding\/themes\/(?:anime|technology|elegant)/);

  assert.match(app, /component-options="componentOptions"/);
  assert.match(app, /siteThemeDefinition\.value\.assets\.empty/);
  assert.match(notFound, /siteThemeAssets\.empty/);
  assert.match(avatarUtil, /return avatar \|\| siteThemeAssets\.avatar/);
  assert.doesNotMatch(avatarUtil, /assets\/avatar\.png/);
  assert.match(exploreAlbum, /getSiteThemeAssets\(readServerSiteTheme\(\)\)\.empty/);
});

test('theme CSS contains no unavailable generated image URLs', () => {
  const css = fs.readdirSync(THEMES_DIR)
    .filter(file => file.endsWith('.css'))
    .map(file => fs.readFileSync(path.join(THEMES_DIR, file), 'utf8'))
    .join('\n');

  assert.doesNotMatch(css, /\/branding\/themes\//);
  assert.doesNotMatch(css, /url\([^)]*\.webp(?:["')]\s*|\s)/i);
  assert.equal(fs.existsSync(path.join(GENERATED_ASSETS_DIR, 'anime-hero.png')), false);
});

test('Blade build chain keeps the server theme marker and stable branding metadata', () => {
  for (const file of [FRONTEND_INDEX, GENERATED_INDEX]) {
    const content = fs.readFileSync(file, 'utf8');
    assert.match(content, /data-site-theme=/, `${file} should preserve data-site-theme`);
    assert.match(content, /\\App\\Support\\SiteTheme::DEFAULT/, `${file} should preserve the Blade default expression`);
    assert.match(content, /\/branding\/favicon\.ico/, `${file} should use the stable favicon endpoint`);
    assert.match(content, /\/branding\/brand-mark\.png/, `${file} should expose the PNG brand mark`);
    assert.match(content, /\/branding\/apple-touch-icon\.png/, `${file} should preserve the stable Apple touch icon endpoint`);
    assert.doesNotMatch(content, /\/branding\/themes\//, `${file} must not preload inactive theme art`);
  }
});

test('branding assets are local, valid, and text-free where required', () => {
  const svg = fs.readFileSync(path.join(BRANDING_DIR, 'logo.svg'), 'utf8');
  assert.match(svg, /^<svg\s/);
  assert.match(svg, /viewBox="0 0 256 256"/);
  assert.doesNotMatch(svg, /<text(?:\s|>)/i);

  const png = fs.readFileSync(path.join(BRANDING_DIR, 'apple-touch-icon.png'));
  assert.deepEqual(readPngDimensions(png), {width: 180, height: 180});

  const ico = fs.readFileSync(path.join(BRANDING_DIR, 'favicon.ico'));
  assert.equal(ico.readUInt16LE(0), 0);
  assert.equal(ico.readUInt16LE(2), 1);
  assert.equal(ico.readUInt16LE(4), 4);
});

test('brand mark is shown only in first-party header and Filament branding surfaces', () => {
  const header = fs.readFileSync(path.join(PROJECT_ROOT, 'web/src/components/Header.vue'), 'utf8');
  const brandView = fs.readFileSync(FILAMENT_BRAND_VIEW, 'utf8');
  const provider = fs.readFileSync(FILAMENT_PROVIDER, 'utf8');

  assert.match(header, /siteThemeAssets.brand/);
  assert.match(header, /app-brand-mark/);
  assert.match(brandView, /\/branding\/brand-mark\.png/);
  assert.match(provider, /->brandLogo\(/);
  assert.match(provider, /->favicon\('\/branding\/favicon\.ico'\)/);
  assert.doesNotMatch(brandView, /github|google|payment/i);
});

test('branding routes precede image and SPA dispatch with MIME-safe fallbacks', () => {
  const routes = fs.readFileSync(WEB_ROUTES, 'utf8');
  const brandingController = fs.readFileSync(BRANDING_CONTROLLER, 'utf8');
  const firstBrandingRoute = routes.indexOf("Route::get('/branding/favicon.ico'");
  const themeAssetRoute = routes.indexOf("Route::get('/branding/themes/{theme}/{asset}.webp'");
  const genericBrandingRoute = routes.indexOf("Route::get('/branding/{asset}'");
  const imageRoute = routes.indexOf("Route::get('/{path}'");
  const spaRoute = routes.indexOf("Route::any('/{any}'");

  assert.notEqual(firstBrandingRoute, -1);
  assert.ok(firstBrandingRoute < imageRoute, 'Branding routes must precede SendImage');
  assert.ok(firstBrandingRoute < spaRoute, 'Branding routes must precede the SPA catch-all');
  assert.ok(themeAssetRoute < genericBrandingRoute, 'Known theme assets must precede the branding 404');
  for (const asset of ['favicon.ico', 'logo.svg', 'brand-mark.png', 'apple-touch-icon.png', 'default-avatar.webp']) {
    assert.match(routes, new RegExp(`/branding/${asset.replace('.', '\\.')}`));
  }

  assert.match(brandingController, /'favicon\.ico', 'image\/x-icon'/);
  assert.match(brandingController, /'logo\.svg', 'image\/svg\+xml'/);
  assert.match(brandingController, /'brand-mark\.png',[\s\S]*?'image\/png'/);
  assert.match(brandingController, /'apple-touch-icon\.png',[\s\S]*?'image\/png'/);
  assert.match(brandingController, /'default-avatar\.webp',[\s\S]*?'image\/webp'/);
  assert.match(brandingController, /"themes\/\{\$theme\}\/\{\$asset\}\.webp",[\s\S]*?'image\/webp'/);

  const encodedAppleIcon = extractEncodedConstant(brandingController, 'FALLBACK_APPLE_TOUCH_ICON_PNG');
  assert.ok(encodedAppleIcon, 'PNG fallback should be embedded as PNG bytes');
  assert.deepEqual(readPngDimensions(Buffer.from(encodedAppleIcon, 'base64')), {width: 180, height: 180});

  for (const constant of ['FALLBACK_BACKGROUND_WEBP', 'FALLBACK_EMPTY_WEBP', 'FALLBACK_AVATAR_WEBP']) {
    const encoded = extractEncodedConstant(brandingController, constant);
    assert.ok(encoded, `${constant} should exist`);
    readWebpDimensionsFromBuffer(Buffer.from(encoded, 'base64'));
  }
});

test('Nginx sends only missing branding files to Laravel fallback', () => {
  const publicConfig = fs.readFileSync(PUBLIC_NGINX, 'utf8');
  const dockerConfig = fs.readFileSync(DOCKER_NGINX, 'utf8');

  assert.match(publicConfig, /location \^~ \/branding\/\s*\{/);
  assert.match(publicConfig, /try_files \$uri \/index\.php\?\$query_string;/);
  assert.match(dockerConfig, /\(\?!branding\/\)/);
  assert.match(dockerConfig, /webp/);
});

test('Docker build context retains final public branding assets', () => {
  const dockerfile = fs.readFileSync(DOCKERFILE, 'utf8');
  const ignoreRules = fs.readFileSync(DOCKERIGNORE, 'utf8')
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(line => line && !line.startsWith('#'));

  assert.match(dockerfile, /^COPY \. \.$/m);
  assert.equal(
    ignoreRules.some(rule => /^(?:\/)?public(?:\/|$)/.test(rule) || /public\/branding/.test(rule)),
    false,
    'public/branding must stay inside the Docker build context',
  );
});

test('bundle visualizer runs only when ANALYZE=true', () => {
  const config = fs.readFileSync(VITE_CONFIG, 'utf8');

  assert.match(config, /ANALYZE/);
  assert.match(config, /analyze\s*\?\s*\[visualizer\(/);
  assert.doesNotMatch(config, /^\s*visualizer\(\{/m);
});
