<?php

namespace App\Http\Controllers;

use App\Settings\AppSettings;
use App\Settings\SiteSettings;
use App\Support\SiteTheme;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * 输出主题文件
 */
class SendThemeController extends Controller
{
    private const THEME_UNAVAILABLE_HTML = <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Site temporarily unavailable</title>
</head>
<body>
    <main>
        <h1>Site temporarily unavailable</h1>
        <p>No valid site theme is installed. Please contact the administrator.</p>
    </main>
</body>
</html>
HTML;

    public function __invoke(): View|Response
    {
        if (! app(AppSettings::class)->enable_site) {
            abort(404, '系统已关闭站点');
        }

        $resolution = app(SiteTheme::class)->resolve(app(SiteSettings::class)->theme);
        if ($resolution === null) {
            // This response intentionally bypasses Blade: the missing default
            // entry is the condition being handled, so rendering any view here
            // would turn a recoverable deployment issue into another 500.
            return response(self::THEME_UNAVAILABLE_HTML, 503, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'Retry-After' => '300',
            ]);
        }

        return view()->file($resolution['viewPath'], [
            // The directory ID is retained for third-party Blade themes, while
            // the runtime ID is intentionally limited to the built-in catalog.
            'siteThemeId' => $resolution['id'],
            'siteTheme' => $resolution['runtimeId'],
        ]);
    }
}
