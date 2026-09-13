<?php

namespace Plugins\G7\Webzine\Addon\Providers;

use App\Extension\BasePluginServiceProvider;

/**
 * 웹진게시판 애드온 서비스 프로바이더.
 *
 * 이 빌드는 컨테이너 바인딩이 필요한 서비스가 없어 식별자만 지정한다.
 * 코어 `PluginServiceProvider` 가 `src/Providers/*ServiceProvider.php` 를 자동 발견해
 * 등록하며, 훅 리스너(`plugin.php::getHookListeners()`)는 PluginManager 가 규약에 따라
 * 자동으로 집어간다.
 */
class WebzineAddonServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'g7-webzine-addon';
}
