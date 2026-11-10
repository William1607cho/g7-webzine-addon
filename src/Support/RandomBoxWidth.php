<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 랜덤 박스 영역 폭과 박스를 넣을 자리 (1.5.0).
 *
 * 박스는 웹진 분기 `children[0]` 의 자식 인덱스 2(필터 바 뒤, 목록 본체 앞)에 들어간다. 목록 폭
 * ({@see WebzineListStyle}) 은 분기 최상위에 걸리므로, 박스가 목록과 다른 폭을 가지려면 그 제한
 * 밖에 있어야 한다. 그때는 `children[0]` 의 자식을 [위 묶음 / 박스 / 아래 묶음] 으로 나누고 목록
 * 폭 style·responsive 를 두 묶음으로 옮긴다(목록 모양은 같다).
 *
 * 프레임워크에 기대지 않는 순수 배열 연산이라 단위 테스트로 검증한다.
 */
final class RandomBoxWidth
{
    /** 목록 본체 자리 (헤더 0, 필터 바 1 다음) */
    private const BOX_INDEX = 2;

    /**
     * 실제로 쓸 폭 모드와 박스 style.
     *
     * @param  array<string, mixed>  $design  정규화 설정
     * @param  int|null  $bodyWidth  글 본문 폭(px), 못 읽었으면 null
     * @return array{mode: string, style: array<string, string>|null, inherit: bool}
     */
    public static function resolve(array $design, ?int $bodyWidth): array
    {
        $mode = (string) $design['random_area_mode'];

        if ($mode === WebzineDesignSettings::AREA_BODY && $bodyWidth === null) {
            $mode = WebzineDesignSettings::AREA_ORIGINAL;
        }

        return match ($mode) {
            WebzineDesignSettings::AREA_ORIGINAL => ['mode' => $mode, 'style' => null, 'inherit' => false],
            WebzineDesignSettings::AREA_BODY => [
                'mode' => $mode,
                'style' => ['maxWidth' => $bodyWidth.'px', 'marginLeft' => 'auto', 'marginRight' => 'auto'],
                'inherit' => false,
            ],
            WebzineDesignSettings::AREA_CUSTOM => [
                'mode' => $mode,
                // 1차와 같은 식 — 목록보다 넓어도 좁아도 가운데 정렬한다.
                'style' => [
                    'width' => (int) $design['random_area_px'].'px',
                    'maxWidth' => 'calc(100vw - 4rem)',
                    'position' => 'relative',
                    'left' => '50%',
                    'transform' => 'translateX(-50%)',
                ],
                'inherit' => false,
            ],
            default => ['mode' => WebzineDesignSettings::AREA_LIST, 'style' => null, 'inherit' => true],
        };
    }

    /**
     * 박스를 넣을 방식: `inline`(목록 폭 안, 1차와 같은 자리) / `split`(목록 폭 밖) / `none`(자리 없음).
     *
     * @param  array<string, mixed>  $branch
     */
    public static function placement(array $branch, bool $inherit): string
    {
        if (! is_array($branch['children'][0]['children'] ?? null)) {
            return 'none';
        }
        $listStyle = $branch['props']['style'] ?? null;

        return $inherit || ! is_array($listStyle) || ! isset($listStyle['maxWidth']) ? 'inline' : 'split';
    }

    /**
     * 박스를 분기에 넣는다.
     *
     * @param  array<string, mixed>  $branch  {@see WebzineListStyle::apply()} 를 거친 분기
     * @param  array<string, mixed>  $box  박스 노드
     * @return array<string, mixed>
     */
    public static function place(array $branch, array $box, bool $inherit): array
    {
        $placement = self::placement($branch, $inherit);
        if ($placement === 'none') {
            return $branch;
        }
        $inner = array_values($branch['children'][0]['children']);

        if ($placement === 'inline') {
            array_splice($inner, self::BOX_INDEX, 0, [$box]);
            $branch['children'][0]['children'] = $inner;

            return $branch;
        }

        $listStyle = $branch['props']['style'];
        $responsive = $branch['responsive'] ?? null;
        $group = static function (array $children, string $part) use ($listStyle, $responsive): array {
            $node = [
                'comment' => 'g7-webzine-addon 목록 폭 묶음('.$part.') — 박스가 목록과 다른 폭일 때만 나눈다',
                'type' => 'basic',
                'name' => 'Div',
                'props' => ['style' => $listStyle],
                'children' => $children,
            ];
            if (is_array($responsive)) {
                $node['responsive'] = $responsive;
            }

            return $node;
        };

        $branch['children'][0]['children'] = [
            $group(array_slice($inner, 0, self::BOX_INDEX), 'top'),
            $box,
            $group(array_slice($inner, self::BOX_INDEX), 'bottom'),
        ];
        unset($branch['props']['style'], $branch['responsive']);

        return $branch;
    }
}
