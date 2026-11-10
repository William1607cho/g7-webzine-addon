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

    /** 배지가 있을 때 템플릿 상단 배지 행을 내리는 거리 (배지 top-2 + 높이 약 1.25rem 아래) */
    private const TOP_ROW_SHIFT = '1.75rem';

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
     * @return array<string, mixed>
     */
    public static function badgeNode(string $text): array
    {
        return [
            'comment' => 'g7-webzine-addon 추천 배지 (문구는 글자로만 출력)',
            'type' => 'basic',
            'name' => 'Span',
            'props' => [
                'className' => 'absolute top-2 left-2 z-20 inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-bold bg-primary-600 text-white shadow-sm pointer-events-none',
                'style' => ['maxWidth' => 'calc(100% - 1rem)'],
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
