<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 웹진 목록 디자인 값(폭·썸네일 크기·요약 줄 수)을 웹진 분기 트리에 적용한다 (1.5.0).
 *
 * 설정이 기본값이면 트리를 전혀 건드리지 않는다(기존 사이트 화면 유지). 적용 결과는
 * `data-g7wz-design` 속성으로 드러난다.
 *
 * 프레임워크에 기대지 않는 순수 배열 연산이라 단위 테스트로 검증한다.
 */
final class WebzineListStyle
{
    private const THUMB_BOX_NEEDLE = 'relative w-20 h-20';

    private const SUMMARY_NEEDLE = 'line-clamp-2';

    /**
     * @param  array<string, mixed>  $branch  웹진 분기 노드
     * @param  array<string, mixed>  $design  정규화 설정
     * @param  int|null  $bodyWidth  글 본문 폭(px), 실측 실패면 null
     * @return array<string, mixed>
     */
    public static function apply(array $branch, array $design, ?int $bodyWidth): array
    {
        $maxWidth = self::resolveMaxWidth($design, $bodyWidth);
        if ($maxWidth !== null) {
            $branch['props']['style'] = ['maxWidth' => $maxWidth.'px', 'marginLeft' => 'auto', 'marginRight' => 'auto'];
            // 768px 미만에서는 항상 화면 폭 100% (명령서 3-2).
            $branch['responsive'] = ['mobile' => ['props' => ['style' => ['maxWidth' => '100%', 'marginLeft' => '0', 'marginRight' => '0']]]];
        }

        $thumb = (int) $design['list_thumb_px'];
        $lines = (int) $design['list_summary_lines'];
        if ($thumb !== WebzineDesignSettings::THUMB_DEFAULT || $lines !== WebzineDesignSettings::LINES_DEFAULT) {
            $branch = self::walk($branch, $thumb, $lines);
        }

        $branch['props']['data-g7wz-design'] = self::summary($design, $maxWidth, $thumb, $lines);

        return $branch;
    }

    /**
     * 목록에 걸 최대 폭(px). null 이면 폭 지정 없음(템플릿 그대로).
     *
     * @param  array<string, mixed>  $design
     */
    public static function resolveMaxWidth(array $design, ?int $bodyWidth): ?int
    {
        return match ($design['list_width_mode']) {
            WebzineDesignSettings::WIDTH_BODY => $bodyWidth,
            WebzineDesignSettings::WIDTH_CUSTOM => (int) $design['list_width_px'],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function walk(array $node, int $thumb, int $lines): array
    {
        $className = $node['props']['className'] ?? null;

        if (is_string($className)) {
            if ($thumb !== WebzineDesignSettings::THUMB_DEFAULT && ($node['name'] ?? null) === 'Div' && str_contains($className, self::THUMB_BOX_NEEDLE)) {
                $node['props']['style'] = ['width' => $thumb.'px', 'height' => $thumb.'px'];
            }
            if ($lines !== WebzineDesignSettings::LINES_DEFAULT && ($node['name'] ?? null) === 'HtmlContent' && str_contains($className, self::SUMMARY_NEEDLE)) {
                $node['props']['className'] = str_replace(self::SUMMARY_NEEDLE, 'line-clamp-'.$lines, $className);
            }
        }

        if ($thumb !== WebzineDesignSettings::THUMB_DEFAULT && ($node['name'] ?? null) === 'Img' && isset($node['props']['width'])) {
            $node['props']['width'] = (string) $thumb;
            $node['props']['height'] = (string) $thumb;
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = self::walk($child, $thumb, $lines);
                }
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $design
     */
    private static function summary(array $design, ?int $maxWidth, int $thumb, int $lines): string
    {
        return sprintf('width=%s:%s,thumb=%d,lines=%d', $design['list_width_mode'], $maxWidth ?? 'none', $thumb, $lines);
    }
}
