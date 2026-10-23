<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\AppService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallationSafetyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/lsky-install-test-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        $this->app->useEnvironmentPath($this->directory);
        file_put_contents($this->directory . '/.env', 'APP_KEY=keep-the-original-key');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->directory . '/existing.sqlite',
        ]);
        touch($this->directory . '/existing.sqlite');
        DB::purge('sqlite');
        Schema::create('sentinel', fn ($table) => $table->string('value'));
        DB::table('sentinel')->insert(['value' => 'keep-existing-data']);

        AppService::swap(new class {
            public function isInstalled(): bool { return false; }
            public function getAppRequirements(): array { return []; }
            public function verifyLicense(...$arguments): bool { return true; }
        });
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_reinstallation_cannot_truncate_an_existing_database_even_with_force(): void
    {
        $exit = Artisan::call('app:install', [
            '--app-name' => 'Test', '--app-url' => 'https://example.test', '--app-license-key' => 'test',
            '--db-connection' => 'sqlite', '--db-database' => $this->directory . '/existing.sqlite',
            '--admin-username' => 'admin', '--admin-email' => 'admin@example.test', '--admin-password' => 'test-password',
            '--force' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertSame('keep-existing-data', DB::table('sentinel')->value('value'));
        self::assertSame('APP_KEY=keep-the-original-key', file_get_contents($this->directory . '/.env'));
        self::assertFileExists($this->directory . '/existing.sqlite');
    }

    public function test_an_installed_application_cannot_be_forcibly_reinstalled(): void
    {
        AppService::swap(new class {
            public function isInstalled(): bool { return true; }
        });

        self::assertSame(1, Artisan::call('app:install', ['--force' => true]));
        self::assertSame('keep-existing-data', DB::table('sentinel')->value('value'));
        self::assertSame('APP_KEY=keep-the-original-key', file_get_contents($this->directory . '/.env'));
    }
}
