<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\TemplateListControls as T;

/**
 * 템플릿 목록 버튼 빌려 쓰기 테스트 (1.4.0).
 *
 * 트리는 `_type_renderer.json` 이 인라인된 모양을 줄여 만든 것이다.
 *  - marked : wc-community 처럼 버튼에 data-testid 표식이 있는 템플릿
 *  - plain  : sirsoft-basic 처럼 표식이 없는 템플릿(글자 버튼) — 모양으로 찾아야 한다
 */
class TemplateListControlsTest extends TestCase
{
    private function writeButton(bool $marked, string $label): array
    {
        return [
            'type' => 'basic', 'name' => 'Button',
            'props' => ['disabled' => '{{posts?.data?.abilities?.can_write !== true}}'] + ($marked ? ['data-testid' => 'list-write', 'title' => '$t:board.write'] : []),
            'children' => [['type' => 'basic', 'name' => $marked ? 'Icon' : 'Span', 'text' => $label]],
        ];
    }

    private function adminLinks(bool $marked): array
    {
        return [
            'type' => 'basic', 'name' => 'Div',
            'if' => '{{posts?.data?.abilities?.can_access_admin}}',
            'children' => [
                ['type' => 'basic', 'name' => 'A', 'props' => ['href' => '/admin/board/{{route.slug}}'] + ($marked ? ['data-testid' => 'list-admin-posts'] : [])],
                ['type' => 'basic', 'name' => 'A', 'props' => ['href' => '/admin/boards/{{route.slug}}/edit'] + ($marked ? ['data-testid' => 'list-admin-manage'] : [])],
            ],
        ];
    }

    private function toggle(bool $marked): array
    {
        return [
            'type' => 'basic', 'name' => 'Button',
            'if' => '{{posts?.data?.abilities?.can_view_deleted}}',
            'props' => ['type' => 'button'] + ($marked ? ['data-testid' => 'toggle-deleted-posts', 'aria-pressed' => "{{query.del === '1' ? 'true' : 'false'}}"] : []),
        ];
    }

    private function emptyStates(string $tag): array
    {
        return [
            'type' => 'basic', 'name' => 'Div', 'meta' => ['is_partial' => true, 'description' => $tag],
            'children' => [
                ['type' => 'basic', 'name' => 'Div', 'if' => '{{posts?.data?.board?.slug && query.page && Number(query.page) > 1 && posts?.data?.data?.length === 0}}'],
                ['type' => 'basic', 'name' => 'Div', 'if' => '{{posts?.data?.board?.slug && !query.search && posts?.data?.pagination?.total === 0}}'],
                ['type' => 'basic', 'name' => 'Div', 'if' => '{{posts?.data?.board?.slug && (query.search || query.category) && posts?.data?.pagination?.total === 0}}'],
            ],
        ];
    }

    /** 유형 분기 하나(목록 본체 행 안에 가짜 권한 버튼을 넣어 반복 노드를 건너뛰는지도 본다) */
    private function typeBranch(string $if, bool $marked, string $tag): array
    {
        return [
            'type' => 'basic', 'name' => 'Div', 'if' => $if,
            'children' => [[
                'type' => 'basic', 'name' => 'Div', 'meta' => ['is_partial' => true, 'description' => $tag],
                'children' => [
                    ['type' => 'basic', 'name' => 'Div', 'children' => [
                        ['type' => 'basic', 'name' => 'H1'],
                        ['type' => 'basic', 'name' => 'Div', 'children' => [$this->adminLinks($marked), $this->writeButton($marked, $tag.'-top')]],
                    ]],
                    ['type' => 'basic', 'name' => 'Div', 'children' => [['type' => 'basic', 'name' => 'Select'], $this->toggle($marked)]],
                    ['type' => 'basic', 'name' => 'Grid', 'children' => [[
                        'type' => 'basic', 'name' => 'Button', 'iteration' => ['source' => '{{posts?.data?.data ?? []}}', 'item_var' => 'post'],
                        'props' => ['disabled' => '{{posts?.data?.abilities?.can_write !== true}}'],
                        'children' => [['type' => 'basic', 'name' => 'Div', 'if' => '{{posts?.data?.abilities?.can_access_admin}}']],
                    ]]],
                    $this->emptyStates($tag.'-empty'),
                    ['type' => 'composite', 'name' => 'Pagination'],
                    ['type' => 'basic', 'name' => 'Div', 'children' => [$this->writeButton($marked, $tag.'-bottom')]],
                ],
            ]],
        ];
    }

