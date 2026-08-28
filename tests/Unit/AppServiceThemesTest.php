<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AppService;
use App\Support\SiteTheme;
use PHPUnit\Framework\TestCase;

class AppServiceThemesTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lsky-app-service-themes-' . bin2hex(random_bytes(6));
        mkdir($this->themeRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->themeRoot);

        parent::tearDown();
    }

    public function test_get_themes_returns_only_valid_manifests_in_stable_order(): void
    {
        $this->createTheme('technology', ['name' => 'Technology']);
        $this->createTheme('third-zeta', ['name' => 'Zeta']);
        $this->createTheme('default', ['name' => 'Default']);
        $this->createTheme('anime', ['name' => 'Anime']);
        $this->createTheme('elegant', ['name' => 'Elegant']);
        $this->createTheme('third-alpha', [
            'id' => 'spoofed-id',
            'name' => 'Alpha',
            'vendor' => ['homepage' => 'https://example.test/theme'],
        ]);
        $this->createTheme('broken', '{not-json');
        $this->createTheme('array-manifest', [['name' => 'Nope']]);
        $this->createTheme('empty-name', ['name' => '']);
        $this->createTheme('no-entry', ['name' => 'No Entry'], false);
        $this->createTheme('no-config', null);

        $service = new AppService(new SiteTheme($this->themeRoot));
        $themes = $service->getThemes();

        self::assertSame(
            ['default', 'anime', 'technology', 'elegant', 'third-alpha', 'third-zeta'],
            array_column($themes, 'id'),
        );

        $thirdParty = $themes[4];
        self::assertSame('third-alpha', $thirdParty['id']);
        self::assertSame('Alpha', $thirdParty['name']);
        self::assertSame(
            ['homepage' => 'https://example.test/theme'],
            $thirdParty['vendor'],
        );
    }

    /**
     * @param array<string, mixed>|string|null $manifest
     */
    private function createTheme(
        string $id,
        array|string|null $manifest,
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
