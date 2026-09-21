<?php

namespace Plugins\G7\Webzine\Addon\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\G7\Webzine\Addon\Console\Commands\BuildFallbackThumbCommand;
use Plugins\G7\Webzine\Addon\Services\FallbackImageService;
use Plugins\G7\Webzine\Addon\Services\FallbackThumbBuilder;

/**
 * 웹진게시판 애드온 서비스 프로바이더.
 *
 * 코어 `PluginServiceProvider` 가 `src/Providers/*ServiceProvider.php` 를 자동 발견해
 * 등록하며, 훅 리스너(`plugin.php::getHookListeners()`)는 PluginManager 가 규약에 따라
 * 자동으로 집어간다.
 *
 * v1.1.0 에서 대체 이미지 저장·정리·서빙을 맡는 `FallbackImageService` 가 추가되었고,
 * 이 서비스는 플러그인 도메인 `StorageInterface` 를 필요로 한다 — 주입은
 * `BasePluginServiceProvider` 의 `$storageServices` 표준에 위임한다.
 *
 * v1.2.0 에서 목록용 파생본 빌더(`FallbackThumbBuilder`)와 그 생성 명령이 더해졌다.
 */
class WebzineAddonServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'g7-webzine-addon';

    /**
     * 기본 StorageInterface 주입이 필요한 서비스.
     *
     * @var array<class-string>
     */
    protected array $storageServices = [
        FallbackImageService::class,
        // 1.2.0 — 목록용 파생본 빌더도 같은 플러그인 스토리지를 쓴다. 여기 넣지 않으면
        // FallbackImageService 가 이것을 주입받을 때 StorageInterface 해석에 실패한다.
        FallbackThumbBuilder::class,
    ];

    /**
     * artisan 명령 등록 (1.2.0 신설).
     *
     * 콘솔에서만 등록한다 — 웹 요청에 명령 클래스를 올릴 이유가 없다.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildFallbackThumbCommand::class,
            ]);
        }
    }
}
