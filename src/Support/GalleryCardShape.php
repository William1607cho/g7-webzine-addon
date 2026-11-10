<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 랜덤 박스 카드 모양 (1.5.0).
 *
 * 템플릿 갤러리형 목록 카드를 최종 레이아웃 트리에서 찾아 깊은 복사로 쓴다
 * ({@see TemplateListControls} 와 같은 방식). 템플릿을 고치면 카드도 따라온다. 못 찾으면 같은
 * 정보를 담은 자체 카드를 쓴다 — 적용 결과는 `data-g7wz-randombox` 속성으로 드러난다.
 *
 * 프레임워크에 기대지 않는 순수 배열 연산이라 단위 테스트로 검증한다.
 */
final class GalleryCardShape
{
    /** 박스 데이터소스 id */
    public const DATA_SOURCE_ID = 'g7wz_random_box';

    private const GALLERY_NEEDLE = "type === 'gallery'";

    /**
     * 갤러리 카드 노드를 찾는다.
     *
     * @param  array<int, mixed>  $components  레이아웃 `components`
     * @return array<string, mixed>|null
     */
    public static function locate(array $components): ?array
    {
        $branch = self::findBranch($components);
        if ($branch === null) {
            return null;
        }

        return self::findCard($branch);
    }

    /**
     * 카드를 박스용으로 고친다 (템플릿 복사본이든 자체 카드든 같은 처리).
     *
     * 카드에는 이미지(또는 자물쇠·대체 이미지)·배지·제목만 남긴다 — 작성자·날짜·조회·댓글 줄은
     * 복사한 구조에서 뺀다({@see RandomBoxCardDecor}).
     *
     * @param  array<string, mixed>|null  $card  템플릿 카드 (없으면 자체 카드)
     * @param  string|null  $badgeText  배지 문구 (null 이면 배지 없음)
     * @return array<string, mixed>
     */
    public static function adapt(?array $card, string $ratio, ?string $badgeText = null): array
    {
        $node = RandomBoxCardDecor::stripMeta($card ?? self::builtinCard());
        if ($badgeText !== null) {
            $node = RandomBoxCardDecor::withBadge($node, $badgeText);
        }
        unset($node['comment']);
        $node['iteration'] = [
            'source' => '{{'.self::DATA_SOURCE_ID.'?.data?.items ?? []}}',
            'item_var' => 'post',
        ];

        $className = (string) ($node['props']['className'] ?? '');
        $node['props']['className'] = trim(preg_replace('/(?:^|\s)aspect-\S+/', ' ', $className) ?? '');
        $node['props']['style'] = ['aspectRatio' => str_replace(':', ' / ', $ratio)];

        return $node;
    }

    /**
     * 박스 전체 노드.
     *
     * @param  array<string, mixed>  $card  {@see self::adapt()} 결과
     * @param  array<string, mixed>  $design  정규화 설정
     * @param  array<string, string>|null  $areaStyle  박스 폭 style ({@see RandomBoxWidth::resolve()})
     * @param  string  $boxDesign  확인용 속성 `data-g7wz-boxdesign` 값
     * @return array<string, mixed>
     */
    public static function boxNode(array $card, array $design, string $origin, ?array $areaStyle = null, string $boxDesign = ''): array
    {
        $children = [];
        if ($design['random_heading_enabled'] && $design['random_heading_text'] !== '') {
            $children[] = [
                'type' => 'basic',
                'name' => 'H2',
                'props' => ['className' => 'text-lg font-semibold mb-3 text-gray-900 dark:text-white'],
                'text' => $design['random_heading_text'],
            ];
        }
        $children[] = [
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['className' => 'grid grid-cols-'.(int) $design['random_columns'].' gap-4'],
            'children' => [$card],
        ];

        $props = [
            'className' => 'hidden md:block mb-6',
            'data-g7wz-randombox' => $origin,
        ];
        if ($boxDesign !== '') {
            $props['data-g7wz-boxdesign'] = $boxDesign;
        }
        if ($areaStyle !== null) {
            $props['style'] = $areaStyle;
        }

        return [
            'comment' => 'g7-webzine-addon 랜덤 박스 (첫 페이지·검색/분류 아닐 때, 768px 이상)',
            'id' => 'g7_webzine_addon_random_box',
            'type' => 'basic',
            'name' => 'Div',
            'if' => '{{'.self::DATA_SOURCE_ID.'?.data?.items?.length > 0 && '.self::listStateCondition().'}}',
            'props' => $props,
            'children' => $children,
        ];
    }

