<?php

namespace Plugins\G7\Webzine\Addon\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\G7\Webzine\Addon\Services\FallbackImageService;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 대체 이미지 서빙 컨트롤러 (공개, v1.1.0 신설).
 *
 * GET /api/plugins/g7-webzine-addon/fallback-image/{version}
 *
 * 방문자 화면의 `<img src>` 가 직접 때리는 경로라 인증이 없다. 공개되는 것은 운영자가
 * "모든 방문자에게 보이라고" 지정한 대체 이미지 한 장뿐이며, 설정값(업로드 경로·원본
 * 파일명 등)은 응답에 실리지 않는다.
 *
 * `{version}` 은 파일 내용 해시다. 해시가 URL 에 들어 있으므로 이미지를 교체하면 URL 이
 * 통째로 바뀌고, 살아 있는 URL 은 내용이 고정이라 1년 immutable 캐시가 안전하다.
 *
 * 1.2.0 부터 **목록용 파생본**(가로 240 WebP)도 같은 경로로 서빙한다. 버전 해시가 다르므로
 * 원본과 주소가 갈리고, 캐시도 따로 잡힌다.
 *
 * 저장된 버전이면 경로를 바로 열고, 아니면 저장소에서 같은 해시의 파일을 찾는다 —
 * 관리자가 업로드만 하고 아직 저장하지 않은 이미지를 설정 화면 미리보기가 부르는
 * 경우다. 정리 로직이 저장소를 한두 개로 유지하므로 그 탐색은 사실상 고정 비용이고,
 * 정리된 옛 이미지의 URL 은 자연히 404 가 된다.
 */
class FallbackImageController extends PublicBaseController
{
    public function __construct(
        private readonly FallbackImageService $imageService,
    ) {}

    /**
     * 현재 설정된 대체 이미지를 스트리밍합니다.
     *
     * @param  string  $version  파일 내용 해시 (URL 캐시 키)
     */
    public function serve(string $version): StreamedResponse|JsonResponse
    {
        $settings = WebzineSettings::all();

        $path = match (true) {
            // 목록용 파생본 (1.2.0) — 목록이 가장 자주 때리는 주소라 먼저 본다.
            $version !== '' && $settings['fallback_thumb_version'] === $version
                => $settings['fallback_thumb_path'],
            $version !== '' && $settings['fallback_upload_version'] === $version
                => $settings['fallback_upload_path'],
            default => $this->imageService->findByVersion($version),
        };

        if ($path === null || $path === '' || ! $this->imageService->exists($path)) {
            return ResponseHelper::notFound('messages.fallback.not_found', domain: 'g7-webzine-addon');
        }

        $response = $this->imageService->response($path, $settings['fallback_upload_name']);

        if ($response === null) {
            return ResponseHelper::notFound('messages.fallback.not_found', domain: 'g7-webzine-addon');
        }

        return $response;
    }
}
