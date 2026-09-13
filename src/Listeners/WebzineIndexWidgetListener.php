<?php

namespace Plugins\G7\Webzine\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;

/**
 * webzine 게시판 목록(`board/index`) 화면을 1열 리스트(좌측 정사각 썸네일+우측 텍스트)
 * 레이아웃으로 렌더링하는 리스너.
 *
 * (2026-09-14 재작업: 최초 구현은 3열 그리드 카드형(`types/card` 인라인 복제)이었으나,
 * 실브라우저 검증 후 "갤러리 같다"는 피드백으로 폐기 — 원하는 형태는 "좌측 정사각
 * 썸네일(작은~중간 크기) + 우측 텍스트가 1열로 쌓이는 리스트"였다. 이 재작업은
 * `WEBZINE_LIST_LAYOUT_SQUARE_THUMBNAIL_LIST_FEASIBILITY_REPORT.md` 조사에서 찾은
 * sirsoft-basic 코어의 장바구니 `_cart_item.json`/`_cart_list.json` 관례(고정 `w-* h-*`
 * 정사각 썸네일 Div + `flex-1 min-w-0` 텍스트, `divide-y`로 나뉜 세로 리스트)를 그대로
 * 이식했다 — board 모듈엔 이 형태의 참고 구현이 없지만, 모듈 경계와 무관한 확립된
 * UI 관례이기 때문. `webzineBranchNode()`의 반환값(구 카드 그리드 서브트리)만 교체했고,
 * 아래 훅 등록·분기 rewrite·삽입 메커니즘 8개 메서드는 전부 무수정이다.)
 *
 * `core.layout_extension.after_apply` **필터 훅**에 붙는다 — `g7-forum-addon`의
 * `BoardIndexWidgetListener`와 같은 메커니즘, 개입 지점만 다르다. forum-addon은 이미
 * 완성된 `types/basic/index.json` 서브트리 안에 컬럼(헤더/셀)을 끼워 넣는 수준이었지만,
 * 이 리스너는 `_type_renderer.json` 상당의 **유형별 분기 컨테이너 자체**를 건드린다:
 *
 * 1. `board.type` 값으로 갈리는 3개 형제 분기(Div) 중 "`basic`이거나 `gallery`/`card`가
 *    아닌 전부"라는 fallback 분기를 찾아, 그 `if` 조건 문자열에서 `webzine`을 제외 대상에
 *    추가한다(`!['gallery','card'].includes(...)` → `!['gallery','card','webzine'].includes(...)`).
 *    이 조건을 고치지 않으면 fallback이 여전히 webzine을 basic(테이블) 레이아웃으로 잡아먹는다.
 * 2. 그 fallback 분기 바로 뒤에 webzine 전용 분기(`if: board.type === 'webzine'`)를 새
 *    형제 노드로 삽입한다. 이 분기의 내용은 `sirsoft-basic` 코어의 카드형(`card`) 목록
 *    레이아웃을 그대로 복제한 것이다({@see self::webzineBranchNode()} 참고).
 *
 * `_type_renderer.json`은 build 시점에 파셜이 전부 인라인되어 최종 트리에 3분기 모두
 * 들어있고 런타임 `if`로 갈리는 구조이므로(레이아웃은 board별로 따로 빌드되지 않고
 * 공유됨), 이 리스너는 `board/index` 레이아웃이면 **board 타입과 무관하게 항상**
 * 구조를 rewrite하고, 실제로 어느 분기가 보이는지는 클라이언트가 로드한 게시판 데이터의
 * `board.type` 값으로 런타임에 결정된다(gallery/card 분기와 동일한 방식).
 *
 * 앵커 문자열(`_type_renderer.json`의 `if` 조건 리터럴)이 향후 코어 업데이트로 바뀌면
 * 매칭이 실패할 수 있다 — 그 경우 조용히 넘어가지 않고 `Log::error`로 남긴다
 * (forum-addon의 `BoardIndexWidgetListener::injectListWidgets()`와 동일한 안전 패턴).
 */
class WebzineIndexWidgetListener implements HookListenerInterface
{
    /** webzine 분기 노드의 안정 식별자 (멱등 방어) */
    private const WEBZINE_BRANCH_ID = 'g7_webzine_addon_list_branch';

    /** fallback 분기 `if` 조건에서 찾는 앵커 문자열 (rewrite 대상 판별 + 치환 대상) */
    private const FALLBACK_ANCHOR = "!['gallery','card'].includes(";

    /** fallback 분기 `if` 조건 rewrite 후 문자열 (webzine 제외 추가) */
    private const FALLBACK_REWRITE = "!['gallery','card','webzine'].includes(";

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'injectWebzineListLayout',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    public function handle(...$args): void {}

    /**
     * @param  array  $layout      확장 적용이 끝난 최종 레이아웃 트리
     * @param  int    $templateId
     * @return array  수정된 레이아웃 (board/index 가 아니면 원본 그대로)
     */
    public function injectWebzineListLayout(array $layout, int $templateId = 0): array
    {
        if (($layout['layout_name'] ?? null) !== 'board/index') {
            return $layout;
        }

        if (! isset($layout['components']) || ! is_array($layout['components'])) {
            return $layout;
        }

        if ($this->treeHasNodeId($layout['components'], self::WEBZINE_BRANCH_ID)) {
            return $layout; // 이미 적용됨 (멱등)
        }

        $applied = 0;
        $layout['components'] = $this->transform($layout['components'], $applied);

        if ($applied === 0) {
            Log::error('[g7-webzine-addon] board/index 유형 분기 앵커(_type_renderer basic-fallback if 조건)를 찾지 못해 webzine 카드 레이아웃을 주입하지 못했습니다. sirsoft-basic 레이아웃 구조 변경 여부 확인 필요.', [
                'template_id' => $templateId,
            ]);
        }

        return $layout;
    }

