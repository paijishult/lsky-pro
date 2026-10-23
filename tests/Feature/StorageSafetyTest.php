<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DriverType;
use App\Http\Controllers\SendImageController;
use App\Models\Driver;
use App\Models\OAuth;
use App\Models\Photo;
use App\Models\Storage;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PhotoService;
use App\Services\PhotoUrlResolver;
use App\StorageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class StorageSafetyTest extends TestCase
{
    use RefreshDatabase;

    public $mockConsoleOutput = false;

    private string $directory;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/lsky-storage-test-' . bin2hex(random_bytes(6));
        $this->originalStoragePath = $this->app->storagePath();
        $this->app->useStoragePath($this->directory . '/storage');
        File::ensureDirectoryExists(storage_path('app/uploads'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_sqlite_schema_and_normal_oauth_bindings_enforce_the_same_identity(): void
    {
        self::assertTrue(Schema::hasIndex('oauth', 'oauth_driver_type_openid_unique'));
        $driver = Driver::create(['name' => 'GitHub', 'type' => DriverType::Socialite, 'options' => ['provider' => 'github']]);
        $user = $this->user('one');
        $content = ['driver_id' => $driver->id, 'openid' => 'external-user', 'type' => null];

        $service = app(AuthService::class);
        self::assertTrue($service->bindOAuthContent($user, $content));
        self::assertTrue($service->bindOAuthContent($user, $content));
        self::assertSame('', OAuth::firstOrFail()->type);
        self::assertSame(1, OAuth::count());

        $other = $this->user('two');
        self::assertTrue($service->bindOAuthContent($other, [...$content, 'type' => 'qq']));
        try {
            $service->bindOAuthContent($other, $content);
            self::fail('An OAuth identity must not be shared between users.');
        } catch (RuntimeException) {
            self::assertSame(2, OAuth::count());
        }
    }

    public function test_storage_prefixes_do_not_mix_up_equal_pathnames(): void
    {
        $first = $this->photo($this->storage('a'), 'photo.jpg', 'first');
        $second = $this->photo($this->storage('b'), 'photo.jpg', 'second');
        $resolver = app(PhotoUrlResolver::class);

        self::assertSame($first->id, $resolver->resolve('a/photo.jpg')?->id);
        self::assertSame($second->id, $resolver->resolve('b/photo.jpg')?->id);
        self::assertNull($resolver->resolve('unknown/photo.jpg'));
        self::assertNull($resolver->resolve('../a/photo.jpg'));
    }

    public function test_legacy_url_fallback_only_uses_unambiguous_paths(): void
    {
        $this->storage('old');
        $photo = $this->photo($this->storage('new'), 'photo.jpg', 'first');
        $resolver = app(PhotoUrlResolver::class);
        self::assertSame($photo->id, $resolver->resolve('old/photo.jpg')?->id);

        $this->photo($this->storage('other'), 'photo.jpg', 'second');
        self::assertNull($resolver->resolve('old/photo.jpg'));
    }

    public function test_deleted_exact_matches_never_fall_back_to_another_storage(): void
    {
        $deleted = $this->photo($this->storage('a'), 'photo.jpg', 'deleted');
        Photo::withoutEvents(fn () => $deleted->delete());
        $this->photo($this->storage('b'), 'photo.jpg', 'different-image');

        self::assertNull(app(PhotoUrlResolver::class)->resolve('a/photo.jpg'));
    }

    public function test_unprefixed_root_images_are_not_duplicated_by_the_controller(): void
    {
        $photo = $this->photo($this->storage(''), 'photo.jpg', 'root-image');
        self::assertSame($photo->id, app(PhotoUrlResolver::class)->resolve('photo.jpg')?->id);

        $response = app(SendImageController::class)('photo.jpg');
        ob_start();
        $response->sendContent();
        self::assertSame('root-image', ob_get_clean());
    }

    public function test_original_stream_is_complete_and_never_creates_public_cache_files(): void
    {
        $storage = $this->storage('remote');
        $this->photo($storage, 'photo.jpg', 'FULL_IMAGE_BYTES');
        $response = app(PhotoService::class)->sendImageResponse('remote', 'photo.jpg');
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        self::assertSame('FULL_IMAGE_BYTES', ob_get_clean());
        self::assertFileDoesNotExist(storage_path('app/uploads/photo.jpg'));
    }

    public function test_deletion_removes_legacy_public_caches_before_queue_processing(): void
    {
        $photo = $this->photo($this->storage('remote'), 'photo.jpg', 'image');
        file_put_contents(storage_path('app/uploads/photo.jpg'), 'legacy-cache');
        $photo->delete();

        self::assertFileDoesNotExist(storage_path('app/uploads/photo.jpg'));
        self::assertNull(app(PhotoUrlResolver::class)->resolve('remote/photo.jpg'));
    }

    public function test_cache_deletion_does_not_remove_another_live_local_original(): void
    {
        $local = $this->storage('local', storage_path('app/uploads'));
        $this->photo($local, 'shared.jpg', 'live-local-image');
        $remote = $this->photo($this->storage('remote'), 'shared.jpg', 'remote-image');
        $remote->delete();

        self::assertSame('live-local-image', file_get_contents(storage_path('app/uploads/shared.jpg')));
    }

    private function storage(string $prefix, ?string $root = null): Storage
    {
        $root ??= $this->directory . '/' . ($prefix ?: 'unprefixed');
        File::ensureDirectoryExists($root);

        return Storage::create([
            'name' => $prefix ?: 'unprefixed', 'prefix' => $prefix, 'provider' => StorageProvider::Local,
            'options' => [...Driver::getStorageDefaultOptions(), 'root' => $root],
        ]);
    }

    private function photo(Storage $storage, string $path, string $contents): Photo
    {
        $file = $storage->options['root'] . '/' . $path;
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $contents);

        return Photo::create([
            'storage_id' => $storage->id, 'name' => $path, 'filename' => basename($path), 'pathname' => $path,
            'mimetype' => 'image/jpeg', 'extension' => 'jpg', 'md5' => md5($contents), 'sha1' => sha1($contents),
        ]);
    }

    private function user(string $name): User
    {
        $id = DB::table('users')->insertGetId(['username' => $name, 'email' => $name . '@example.test', 'password' => 'unused']);

        return User::findOrFail($id);
    }
}
