<?php

use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\SendImageController;
use App\Http\Controllers\SendThemeController;
use Illuminate\Support\Facades\Route;

// Keep branding endpoints ahead of both image dispatch and the SPA catch-all.
// Existing files are normally served by Nginx; these routes provide type-safe
// fallbacks when a deployment has not installed an optimized branding asset.
Route::get('/branding/favicon.ico', [BrandingAssetController::class, 'favicon'])
    ->name('branding.favicon');
Route::get('/branding/logo.svg', [BrandingAssetController::class, 'logo'])
    ->name('branding.logo');
Route::get('/branding/brand-mark.png', [BrandingAssetController::class, 'brandMark'])
    ->name('branding.brand-mark');
Route::get('/branding/apple-touch-icon.png', [BrandingAssetController::class, 'appleTouchIcon'])
    ->name('branding.apple-touch-icon');
Route::get('/branding/default-avatar.webp', [BrandingAssetController::class, 'defaultAvatar'])
    ->name('branding.default-avatar');
Route::get('/branding/themes/{theme}/{asset}.webp', [BrandingAssetController::class, 'themeAsset'])
    ->where('theme', 'anime|technology|elegant')
    ->where('asset', 'hero|auth|empty')
    ->name('branding.theme-asset');
Route::get('/branding/{asset}', static fn () => abort(404))
    ->where('asset', '.*');

// 依靠本地储存的符号链接，实现直接访问文件
// 服务器如果未找到静态资源(没有符号链接或者没有文件)，则会流转到路由，由程序进行处理
$regex = '^(?!admin).*?\.(?i:' . implode('|', array_values(AppService::getAllImageTypes())) . ')$';
Route::get('/{path}', SendImageController::class)->where('path', $regex);

Route::any('/{any}', SendThemeController::class)->where('any', '.*');
