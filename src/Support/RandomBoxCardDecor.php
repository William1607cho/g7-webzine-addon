<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 랜덤 박스 카드 다듬기 (1.5.0): 작성자·날짜·조회·댓글 줄을 빼고 "추천" 배지를 붙인다.
 *
 * 둘 다 복사한 레이아웃 구조(배열) 단계에서 한다 — 화면에 그려진 DOM 은 건드리지 않는다.
 * 배지 문구는 레이아웃 `text`(글자 노드)로만 나간다({@see WebzineDesignSettings::badgeText()}).
 *
 * 프레임워크에 기대지 않는 순수 배열 연산이라 단위 테스트로 검증한다.
 */
final class RandomBoxCardDecor
{
    /** 메타 줄 표식 (작성자·작성일·조회수·댓글수) */
    private const META_COMPONENTS = ['Avatar', 'UserInfo'];

    private const META_NEEDLES = ['created_at', 'view_count', 'comment_count'];

    private const TITLE_NEEDLES = ['post?.title', 'post.title'];

    /** 템플릿 카드의 상단 배지 행(공지·분류·N) 표식 */
    private const TOP_ROW_NEEDLE = 'absolute inset-x-0 top-0';

    /**
     * 배지가 있을 때 템플릿 상단 배지 행(p-2)을 내리는 거리.
     * 배지 아래 끝 = top-2(.5rem) + 높이 2rem(글자 1rem + 위아래 여백 .5rem×2) = 2.5rem,
     * 행의 배지 시작 = 2.25rem + 행 여백 .5rem = 2.75rem → 사이 .25rem.
     */
    private const TOP_ROW_SHIFT = '2.25rem';

    /** 배지 쌓임 순서 — 이미지(auto) 위, 템플릿 잠금·블라인드 오버레이(z-10) 아래 */
    public const BADGE_Z = 5;

    /**
     * 메타 줄을 뺀다. 제목이 들어 있는 노드는 남기고 그 안을 다시 살핀다.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function stripMeta(array $node): array
    {
        if (! isset($node['children']) || ! is_array($node['children'])) {
            return $node;
        }

        $kept = [];
        foreach ($node['children'] as $child) {
            if (! is_array($child)) {
                $kept[] = $child;

                continue;
            }
            $flat = json_encode($child, JSON_UNESCAPED_UNICODE) ?: '';
            if (self::hasMeta($child, $flat) && ! self::containsAny($flat, self::TITLE_NEEDLES)) {
                continue;
            }
            $kept[] = self::stripMeta($child);
        }
        $node['children'] = $kept;

        return $node;
    }

    /**
     * 카드 좌상단 배지를 붙인다(첫 자식으로). 템플릿 상단 배지 행이 있으면 겹치지 않게 내린다.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public static function withBadge(array $card, string $text): array
    {
        $children = is_array($card['children'] ?? null) ? $card['children'] : [];
        foreach ($children as $i => $child) {
            $className = is_array($child) ? ($child['props']['className'] ?? null) : null;
            if (is_string($className) && str_contains($className, self::TOP_ROW_NEEDLE)) {
                $style = is_array($child['props']['style'] ?? null) ? $child['props']['style'] : [];
                $children[$i]['props']['style'] = ['top' => self::TOP_ROW_SHIFT] + $style;
            }
        }
        array_unshift($children, self::badgeNode($text));
        $card['children'] = $children;

        return $card;
    }

    /**
     * 배지 노드. 색은 템플릿 primary(사이트 주황) 600 — 라이트·다크 같은 값(흰 글자 대비 약 4.1).
     *
     * 쌓임 순서는 이미지 위·잠금/블라인드 오버레이(z-10) 아래(z 5)다. 작은 카드(6열·16:9 등)에서
     * 배지와 가운데 자물쇠가 겹쳐도 자물쇠가 위에 그려진다.
     *
     * @return array<string, mixed>
     */
    public static function badgeNode(string $text): array
    {
        return [
            'comment' => 'g7-webzine-addon 추천 배지 (문구는 글자로만 출력)',
            'type' => 'basic',
            'name' => 'Span',
            'props' => [
                // 여백은 네 방향 모두 p-2(.5rem), 모서리 둥글기는 그 여백과 같은 rounded-lg(.5rem).
                'className' => 'absolute top-2 left-2 inline-flex items-center gap-1 p-2 rounded-lg text-xs font-bold bg-primary-600 text-white shadow-sm pointer-events-none',
                'style' => ['maxWidth' => 'calc(100% - 1rem)', 'zIndex' => self::BADGE_Z],
                'data-g7wz-badge' => '1',
            ],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Icon',
                    'props' => ['name' => 'trophy', 'size' => 'xs', 'className' => 'text-white flex-shrink-0'],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Span',
                    'props' => ['className' => 'truncate min-w-0'],
                    'text' => WebzineDesignSettings::badgeText($text),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function hasMeta(array $node, string $flat): bool
    {
        if (self::containsAny($flat, self::META_NEEDLES)) {
            return true;
        }
        foreach (self::META_COMPONENTS as $component) {
            if (str_contains($flat, '"name":"'.$component.'"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
