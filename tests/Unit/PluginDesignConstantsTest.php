<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\WebzineDesignSettings as D;

/**
 * plugin.php 에 따로 둔 2차 디자인 값이 WebzineDesignSettings 와 같은지 글자로 대조한다 (1.5.0 2차).
 *
 * plugin.php 는 코어 AbstractPlugin 을 상속해 단위 테스트에서 불러올 수 없으므로 파일 글자를 읽는다.
 */
class PluginDesignConstantsTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/plugin.php');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function literal(array $values): string
    {
        return "['".implode("', '", $values)."']";
    }

    public function test_plugin_php_의_값이_설정_클래스와_같다(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('private const THUMB_MODES = '.$this->literal(D::THUMB_MODES).';', $src);
        $this->assertStringContainsString("private const THUMB_FIT = '".D::THUMB_FIT."';", $src);
        $this->assertStringContainsString('private const AREA_MODES = '.$this->literal(D::AREA_MODES).';', $src);
        $this->assertStringContainsString('private const BADGE_MAX = '.D::BADGE_MAX.';', $src);
        $this->assertStringContainsString("private const BADGE_DEFAULT = '".D::BADGE_DEFAULT."';", $src);
    }

    public function test_plugin_php_는_2차_새_상수를_설정_클래스에서_읽지_않는다(): void
    {
        $src = $this->source();

        foreach (['THUMB_MODES', 'THUMB_FIT', 'THUMB_PX', 'BADGE_MAX', 'BADGE_DEFAULT', 'AREA_ORIGINAL', 'AREA_BODY', 'AREA_MODES'] as $name) {
            $this->assertStringNotContainsString('WebzineDesignSettings::'.$name, $src);
        }
    }
}
