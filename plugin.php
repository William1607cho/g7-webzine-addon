<?php

namespace Plugins\G7\Webzine\Addon;

use App\Extension\AbstractPlugin;
use Illuminate\Support\Facades\DB;
use Plugins\G7\Webzine\Addon\Listeners\BoardTypeSeedListener;
use Plugins\G7\Webzine\Addon\Listeners\WebzineIndexWidgetListener;
use Plugins\G7\Webzine\Addon\Listeners\WebzineThumbnailListener;

/**
 * 웹진게시판 애드온 (g7-webzine-addon) — 목록 화면 카드형(썸네일+요약) 레이아웃, 0.1.0.
 *
 * sirsoft-board 게시판에 웹진형(`webzine`) board_type 을 더하고, 목록(`board/index`) 화면을
 * `types/card/index.json`(썸네일+본문 요약 카드 그리드)과 동일한 형태로 렌더링한다.
 * 대상 모듈(sirsoft-board)과 방문자 템플릿(sirsoft-basic)은 **파일 한 줄도 수정하지 않는다**
 * — `core.layout_extension.after_apply` 필터 훅으로 최종 레이아웃 트리에 webzine 전용
 * 분기를 삽입하는 방식으로만 동작한다 (`WEBZINE_LIST_THUMBNAIL_SUMMARY_FEASIBILITY_REPORT.md`
 * 조사 결과 반영).
 *
 * 이 빌드에 담긴 것:
 *  - `board_types` 에 `webzine` 행 등록 (install 시 삽입 + `seed.sirsoft-board.board_types
 *    .translations` 필터 리스너로 재시드 생존) / uninstall 시 정리(사용 중이면 차단)
 *  - `WebzineIndexWidgetListener` 가 `core.layout_extension.after_apply` 로 `board/index`
 *    레이아웃의 유형별 분기(`_type_renderer.json` 상당 트리)에 webzine 전용 카드 그리드
 *    분기를 새로 삽입하고, 기존 basic-fallback 분기 조건에서 webzine 을 제외시킨다.
 *  - `WebzineThumbnailListener` 가 `sirsoft-board.post.filter_content_thumbnail` 필터로,
 *    webzine 게시판에 한해 본문 첫 이미지가 외부(타 사이트) origin 이어도 썸네일 후보로
 *    허용한다 (다른 board 타입의 기존 "내부 이미지만" 동작에는 영향 없음).
 *
 * 요약 길이(150자)·썸네일 추출·비밀글 마스킹 등은 전부 sirsoft-board 코어가 이미
 * 계산해 목록 API 응답(`thumbnail`, `content_preview`)에 포함하고 있어, 이 애드온은
 * 별도 API·DB 테이블 없이 순수 레이아웃/필터 훅만으로 동작한다.
 */
class Plugin extends AbstractPlugin
{
    /** board_types 에 등록하는 웹진 유형 슬러그 */
    public const WEBZINE_BOARD_TYPE = 'webzine';

    /**
     * 플러그인 메타데이터.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'William Cho',
            'license' => 'MIT',
            'keywords' => ['webzine', 'board', 'thumbnail', 'card-list', 'sirsoft-board'],
        ];
    }

    /**
     * 훅 리스너 목록.
     *
     * - BoardTypeSeedListener: `seed.sirsoft-board.board_types.translations` 필터에 붙어,
     *   sirsoft-board 의 BoardTypeSeeder 가 (재)실행될 때 `webzine` 유형이 upsert 되고
     *   동시에 stale-cleanup 화이트리스트에도 포함되도록 한다.
     * - WebzineIndexWidgetListener: `core.layout_extension.after_apply` 필터에 붙어,
     *   `board/index` 레이아웃 트리에서 유형별 분기 컨테이너를 찾아 webzine 전용
     *   카드 그리드 분기를 형제 노드로 삽입하고, basic-fallback 분기 조건을 rewrite 한다.
     * - WebzineThumbnailListener: `sirsoft-board.post.filter_content_thumbnail` 필터에
     *   붙어, webzine 게시판 글에 한해 본문 첫 이미지가 외부 origin 이어도(http/https 만,
     *   data:/javascript: 등 위험 스킴은 계속 제외) 썸네일 후보로 허용한다.
     *
     * @return array<int, class-string>
     */
    public function getHookListeners(): array
    {
        return [
            BoardTypeSeedListener::class,
            WebzineIndexWidgetListener::class,
            WebzineThumbnailListener::class,
        ];
    }

    /**
     * 플러그인 설치 — `board_types` 에 `webzine` 행을 보장한다.
     *
     * 주의: PluginManager 는 install() 을 **마이그레이션 실행 전** 에 호출한다(이 플러그인은
     * 마이그레이션 자체가 없음). `board_types` 는 sirsoft-board(의존성으로 이미 활성)
     * 소유 테이블이라 존재가 보장된다.
     *
     * @return bool
     */
    public function install(): bool
    {
        $this->ensureWebzineBoardType();

        return true;
    }

    /**
     * 플러그인 제거.
     *
     * `webzine` 유형을 쓰는 게시판이 하나라도 있으면 제거를 **차단**한다 (게시판이 유형을
     * 잃고 basic 으로 조용히 폴백되는 것을 막는다). 운영자는 해당 게시판을 다른 유형으로
     * 바꾸거나 삭제한 뒤 다시 시도해야 한다. 사용 중이 아니면 `board_types` 의 `webzine`
     * 행을 지운다.
     *
     * 레이아웃 확장은 PluginManager 가 소유자 기준으로 자동 정리하므로 여기서 다루지
     * 않는다.
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        $count = $this->webzineBoardsInUse();
        if ($count > 0) {
            return $this->failWith(
                "웹진형('webzine') 유형을 사용하는 게시판이 {$count}개 있어 제거할 수 없습니다. "
                .'해당 게시판을 다른 유형으로 변경하거나 삭제한 뒤 다시 시도하세요.'
            );
        }

        DB::table('board_types')->where('slug', self::WEBZINE_BOARD_TYPE)->delete();

        return true;
    }

    /**
     * `board_types` 에 `webzine` 행이 없으면 삽입한다 (idempotent).
     */
    private function ensureWebzineBoardType(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('board_types')) {
            return;
        }

        $exists = DB::table('board_types')
            ->where('slug', self::WEBZINE_BOARD_TYPE)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('board_types')->insert([
            'slug' => self::WEBZINE_BOARD_TYPE,
            'name' => json_encode(['ko' => '웹진형', 'en' => 'Webzine'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `webzine` 유형을 쓰는 활성/비활성 게시판 수.
     */
    private function webzineBoardsInUse(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('boards')) {
            return 0;
        }

        return (int) DB::table('boards')
            ->where('type', self::WEBZINE_BOARD_TYPE)
            ->count();
    }
}
