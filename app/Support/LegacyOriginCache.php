<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Photo;
use App\StorageProvider;
use Illuminate\Support\Facades\Storage;

final class LegacyOriginCache
{
    public static function forget(Photo $photo): bool
    {
        // The old public cache shares a directory with real local originals.
        if (Photo::where('pathname', $photo->pathname)->whereKeyNot($photo->id)
            ->whereHas('storage', fn ($query) => $query->where('provider', StorageProvider::Local))->exists()) {
            return false;
        }

        $disk = Storage::build(['driver' => 'local', 'root' => storage_path('app/uploads'), 'throw' => true]);
        if (! $disk->exists($photo->pathname)) {
            return false;
        }

        return $disk->delete($photo->pathname);
    }
}
