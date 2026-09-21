<?php

namespace Plugins\G7\Webzine\Addon\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Webzine\Addon\Plugin;
use Plugins\G7\Webzine\Addon\Support\ListSummary;

/**
 * 웹진 게시판 목록 응답의 본문 요약을 다시 계산한다 (1.2.0 신설).
 *
 * ## 임시 우회다 — 걷어낼 자리를 하나로 모았다
 *
 * 결함은 코어(`sirsoft-board`)에 있다({@see ListSummary} 클래스 주석 참고).
 * **코어가 고쳐지면 `plugin.php::getMiddleware()` 에서 이 클래스 한 줄을 지우면 끝난다.**
 * 레이아웃도 프론트도 DB 스키마도 건드리지 않았으므로 되돌릴 것이 없다.
 *
 * ## 범위
 *
 * 방문자 게시판 목록 라우트 하나뿐이고, 그중에서도 **`webzine` 유형 게시판만** 다시 쓴다.
 * 관리자 목록은 대상이 아니다 — 관리 화면의 값이 방문자 화면 사정으로 달라지면 곤란하다.
 * 봇 SSR 은 같은 라우트를 내부 HTTP 로 부르므로 자동으로 같은 결과를 받는다.
 *
 * ## 코어 판정을 다시 하지 않는다
 *
 * 어떤 글을 다시 계산할지는 {@see ListSummary::eligible()} 가 **응답에 이미 실린 값**
 * (`is_secret`·`status`·`deleted_at`)으로만 정한다. DB 에서 권한이나 비밀 여부를 다시
 * 묻지 않는다. 비밀·블라인드 글은 코어가 가린 빈 문자열이 그대로 나간다.
 *
 * ## 조회 1회
 *
 * 응답을 훑어 대상 글 id 를 모으고(조회 0), 그 id 들로 본문을 **한 번에** 읽은 뒤(조회 1),
 * 다시 훑으며 바꾼다. 글 수와 무관하게 질의는 1회다. 본문은 `SUBSTRING` 으로 앞부분만
 * 가져온다 — 목록 한 페이지가 본문 전체를 끌고 오지 않게 한다.
 */
class RecalculateListSummaryExtension
{
    /**
     * 대상 라우트 — 방문자 게시판 목록 하나뿐이다.
     */
    public const TARGET_ROUTE = 'api.modules.sirsoft-board.boards.posts.index';

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        try {
            $data = $response->getData(true);

            if (! is_array($data) || ! $this->isWebzineList($data)) {
                return $response;
            }

            $items = $data['data']['data'] ?? null;

            if (! is_array($items) || $items === []) {
                return $response;
            }

            $ids = [];
            foreach ($items as $item) {
                if (is_array($item) && ListSummary::eligible($item) && isset($item['id'])) {
                    $ids[] = (int) $item['id'];
                }
            }

            if ($ids === []) {
                return $response;
            }

            $bodies = $this->bodiesFor($ids);
            $changed = false;

            foreach ($items as $i => $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                $row = $bodies[(int) $item['id']] ?? null;

                if ($row === null) {
                    continue;
                }

                $summary = ListSummary::build($row->content ?? null, (string) ($row->content_mode ?? 'text'));

                if ($summary !== ($item['content_preview'] ?? null)) {
                    $items[$i]['content_preview'] = $summary;
                    $changed = true;
                }
            }

            if (! $changed) {
                return $response;
            }

            $data['data']['data'] = $items;

            // 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다 — 우리가 바꾼 문자열 말고는
            // 바이트가 같다.
            $response->setData($data);

            return $response;
        } catch (\Throwable $e) {
            Log::warning('[g7-webzine-addon] 목록 요약 재계산 실패 (원본 응답을 그대로 내보냅니다)', [
                'route' => optional($request->route())->getName(),
                'error' => $e->getMessage(),
            ]);

            return $response;
        }
    }

    /**
     * 이 응답이 webzine 유형 게시판의 목록인지 봅니다.
     *
     * 게시판 유형은 코어가 응답에 실어 보낸다(`data.board.type`). 따로 조회하지 않는다.
     *
     * @param  array<string, mixed>  $data
     */
    private function isWebzineList(array $data): bool
    {
        return ($data['data']['board']['type'] ?? null) === Plugin::WEBZINE_BOARD_TYPE;
    }

    /**
     * 글 id → (content, content_mode) 맵 (조회 1회).
     *
     * 본문은 요약에 필요한 만큼만 잘라 온다.
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function bodiesFor(array $ids): array
    {
        return DB::table('board_posts')
            ->whereIn('id', $ids)
            ->select([
                'id',
                'content_mode',
                DB::raw('SUBSTRING(content, 1, '.ListSummary::SOURCE_LIMIT.') as content'),
            ])
            ->get()
            ->keyBy('id')
            ->all();
    }
}
