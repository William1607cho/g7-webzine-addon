<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 템플릿이 그리는 게시판 목록 버튼을 찾아 웹진 분기에 그대로 가져다 쓰기 위한 도구 (1.4.0).
 *
 * 웹진 분기는 1.0.0 부터 헤더·필터·빈 상태·글쓰기 버튼까지 카드형 목록을 통째로 복제해
 * 들고 있었다. 그래서 템플릿이 버튼 모양을 바꿔도(예: wc-community fork-20260926 의 아이콘
 * 버튼) 웹진 목록만 옛 글자 버튼으로 남았다.
 *
 * 1.4.0 부터는 `after_apply` 필터 훅이 받는 **최종 레이아웃 트리**(파셜이 모두 인라인된
 * 상태)에서, 템플릿의 카드형(없으면 갤러리형·기본형) 분기가 그린 버튼 노드를 찾아 **깊은
 * 복사**로 웹진 분기의 같은 자리에 넣는다. 템플릿만 고치면 웹진 목록도 따라간다.
 *
 * 찾는 순서는 자리마다 둘이다.
 *  1. 표식 — 템플릿이 단 `data-testid`(wc-community: `list-write`·`list-admin-posts`·
 *     `toggle-deleted-posts`). 빈 상태 묶음은 표식이 없어 2 만 쓴다.
 *  2. 모양 — 표식이 없는 템플릿(sirsoft-basic 등)을 위해 권한 식(`can_write`·
 *     `can_access_admin`·`can_view_deleted`)과 빈 상태 조건식으로 찾는다.
 * 둘 다 실패한 자리는 웹진 분기가 원래 갖고 있던 버튼을 그대로 쓴다(1.3.0 과 같은 모양).
 *
 * 프레임워크에 기대지 않는 순수 배열 연산이라 단위 테스트로 검증한다.
 */
final class TemplateListControls
{
    /** 웹진 분기 템플릿에서 "여기를 템플릿 노드로 바꿔 끼운다" 는 자리 표시 키 (최종 트리에는 남지 않는다) */
    public const SLOT_KEY = 'g7wz_slot';

    public const WRITE_BUTTON = 'write_button';

    public const ADMIN_LINKS = 'admin_links';

    public const DELETED_TOGGLE = 'deleted_toggle';

    public const EMPTY_STATES = 'empty_states';

    /** @var list<string> */
    public const SLOTS = [self::ADMIN_LINKS, self::WRITE_BUTTON, self::DELETED_TOGGLE, self::EMPTY_STATES];

    /** 버튼을 빌려 올 형제 분기 — 앞에 있을수록 우선 (웹진 분기는 카드형에서 복제한 것이라 카드형이 가장 가깝다) */
    private const SOURCE_BRANCHES = [
        'card' => "type === 'card'",
        'gallery' => "type === 'gallery'",
        'basic' => "!['gallery','card'",
    ];

    /** 템플릿 표식 (data-testid) */
    private const MARKERS = [
        self::WRITE_BUTTON => 'list-write',
        self::ADMIN_LINKS => 'list-admin-posts',
        self::DELETED_TOGGLE => 'toggle-deleted-posts',
    ];

    /**
     * 최종 레이아웃 트리에서 템플릿 목록 버튼을 모은다.
     *
     * @param  array<int, mixed>  $components  레이아웃 `components`
     * @return array{source: ?string, nodes: array<string, array<string, mixed>>, via: array<string, string>}
     *         source = 빌려 온 분기(card|gallery|basic|null), via[자리] = marker|shape|none
     */
    public static function collect(array $components): array
    {
        $result = ['source' => null, 'nodes' => [], 'via' => array_fill_keys(self::SLOTS, 'none')];

        foreach (self::SOURCE_BRANCHES as $source => $needle) {
            $branch = self::findBranch($components, $needle);
            if ($branch === null) {
                continue;
            }

            $nodes = [];
            $via = [];
            foreach (self::SLOTS as $slot) {
                [$node, $how] = self::locate($branch, $slot);
                if ($node !== null) {
                    $nodes[$slot] = $node;
                    $via[$slot] = $how;
                }
            }

            if ($nodes !== []) {
                $result['source'] = $source;
                $result['nodes'] = $nodes;
                $result['via'] = $via + $result['via'];

                return $result;
            }
        }

        return $result;
    }

