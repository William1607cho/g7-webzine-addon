<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 웹진 목록 디자인 값(폭·썸네일·요약 줄 수·행 높이)을 웹진 분기 트리에 적용한다 (1.5.0).
 *
 * - 폭: 분기 최상위 maxWidth. 768px 미만은 항상 100%.
 * - 행 높이(768px 이상): 오른쪽 글 영역에 min-height = 제목 1줄 + 요약 N줄 + 작성자 줄
 *   ({@see WebzineRowMetrics}). 요약이 짧거나 없으면 아래가 빈다.
 * - 썸네일: "행 높이에 맞춤"(기본)은 768px 이상에서 그 높이의 정사각형, 768 미만은 템플릿
 *   기본(80px). "직접 입력"은 1차와 같이 모든 폭에서 N px.
 * - 768 경계는 빌드 CSS 에 높이 반응형 클래스가 없어 인라인 style + `responsive.mobile` 되돌림.
 *
 * 768px 미만의 모양은 1차(1.5.0 1차·1.4.0)와 같다. 적용 결과는 `data-g7wz-design` 속성으로 드러난다.
 * 프레임워크에 기대지 않는 순수 배열 연산이라 단위 테스트로 검증한다.
 */
final class WebzineListStyle
{
    private const THUMB_BOX_NEEDLE = 'relative w-20 h-20';

    private const SUMMARY_NEEDLE = 'line-clamp-2';

    private const TEXT_COLUMN_NEEDLE = 'flex-1 min-w-0 flex flex-col gap-1';

    /** 768px 미만에서 인라인 style 을 비워 템플릿 클래스로 돌린다 */
    private const MOBILE_RESET_SIZE = ['width' => '', 'height' => ''];

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
            // 768px 미만에서는 항상 화면 폭 100%.
            $branch['responsive'] = ['mobile' => ['props' => ['style' => ['maxWidth' => '100%', 'marginLeft' => '0', 'marginRight' => '0']]]];
        }

        $lines = (int) $design['list_summary_lines'];
        $fit = ($design['list_thumb_mode'] ?? WebzineDesignSettings::THUMB_FIT) === WebzineDesignSettings::THUMB_FIT;
        $thumb = (int) $design['list_thumb_px'];

        $branch = self::walk($branch, [
            'lines' => $lines,
            'rowCss' => WebzineRowMetrics::css($lines),
            'fit' => $fit,
            // "직접 입력"이 80 이면 1차와 같이 썸네일을 건드리지 않는다.
            'px' => ! $fit && $thumb !== WebzineDesignSettings::THUMB_DEFAULT ? $thumb : null,
        ]);

        $branch['props']['data-g7wz-design'] = self::summary($design, $maxWidth, $fit, $thumb, $lines);

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
     * @param  array{lines: int, rowCss: string, fit: bool, px: int|null}  $opt
     * @return array<string, mixed>
     */
    private static function walk(array $node, array $opt): array
    {
        $name = $node['name'] ?? null;
        $className = $node['props']['className'] ?? null;

        if (is_string($className)) {
            if ($name === 'Div' && str_contains($className, self::THUMB_BOX_NEEDLE)) {
                if ($opt['fit']) {
                    $node['props']['style'] = ['width' => $opt['rowCss'], 'height' => $opt['rowCss']];
                    $node['responsive']['mobile']['props']['style'] = self::MOBILE_RESET_SIZE;
                } elseif ($opt['px'] !== null) {
                    $node['props']['style'] = ['width' => $opt['px'].'px', 'height' => $opt['px'].'px'];
                }
            }
            if ($name === 'Div' && str_contains($className, self::TEXT_COLUMN_NEEDLE)) {
                $node['props']['style'] = ['minHeight' => $opt['rowCss']];
                $node['responsive']['mobile']['props']['style'] = ['minHeight' => ''];
            }
            if ($opt['lines'] !== WebzineDesignSettings::LINES_DEFAULT && $name === 'HtmlContent' && str_contains($className, self::SUMMARY_NEEDLE)) {
                $node['props']['className'] = str_replace(self::SUMMARY_NEEDLE, 'line-clamp-'.$opt['lines'], $className);
            }
        }

        if ($opt['px'] !== null && $name === 'Img' && isset($node['props']['width'])) {
            $node['props']['width'] = (string) $opt['px'];
            $node['props']['height'] = (string) $opt['px'];
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = self::walk($child, $opt);
                }
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $design
     */
    private static function summary(array $design, ?int $maxWidth, bool $fit, int $thumb, int $lines): string
    {
        $row = WebzineRowMetrics::px($lines);

        return sprintf(
            'width=%s:%s,thumb=%s,lines=%d,row=%d',
            $design['list_width_mode'],
            $maxWidth ?? 'none',
            $fit ? 'fit:'.$row : 'px:'.$thumb,
            $lines,
            $row
        );
    }
}
