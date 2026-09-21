<?php

namespace Plugins\G7\Webzine\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Traits\ClearsTemplateCaches;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Webzine\Addon\Services\FallbackImageService;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 설정 저장 후처리 리스너 (v1.1.0 신설).
 *
 * `core.plugin_settings.after_save` 액션 훅에 붙어, **이 플러그인 설정이 저장될 때만**
 * 두 가지 뒤처리를 한다.
 *
 * 1. **이전 대체 이미지 파일 정리** — 새 이미지를 올리고 저장하면 예전 파일은 아무도
 *    참조하지 않는다. 설정이 가리키는 파일 하나만 남기고 지운다(업로드만 하고 저장하지
 *    않은 채 버려진 파일도 여기서 함께 사라진다).
 *
 * 2. **확장 캐시 버전 bump** — 목록 레이아웃은 설정에 따라 세 갈래로 **서버에서** 갈라
 *    렌더된다(자리표시자/대체 이미지/요약만). 그런데 최종 레이아웃 응답은
 *    `PublicLayoutController::serve()` 가 `layout.{template}.{name}.v{버전}` 키로 캐시하고,
 *    응답에도 `max-age` 가 붙어 브라우저가 들고 있는다. 캐시 버전을 올리면 서버 키와
 *    프론트 요청 URL(`?v=`)이 동시에 바뀌어 저장 직후 새로고침 한 번에 반영된다.
 *    (코어가 레이아웃·확장 변경 때 쓰는 것과 같은 수단이다. 부수적으로 확장 정적
 *    자산 재게시가 예약되므로, 저장 직후 첫 요청이 약간 느릴 수 있다.)
 */
class SettingsSavedListener implements HookListenerInterface
{
    use ClearsTemplateCaches;

    /**
     * 구독 훅 정의.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.after_save' => [
                'method' => 'onSettingsSaved',
                'type' => 'action',
                'priority' => 20,
                // 액션 훅은 기본이 큐 처리다. 큐로 넘기면 "저장 → 새로고침" 사이에 캐시
                // 버전 bump 가 끼지 못해 관리자가 방금 바꾼 설정이 반영되지 않은 화면을
                // 보게 된다(실측: 워커가 집어가기까지 수 초). 같은 요청 안에서 끝내야
                // 저장 직후 새로고침 한 번에 반영된다.
                'sync' => true,
            ],
        ];
    }

    public function handle(...$args): void {}

    /**
     * 설정 저장 직후 호출된다.
     *
     * @param  string  $identifier  저장된 플러그인 식별자
     * @param  array<string, mixed>  $settings  병합된 최종 설정
     * @param  mixed  $result  저장 결과 (사용하지 않음)
     */
    public function onSettingsSaved(string $identifier = '', array $settings = [], mixed $result = null): void
    {
        if ($identifier !== WebzineSettings::IDENTIFIER) {
            return;
        }

        $this->pruneUnusedImages();

        $this->incrementExtensionCacheVersion();
    }

    /**
     * 설정이 가리키는 파일 하나만 남기고 대체 이미지 저장소를 정리한다.
     *
     * 정리는 부수 작업이라, 실패해도 저장 흐름을 깨지 않고 경고만 남긴다.
     */
    private function pruneUnusedImages(): void
    {
        try {
            $settings = WebzineSettings::all();

            // 원본과 목록용 파생본(1.2.0)을 모두 남긴다. 파생본을 빠뜨리면 설정을 저장할
            // 때마다 방금 만든 파생본이 지워져 목록이 원본 주소로 되돌아간다.
            $keep = array_values(array_filter([
                $settings['fallback_upload_path'],
                $settings['fallback_thumb_path'],
            ]));

            $deleted = app(FallbackImageService::class)->pruneExcept($keep);

            if ($deleted > 0) {
                Log::info('[g7-webzine-addon] 사용하지 않는 대체 이미지 파일 정리', ['deleted' => $deleted]);
            }
        } catch (\Throwable $e) {
            Log::warning('[g7-webzine-addon] 대체 이미지 파일 정리 실패', ['error' => $e->getMessage()]);
        }
    }
}
