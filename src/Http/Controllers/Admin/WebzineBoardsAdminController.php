<?php

namespace Plugins\G7\Webzine\Addon\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Board\Services\BoardService;
use Plugins\G7\Webzine\Addon\Plugin;

/**
 * 설정 화면의 "랜덤 박스 적용 게시판" 선택지 (관리자, 1.5.0).
 *
 * GET /api/plugins/g7-webzine-addon/admin/webzine-boards
 *
 * 권한은 라우트 미들웨어(`permission:admin,core.plugins.update`)가 검증한다.
 */
class WebzineBoardsAdminController extends AdminBaseController
{
    public function __construct(
        private readonly BoardService $boards,
    ) {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $rows = $this->boards->getActiveBoards()
            ->filter(fn ($board) => $board->type === Plugin::WEBZINE_BOARD_TYPE)
            ->map(fn ($board) => [
                'id' => (int) $board->id,
                'slug' => (string) $board->slug,
                'name' => (string) $board->getLocalizedName(),
            ])
            ->values()
            ->all();

        return ResponseHelper::success('messages.random_box.boards_listed', $rows, domain: 'g7-webzine-addon');
    }
}
