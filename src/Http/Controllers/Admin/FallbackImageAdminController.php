<?php

namespace Plugins\G7\Webzine\Addon\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Webzine\Addon\Http\Requests\FallbackImageUploadRequest;
use Plugins\G7\Webzine\Addon\Services\FallbackImageService;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 대체 이미지 업로드 컨트롤러 (관리자, v1.1.0 신설).
 *
 * POST /api/plugins/g7-webzine-addon/admin/fallback-image
 *
 * 권한은 라우트 미들웨어(`permission:admin,core.plugins.update`)가 검증한다 — 대체
 * 이미지 업로드는 "플러그인 설정 변경" 의 일부이므로 코어의 플러그인 수정 권한을
 * 그대로 쓰고 별도 권한을 새로 만들지 않는다(권한 표가 늘어나면 운영자가 두 곳을
 * 맞춰 줘야 하는데, 실제로 두 권한이 갈릴 상황이 없다).
 *
 * 응답으로 돌려주는 값은 **설정 폼에 그대로 채워 넣을 메타**다. 실제 저장은 관리자가
 * 설정 화면에서 "저장" 을 눌러 코어 설정 API(`PUT /api/admin/plugins/{id}/settings`)로
 * 커밋할 때 일어난다 — 업로드만 하고 저장하지 않으면 설정은 예전 이미지를 그대로 가리킨다.
 */
class FallbackImageAdminController extends AdminBaseController
{
    public function __construct(
        private readonly FallbackImageService $imageService,
    ) {
        parent::__construct();
    }

    /**
     * 대체 이미지를 업로드합니다.
     *
     * 업로드 직후, 방금 올린 파일과 **현재 설정이 가리키는 파일**만 남기고 나머지
     * (저장하지 않고 버린 이전 업로드들)를 정리한다. 현재 설정 파일은 아직 화면에
     * 쓰이고 있을 수 있어 여기서 지우지 않고, 설정 저장 시점에
     * {@see \Plugins\G7\Webzine\Addon\Listeners\SettingsSavedListener} 가 정리한다.
     */
    public function store(FallbackImageUploadRequest $request): JsonResponse
    {
        try {
            $meta = $this->imageService->store($request->file('fallback_image'));

            $current = WebzineSettings::all();

            // 방금 올린 원본·파생본과 현재 설정이 가리키는 원본·파생본만 남긴다.
            // 파생본 경로를 빠뜨리면 방금 만든 파생본이 곧바로 지워진다.
            $this->imageService->pruneExcept([
                $meta['path'],
                $meta['thumb_path'],
                $current['fallback_upload_path'],
                $current['fallback_thumb_path'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[g7-webzine-addon] 대체 이미지 업로드 실패', ['error' => $e->getMessage()]);

            return ResponseHelper::error('messages.fallback.upload_failed', 500, domain: 'g7-webzine-addon');
        }

        return ResponseHelper::success(
            'messages.fallback.uploaded',
            $meta,
            domain: 'g7-webzine-addon',
        );
    }
}
