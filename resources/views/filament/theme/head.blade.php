{{--
    Filament does not expose a render hook inside its <html> opening tag.
    This server-rendered head hook runs before the panel body and applies the
    same marker before the first paint. The meta tag is also useful to tools
    that inspect the server response without executing JavaScript.
--}}
@php
    $siteTheme = \App\Support\SiteTheme::isRuntimeTheme($siteTheme ?? null)
        ? $siteTheme
        : \App\Support\SiteTheme::DEFAULT;
@endphp
<meta name="site-theme" content="{{ $siteTheme }}">
<link rel="icon" type="image/png" sizes="512x512" href="/branding/brand-mark.png">
<link rel="apple-touch-icon" sizes="180x180" href="/branding/apple-touch-icon.png">
<script>
    document.documentElement.dataset.siteTheme = @js($siteTheme);
</script>