    /**
     * 트리를 재귀 순회하며 basic-fallback 분기를 찾아 조건을 rewrite 하고, 그 형제로
     * webzine 전용 분기를 삽입한다.
     *
     * @param  array<int, mixed>  $nodes
     * @param  int  $applied  (참조) 적용 횟수 — fallback 분기를 찾아 실제로 rewrite+삽입했을 때만 증가.
     * @return array<int, mixed>
     */
    private function transform(array $nodes, int &$applied): array
    {
        $fallbackIndex = null;
        foreach ($nodes as $i => $node) {
            if ($this->isBasicFallbackDiv($node)) {
                $fallbackIndex = $i;
                break;
            }
        }

        $out = [];
        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->transform($node['children'], $applied);
            }
            $out[] = $node;
        }

        if ($fallbackIndex !== null) {
            $out[$fallbackIndex] = $this->rewriteBasicFallbackIf($out[$fallbackIndex]);
            $out = $this->insertAfter($out, $fallbackIndex, $this->webzineBranchNode());
            $applied++;
        }

        return $out;
    }

    /**
     * fallback 분기 판정: `type === 'basic' || !['gallery','card'].includes(...)` 형태의
     * `if` 조건을 가진 basic Div. (`_type_renderer.json`의 fallback 분기 리터럴과 정확히
     * 일치하는 앵커 문자열로 판별 — forum-addon이 className/text로 판별하는 것과 동일한
     * "정확한 리터럴 매칭" 원칙.)
     */
    private function isBasicFallbackDiv(mixed $node): bool
    {
        if (! is_array($node) || ($node['type'] ?? null) !== 'basic' || ($node['name'] ?? null) !== 'Div') {
            return false;
        }

        $if = $node['if'] ?? null;

        return is_string($if) && str_contains($if, self::FALLBACK_ANCHOR);
    }

    /**
     * fallback 분기의 `if` 조건 문자열에서 webzine 을 제외 대상으로 추가한다.
     */
    private function rewriteBasicFallbackIf(array $node): array
    {
        $if = $node['if'] ?? '';

        if (is_string($if) && str_contains($if, self::FALLBACK_ANCHOR)) {
            $node['if'] = str_replace(self::FALLBACK_ANCHOR, self::FALLBACK_REWRITE, $if);
        }

        return $node;
    }

    /**
     * 주어진 인덱스 바로 뒤에 노드를 삽입한 새 배열을 반환한다.
     *
     * @param  array<int, mixed>  $children
     */
    private function insertAfter(array $children, int $index, array $newNode): array
    {
        $before = array_slice($children, 0, $index + 1);
        $after = array_slice($children, $index + 1);

        return [...$before, $newNode, ...$after];
    }

    /**
     * 트리 어딘가에 주어진 id 를 가진 노드가 이미 있는지 (멱등 방어).
     *
     * @param  array<int, mixed>  $nodes
     */
    private function treeHasNodeId(array $nodes, string $id): bool
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === $id) {
                return true;
            }
            if (isset($node['children']) && is_array($node['children']) && $this->treeHasNodeId($node['children'], $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * webzine 목록 분기 노드 (1열 리스트: 좌측 정사각 썸네일 + 우측 텍스트).
     *
     * 최상위 헤더(게시판명/설명/글쓰기 버튼)·필터 바(카테고리/검색/삭제글 포함 토글)·
     * 빈 상태 안내·페이지네이션·하단 글쓰기 버튼(children 인덱스 0,1,3,4,5)은
     * 2026-09-13 최초 구현 때 `types/card/index.json`에서 복제한 그대로 유지한다 —
     * 카드형이든 리스트형이든 공통으로 필요한 "카드/리스트 밖" UI 조각이기 때문이다.
     *
     * **children 인덱스 2(게시글 목록 본체)만 2026-09-14에 전면 교체했다.** 기존
     * `Grid`(`cols:1→2→3` 반응형, `h-48` 가로 이미지 카드)를 폐기하고, `sirsoft-basic`
     * 코어의 장바구니 파셜 — {@see 'app/templates/sirsoft-basic/layouts/partials/
     * shop/_cart_item.json'}, {@see 'app/templates/sirsoft-basic/layouts/partials/
     * shop/_cart_list.json'} — 이 쓰는 관례(고정 `w-20 h-20` 정사각 썸네일 Div +
     * `flex-1 min-w-0` 텍스트, `divide-y`로 나뉜 세로 리스트, Grid 미사용)를 그대로
     * 이식했다. `WEBZINE_LIST_LAYOUT_SQUARE_THUMBNAIL_LIST_FEASIBILITY_REPORT.md`
     * 조사에서 이 관례가 board 모듈 밖(장바구니·마이페이지 주문내역 5개 파일)에
     * 반복 확립되어 있음을 확인하고 채택했다.
     *
     * 데이터 바인딩 표현식(`post?.thumbnail`, `post?.content_preview` 등)은 전부 구
     * 카드 서브트리에서 문자 그대로 옮겨왔다 — 값이 아니라 **배치만** 바뀌었다:
     * - 공지/카테고리/신규(N) 배지: 원래 이미지 위 절대배치 오버레이였으나, 정사각
     *   썸네일이 작아 배지를 올릴 공간이 없어 제목 행으로 이동(인라인 배지).
     * - 블라인드/삭제 반투명 오버레이·비밀글 잠금 오버레이: 원본 표현식 그대로,
     *   `h-48` 이미지 박스 대신 `w-20 h-20` 정사각 썸네일 Div 위로 위치만 이동
     *   (잠금 아이콘 크기만 작은 박스에 맞춰 `2x`→`lg` 축소).
     * - 카드 하단 "구분선 + 회색 배경 메타 밴드"는 리스트 행에서는 불필요해 제거하고,
     *   작성자/작성일/조회수/댓글수 메타는 텍스트 컬럼의 3번째 줄로 그대로 이식.
     * - `content_preview` `line-clamp`는 2줄 유지(요구사항 "1~2줄" 범위 내 선택).
     *
     * @return array<string, mixed>
     */
    private function webzineBranchNode(): array
    {
        return [
            'comment' => 'webzine 유형 (g7-webzine-addon 이 런타임에 삽입 — sirsoft-basic 코어 파일 아님)',
            'id' => self::WEBZINE_BRANCH_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => "{{posts?.data?.board?.type === 'webzine'}}",
            'children' =>
array (
          0 => 
          array (
            'meta' => 
            array (
              'is_partial' => true,
              'description' => '웹진 게시판 목록(g7-webzine-addon). 헤더+필터는 sirsoft-basic card 유형에서 이식, 목록 본체는 1열 리스트(좌측 정사각 썸네일+우측 텍스트, sirsoft-basic 장바구니 _cart_item.json 관례 이식)로 교체 + 빈상태 + 페이지네이션 + 글쓰기 버튼',
            ),
            'type' => 'basic',
            'name' => 'Div',
            'children' => 
            array (
              0 => 
              array (
                'comment' => '헤더 (게시판명 + 설명 + 글쓰기 버튼)',
                'type' => 'basic',
                'name' => 'Div',
                'props' => 
                array (
                  'className' => 'flex justify-between items-start mb-6',
                ),
                'children' => 
                array (
                  0 => 
                  array (
                    'type' => 'basic',
                    'name' => 'Div',
                    'children' => 
                    array (
                      0 => 
                      array (
                        'type' => 'basic',
                        'name' => 'H1',
                        'props' => 
                        array (
                          'className' => 'text-2xl font-bold text-gray-900 dark:text-white',
                        ),
                        'text' => '{{posts?.data?.board?.name ?? \'\'}}',
                      ),
                      1 => 
                      array (
                        'type' => 'basic',
                        'name' => 'P',
                        'if' => '{{posts?.data?.board?.description}}',
                        'props' => 
                        array (
                          'className' => 'text-gray-600 dark:text-gray-400 mt-1',
                        ),
                        'text' => '{{posts?.data?.board?.description}}',
                      ),
                    ),
                  ),
                  1 => 
                  array (
                    'comment' => '상단 액션 (관리자 진입 링크 + 글쓰기 버튼)',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => 
                    array (
                      'className' => 'flex items-center gap-2',
                    ),
                    'children' => 
                    array (
                      0 => 
                      array (
                        'meta' => 
                        array (
                          'is_partial' => true,
                          'description' => '게시판 관리자 진입 크로스링크 (관리자 게시물 조회 + 게시판 관리). can_access_admin 권한 보유자에게만 노출, 새 탭으로 이동.',
                        ),
                        'comment' => '관리자 진입 링크 묶음 - 유저 게시판 화면에서 관리자 화면으로 이동 (권한 게이트)',
                        'type' => 'basic',
                        'name' => 'Div',
                        'if' => '{{posts?.data?.abilities?.can_access_admin}}',
                        'props' => 
                        array (
                          'className' => 'inline-flex items-center gap-2',
                        ),
                        'children' => 
                        array (
                          0 => 
                          array (
                            'type' => 'basic',
                            'name' => 'A',
                            'props' => 
                            array (
                              'href' => '/admin/board/{{route.slug}}',
                              'target' => '_blank',
                              'rel' => 'noopener noreferrer',
                              'title' => '$t:board.admin_links.posts',
                              'className' => 'inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors no-underline',
                            ),
                            'children' => 
                            array (
                              0 => 
                              array (
                                'type' => 'basic',
                                'name' => 'Icon',
                                'props' => 
                                array (
                                  'name' => 'external-link',
                                  'size' => 'sm',
                                ),
                              ),
                              1 => 
                              array (
                                'type' => 'basic',
                                'name' => 'Span',
                                'props' => 
                                array (
                                  'className' => 'hidden sm:inline',
                                ),
                                'text' => '$t:board.admin_links.posts',
                              ),
                            ),
                          ),
                          1 => 
                          array (
                            'type' => 'basic',
                            'name' => 'A',
                            'props' => 
                            array (
                              'href' => '/admin/boards/{{route.slug}}/edit',
                              'target' => '_blank',
                              'rel' => 'noopener noreferrer',
                              'title' => '$t:board.admin_links.manage',
                              'className' => 'inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors no-underline',
                            ),
                            'children' => 
                            array (
                              0 => 
                              array (
                                'type' => 'basic',
                                'name' => 'Icon',
                                'props' => 
                                array (
                                  'name' => 'settings',
                                  'size' => 'sm',
                                ),
                              ),
                              1 => 
                              array (
                                'type' => 'basic',
                                'name' => 'Span',
                                'props' => 
                                array (
                                  'className' => 'hidden sm:inline',
                                ),
                                'text' => '$t:board.admin_links.manage',
                              ),
                            ),
                          ),
                        ),
                      ),
                      1 => 
                      array (
                        'meta' => 
                        array (
                          'is_partial' => true,
                          'description' => '글쓰기 버튼 (권한/로그인 체크 포함)',
                        ),
                        'comment' => '글쓰기 버튼 - 권한 없으면 비활성화, 클릭 시 권한/로그인 체크',
                        'type' => 'basic',
                        'name' => 'Button',
                        'props' => 
                        array (
                          'disabled' => '{{posts?.data?.abilities?.can_write !== true}}',
                          'className' => 'inline-flex items-center gap-2 px-4 py-2 bg-gray-900 dark:bg-gray-700 text-white dark:text-gray-100 rounded-lg hover:bg-gray-800 dark:hover:bg-gray-600 font-medium transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed',
                        ),
                        'actions' => 
                        array (
                          0 => 
                          array (
                            'type' => 'click',
                            'handler' => 'switch',
                            'params' => 
                            array (
                              'value' => '{{posts?.data?.abilities?.can_write ? \'allowed\' : (_global.currentUser?.uuid ? \'no_permission\' : \'not_logged_in\')}}',
                            ),
                            'cases' => 
                            array (
                              'allowed' => 
                              array (
                                'handler' => 'navigate',
                                'params' => 
                                array (
                                  'path' => '/board/{{route.slug}}/write',
                                  'mergeQuery' => true,
                                  'query' => 
                                  array (
                                  ),
                                ),
                              ),
                              'no_permission' => 
                              array (
                                'handler' => 'toast',
                                'params' => 
                                array (
                                  'type' => 'warning',
                                  'message' => '$t:board.no_write_permission',
                                ),
                              ),
                              'not_logged_in' => 
                              array (
                                'handler' => 'sequence',
                                'actions' => 
                                array (
                                  0 => 
                                  array (
                                    'handler' => 'toast',
                                    'params' => 
                                    array (
                                      'type' => 'info',
                                      'message' => '$t:board.login_required_to_write',
                                    ),
                                  ),
                                  1 => 
                                  array (
                                    'handler' => 'navigate',
                                    'params' => 
                                    array (
                                      'path' => '/login',
                                      'query' => 
                                      array (
                                        'redirect' => '/board/{{route.slug}}/write',
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),
                        'children' => 
                        array (
                          0 => 
                          array (
                            'type' => 'basic',
                            'name' => 'Icon',
                            'props' => 
                            array (
                              'name' => 'pencil',
                              'size' => 'sm',
                            ),
                          ),
                          1 => 
                          array (
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => '$t:board.write',
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              1 => 
              array (
                'comment' => '필터 바 (카테고리 필터 + 검색)',
                'type' => 'basic',
                'name' => 'Div',
                'props' => 
                array (
                  'className' => 'flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 mb-4',
                ),
                'children' => 
                array (
                  0 => 
                  array (
                    'comment' => '카테고리 필터 (카테고리가 있을 때만 표시)',
                    'type' => 'basic',
                    'name' => 'Select',
                    'if' => '{{posts?.data?.board?.categories && posts?.data?.board?.categories.length > 0}}',
                    'props' => 
                    array (
                      'value' => '{{query.category || \'all\'}}',
                      'className' => 'w-full sm:w-auto sm:min-w-48 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white text-sm',
                      'options' => '{{[{value:\'all\', label:\'$t:board.filter.category_all\'},{value:\'unclassified\', label:\'$t:board.filter.category_unclassified\'}].concat((posts?.data?.board?.categories ?? []).map(function(c){return{value:c,label:c};}))}}',
                    ),
                    'actions' => 
                    array (
                      0 => 
                      array (
                        'type' => 'change',
                        'handler' => 'navigate',
                        'params' => 
                        array (
                          'path' => '/board/{{route.slug}}',
                          'mergeQuery' => true,
                          'query' => 
                          array (
                            'category' => '{{$event.target.value === \'all\' ? \'\' : $event.target.value}}',
                            'page' => 1,
                          ),
                        ),
                      ),
                    ),
                  ),
                  1 => 
                  array (
                    'comment' => '삭제된 게시글 포함 토글 버튼 (manager 권한 보유 시에만 표시)',
                    'type' => 'basic',
                    'name' => 'Button',
                    'if' => '{{posts?.data?.abilities?.can_view_deleted}}',
                    'props' => 
                    array (
                      'type' => 'button',
                      'className' => '{{query.del === \'1\' ? \'inline-flex items-center gap-1.5 px-3 py-2 border-2 border-blue-600 dark:border-blue-400 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-lg text-sm font-medium\' : \'inline-flex items-center gap-1.5 px-3 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700\'}}',
                    ),
                    'actions' => 
                    array (
                      0 => 
                      array (
                        'type' => 'click',
                        'handler' => 'navigate',
                        'params' => 
                        array (
                          'path' => '/board/{{route.slug}}',
                          'mergeQuery' => true,
                          'query' => 
                          array (
                            'del' => '{{query.del === \'1\' ? \'\' : \'1\'}}',
                            'page' => 1,
                          ),
                        ),
                      ),
                    ),
                    'children' => 
                    array (
                      0 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Icon',
                        'props' => 
                        array (
                          'name' => 'eye',
                          'size' => 'sm',
                        ),
                      ),
                      1 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Span',
                        'text' => '$t:board.filter.include_deleted',
                      ),
                    ),
                  ),
                  2 => 
                  array (
                    'comment' => '검색 바 (오른쪽 정렬)',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => 
                    array (
                      'className' => 'w-full sm:w-auto sm:ml-auto',
                    ),
                    'children' => 
                    array (
                      0 => 
                      array (
                        'type' => 'composite',
                        'name' => 'SearchBar',
                        'props' => 
                        array (
                          'name' => 'search',
                          'placeholder' => '$t:board.search_placeholder',
                          'value' => '{{query.search || \'\'}}',
                          'showButton' => false,
                          'className' => 'w-full sm:w-64',
                        ),
                        'actions' => 
                        array (
                          0 => 
                          array (
                            'type' => 'submit',
                            'handler' => 'navigate',
                            'params' => 
                            array (
                              'path' => '/board/{{route.slug}}',
                              'mergeQuery' => true,
                              'query' => 
                              array (
                                'search' => '{{$event.target.search.value}}',
                                'page' => 1,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              2 => [
                'comment' => '1열 리스트 (좌측 정사각 썸네일 + 우측 텍스트) — _cart_item.json/_cart_list.json 관례 이식',
                'type' => 'basic',
                'name' => 'Div',
                'props' => [
                  'className' => 'bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 divide-y divide-gray-200 dark:divide-gray-700 overflow-hidden',
                ],
                'children' => [
                  0 => [
                    'type' => 'basic',
                    'name' => 'Button',
                    'iteration' => [
                      'source' => '{{posts?.data?.data ?? []}}',
                      'item_var' => 'post',
                    ],
                    'props' => [
                      'className' => 'flex w-full text-left gap-4 p-4 items-start hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-200 group cursor-pointer',
                    ],
                    'comment' => '행 클릭 시 현재 목록 상태(category/search/page/del/filters)를 상세 URL 에 부착 (45-1 목록 복귀, 원본 카드와 동일 동작)',
                    'actions' => [
                      0 => [
                        'type' => 'click',
                        'handler' => 'navigate',
                        'params' => [
                          'path' => '/board/{{route.slug}}/{{post.id}}',
                          'mergeQuery' => true,
                          'query' => [
                          ],
                        ],
                      ],
                    ],
                    'children' => [
                      0 => [
                        'comment' => '좌측 정사각 썸네일 (w-20 h-20, sirsoft-basic _cart_item.json 관례 이식)',
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => [
                          'className' => 'relative w-20 h-20 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-900 flex-shrink-0',
                        ],
                        'children' => [
                          0 => [
                            'comment' => '썸네일 이미지 (블라인드/삭제 여부와 관계없이 있으면 표시) — 원본 표현식 그대로',
                            'type' => 'basic',
                            'name' => 'Img',
                            'if' => '{{post?.thumbnail}}',
                            'props' => [
                              'src' => '{{post?.thumbnail}}',
                              'alt' => '{{post?.title}}',
                              'className' => 'w-full h-full object-cover group-hover:scale-105 transition-transform duration-200',
                            ],
                          ],
                          1 => [
                            'comment' => '이미지가 없는 경우 플레이스홀더 — 원본 표현식 그대로',
                            'type' => 'basic',
                            'name' => 'Div',
                            'if' => '{{!post?.thumbnail}}',
                            'props' => [
                              'className' => 'w-full h-full flex flex-col items-center justify-center gap-0.5 bg-gray-200 dark:bg-gray-700',
                            ],
                            'children' => [
                              0 => [
                                'type' => 'basic',
                                'name' => 'Icon',
                                'props' => [
                                  'name' => 'image',
                                  'className' => 'text-gray-400 dark:text-gray-500',
                                ],
                              ],
                              1 => [
                                'type' => 'basic',
                                'name' => 'Span',
                                'props' => [
                                  'className' => 'text-[10px] leading-none text-gray-400 dark:text-gray-500',
                                ],
                                'text' => '$t:userinfo.no_image',
                              ],
                            ],
                          ],
                          2 => [
                            'comment' => '블라인드/삭제됨 반투명 오버레이 — 원본 표현식 그대로, 정사각 썸네일 Div 위로 위치만 이동',
                            'type' => 'basic',
                            'name' => 'Div',
                            'if' => '{{post?.status === \'blinded\' || post?.deleted_at}}',
                            'props' => [
                              'className' => 'absolute inset-0 bg-gray-900/40 dark:bg-gray-900/40 z-10',
                            ],
                          ],
                          3 => [
                            'comment' => '비밀글 잠금 오버레이 — 원본 표현식 그대로(아이콘 크기만 작은 박스에 맞춰 2x→lg 축소), 정사각 썸네일 Div 위로 위치만 이동',
                            'type' => 'basic',
                            'name' => 'Div',
                            'if' => '{{post?.is_secret && !post?.abilities?.can_view}}',
                            'props' => [
                              'className' => 'absolute inset-0 bg-gray-900/50 dark:bg-gray-900/50 flex items-center justify-center z-10',
                            ],
                            'children' => [
                              0 => [
                                'type' => 'basic',
                                'name' => 'Icon',
                                'props' => [
                                  'name' => 'lock',
                                  'size' => 'lg',
                                  'className' => 'text-white',
                                ],
                              ],
                            ],
                          ],
                        ],
                      ],
                      1 => [
                        'comment' => '우측 텍스트 (flex-1 min-w-0로 남은 폭을 채우고 제목이 truncate 되도록)',
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => [
                          'className' => 'flex-1 min-w-0 flex flex-col gap-1',
                        ],
                        'children' => [
                          0 => [
                            'comment' => '제목 행 — 블라인드/답글/비밀글 아이콘 + 제목(truncate) + 첨부/삭제 배지는 원본 표현식 그대로, 공지/카테고리/신규 배지는 이미지 오버레이에서 이 행으로 이동',
                            'type' => 'basic',
                            'name' => 'Div',
                            'props' => [
                              'className' => 'flex items-center gap-1.5 min-w-0',
                            ],
                            'children' => [
                              0 => [
                                'comment' => '블라인드 아이콘 (제목 앞) — 원본 표현식 그대로',
                                'type' => 'basic',
                                'name' => 'Icon',
                                'if' => '{{post?.status === \'blinded\'}}',
                                'props' => [
                                  'name' => 'eye-slash',
                                  'size' => 'sm',
                                  'className' => 'text-orange-500 dark:text-orange-400 flex-shrink-0',
                                ],
                              ],
                              1 => [
                                'comment' => '공지사항 배지 (제목 앞) — 이미지 오버레이에서 이동, 원본 클래스 그대로',
                                'type' => 'basic',
                                'name' => 'Span',
                                'if' => '{{post?.is_notice}}',
                                'props' => [
                                  'className' => 'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium bg-blue-600 text-white dark:bg-blue-500 flex-shrink-0',
                                ],
                                'children' => [
                                  0 => [
                                    'type' => 'basic',
                                    'name' => 'Icon',
                                    'props' => [
                                      'name' => 'bell',
                                      'size' => 'xs',
                                    ],
                                  ],
                                  1 => [
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'text' => '$t:board.notice',
                                  ],
                                ],
                              ],
                              2 => [
                                'comment' => '카테고리 배지 (제목 앞) — 이미지 오버레이에서 이동, 원본 클래스 그대로',
                                'type' => 'basic',
                                'name' => 'Span',
                                'if' => '{{post?.category}}',
                                'props' => [
                                  'className' => 'inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-black/40 text-white backdrop-blur-sm flex-shrink-0',
                                ],
                                'text' => '{{post?.category}}',
                              ],
                              3 => [
                                'comment' => '답글 배지 (제목 앞) — 원본 표현식 그대로',
                                'type' => 'basic',
                                'name' => 'Span',
                                'if' => '{{post?.is_reply}}',
                                'props' => [
                                  'className' => 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300 flex-shrink-0',
                                ],
                                'text' => 'RE',
                              ],
                              4 => [
                                'comment' => '비밀글 아이콘 (제목 앞) — 원본 표현식 그대로',
                                'type' => 'basic',
                                'name' => 'Icon',
                                'if' => '{{post?.is_secret}}',
                                'props' => [
                                  'name' => 'lock',
                                  'size' => 'sm',
                                  'className' => 'text-gray-400 dark:text-gray-500 flex-shrink-0',
                                ],
                              ],
                              5 => [
                                'comment' => '제목 — 원본 표현식 그대로',
                                'type' => 'basic',
                                'name' => 'H3',
                                'props' => [
                                  'className' => 'text-base font-bold truncate min-w-0 transition-colors {{(post?.status === \'blinded\' || post?.deleted_at) ? \'text-gray-400 dark:text-gray-500\' : \'text-gray-900 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400\'}}',
                                ],
                                'text' => '{{post?.title ?? \'\'}}',
                              ],
                              6 => [
                                'comment' => '첨부파일 아이콘 (제목 뒤) — 원본 표현식 그대로',
                                'type' => 'basic',
                                'name' => 'Icon',
                                'if' => '{{post?.has_attachment}}',
                                'props' => [
                                  'name' => 'paperclip',
                                  'size' => 'sm',
                                  'className' => 'text-gray-400 dark:text-gray-500 flex-shrink-0',
                                ],
                              ],
                              7 => [
                                'comment' => '삭제됨 배지 (제목 뒤) — 원본 표현식 그대로',
                                'type' => 'basic',
                                'name' => 'Span',
                                'if' => '{{post?.deleted_at}}',
                                'props' => [
                                  'className' => 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 flex-shrink-0',
                                ],
                                'text' => '$t:board.post.deleted_badge',
                              ],
                              8 => [
                                'comment' => 'N 신규 배지 (제목 뒤) — 이미지 오버레이에서 이동, 원본 클래스 그대로',
                                'type' => 'basic',
                                'name' => 'Span',
                                'if' => '{{post?.is_new}}',
                                'props' => [
                                  'className' => 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold bg-red-500 dark:bg-red-500 text-white flex-shrink-0',
                                ],
                                'text' => 'N',
                              ],
                            ],
                          ],
                          1 => [
                            'comment' => '본문 요약 (비밀글 미열람/블라인드/삭제됨 제외) — 원본 표현식 그대로, line-clamp 는 리스트 행 높이에 맞춰 2줄 유지',
                            'type' => 'composite',
                            'name' => 'HtmlContent',
                            'if' => '{{post?.content_preview && !(post?.is_secret && !post?.abilities?.can_view)}}',
                            'props' => [
                              'content' => '{{post?.content_preview ?? \'\'}}',
                              'isHtml' => false,
                              'className' => 'text-sm line-clamp-2 overflow-hidden !whitespace-normal {{(post?.status === \'blinded\' || post?.deleted_at) ? \'text-gray-400 dark:text-gray-600\' : \'text-gray-500 dark:text-gray-400\'}}',
                            ],
                          ],
                          2 => [
                            'comment' => '메타 정보(작성자+작성일 / 조회수+댓글수) — 원본 카드 하단 메타 표현식 그대로, 구분선·배경 밴드만 제거(리스트 행이라 불필요)',
                            'type' => 'basic',
                            'name' => 'Div',
                            'props' => [
                              'className' => 'flex items-center justify-between flex-wrap gap-x-3 gap-y-1 mt-0.5',
                            ],
                            'children' => [
                              0 => [
                                'type' => 'basic',
                                'name' => 'Div',
                                'props' => [
                                  'className' => 'flex items-center gap-2 min-w-0',
                                ],
                                'children' => [
                                  0 => [
                                    'type' => 'composite',
                                    'name' => 'Avatar',
                                    'props' => [
                                      'author' => '{{post?.author}}',
                                      'size' => 'xs',
                                      'className' => '{{(post?.status === \'blinded\' || post?.deleted_at) ? \'opacity-40\' : \'\'}}',
                                    ],
                                  ],
                                  1 => [
                                    'type' => 'composite',
                                    'name' => 'UserInfo',
                                    'props' => [
                                      'author' => '{{post?.author}}',
                                      'showDropdown' => true,
                                      'stopPropagation' => true,
                                      'className' => 'text-xs font-medium {{(post?.status === \'blinded\' || post?.deleted_at) ? \'text-gray-400 dark:text-gray-500\' : \'text-gray-700 dark:text-gray-300\'}}',
                                    ],
                                  ],
                                  2 => [
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'props' => [
                                      'className' => 'text-xs text-gray-300 dark:text-gray-600',
                                    ],
                                    'text' => '·',
                                  ],
                                  3 => [
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'props' => [
                                      'className' => 'text-xs {{(post?.status === \'blinded\' || post?.deleted_at) ? \'text-gray-400 dark:text-gray-500\' : \'text-gray-500 dark:text-gray-400\'}}',
                                      'title' => '{{post?.created_at ?? \'\'}}',
                                    ],
                                    'text' => '{{post?.created_at_formatted ?? \'\'}}',
                                  ],
                                ],
                              ],
                              1 => [
                                'type' => 'basic',
                                'name' => 'Div',
                                'props' => [
                                  'className' => 'flex items-center gap-3 flex-shrink-0',
                                ],
                                'children' => [
                                  0 => [
                                    'comment' => '조회수 — 원본 표현식 그대로',
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'if' => '{{posts?.data?.board?.settings?.show_view_count !== false}}',
                                    'props' => [
                                      'className' => 'inline-flex items-center gap-1 text-xs {{(post?.status === \'blinded\' || post?.deleted_at) ? \'text-gray-400 dark:text-gray-500\' : \'text-gray-500 dark:text-gray-400\'}}',
                                    ],
                                    'children' => [
                                      0 => [
                                        'type' => 'basic',
                                        'name' => 'Icon',
                                        'props' => [
                                          'name' => 'eye',
                                          'size' => 'sm',
                                        ],
                                      ],
                                      1 => [
                                        'type' => 'basic',
                                        'name' => 'Span',
                                        'text' => '{{post?.view_count ?? 0}}',
                                      ],
                                    ],
                                  ],
                                  1 => [
                                    'comment' => '댓글수 — 원본 표현식 그대로',
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'if' => '{{post?.comment_count > 0}}',
                                    'props' => [
                                      'className' => 'inline-flex items-center gap-1 text-xs font-medium {{(post?.status === \'blinded\' || post?.deleted_at) ? \'text-gray-400 dark:text-gray-500\' : \'text-blue-600 dark:text-blue-400\'}}',
                                    ],
                                    'children' => [
                                      0 => [
                                        'type' => 'basic',
                                        'name' => 'Icon',
                                        'props' => [
                                          'name' => 'message',
                                          'size' => 'sm',
                                        ],
                                      ],
                                      1 => [
                                        'type' => 'basic',
                                        'name' => 'Span',
                                        'text' => '{{post?.comment_count ?? 0}}',
                                      ],
                                    ],
                                  ],
                                ],
                              ],
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
              3 => 
              array (
                'meta' => 
                array (
                  'is_partial' => true,
                  'description' => '게시판 목록 빈 상태 안내 (빈 페이지 / 게시글 없음 / 검색 결과 없음)',
                ),
                'type' => 'basic',
                'name' => 'Div',
                'children' => 
                array (
                  0 => 
                  array (
                    'comment' => '해당 페이지에 게시글 없음 (page 파라미터 존재 + 데이터 0건)',
                    'type' => 'basic',
                    'name' => 'Div',
                    'if' => '{{posts?.data?.board?.slug && query.page && Number(query.page) > 1 && posts?.data?.data?.length === 0}}',
                    'props' => 
                    array (
                      'className' => 'flex flex-col items-center justify-center py-16 text-center bg-gray-100/70 dark:bg-gray-800/50 rounded-xl',
                    ),
                    'children' => 
                    array (
                      0 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => 
                        array (
                          'className' => 'text-6xl mb-4',
                        ),
                        'text' => '📄',
                      ),
                      1 => 
                      array (
                        'type' => 'basic',
                        'name' => 'P',
                        'props' => 
                        array (
                          'className' => 'text-gray-600 dark:text-gray-400 mb-2',
                        ),
                        'text' => '$t:board.no_posts_on_page',
                      ),
                      2 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Button',
                        'props' => 
                        array (
                          'className' => 'inline-flex items-center gap-2 px-4 py-2 border-2 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-colors cursor-pointer mt-4',
                        ),
                        'text' => '$t:board.go_to_first_page',
                        'actions' => 
                        array (
                          0 => 
                          array (
                            'comment' => '페이지만 되돌린다 — 검색어/분류/필터는 그대로 유지 (#75). page: "" 는 파라미터 제거 = 1페이지.',
                            'type' => 'click',
                            'handler' => 'navigate',
                            'params' => 
                            array (
                              'path' => '/board/{{route.slug}}',
                              'mergeQuery' => true,
                              'query' => 
                              array (
                                'page' => '',
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                  1 => 
                  array (
                    'comment' => '게시글 없음 (검색 아닐 때 + 공지 제외 일반 게시글 0개)',
                    'type' => 'basic',
                    'name' => 'Div',
                    'if' => '{{posts?.data?.board?.slug && !query.search && !query.category && (!query.page || Number(query.page) <= 1) && posts?.data?.pagination?.total === 0}}',
                    'props' => 
                    array (
                      'className' => 'flex flex-col items-center justify-center py-16 text-center bg-gray-100/70 dark:bg-gray-800/50 rounded-xl',
                    ),
                    'children' => 
                    array (
                      0 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => 
                        array (
                          'className' => 'text-6xl mb-4',
                        ),
                        'text' => '📝',
                      ),
                      1 => 
                      array (
                        'type' => 'basic',
                        'name' => 'P',
                        'props' => 
                        array (
                          'className' => 'text-gray-600 dark:text-gray-400',
                        ),
                        'text' => '$t:board.no_posts',
                      ),
                    ),
                  ),
                  2 => 
                  array (
                    'comment' => '검색 결과 없음 (검색 시 + 공지 제외 일반 게시글 0개)',
                    'type' => 'basic',
                    'name' => 'Div',
                    'if' => '{{posts?.data?.board?.slug && (query.search || query.category) && posts?.data?.pagination?.total === 0}}',
                    'props' => 
                    array (
                      'className' => 'flex flex-col items-center justify-center py-16 text-center bg-gray-100/70 dark:bg-gray-800/50 rounded-xl',
                    ),
                    'children' => 
                    array (
                      0 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => 
                        array (
                          'className' => 'text-6xl mb-4',
                        ),
                        'text' => '🔍',
                      ),
                      1 => 
                      array (
                        'type' => 'basic',
                        'name' => 'P',
                        'props' => 
                        array (
                          'className' => 'text-gray-600 dark:text-gray-400 mb-6',
                        ),
                        'text' => '$t:board.no_search_results',
                      ),
                      2 => 
                      array (
                        'comment' => '검색 초기화 버튼',
                        'type' => 'basic',
                        'name' => 'Button',
                        'props' => 
                        array (
                          'className' => 'inline-flex items-center gap-2 px-4 py-2 border-2 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-colors cursor-pointer',
                        ),
                        'text' => '$t:board.clear_search',
                        'actions' => 
                        array (
                          0 => 
                          array (
                            'comment' => 'audit:allow layout-list-context-navigate-merge-query 검색 초기화 — 목록 상태를 전부 비우는 것이 이 버튼의 목적이므로 병합하지 않는다.',
                            'type' => 'click',
                            'handler' => 'navigate',
                            'params' => 
                            array (
                              'path' => '/board/{{route.slug}}',
                              'mergeQuery' => false,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              4 => 
              array (
                'comment' => '페이지네이션',
                'type' => 'composite',
                'name' => 'Pagination',
                'props' => 
                array (
                  'currentPage' => '{{posts?.data?.pagination?.current_page ?? 1}}',
                  'totalPages' => '{{posts?.data?.pagination?.last_page ?? null}}',
                  'hasMorePages' => '{{posts?.data?.pagination?.has_more_pages ?? false}}',
                  'className' => 'mt-6 justify-center',
                ),
                'actions' => 
                array (
                  0 => 
                  array (
                    'event' => 'onPageChange',
                    'handler' => 'navigate',
                    'params' => 
                    array (
                      'path' => '/board/{{route?.slug}}',
                      'mergeQuery' => true,
                      'query' => 
                      array (
                        'page' => '{{$args[0]}}',
                        'category' => '{{query.category}}',
                        'filters[0][field]' => '{{query[\'filters[0][field]\']}}',
                        'filters[0][value]' => '{{query[\'filters[0][value]\']}}',
                        'filters[0][operator]' => '{{query[\'filters[0][operator]\']}}',
                      ),
                    ),
                  ),
                ),
              ),
              5 => 
              array (
                'comment' => '하단 글쓰기 버튼',
                'type' => 'basic',
                'name' => 'Div',
                'props' => 
                array (
                  'className' => 'flex justify-end mt-4',
                ),
                'children' => 
                array (
                  0 => 
                  array (
                    'meta' => 
                    array (
                      'is_partial' => true,
                      'description' => '글쓰기 버튼 (권한/로그인 체크 포함)',
                    ),
                    'comment' => '글쓰기 버튼 - 권한 없으면 비활성화, 클릭 시 권한/로그인 체크',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => 
                    array (
                      'disabled' => '{{posts?.data?.abilities?.can_write !== true}}',
                      'className' => 'inline-flex items-center gap-2 px-4 py-2 bg-gray-900 dark:bg-gray-700 text-white dark:text-gray-100 rounded-lg hover:bg-gray-800 dark:hover:bg-gray-600 font-medium transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed',
                    ),
                    'actions' => 
                    array (
                      0 => 
                      array (
                        'type' => 'click',
                        'handler' => 'switch',
                        'params' => 
                        array (
                          'value' => '{{posts?.data?.abilities?.can_write ? \'allowed\' : (_global.currentUser?.uuid ? \'no_permission\' : \'not_logged_in\')}}',
                        ),
                        'cases' => 
                        array (
                          'allowed' => 
                          array (
                            'handler' => 'navigate',
                            'params' => 
                            array (
                              'path' => '/board/{{route.slug}}/write',
                              'mergeQuery' => true,
                              'query' => 
                              array (
                              ),
                            ),
                          ),
                          'no_permission' => 
                          array (
                            'handler' => 'toast',
                            'params' => 
                            array (
                              'type' => 'warning',
                              'message' => '$t:board.no_write_permission',
                            ),
                          ),
                          'not_logged_in' => 
                          array (
                            'handler' => 'sequence',
                            'actions' => 
                            array (
                              0 => 
                              array (
                                'handler' => 'toast',
                                'params' => 
                                array (
                                  'type' => 'info',
                                  'message' => '$t:board.login_required_to_write',
                                ),
                              ),
                              1 => 
                              array (
                                'handler' => 'navigate',
                                'params' => 
                                array (
                                  'path' => '/login',
                                  'query' => 
                                  array (
                                    'redirect' => '/board/{{route.slug}}/write',
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                    'children' => 
                    array (
                      0 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Icon',
                        'props' => 
                        array (
                          'name' => 'pencil',
                          'size' => 'sm',
                        ),
                      ),
                      1 => 
                      array (
                        'type' => 'basic',
                        'name' => 'Span',
                        'text' => '$t:board.write',
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        ];
    }
}