    /** @param list<string> $types */
    private function tree(bool $marked, array $types = ['basic', 'gallery', 'card']): array
    {
        $ifs = [
            'basic' => "{{posts?.data?.board?.type === 'basic' || !['gallery','card'].includes(posts?.data?.board?.type ?? 'basic')}}",
            'gallery' => "{{posts?.data?.board?.type === 'gallery'}}",
            'card' => "{{posts?.data?.board?.type === 'card'}}",
        ];
        $children = [];
        foreach ($types as $t) {
            $children[] = $this->typeBranch($ifs[$t], $marked, $t);
        }

        return [['type' => 'basic', 'name' => 'Div', 'children' => [['type' => 'basic', 'name' => 'Div', 'children' => $children]]]];
    }

    /** 웹진 분기 템플릿을 줄인 것 — 자리 표시가 붙은 자체 버튼 5곳 */
    private function webzineBranch(): array
    {
        $own = fn (string $slot, string $label) => ['type' => 'basic', 'name' => 'Button', T::SLOT_KEY => $slot, 'text' => $label];

        return [
            'id' => 'g7_webzine_addon_list_branch', 'type' => 'basic', 'name' => 'Div',
            'if' => "{{posts?.data?.board?.type === 'webzine'}}",
            'children' => [
                ['type' => 'basic', 'name' => 'Div', 'children' => [$own(T::ADMIN_LINKS, 'own-admin'), $own(T::WRITE_BUTTON, 'own-write-top')]],
                ['type' => 'basic', 'name' => 'Div', 'children' => [$own(T::DELETED_TOGGLE, 'own-toggle')]],
                ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'webzine-rows'], 'children' => [['type' => 'basic', 'name' => 'Span', 'text' => 'row']]],
                $own(T::EMPTY_STATES, 'own-empty'),
                ['type' => 'basic', 'name' => 'Div', 'children' => [$own(T::WRITE_BUTTON, 'own-write-bottom')]],
            ],
        ];
    }

    /** @param array<mixed> $node */
    private function countKey(array $node, string $key): int
    {
        $n = 0;
        array_walk_recursive($node, function ($v, $k) use (&$n, $key) {
            if ($k === $key) {
                $n++;
            }
        });

        return $n;
    }

    public function test_marked_template_is_found_by_markers_from_the_card_branch(): void
    {
        $c = T::collect($this->tree(true));

        $this->assertSame('card', $c['source']);
        $this->assertSame([T::ADMIN_LINKS => 'marker', T::WRITE_BUTTON => 'marker', T::DELETED_TOGGLE => 'marker', T::EMPTY_STATES => 'shape'], $c['via']);
        $this->assertSame($this->adminLinks(true), $c['nodes'][T::ADMIN_LINKS]);
        $this->assertSame($this->writeButton(true, 'card-top'), $c['nodes'][T::WRITE_BUTTON]);
        $this->assertSame($this->toggle(true), $c['nodes'][T::DELETED_TOGGLE]);
        $this->assertSame($this->emptyStates('card-empty'), $c['nodes'][T::EMPTY_STATES]);
    }

    public function test_plain_template_without_markers_is_found_by_shape(): void
    {
        $c = T::collect($this->tree(false));

        $this->assertSame('card', $c['source']);
        $this->assertSame([T::ADMIN_LINKS => 'shape', T::WRITE_BUTTON => 'shape', T::DELETED_TOGGLE => 'shape', T::EMPTY_STATES => 'shape'], $c['via']);
        $this->assertSame($this->adminLinks(false), $c['nodes'][T::ADMIN_LINKS]);
        $this->assertSame($this->writeButton(false, 'card-top'), $c['nodes'][T::WRITE_BUTTON]);
    }

    public function test_iteration_rows_are_never_used_as_the_source(): void
    {
        // 표식 없는 트리에서 목록 본체(반복 행)를 맨 앞으로 옮겨도, 찾는 것은 헤더 버튼이어야 한다.
        $tree = $this->tree(false, ['card']);
        $inner = &$tree[0]['children'][0]['children'][0]['children'][0]['children'];
        array_unshift($inner, $inner[2]);
        unset($inner[3]);
        $inner = array_values($inner);
        unset($inner);
        $this->assertArrayHasKey('iteration', $tree[0]['children'][0]['children'][0]['children'][0]['children'][0]['children'][0]);

        $c = T::collect($tree);

        $this->assertArrayNotHasKey('iteration', $c['nodes'][T::WRITE_BUTTON]);
        $this->assertSame('{{posts?.data?.abilities?.can_access_admin}}', $c['nodes'][T::ADMIN_LINKS]['if']);
        $this->assertCount(2, $c['nodes'][T::ADMIN_LINKS]['children']);
    }

    public function test_falls_back_to_gallery_then_basic_branch(): void
    {
        $this->assertSame('gallery', T::collect($this->tree(true, ['basic', 'gallery']))['source']);
        $this->assertSame('basic', T::collect($this->tree(true, ['basic']))['source']);
    }

    public function test_webzine_branch_itself_is_not_a_source(): void
    {
        $c = T::collect([$this->webzineBranch()]);

        $this->assertNull($c['source']);
        $this->assertSame([], $c['nodes']);
        $this->assertSame(array_fill_keys(T::SLOTS, 'none'), $c['via']);
    }

    public function test_apply_replaces_every_slot_with_template_copies(): void
    {
        $c = T::collect($this->tree(true));
        $out = T::apply($this->webzineBranch(), $c['nodes']);

        $top = $out['children'][0]['children'];
        $this->assertSame('{{posts?.data?.abilities?.can_access_admin}}', $top[0]['if']);
        $this->assertSame('list-write', $top[1]['props']['data-testid']);
        $this->assertSame('toggle-deleted-posts', $out['children'][1]['children'][0]['props']['data-testid']);
        $this->assertSame('card-empty', $out['children'][3]['meta']['description']);
        $bottom = $out['children'][4]['children'][0];
        $this->assertSame('list-write', $bottom['props']['data-testid']);
        // 상·하단 모두 템플릿 상단 버튼의 복사본(템플릿도 두 곳이 같은 파셜이다)
        $this->assertSame('card-top', $bottom['children'][0]['text']);
        $this->assertStringContainsString('g7-webzine-addon', $bottom['comment']);
        $this->assertSame(0, $this->countKey($out, T::SLOT_KEY));
        // 목록 본체·분기 식별자는 그대로
        $this->assertSame('webzine-rows', $out['children'][2]['props']['className']);
        $this->assertSame('g7_webzine_addon_list_branch', $out['id']);
    }

    public function test_apply_keeps_own_buttons_where_nothing_was_found(): void
    {
        $c = T::collect($this->tree(true));
        unset($c['nodes'][T::DELETED_TOGGLE]);
        $out = T::apply($this->webzineBranch(), $c['nodes']);

        $this->assertSame('own-toggle', $out['children'][1]['children'][0]['text']);
        $this->assertArrayNotHasKey(T::SLOT_KEY, $out['children'][1]['children'][0]);
        $this->assertSame(0, $this->countKey($out, T::SLOT_KEY));

        $none = T::apply($this->webzineBranch(), []);
        $this->assertSame('own-write-top', $none['children'][0]['children'][1]['text']);
        $this->assertSame('own-write-bottom', $none['children'][4]['children'][0]['text']);
        $this->assertSame(0, $this->countKey($none, T::SLOT_KEY));
    }

    public function test_summary_lists_every_slot(): void
    {
        $this->assertSame(
            'card:admin_links=marker,write_button=marker,deleted_toggle=marker,empty_states=shape',
            T::summary(T::collect($this->tree(true)))
        );
        $this->assertSame(
            'none:admin_links=none,write_button=none,deleted_toggle=none,empty_states=none',
            T::summary(T::collect([]))
        );
    }
}
