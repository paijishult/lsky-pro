<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * Resolves installed site themes without allowing configuration values to
 * become arbitrary Blade view paths.
 *
 * A theme is installed only when its directory has a valid config.json object,
 * a non-empty string name, and an index.blade.php entry point. Directory names
 * are the authoritative IDs; manifest metadata can never override them.
 */
final class SiteTheme
{
    public const DEFAULT = 'default';

    public const ANIME = 'anime';

    public const TECHNOLOGY = 'technology';

    public const ELEGANT = 'elegant';

    /**
     * @var list<string>
     */
    private const RUNTIME_THEME_IDS = [
        self::DEFAULT,
        self::ANIME,
        self::TECHNOLOGY,
        self::ELEGANT,
    ];

    private string $themeRoot;

    public function __construct(?string $themeRoot = null)
    {
        $this->themeRoot = $themeRoot ?? resource_path('views/themes');
    }

    /**
     * @return list<string>
     */
    public static function runtimeThemeIds(): array
    {
        return self::RUNTIME_THEME_IDS;
    }

    /**
     * Theme IDs are directory names, not arbitrary view fragments.
     */
    public static function isSafeId(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $value) === 1;
    }

    public static function isRuntimeTheme(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::RUNTIME_THEME_IDS, true);
    }

    /**
     * Invalid, blank, or missing values are represented by the safe default ID.
     */
    public static function normalizeId(mixed $value): string
    {
        return self::isSafeId($value) ? $value : self::DEFAULT;
    }

    /**
     * Return all installed themes using the same manifest protocol as resolve().
     * Built-in themes retain their catalog order; third-party themes follow by ID.
     *
     * @return list<array<string, mixed>>
     */
    public function themes(): array
    {
        if (! is_dir($this->themeRoot)) {
            return [];
        }

        $entries = scandir($this->themeRoot);
        if ($entries === false) {
            return [];
        }

        $themes = [];
        foreach ($entries as $id) {
            if ($id === '.' || $id === '..' || ! self::isSafeId($id)) {
                continue;
            }

            $manifest = $this->manifest($id);
            if ($manifest !== null) {
                $themes[] = $manifest;
            }
        }

        $runtimeOrder = array_flip(self::RUNTIME_THEME_IDS);
        usort($themes, static function (array $left, array $right) use ($runtimeOrder): int {
            $leftOrder = $runtimeOrder[$left['id']] ?? PHP_INT_MAX;
            $rightOrder = $runtimeOrder[$right['id']] ?? PHP_INT_MAX;

            $order = $leftOrder <=> $rightOrder;

            return $order !== 0 ? $order : strcmp($left['id'], $right['id']);
        });

        return $themes;
    }

    /**
     * Read a valid manifest and force the directory ID to remain authoritative.
     * Third-party extension fields are returned unchanged.
     *
     * @return array<string, mixed>|null
     */
    public function manifest(mixed $theme): ?array
    {
        if (! self::isSafeId($theme)) {
            return null;
        }

        $directory = $this->themeDirectory($theme);
        $entryPath = $directory . DIRECTORY_SEPARATOR . 'index.blade.php';
        $configPath = $directory . DIRECTORY_SEPARATOR . 'config.json';

        if (! is_dir($directory) || ! is_file($entryPath) || ! is_file($configPath)) {
            return null;
        }

        $json = @file_get_contents($configPath);
        if ($json === false) {
            return null;
        }

        try {
            // Decode once as an object to reject JSON arrays, then associatively
            // to preserve the array-shaped extension metadata used by callers.
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_object($object) || ! is_array($config)) {
            return null;
        }

        $name = $config['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return array_merge($config, [
            'id' => $theme,
            'name' => trim($name),
        ]);
    }

    /**
     * Resolve to the requested installed theme or the installed default.
     * Null explicitly means that no renderable fallback exists.
     */
    public function resolveDirectoryTheme(mixed $requested): ?string
    {
        $candidate = self::normalizeId($requested);

        if ($this->isAvailable($candidate)) {
            return $candidate;
        }

        if ($candidate !== self::DEFAULT && $this->isAvailable(self::DEFAULT)) {
            return self::DEFAULT;
        }

        return null;
    }

    /**
     * Return the runtime marker consumed by the shared SPA and Filament.
     * Third-party themes, invalid selections, and a missing frontend all use the
     * unstyled default marker; only resolve() decides whether rendering is safe.
     */
    public function resolveRuntimeTheme(mixed $requested): string
    {
        $directoryTheme = $this->resolveDirectoryTheme($requested);

        return self::isRuntimeTheme($directoryTheme) ? $directoryTheme : self::DEFAULT;
    }

    /**
     * @return array{id: string, runtimeId: string, view: string}|null
     */
    public function resolve(mixed $requested): ?array
    {
        $directoryTheme = $this->resolveDirectoryTheme($requested);
        if ($directoryTheme === null) {
            return null;
        }

        return [
            'id' => $directoryTheme,
            'runtimeId' => self::isRuntimeTheme($directoryTheme) ? $directoryTheme : self::DEFAULT,
            'view' => $this->viewName($directoryTheme),
        ];
    }

    public function isAvailable(mixed $theme): bool
    {
        return $this->manifest($theme) !== null;
    }

    /**
     * Build a Blade view name only after normalizing the ID.
     */
    public function viewName(mixed $theme): string
    {
        return 'themes/' . self::normalizeId($theme) . '/index';
    }

    private function themeDirectory(string $theme): string
    {
        return $this->themeRoot . DIRECTORY_SEPARATOR . $theme;
    }
}
