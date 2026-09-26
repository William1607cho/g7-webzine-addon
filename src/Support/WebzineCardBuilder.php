<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 공개 메서드 {@see \Plugins\G7\Webzine\Addon\PublicApi\WebzineCards::cards()} 의 순수 계산 (1.3.0 신설).
 *
 * DB·파일·HTTP 를 건드리지 않는다. 본문은 호출자(공개 메서드)가 한 번에 읽어 넘기고, 여기서는
 * 항목별 카드 값(요약·썸네일·대체 이미지)만 만든다.
 *
 * ## 가리는 규칙 (권한 판정이 아니다)
 *
 * 권한은 판정하지 않는다 — 호출자가 코어 경로로 이미 거른 글만 넘긴다는 전제다. 대신 **응답에 실린
 * 상태 값**만 보고 더 가린다:
 *
 *  - 비밀글(`is_secret` 참): 요약·썸네일·대체 이미지 **모두 null**. 호출자가 썸네일을 넘겨도 버린다.
 *  - 발행 상태가 아니거나(`status` ≠ `published`, 값이 없어도 마찬가지) 삭제된 글(`deleted_at` 있음):
 *    요약 null. 썸네일은 넘어온 값을 그대로 둔다(코어가 이미 낸 값).
 *  - 그 밖: 요약 = 본문에서 다시 계산({@see ListSummary::build()}, 웹진 목록과 같은 규칙). 본문을
 *    못 받았으면 null.
 *
 * 대체 이미지는 썸네일이 없는 글에만 싣는다(웹진 목록 1.2.0 과 같은 기준).
 */
final class WebzineCardBuilder
{
    /**
     * @param  array<int, mixed>  $items  글 항목(코어 PostResource 모양의 부분집합)
     * @param  array<int, array{content: ?string, content_mode: string}>  $bodies  id => 본문 앞부분
     * @param  string|null  $fallbackImage  썸네일 없는 글에 쓸 대체 이미지 주소(없으면 null)
     * @return array<int, array{summary: ?string, thumbnail: ?string, fallback_image: ?string}>  id => 카드 값
     */
    public static function build(array $items, array $bodies, ?string $fallbackImage): array
    {
        $cards = [];
        foreach ($items as $item) {
            $id = self::idOf($item);
            if ($id === null) {
                continue;
            }
            if (! empty($item['is_secret'])) {
                $cards[$id] = ['summary' => null, 'thumbnail' => null, 'fallback_image' => null];

                continue;
            }
            $thumbnail = self::thumbnailOf($item);
            $cards[$id] = [
                'summary' => self::summarizable($item) ? self::summaryOf($bodies[$id] ?? null) : null,
                'thumbnail' => $thumbnail,
                'fallback_image' => $thumbnail === null ? $fallbackImage : null,
            ];
        }

        return $cards;
    }

    /**
     * 본문을 읽어야 하는 글 id(비밀·미발행·삭제 글 제외).
     *
     * @param  array<int, mixed>  $items
     * @return array<int, int>
     */
    public static function idsNeedingBody(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = self::idOf($item);
            if ($id !== null && empty($item['is_secret']) && self::summarizable($item)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function idOf(mixed $item): ?int
    {
        if (! is_array($item) || ! isset($item['id']) || ! is_numeric($item['id']) || (int) $item['id'] <= 0) {
            return null;
        }

        return (int) $item['id'];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function summarizable(array $item): bool
    {
        return ($item['status'] ?? null) === 'published' && ($item['deleted_at'] ?? null) === null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function thumbnailOf(array $item): ?string
    {
        $thumbnail = $item['thumbnail'] ?? null;

        return is_string($thumbnail) && $thumbnail !== '' ? $thumbnail : null;
    }

    /**
     * @param  array{content: ?string, content_mode: string}|null  $body
     */
    private static function summaryOf(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return ListSummary::build($body['content'] ?? null, (string) ($body['content_mode'] ?? 'text'));
    }
}
