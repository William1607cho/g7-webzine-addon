<?php

namespace Plugins\G7\Webzine\Addon\PublicApi;

use Illuminate\Support\Facades\DB;
use Plugins\G7\Webzine\Addon\Support\ListSummary;
use Plugins\G7\Webzine\Addon\Support\WebzineCardBuilder;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 다른 확장이 쓰는 **공개 계약** — 웹진 카드 값(요약·썸네일·대체 이미지) (1.3.0 신설).
 *
 * 이 클래스의 이름·상수 `VERSION`·메서드 `cards()` 의 입력과 출력은 약속이다. 애드온 내부 구현
 * (요약 계산 방식, 대체 이미지 설정 등)이 바뀌어도 이 입출력은 유지한다. 바꿔야 하면 `VERSION` 을
 * 올리고 CHANGELOG 에 적는다. 다른 확장은 애드온 내부 클래스(`Support\*` 등)를 직접 부르지 말고
 * 이것만 쓴다.
 *
 * 쓰는 쪽(예: g7-home-widgets 웹진 위젯)의 판정: 코어 플러그인 활성 여부
 * (`PluginRepositoryInterface::findActiveByIdentifier('g7-webzine-addon')`) **그리고**
 * `class_exists(WebzineCards::class) && WebzineCards::VERSION >= 1`.
 *
 * **권한 판정을 하지 않는다.** 호출자가 코어 경로(`PostService`·`PostResource`, 열람 가능 게시판)로
 * 이미 거른 글만 넘긴다는 전제다. 그 대신 비밀글은 요약·썸네일을 모두 null 로 돌려준다(호출자가
 * 값을 넘겨도). 규칙은 {@see WebzineCardBuilder}.
 */
final class WebzineCards
{
    /** 계약 판본. 호출 측은 `>= 1` 을 확인한다. */
    public const VERSION = 1;

    /** 한 번에 받는 항목 수 상한(이보다 많으면 앞에서부터 이만큼만 처리) */
    public const MAX_ITEMS = 100;

    /**
     * 글 항목마다 웹진 카드 값을 만든다.
     *
     * 입력 항목의 키(코어 `PostResource` 모양의 부분집합): `id`(int, 필수), `is_secret`(bool),
     * `status`(string, `published` 일 때만 요약), `deleted_at`(삭제면 값, 아니면 null),
     * `thumbnail`(?string, 코어가 낸 썸네일). 그 밖의 키는 무시한다.
     *
     * 출력: 글 id => `summary`(?string, 최대 150자·말줄임표 포함, 웹진 목록과 같은 규칙),
     * `thumbnail`(?string, 입력 값 그대로 — 비밀글은 null), `fallback_image`(?string, 썸네일이 없는 글에만,
     * 웹진 애드온 설정의 대체 이미지 목록용 주소, 설정이 없으면 null). 비밀글은 세 값 모두 null.
     * id 가 없거나 잘못된 항목은 결과에 없다. 조회는 본문 앞부분 1회(항목 수와 무관).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{summary: ?string, thumbnail: ?string, fallback_image: ?string}>
     */
    public static function cards(array $items): array
    {
        $items = array_slice(array_values($items), 0, self::MAX_ITEMS);
        $ids = WebzineCardBuilder::idsNeedingBody($items);
        $bodies = $ids === [] ? [] : self::bodiesFor($ids);
        $settings = WebzineSettings::all();
        $fallback = WebzineSettings::effectiveMode($settings) === WebzineSettings::MODE_IMAGE
            ? WebzineSettings::listImageUrl($settings)
            : null;

        return WebzineCardBuilder::build($items, $bodies, $fallback);
    }

    /**
     * 글 id → 본문 앞부분(요약에 필요한 만큼)과 본문 형식. 조회 1회.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{content: ?string, content_mode: string}>
     */
    private static function bodiesFor(array $ids): array
    {
        $rows = DB::table('board_posts')
            ->whereIn('id', $ids)
            ->select(['id', 'content_mode', DB::raw('SUBSTRING(content, 1, '.ListSummary::SOURCE_LIMIT.') as content')])
            ->get();

        $bodies = [];
        foreach ($rows as $row) {
            $bodies[(int) $row->id] = ['content' => $row->content, 'content_mode' => (string) ($row->content_mode ?? 'text')];
        }

        return $bodies;
    }
}
