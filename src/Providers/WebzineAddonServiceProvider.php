<?php

namespace Plugins\G7\Webzine\Addon\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\G7\Webzine\Addon\Services\FallbackImageService;

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
    ];
}
