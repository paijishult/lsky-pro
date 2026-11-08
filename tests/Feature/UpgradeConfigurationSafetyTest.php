<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\UpgradeCommand;
use Illuminate\Console\Application;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Foundation\Console\ConfigClearCommand;
use Illuminate\Queue\Console\RestartCommand;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use ZipArchive;

class UpgradeConfigurationSafetyTest extends TestCase
{
    public function test_upgrade_invalidates_cached_configuration_before_restarting_workers(): void
    {
        $directory = sys_get_temp_dir() . '/lsky-upgrade-test-' . bin2hex(random_bytes(6));
        $basePath = $this->app->basePath();
        $storagePath = $this->app->storagePath();
        File::ensureDirectoryExists($directory . '/config');
        File::ensureDirectoryExists($directory . '/bootstrap/cache');
        File::ensureDirectoryExists($directory . '/storage/app');

        try {
            $this->app->setBasePath($directory);
            $this->app->useStoragePath($directory . '/storage');
            $oldConfig = "<?php return ['temporary_file_upload' => ['disk' => null]];";
            $newConfig = "<?php return ['temporary_file_upload' => ['disk' => 'local']];";
            file_put_contents(config_path('livewire.php'), $oldConfig);
            file_put_contents(base_path('hashes.json'), json_encode(['config/livewire.php' => md5($oldConfig)]));
            $cachePath = $this->app->getCachedConfigPath();
            file_put_contents($cachePath, '<?php return [];');
            file_put_contents(base_path('upgrading.lock'), '');

            $zip = new ZipArchive();
            self::assertTrue($zip->open(base_path('upgrade.zip'), ZipArchive::CREATE));
            $zip->addFromString('config/livewire.php', $newConfig);
            $zip->addFromString('hashes.json', json_encode(['config/livewire.php' => md5($newConfig)]));
            $zip->close();

            $command = new class extends UpgradeCommand {
                public array $calls = [];

                public function call($command, array $arguments = [])
                {
                    $this->calls[] = $command;

                    // Exercise real cache invalidation, without migrating a
                    // database or restarting any actual workers in this fixture.
                    return $command === ConfigClearCommand::class ? parent::call($command, $arguments) : 0;
                }
            };
            $command->setLaravel($this->app);
            $console = new Application($this->app, $this->app['events'], 'test');
            $console->add($command);
            $console->setAutoExit(false);
            $output = new BufferedOutput();

            $exit = $console->run(new ArrayInput(['command' => 'app:upgrade', 'path' => 'upgrade.zip']), $output);
            self::assertSame(0, $exit, $output->fetch());
            self::assertSame($newConfig, file_get_contents(config_path('livewire.php')));
            self::assertFileDoesNotExist($cachePath);
            self::assertSame([ConfigClearCommand::class, MigrateCommand::class, RestartCommand::class], $command->calls);
            self::assertFileDoesNotExist(base_path('upgrading.lock'));
        } finally {
            $this->app->setBasePath($basePath);
            $this->app->useStoragePath($storagePath);
            File::deleteDirectory($directory);
        }
    }
}
