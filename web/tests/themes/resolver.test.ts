import assert from 'node:assert/strict';
import test from 'node:test';
import {
  applySiteTheme,
  readServerSiteTheme,
  resolveSiteTheme,
} from '../../src/themes/resolver.ts';

test('resolveSiteTheme accepts only built-in runtime IDs', () => {
  assert.equal(resolveSiteTheme('anime'), 'anime');
  assert.equal(resolveSiteTheme('technology'), 'technology');
  assert.equal(resolveSiteTheme('elegant'), 'elegant');
  assert.equal(resolveSiteTheme('default'), 'default');
  assert.equal(resolveSiteTheme('third-party'), 'default');
  assert.equal(resolveSiteTheme('../../admin'), 'default');
  assert.equal(resolveSiteTheme(null), 'default');
});

test('server marker reads and applies without touching the light/dark store', () => {
  const documentLike = {
    documentElement: {
      dataset: {siteTheme: 'technology'},
    },
  };
  const elementLike = {dataset: {siteTheme: 'invalid'}};

  assert.equal(readServerSiteTheme(documentLike), 'technology');
  assert.equal(applySiteTheme('elegant', elementLike), 'elegant');
  assert.equal(elementLike.dataset.siteTheme, 'elegant');
  assert.equal(applySiteTheme('third-party', elementLike), 'default');
  assert.equal(elementLike.dataset.siteTheme, 'default');
});
