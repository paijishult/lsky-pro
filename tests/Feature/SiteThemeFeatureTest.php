<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\BrandingAssetController;
use App\Settings\AppSettings;
use App\Settings\SiteSettings;
use App\Support\SiteTheme;
use ReflectionClass;
use Tests\TestCase;

class SiteThemeFeatureTest extends TestCase
{
    private string $tempRoot;

    private string $themeRoot;

    private SiteSettings $siteSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lsky-site-theme-feature-' . bin2hex(random_bytes(6));
        $this->themeRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'themes';
        mkdir($this->themeRoot, 0777, true);

        // SiteTheme discovers entries under views/themes and SendThemeController
        // renders the conventional themes/{id}/index view name. Mount the
        // temporary parent as the first view location so the feature tests
        // exercise the generated temporary shells instead of the repository
        // default theme.
        $this->app->make('view')->getFinder()->prependLocation($this->tempRoot);

        $appSettings = (new ReflectionClass(AppSettings::class))->newInstanceWithoutConstructor();
        $appSettings->enable_site = true;
        $this->app->instance(AppSettings::class, $appSettings);

        $this->siteSettings = (new ReflectionClass(SiteSettings::class))->newInstanceWithoutConstructor();
        $this->siteSettings->theme = SiteTheme::DEFAULT;
        $this->siteSettings->title = 'Theme Test';
        $this->siteSettings->subtitle = 'No external services';
        $this->app->instance(SiteSettings::class, $this->siteSettings);
        $this->app->instance(SiteTheme::class, new SiteTheme($this->themeRoot));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function test_all_four_built_in_themes_render_with_their_runtime_marker(): void
    {
        foreach (SiteTheme::runtimeThemeIds() as $theme) {
            $this->createTheme($theme);
        }

        foreach (SiteTheme::runtimeThemeIds() as $theme) {
            $this->siteSettings->theme = $theme;

            $this->get('/theme-feature?theme=' . $theme)
                ->assertOk()
                ->assertSee('data-site-theme="' . $theme . '"', false)
                ->assertSee('data-theme-id="' . $theme . '"', false);
        }
    }

    public function test_valid_third_party_theme_renders_with_default_spa_marker(): void
    {
        $this->createTheme('default');
        $this->createTheme('third-party', [
            'name' => 'Third Party',
            'engine' => ['version' => 2],
        ]);
        $this->siteSettings->theme = 'third-party';

        $this->get('/third-party-feature')
            ->assertOk()
            ->assertSee('data-site-theme="default"', false)
            ->assertSee('data-theme-id="third-party"', false);
    }

    public function test_illegal_ids_and_invalid_manifests_fall_back_to_default(): void
    {
        $this->createTheme('default');
        $this->createTheme('broken-json', '{broken');
        $this->createTheme('array-manifest', [['name' => 'Array']]);
        $this->createTheme('empty-name', ['name' => '   ']);
        $this->createTheme('missing-entry', ['name' => 'Missing Entry'], false);

        foreach (['../admin', 'broken-json', 'array-manifest', 'empty-name', 'missing-entry'] as $theme) {
            $this->siteSettings->theme = $theme;

            $this->get('/invalid-theme-feature?theme=' . rawurlencode($theme))
                ->assertOk()
                ->assertSee('data-site-theme="default"', false)
                ->assertSee('data-theme-id="default"', false);
        }
    }

    public function test_missing_default_directory_and_entry_return_minimal_503_without_blade(): void
    {
        $this->siteSettings->theme = 'missing';

        $this->get('/missing-theme-feature')
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('No valid site theme is installed.', false)
            ->assertDontSee('Theme Test', false);
    }

    public function test_branding_routes_return_type_correct_fallbacks(): void
    {
        $emptyBrandingRoot = $this->themeRoot . DIRECTORY_SEPARATOR . 'branding';
        mkdir($emptyBrandingRoot, 0777, true);
        $missingLegacyFavicon = $this->themeRoot . DIRECTORY_SEPARATOR . 'missing-favicon.ico';

        $this->app->bind(
            BrandingAssetController::class,
            static fn () => new BrandingAssetController($emptyBrandingRoot, $missingLegacyFavicon),
        );

        $favicon = $this->get('/branding/favicon.ico')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/x-icon')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertSame("\x00\x00\x01\x00", substr($favicon->getContent(), 0, 4));

        $logo = $this->get('/branding/logo.svg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringStartsWith('<svg ', $logo->getContent());

        $brandMark = $this->get('/branding/brand-mark.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertSame("\x89PNG\r\n\x1a\n", substr($brandMark->getContent(), 0, 8));

        $appleTouchIcon = $this->get('/branding/apple-touch-icon.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $png = $appleTouchIcon->getContent();
        self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        self::assertSame(180, unpack('N', substr($png, 16, 4))[1]);
        self::assertSame(180, unpack('N', substr($png, 20, 4))[1]);
        self::assertStringNotContainsString('<svg', $png);

        foreach (['/branding/default-avatar.webp', '/branding/themes/anime/hero.webp', '/branding/themes/elegant/empty.webp'] as $asset) {
            $webp = $this->get($asset)
                ->assertOk()
                ->assertHeader('Content-Type', 'image/webp')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->getContent();
            self::assertSame('RIFF', substr($webp, 0, 4));
            self::assertSame('WEBP', substr($webp, 8, 4));
        }

        $this->get('/branding/themes/default/hero.webp')->assertNotFound();
        $this->get('/branding/themes/anime/unknown.webp')->assertNotFound();
        $this->get('/branding/not-allowed.svg')->assertNotFound();
    }

    public function test_filament_head_view_emits_a_normalized_theme_marker(): void
    {
        $this->view('filament.theme.head', ['siteTheme' => SiteTheme::TECHNOLOGY])
            ->assertSee('<meta name="site-theme" content="technology">', false)
            ->assertSee('document.documentElement.dataset.siteTheme', false);

        $this->view('filament.theme.head', ['siteTheme' => 'third-party'])
            ->assertSee('<meta name="site-theme" content="default">', false);
    }

    /**
     * @param array<string, mixed>|string $manifest
     */
    private function createTheme(
        string $id,
        array|string $manifest = ['name' => 'Theme'],
        bool $withEntry = true,
    ): void {
        $directory = $this->themeRoot . DIRECTORY_SEPARATOR . $id;
        mkdir($directory, 0777, true);

        $json = is_array($manifest)
            ? json_encode($manifest, JSON_THROW_ON_ERROR)
            : $manifest;
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'config.json', $json);

        if ($withEntry) {
            file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'index.blade.php',
                '<!doctype html><html data-site-theme="{{ $siteTheme }}"><body data-theme-id="{{ $siteThemeId }}">Theme shell</body></html>',
            );
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
