<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\Webzine\Addon\Http\Controllers\Admin\FallbackImageAdminController;
use Plugins\G7\Webzine\Addon\Http\Controllers\FallbackImageController;
use Plugins\G7\Webzine\Addon\Http\Controllers\ThumbFallbackScriptController;

/*
 * g7-webzine-addon 플러그인 API 라우트 (v1.1.0 신설)
 *
 * URL prefix: /api/plugins/g7-webzine-addon (PluginRouteServiceProvider 자동 적용)
 */

// 대체 이미지 서빙 (공개 — 방문자 화면의 <img src> 가 직접 접근)
Route::get('fallback-image/{version}', [FallbackImageController::class, 'serve'])
    ->where('version', '[a-f0-9]{8,64}')
    ->name('fallback-image');

// 썸네일 로드 실패 폴백 스크립트 (공개 — 목록 레이아웃의 scripts 가 로드)
Route::get('thumb-fallback.js', [ThumbFallbackScriptController::class, 'show'])
    ->name('thumb-fallback-script');

// 대체 이미지 업로드 (관리자 인증 + 코어 플러그인 수정 권한)
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {
    Route::post('fallback-image', [FallbackImageAdminController::class, 'store'])
        ->middleware('permission:admin,core.plugins.update')
        ->name('fallback-image.store');
});
