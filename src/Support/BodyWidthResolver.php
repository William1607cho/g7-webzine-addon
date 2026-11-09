<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * "글 본문 폭과 같게" 의 폭 값을 글 보기 레이아웃 JSON 에서 읽는다 (1.5.0).
 *
 * 템플릿 파일의 content 영역 첫 노드 className 에 있는 `max-w-*` 를 픽셀로 바꾼다
 * (Tailwind 기본값, 1rem = 16px). 못 찾으면 null 이고, 호출자는 "현재값"(폭 지정 없음)으로
 * 대체 동작한다.
 */
final class BodyWidthResolver
{
    /** @var array<string, int> */
    private const MAX_WIDTHS = [
        'xs' => 320, 'sm' => 384, 'md' => 448, 'lg' => 512, 'xl' => 576,
        '2xl' => 672, '3xl' => 768, '4xl' => 896, '5xl' => 1024, '6xl' => 1152, '7xl' => 1280,
    ];

    /**
     * @param  array<string, mixed>  $layout  글 보기 레이아웃 JSON
     */
    public static function fromShowLayout(array $layout): ?int
    {
        $first = $layout['slots']['content'][0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        $className = $first['props']['className'] ?? null;
        if (! is_string($className) || preg_match('/(?:^|\s)max-w-([0-9a-z]+)(?:\s|$)/', $className, $m) !== 1) {
            return null;
        }

        return self::MAX_WIDTHS[$m[1]] ?? null;
    }
}
