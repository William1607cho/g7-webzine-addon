<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\WebzineDesignSettings as D;

/**
 * 디자인 설정 정규화 테스트 (1.5.0).
 *
 * 기본값이 어긋나면 업데이트만으로 다른 사이트 화면이 바뀐다. 그래서 "빈 설정 = 기본값" 을 먼저 시험한다.
 */
class WebzineDesignSettingsTest extends TestCase
{
    public function test_빈_설정이면_기본값이다(): void
    {
        $d = D::normalize([]);

        $this->assertSame('current', $d['list_width_mode']);
        $this->assertSame(80, $d['list_thumb_px']);
        $this->assertSame(2, $d['list_summary_lines']);
        $this->assertFalse($d['random_enabled']);
        $this->assertSame([], $d['random_board_ids']);
        $this->assertSame(4, $d['random_count']);
        $this->assertSame('list', $d['random_area_mode']);
        $this->assertSame(4, $d['random_columns']);
        $this->assertSame('1:1', $d['random_ratio']);
        $this->assertFalse($d['random_heading_enabled']);
        $this->assertSame('', $d['random_heading_text']);
        $this->assertSame('recent', $d['random_range_mode']);
        $this->assertSame(100, $d['random_recent_n']);
        $this->assertFalse($d['random_only_images']);
        $this->assertTrue($d['random_exclude_notice']);
        $this->assertSame('fixed', $d['random_ttl_mode']);
        $this->assertSame(10, $d['random_ttl_minutes']);
    }

    public function test_숫자_문자열도_숫자로_받는다(): void
    {
        $d = D::normalize(['list_thumb_px' => '120', 'random_count' => ' 6 ']);

        $this->assertSame(120, $d['list_thumb_px']);
        $this->assertSame(6, $d['random_count']);
    }

    public function test_범위_밖_숫자는_범위_안으로_조인다(): void
    {
        $d = D::normalize(['list_width_px' => 99, 'random_columns' => 40, 'random_ttl_minutes' => -3]);

        $this->assertSame(480, $d['list_width_px']);
        $this->assertSame(6, $d['random_columns']);
        $this->assertSame(1, $d['random_ttl_minutes']);
    }

    public function test_숫자가_아니면_기본값이다(): void
    {
        $d = D::normalize(['list_thumb_px' => 'abc', 'random_count' => null]);

        $this->assertSame(80, $d['list_thumb_px']);
        $this->assertSame(4, $d['random_count']);
    }

    public function test_허용_목록_밖_값은_기본값으로_강등된다(): void
    {
        $d = D::normalize(['list_width_mode' => 'huge', 'random_ratio' => '21:9', 'random_ttl_mode' => 1]);

        $this->assertSame('current', $d['list_width_mode']);
        $this->assertSame('1:1', $d['random_ratio']);
        $this->assertSame('fixed', $d['random_ttl_mode']);
    }

    public function test_게시판_id_는_양의_정수만_남기고_중복을_지운다(): void
    {
        $d = D::normalize(['random_board_ids' => [6, '7', 7, 0, -1, 'x', null]]);

        $this->assertSame([6, 7], $d['random_board_ids']);
    }

    public function test_게시판_id_가_배열이_아니면_비어_있다(): void
    {
        $this->assertSame([], D::normalize(['random_board_ids' => 'all'])['random_board_ids']);
    }

    public function test_머리_문구의_표현식과_다국어_표식을_지운다(): void
    {
        $this->assertSame('추천 글', D::normalize(['random_heading_text' => '{{추천}} 글'])['random_heading_text']);
        $this->assertSame('t:admin.x', D::normalize(['random_heading_text' => '$t:admin.x'])['random_heading_text']);
    }

    public function test_문자열_불값을_읽는다(): void
    {
        $this->assertTrue(D::normalize(['random_enabled' => 'true'])['random_enabled']);
        $this->assertFalse(D::normalize(['random_exclude_notice' => 'false'])['random_exclude_notice']);
    }

    public function test_전체_후보는_상한까지다(): void
    {
        $this->assertSame(500, D::candidateLimit(D::normalize(['random_range_mode' => 'all'])));
        $this->assertSame(30, D::candidateLimit(D::normalize(['random_recent_n' => 30])));
    }
}
