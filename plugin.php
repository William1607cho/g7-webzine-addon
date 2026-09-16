<?php

namespace Plugins\G7\Webzine\Addon;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Webzine\Addon\Listeners\BoardTypeSeedListener;
use Plugins\G7\Webzine\Addon\Listeners\SettingsSavedListener;
use Plugins\G7\Webzine\Addon\Listeners\WebzineIndexWidgetListener;
use Plugins\G7\Webzine\Addon\Listeners\WebzineThumbnailListener;
use Plugins\G7\Webzine\Addon\Services\FallbackImageService;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

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
     * 플러그인 설정 스키마 (v1.1.0 신설).
     *
     * 한 화면에 두 묶음이 들어간다:
     *
     *  - `no_thumbnail_mode` : 썸네일이 없는 글의 썸네일 영역을 어떻게 할지 (요약만 /
     *    기본 자리표시자 / 대체 이미지). **기본값은 "요약만"** — 이 애드온의 원래 사양이다
     *    (v1.0.0 은 카드형 레이아웃을 복제해 오면서 "이미지 없음" 자리표시자가 함께
     *    딸려 왔었다).
     *  - `fallback_*` : "대체 이미지" 모드에서 쓸 이미지의 출처(업로드 / URL)와 대체 텍스트.
     *
     * `fallback_upload_*` 세 값은 관리자가 직접 타이핑하는 값이 아니라 **업로드 API 가
     * 채우는 서버 관리 값**이다(설정 화면의 업로드 버튼이 폼에 넣어 준다). 스키마에
     * 두는 이유는 설정 파일에 함께 저장·복원되어야 하기 때문이다.
     *
     * @return array<string, array<string, mixed>> 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'no_thumbnail_mode' => [
                'type' => 'enum',
                'options' => WebzineSettings::MODES,
                'default' => WebzineSettings::MODE_SUMMARY,
                'label' => [
                    'ko' => '썸네일 없을 때 표시',
                    'en' => 'When No Thumbnail',
                ],
                'hint' => [
                    'ko' => '본문에 이미지가 없어 썸네일을 만들 수 없는 글의 목록 표시 방식입니다.',
                    'en' => 'How to render list rows for posts with no image to build a thumbnail from.',
                ],
                'required' => false,
            ],
            'fallback_source' => [
                'type' => 'enum',
                'options' => WebzineSettings::SOURCES,
                'default' => WebzineSettings::SOURCE_UPLOAD,
                'label' => [
                    'ko' => '대체 이미지 지정 방식',
                    'en' => 'Fallback Image Source',
                ],
                'hint' => [
                    'ko' => '대체 이미지를 이 사이트에 업로드해 쓸지, 이미 있는 이미지 주소를 쓸지 선택합니다.',
                    'en' => 'Whether to upload the fallback image to this site or point at an existing image URL.',
                ],
                'required' => false,
            ],
            'fallback_upload_path' => [
                'type' => 'string',
                'max' => 180,
                'default' => '',
                'label' => [
                    'ko' => '업로드된 대체 이미지 경로',
                    'en' => 'Uploaded Fallback Image Path',
                ],
                'hint' => [
                    'ko' => '업로드 방식일 때 쓰는 저장 경로입니다. 설정 화면의 업로드 버튼이 자동으로 채웁니다.',
                    'en' => 'Storage path used in upload mode. The settings screen fills this automatically.',
                ],
                'required' => false,
            ],
            'fallback_upload_name' => [
                'type' => 'string',
                'max' => 255,
                'default' => '',
                'label' => [
                    'ko' => '업로드된 대체 이미지 원본 파일명',
                    'en' => 'Uploaded Fallback Image Original Name',
                ],
                'hint' => [
                    'ko' => '표시용 원본 파일명입니다. 설정 화면의 업로드 버튼이 자동으로 채웁니다.',
                    'en' => 'Original file name, for display only. The settings screen fills this automatically.',
                ],
                'required' => false,
            ],
            'fallback_upload_version' => [
                'type' => 'string',
                'max' => 64,
                'default' => '',
                'label' => [
                    'ko' => '업로드된 대체 이미지 버전 해시',
                    'en' => 'Uploaded Fallback Image Version Hash',
                ],
                'hint' => [
                    'ko' => '파일 내용 해시입니다. 공개 URL 에 들어가 이미지 교체 즉시 캐시를 무효화합니다. 설정 화면의 업로드 버튼이 자동으로 채웁니다.',
                    'en' => 'Content hash embedded in the public URL so replacing the image busts caches immediately. The settings screen fills this automatically.',
                ],
                'required' => false,
            ],
            'fallback_image_url' => [
                'type' => 'string',
                'max' => 2000,
                'default' => '',
                'label' => [
                    'ko' => '대체 이미지 URL',
                    'en' => 'Fallback Image URL',
                ],
                'hint' => [
                    'ko' => 'URL 방식일 때 쓸 이미지 주소입니다. https:// 또는 http:// 로 시작하는 주소, 혹은 / 로 시작하는 사이트 내 경로만 허용합니다.',
                    'en' => 'Image address used in URL mode. Only https:// or http:// addresses, or site-internal paths starting with /, are accepted.',
                ],
                'required' => false,
            ],
            'fallback_alt' => [
                'type' => 'string',
                'max' => 200,
                'default' => '',
                'label' => [
                    'ko' => '대체 이미지 대체 텍스트',
                    'en' => 'Fallback Image Alt Text',
                ],
                'hint' => [
                    'ko' => '이미지를 볼 수 없는 환경(스크린리더 등)에서 읽히는 문구입니다. 비워 두면 사이트명이 쓰입니다.',
                    'en' => 'Text announced where the image cannot be seen (screen readers, etc.). Defaults to the site name when left empty.',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 플러그인 설정 기본값 (v1.1.0 신설).
     *
     * 공개 배포 기본값은 "요약만" — 썸네일이 없으면 썸네일 영역을 아예 그리지 않는다.
     *
     * @return array<string, string> 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'no_thumbnail_mode' => WebzineSettings::MODE_SUMMARY,
            'fallback_source' => WebzineSettings::SOURCE_UPLOAD,
            'fallback_upload_path' => '',
            'fallback_upload_name' => '',
            'fallback_upload_version' => '',
            'fallback_image_url' => '',
            'fallback_alt' => '',
        ];
    }

    /**
     * 관리자 메뉴 정의 (v1.1.0 신설).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => '웹진게시판 애드온', 'en' => 'Webzine Board Add-on'],
                'slug' => 'g7-webzine-addon-settings',
                'url' => '/admin/plugins/g7-webzine-addon/settings',
                'icon' => 'fas fa-newspaper',
                'order' => 62,
            ],
        ];
    }

    /**
     * 플러그인 활성화 — 관리자 메뉴 등록 (v1.1.0 신설).
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);

        foreach ($this->getAdminMenus() as $menuData) {
            $helper->syncMenuRecursive(
                $menuData,
                ExtensionOwnerType::Plugin,
                $this->getIdentifier(),
            );
        }

        return true;
    }

    /**
     * 플러그인 비활성화 — 관리자 메뉴 제거 (v1.1.0 신설).
     */
    public function deactivate(): bool
    {
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: [],
        );

        return true;
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
     * - SettingsSavedListener(v1.1.0): `core.plugin_settings.after_save` 액션에 붙어,
     *   설정 저장 시 쓰지 않는 대체 이미지 파일을 정리하고 확장 캐시 버전을 올려
     *   목록 레이아웃 변경이 새로고침 한 번에 반영되게 한다.
     *
     * @return array<int, class-string>
     */
    public function getHookListeners(): array
    {
        return [
            BoardTypeSeedListener::class,
            WebzineIndexWidgetListener::class,
            WebzineThumbnailListener::class,
            SettingsSavedListener::class,
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

        $this->deactivate();
        $this->purgeFallbackImages();

        return true;
    }

    /**
     * 업로드된 대체 이미지 파일을 모두 지운다 (제거 시 잔존 파일 방지, v1.1.0 신설).
     *
     * 제거 흐름을 막을 만한 일이 아니므로 실패해도 경고만 남긴다.
     */
    private function purgeFallbackImages(): void
    {
        try {
            app(FallbackImageService::class)->deleteAll();
        } catch (\Throwable $e) {
            Log::warning('[g7-webzine-addon] 대체 이미지 저장소 정리 실패', ['error' => $e->getMessage()]);
        }
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
