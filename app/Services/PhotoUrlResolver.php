<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Photo;
use App\Models\Storage;
use Illuminate\Database\Eloquent\Collection;

class PhotoUrlResolver
{
    public function resolve(string $urlPath): ?Photo
    {
        $urlPath = trim($urlPath, '/');
        if ($urlPath === '' || preg_match('/[\x00-\x1f\\\\]/', $urlPath)
            || array_intersect(explode('/', $urlPath), ['.', '..'])) {
            return null;
        }

        $paths = [$urlPath];
        $locations = [];
        foreach (Storage::withTrashed()->get(['id', 'prefix']) as $storage) {
            $prefix = trim((string) $storage->prefix, '/');
            if ($prefix === '') {
                $locations[] = [$storage->id, $urlPath];
            } elseif (str_starts_with($urlPath, $prefix . '/')) {
                $path = substr($urlPath, strlen($prefix) + 1);
                $locations[] = [$storage->id, $path];
                $paths[] = $path;
            }
        }

        $query = Photo::withTrashed()->with('storage.processDrivers');
        $query->where(function ($query) use ($locations) {
            $query->whereRaw('1 = 0');
            foreach ($locations as [$storageId, $path]) {
                $query->orWhere(fn ($query) => $query->where('storage_id', $storageId)->where('pathname', $path));
            }
        });
        $matches = $query->get();
        if ($matches->isNotEmpty()) {
            return $this->unambiguous($matches);
        }

        // Keep the existing legacy URL fallback, but never guess between different originals.
        return $this->unambiguous(Photo::withTrashed()->with('storage.processDrivers')
            ->whereIn('pathname', array_values(array_unique($paths)))->get());
    }

    private function unambiguous(Collection $photos): ?Photo
    {
        $live = $photos->filter(fn (Photo $photo) => ! $photo->trashed() && $photo->storage !== null);
        $origins = $live->unique(fn (Photo $photo) => $photo->storage_id . ':' . $photo->pathname);

        return $origins->count() === 1 ? $origins->first() : null;
    }
}
