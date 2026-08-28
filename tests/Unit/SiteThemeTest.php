<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SiteTheme;
use PHPUnit\Framework\TestCase;

class SiteThemeTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lsky-site-theme-' . bin2hex(random_bytes(6));
        mkdir($this->themeRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->themeRoot);

        parent::tearDown();
    }

    public function test_theme_ids_are_safe_directory_segments(): void
    {
        self::assertTrue(SiteTheme::isSafeId('third-party_theme.v1'));
        self::assertFalse(SiteTheme::isSafeId(''));
        self::assertFalse(SiteTheme::isSafeId('../admin'));
        self::assertFalse(SiteTheme::isSafeId('theme/index'));
        self::assertFalse(SiteTheme::isSafeId(' theme'));
        self::assertFalse(SiteTheme::isSafeId(null));
    }

    public function test_runtime_catalog_is_limited_to_the_four_built_in_ids(): void
    {
        self::assertSame(
            ['default', 'anime', 'technology', 'elegant'],
            SiteTheme::runtimeThemeIds(),
        );
        self::assertTrue(SiteTheme::isRuntimeTheme('anime'));
        self::assertFalse(SiteTheme::isRuntimeTheme('third-party'));
    }

    public function test_manifest_requires_an_object_name_and_entry_while_preserving_extensions(): void
    {
        $this->createTheme('third-party', [
            'id' => 'manifest-id-must-not-win',
            'name' => '  Third Party  ',
            'version' => '3.2.1',
            'extension' => ['supportsWidgets' => true],
        ]);

        $manifest = (new SiteTheme($this->themeRoot))->manifest('third-party');

        self::assertNotNull($manifest);
        self::assertSame('third-party', $manifest['id']);
        self::assertSame('Third Party', $manifest['name']);
        self::assertSame('3.2.1', $manifest['version']);
        self::assertSame(['supportsWidgets' => true], $manifest['extension']);
    }

    public function test_invalid_manifests_and_missing_entries_are_not_available_or_listed(): void
    {
        $this->createTheme('default');
        $this->createTheme('missing-config', null);
        $this->createTheme('broken-json', '{broken');
        $this->createTheme('json-array', [['name' => 'Array Theme']]);
        $this->createTheme('json-string', '"String Theme"');
        $this->createTheme('missing-name', ['version' => '1.0']);
        $this->createTheme('empty-name', ['name' => '   ']);
        $this->createTheme('missing-entry', ['name' => 'Missing Entry'], false);

        $resolver = new SiteTheme($this->themeRoot);

        self::assertSame(['default'], array_column($resolver->themes(), 'id'));
        foreach ([
            'missing-config',
            'broken-json',
            'json-array',
            'json-string',
            'missing-name',
            'empty-name',
            'missing-entry',
        ] as $invalidTheme) {
            self::assertFalse($resolver->isAvailable($invalidTheme), $invalidTheme);
            self::assertNull($resolver->manifest($invalidTheme), $invalidTheme);
        }
    }

    public function test_invalid_or_incomplete_requests_fall_back_to_the_valid_default(): void
    {
        $this->createTheme('default');
        $this->createTheme('anime');
        $this->createTheme('third-party');
        $this->createTheme('third.party');
        $this->createTheme('technology', ['name' => 'Technology'], false);

        $resolver = new SiteTheme($this->themeRoot);

        self::assertSame('anime', $resolver->resolveDirectoryTheme('anime'));
        self::assertSame('anime', $resolver->resolveRuntimeTheme('anime'));
        self::assertSame('default', $resolver->resolveDirectoryTheme('missing'));
        self::assertSame('default', $resolver->resolveDirectoryTheme('../admin'));
        self::assertSame('third-party', $resolver->resolveDirectoryTheme('third-party'));
        self::assertSame('third.party', $resolver->resolveDirectoryTheme('third.party'));
        self::assertSame('themes/third.party/index', $resolver->viewName('third.party'));
        self::assertSame('default', $resolver->resolveRuntimeTheme('third-party'));
        self::assertSame('default', $resolver->resolveDirectoryTheme('technology'));

        $resolution = $resolver->resolve('third-party');

        self::assertNotNull($resolution);
        self::assertSame('third-party', $resolution['id']);
        self::assertSame('default', $resolution['runtimeId']);
        self::assertSame('themes/third-party/index', $resolution['view']);
        self::assertSame(
            $this->themeRoot . DIRECTORY_SEPARATOR . 'third-party' . DIRECTORY_SEPARATOR . 'index.blade.php',
            $resolution['viewPath'],
        );
    }

    public function test_resolver_returns_null_when_no_valid_default_fallback_exists(): void
    {
        $resolver = new SiteTheme($this->themeRoot);

        // The default directory and entry point are both absent.
        self::assertNull($resolver->resolveDirectoryTheme('missing'));
        self::assertNull($resolver->resolve('missing'));

        // A directory and manifest without the entry point are still unusable.
        $this->createTheme('default', ['name' => 'Default'], false);
        self::assertNull($resolver->resolveDirectoryTheme('missing'));
        self::assertNull($resolver->resolve('default'));
    }

    public function test_view_name_never_contains_an_untrusted_view_segment(): void
    {
        $resolver = new SiteTheme($this->themeRoot);

        self::assertSame('themes/default/index', $resolver->viewName('../../admin'));
        self::assertSame('themes/elegant/index', $resolver->viewName('elegant'));
    }

    /**
     * @param array<string, mixed>|string|null $manifest
     */
    private function createTheme(
        string $id,
        array|string|null $manifest = ['name' => 'Theme'],
        bool $withEntry = true,
    ): void {
        $directory = $this->themeRoot . DIRECTORY_SEPARATOR . $id;
        mkdir($directory, 0777, true);

        if ($manifest !== null) {
            $json = is_array($manifest)
                ? json_encode($manifest, JSON_THROW_ON_ERROR)
                : $manifest;
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'config.json', $json);
        }

        if ($withEntry) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'index.blade.php', '<!doctype html>');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $path = $item->getPathname();
            if ($item->isDir()) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
