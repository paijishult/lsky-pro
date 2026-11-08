<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LivewireUploadSafetyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/lsky-livewire-test-' . bin2hex(random_bytes(6));
        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'filesystems.disks.local.root' => $this->directory . '/private',
            'filesystems.disks.public.root' => $this->directory . '/public',
        ]);
        Storage::forgetDisk(['local', 'public']);
        // Exercise Livewire's signed HTTP endpoint, without the unrelated CSRF
        // check that would otherwise reject synthetic requests in production.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_local_disk_is_outside_the_public_storage_links(): void
    {
        $filesystems = require config_path('filesystems.php');

        self::assertSame(storage_path('app/private'), $filesystems['disks']['local']['root']);
        foreach ($filesystems['links'] as $target) {
            self::assertFalse(str_starts_with($filesystems['disks']['local']['root'] . '/', rtrim($target, '/') . '/'));
        }
    }

    #[DataProvider('uploadCases')]
    public function test_temporary_uploads_remain_private_regardless_of_the_default_disk(string $defaultDisk, string $filename, string $contents): void
    {
        config(['filesystems.default' => $defaultDisk]);

        // Livewire substitutes tmp-for-tests in the testing environment. Exercise
        // the production disk selection and real filesystem writes instead.
        $environment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $disk = FileUploadConfiguration::disk();
            self::assertSame('local', $disk);

            $response = $this->post(URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5)), [
                'files' => [UploadedFile::fake()->createWithContent($filename, $contents)],
            ])->assertOk();

            $paths = $response->json('paths');
            self::assertCount(1, $paths);
            $path = FileUploadConfiguration::path($paths[0]);
            self::assertSame($contents, Storage::disk('local')->get($path));
            self::assertSame('private', Storage::disk('local')->getVisibility($path));
            self::assertFileExists($this->directory . '/private/' . $path);
            self::assertDirectoryDoesNotExist($this->directory . '/public/livewire-tmp');

            if ($filename === 'photo.png') {
                $temporaryFile = TemporaryUploadedFile::createFromLivewire($paths[0]);
                self::assertSame($filename, $temporaryFile->getClientOriginalName());
                self::assertTrue($temporaryFile->isPreviewable());
                $this->get($temporaryFile->temporaryUrl())->assertOk()->assertStreamedContent($contents);

                $storedPath = $temporaryFile->storeAs('photos', $filename, 'public');
                self::assertSame('photos/photo.png', $storedPath);
                self::assertSame($contents, Storage::disk('public')->get($storedPath));
            }
        } finally {
            $this->app['env'] = $environment;
        }
    }

    public function test_unsigned_uploads_are_rejected_without_writing_files(): void
    {
        $this->post(route('livewire.upload-file'), [
            'files' => [UploadedFile::fake()->createWithContent('probe.php', '<?php /* harmless fixture */')],
        ])->assertUnauthorized();

        self::assertDirectoryDoesNotExist($this->directory . '/private/livewire-tmp');
        self::assertDirectoryDoesNotExist($this->directory . '/public/livewire-tmp');
    }

    public static function uploadCases(): array
    {
        return [
            'public default, script' => ['public', 'probe.php', '<?php /* harmless upload regression fixture */'],
            'public default, image' => ['public', 'photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')],
            's3 default, script' => ['s3', 'probe.php', '<?php /* harmless upload regression fixture */'],
            's3 default, image' => ['s3', 'photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')],
        ];
    }
}
