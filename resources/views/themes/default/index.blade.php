<!DOCTYPE html>
<html lang="zh_CN" class="scroll-smooth" data-site-theme="{{ $siteTheme ?? \App\Support\SiteTheme::DEFAULT }}">
  <head>
    <meta charset="UTF-8">
    <link rel="icon" href="/branding/favicon.ico">
    <link rel="icon" type="image/png" sizes="512x512" href="/branding/brand-mark.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/branding/apple-touch-icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ app(\App\Settings\SiteSettings::class)->title }} - {{ app(\App\Settings\SiteSettings::class)->subtitle }}</title>
    <script type="module" crossorigin src="/themes/default/js/index-BZq9wa-S.js"></script>
    <link rel="modulepreload" crossorigin href="/themes/default/js/vue-vendors-Ti_PPrle.js">
    <link rel="modulepreload" crossorigin href="/themes/default/js/utils-DGAJShNI.js">
    <link rel="modulepreload" crossorigin href="/themes/default/js/naive-ui-fHM20d8M.js">
    <link rel="modulepreload" crossorigin href="/themes/default/js/icons-EafKk7j9.js">
    <link rel="stylesheet" crossorigin href="/themes/default/assets/css/index-BkboBWFs.css">
  </head>
  <body>
    <div id="app"></div>
  </body>
</html>
