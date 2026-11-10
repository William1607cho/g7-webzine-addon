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

    public function test_2차_새_키의_기본값(): void
    {
        $d = D::normalize([]);

        $this->assertSame('fit', $d['list_thumb_mode']);
        $this->assertTrue($d['random_badge_enabled']);
        $this->assertSame('추천게시물', $d['random_badge_text']);
        $this->assertSame('list', $d['random_area_mode']);
    }

    public function test_박스_폭_네_가지와_1차_저장값을_읽는다(): void
    {
        foreach (['list', 'original', 'body', 'custom'] as $mode) {
            $this->assertSame($mode, D::normalize(['random_area_mode' => $mode])['random_area_mode']);
        }
        // 1차 스테이징 저장값(목록 폭과 같게 = list, 썸네일 80, 모드 키 없음)
        $first = D::normalize(['random_area_mode' => 'list', 'list_thumb_px' => 80]);
        $this->assertSame('list', $first['random_area_mode']);
        $this->assertSame('fit', $first['list_thumb_mode']);
        $this->assertSame(80, $first['list_thumb_px']);
        $this->assertSame('list', D::normalize(['random_area_mode' => 'wide'])['random_area_mode']);
        $this->assertSame('fit', D::normalize(['list_thumb_mode' => 'huge'])['list_thumb_mode']);
        $this->assertSame('px', D::normalize(['list_thumb_mode' => 'px'])['list_thumb_mode']);
    }

    public function test_배지_문구의_공백을_정리하고_비면_기본_문구다(): void
    {
        $this->assertSame('오늘의 글', D::badgeText("  오늘의 \n\t  글  "));
        $this->assertSame('추천게시물', D::badgeText('   '));
        $this->assertSame('추천게시물', D::badgeText(null));
        $this->assertSame('추천게시물', D::badgeText(['x']));
        $this->assertSame('추천게시물', D::normalize(['random_badge_text' => ''])['random_badge_text']);
    }

    public function test_배지_문구는_20자로_자르고_표현식과_다국어_표식을_지운다(): void
    {
        $long = str_repeat('가', 25);

        $this->assertSame(str_repeat('가', 20), D::badgeText($long));
        $this->assertSame('추천 글', D::badgeText('{{추천}} 글'));
        $this->assertSame('t:admin.x', D::badgeText('$t:admin.x'));
        $this->assertSame('t:x', D::badgeText('$$ t:x'));
        $this->assertSame('<b>굵게</b>', D::badgeText('<b>굵게</b>'));
    }

    public function test_배지_끄기를_읽는다(): void
    {
        $this->assertFalse(D::normalize(['random_badge_enabled' => false])['random_badge_enabled']);
        $this->assertFalse(D::normalize(['random_badge_enabled' => 'false'])['random_badge_enabled']);
    }
}