    /**
     * 웹진 분기 안의 자리 표시 노드를 템플릿 노드 복사본으로 바꾼다. 못 찾은 자리는 원래 노드를
     * 그대로 두고 자리 표시 키만 지운다.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $templateNodes  collect() 의 nodes
     * @return array<string, mixed>
     */
    public static function apply(array $node, array $templateNodes): array
    {
        $slot = $node[self::SLOT_KEY] ?? null;
        if (is_string($slot)) {
            if (isset($templateNodes[$slot])) {
                $copy = $templateNodes[$slot];
                $copy['comment'] = 'g7-webzine-addon: 템플릿 '.$slot.' 복사본 (템플릿을 고치면 따라온다)';

                return $copy;
            }
            unset($node[self::SLOT_KEY]);
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = self::apply($child, $templateNodes);
                }
            }
        }

        return $node;
    }

    /**
     * 점검용 요약 문자열 — 웹진 분기 Div 의 `data-g7wz-controls` 속성 값으로 쓴다.
     * 예: `card:admin_links=marker,write_button=marker,deleted_toggle=marker,empty_states=shape`
     *
     * @param  array{source: ?string, via: array<string, string>}  $collected
     */
    public static function summary(array $collected): string
    {
        $parts = [];
        foreach (self::SLOTS as $slot) {
            $parts[] = $slot.'='.($collected['via'][$slot] ?? 'none');
        }

        return ($collected['source'] ?? 'none').':'.implode(',', $parts);
    }

    /**
     * `if` 조건에 needle 이 들어 있는 분기 Div 를 찾는다(깊이 우선, 처음 것).
     *
     * @param  array<int, mixed>  $nodes
     * @return array<string, mixed>|null
     */
    private static function findBranch(array $nodes, string $needle): ?array
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $if = $node['if'] ?? null;
            if (is_string($if) && str_contains($if, 'board?.type') && str_contains($if, $needle)) {
                return $node;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $found = self::findBranch($node['children'], $needle);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $branch
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private static function locate(array $branch, string $slot): array
    {
        if (isset(self::MARKERS[$slot])) {
            $marker = self::MARKERS[$slot];
            $node = $slot === self::ADMIN_LINKS
                ? self::findParentOf($branch, fn (array $n) => self::testId($n) === $marker)
                : self::find($branch, fn (array $n) => self::testId($n) === $marker);
            if ($node !== null) {
                return [$node, 'marker'];
            }
        }

        $node = self::find($branch, match ($slot) {
            self::WRITE_BUTTON => fn (array $n) => ($n['name'] ?? null) === 'Button'
                && self::strContains($n['props']['disabled'] ?? null, 'abilities?.can_write'),
            self::ADMIN_LINKS => fn (array $n) => self::strContains($n['if'] ?? null, 'abilities?.can_access_admin'),
            self::DELETED_TOGGLE => fn (array $n) => ($n['name'] ?? null) === 'Button'
                && self::strContains($n['if'] ?? null, 'abilities?.can_view_deleted'),
            self::EMPTY_STATES => fn (array $n) => self::isEmptyStates($n),
        });

        return [$node, $node !== null ? 'shape' : 'none'];
    }

    /**
     * 빈 상태 묶음: 자식 분기 중에 "페이지에 글 없음"(page>1 + 0건)과 "글 없음"(total 0)이 함께 있는 노드.
     *
     * @param  array<string, mixed>  $n
     */
    private static function isEmptyStates(array $n): bool
    {
        if (! isset($n['children']) || ! is_array($n['children'])) {
            return false;
        }
        $page = false;
        $total = false;
        foreach ($n['children'] as $c) {
            $if = is_array($c) ? ($c['if'] ?? null) : null;
            $page = $page || (self::strContains($if, 'Number(query.page) > 1') && self::strContains($if, 'length === 0'));
            $total = $total || self::strContains($if, 'pagination?.total === 0');
        }

        return $page && $total;
    }

    /**
     * 조건에 맞는 첫 노드(깊이 우선). 반복(iteration) 노드와 그 안은 목록 행이라 보지 않는다.
     *
     * @param  array<string, mixed>  $node
     */
    private static function find(array $node, callable $match): ?array
    {
        if (isset($node['iteration'])) {
            return null;
        }
        if ($match($node)) {
            return $node;
        }
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child) && ($found = self::find($child, $match)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 자식 중 하나가 조건에 맞는 첫 노드(그 부모)를 돌려준다.
     *
     * @param  array<string, mixed>  $node
     */
    private static function findParentOf(array $node, callable $match): ?array
    {
        if (isset($node['iteration'])) {
            return null;
        }
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child) && $match($child)) {
                return $node;
            }
        }
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child) && ($found = self::findParentOf($child, $match)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $n */
    private static function testId(array $n): ?string
    {
        $v = $n['props']['data-testid'] ?? null;

        return is_string($v) ? $v : null;
    }

    private static function strContains(mixed $haystack, string $needle): bool
    {
        return is_string($haystack) && str_contains($haystack, $needle);
    }
}