    /**
     * 데이터소스 정의. 선택된 게시판 slug 만 요청한다.
     *
     * @param  array<int, string>  $slugs  검증된 게시판 slug
     * @return array<string, mixed>
     */
    public static function dataSource(array $slugs): array
    {
        return [
            'id' => self::DATA_SOURCE_ID,
            'type' => 'api',
            'endpoint' => '/api/plugins/g7-webzine-addon/random-box/{{route.slug}}',
            'method' => 'GET',
            'auto_fetch' => true,
            'auth_mode' => 'optional',
            'refetchOnMount' => true,
            'if' => '{{'.self::slugListExpression($slugs).'.includes(route.slug) && '.self::listStateCondition().'}}',
            'fallback' => ['data' => ['items' => []]],
        ];
    }

    /** 첫 페이지이고 검색·분류 중이 아닐 때 */
    private static function listStateCondition(): string
    {
        return "(!query.page || Number(query.page) <= 1) && !query.search && !query.category";
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private static function slugListExpression(array $slugs): string
    {
        $safe = array_values(array_filter($slugs, static fn (string $s) => preg_match('/^[A-Za-z0-9_-]+$/', $s) === 1));

        return "['".implode("','", $safe)."']";
    }

    /**
     * @param  array<int, mixed>  $nodes
     */
    private static function findBranch(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $if = $node['if'] ?? null;
            if (is_string($if) && str_contains($if, 'board?.type') && str_contains($if, self::GALLERY_NEEDLE)) {
                return $node;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $found = self::findBranch($node['children']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * 갤러리 분기 안의 첫 iteration 노드 (`post` 를 도는 카드).
     *
     * @param  array<string, mixed>  $node
     */
    private static function findCard(array $node): ?array
    {
        foreach (($node['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (isset($child['iteration'])) {
                return ($child['iteration']['item_var'] ?? null) === 'post' ? $child : null;
            }
            $found = self::findCard($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 템플릿 갤러리 카드를 못 찾았을 때의 자체 카드.
     *
     * @return array<string, mixed>
     */
    private static function builtinCard(): array
    {
        return [
            'type' => 'basic',
            'name' => 'Button',
            'props' => [
                'className' => 'block w-full text-left rounded-xl shadow-sm relative group cursor-pointer border border-gray-200 dark:border-gray-700 overflow-hidden',
            ],
            'actions' => [[
                'type' => 'click',
                'handler' => 'navigate',
                'params' => ['path' => '/board/{{route.slug}}/{{post.id}}', 'mergeQuery' => true, 'query' => []],
            ]],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Img',
                    'if' => '{{post?.thumbnail}}',
                    'props' => ['src' => '{{post?.thumbnail}}', 'alt' => '{{post?.title}}', 'className' => 'absolute inset-0 w-full h-full object-cover'],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'if' => '{{!post?.thumbnail}}',
                    'props' => ['className' => 'absolute inset-0 bg-gray-700 dark:bg-gray-800 flex items-center justify-center'],
                    'children' => [[
                        'type' => 'basic',
                        'name' => 'Icon',
                        'props' => ['name' => '{{post?.is_secret ? \'lock\' : \'image\'}}', 'size' => '2x', 'className' => 'text-gray-400 dark:text-gray-500'],
                    ]],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'absolute inset-x-0 bottom-0 px-3 pt-8 pb-3 bg-gradient-to-t from-black/90 to-transparent'],
                    'children' => [[
                        'type' => 'basic',
                        'name' => 'Span',
                        'props' => ['className' => 'block text-sm font-semibold text-white truncate'],
                        'text' => '{{post?.title}}',
                    ]],
                ],
            ],
        ];
    }
}
