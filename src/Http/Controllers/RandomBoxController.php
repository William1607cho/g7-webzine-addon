<?php

namespace Plugins\G7\Webzine\Addon\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\G7\Webzine\Addon\Services\RandomBoxService;

/**
 * 웹진 목록 위 랜덤 박스 데이터 (공개, 1.5.0).
 *
 * GET /api/plugins/g7-webzine-addon/random-box/{slug}
 *
 * 열람 권한은 라우트 미들웨어가 코어 게시판 목록과 같은 권한(`posts.read`)으로 판정한다.
 * 봇 SSR 데이터 목록(`meta.seo.data_sources`)에는 넣지 않는다.
 */
class RandomBoxController extends PublicBaseController
{
    public function __construct(
        private readonly RandomBoxService $box,
    ) {}

    public function show(string $slug): JsonResponse
    {
        return ResponseHelper::success(
            'messages.random_box.fetched',
            $this->box->forBoard($slug),
            domain: 'g7-webzine-addon',
        );
    }
}
