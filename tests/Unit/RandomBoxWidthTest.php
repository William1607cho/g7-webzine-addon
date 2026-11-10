<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\RandomBoxWidth as B;
use Plugins\G7\Webzine\Addon\Support\WebzineDesignSettings as D;
use Plugins\G7\Webzine\Addon\Support\WebzineRowMetrics as M;

/**
 * 랜덤 박스 폭·자리와 행 높이 계산 테스트 (1.5.0 2차).
 */
class RandomBoxWidthTest extends TestCase
{
    /**
     * 웹진 분기 골격: children[0] 아래 [헤더, 필터, 목록, 빈 상태, 페이지, 글쓰기].
     *
     * @return array<string, mixed>
     */
    private function branch(bool $constrained): array
    {
        $branch = [
            'name' => 'Div',
            'props' => ['data-g7wz-controls' => 'x'],
            'children' => [['name' => 'Div', 'children' => [
                ['id' => 'h'], ['id' => 'f'], ['id' => 'list'], ['id' => 'empty'], ['name' => 'Pagination'], ['id' => 'write'],
            ]]],
        ];
        if ($constrained) {
            $branch['props']['style'] = ['maxWidth' => '896px', 'marginLeft' => 'auto', 'marginRight' => 'auto'];
            $branch['responsive'] = ['mobile' => ['props' => ['style' => ['maxWidth' => '100%']]]];
        }

        return $branch;
    }

    public function test_목록과_같게는_스타일_없이_목록_폭을_따른다(): void
    {
        $w = B::resolve(D::normalize(['random_area_mode' => 'list']), 896);

        $this->assertSame('list', $w['mode']);
        $this->assertNull($w['style']);
        $this->assertTrue($w['inherit']);
    }

    public function test_원래_폭은_폭_지정이_없고_목록_제한_밖이다(): void
    {
        $w = B::resolve(D::normalize(['random_area_mode' => 'original']), null);

        $this->assertSame('original', $w['mode']);
        $this->assertNull($w['style']);
        $this->assertFalse($w['inherit']);
    }

    public function test_글_폭_따라는_본문_폭을_최대폭으로_건다(): void
    {
        $w = B::resolve(D::normalize(['random_area_mode' => 'body']), 896);

        $this->assertSame(['maxWidth' => '896px', 'marginLeft' => 'auto', 'marginRight' => 'auto'], $w['style']);
    }

    public function test_글_폭을_못_읽으면_원래_폭이다(): void
    {
        $w = B::resolve(D::normalize(['random_area_mode' => 'body']), null);

        $this->assertSame('original', $w['mode']);
        $this->assertNull($w['style']);
    }

    public function test_직접_입력은_1차와_같은_가운데_정렬_식이다(): void
    {
        $w = B::resolve(D::normalize(['random_area_mode' => 'custom', 'random_area_px' => 1000]), null);

        $this->assertSame('1000px', $w['style']['width']);
        $this->assertSame('translateX(-50%)', $w['style']['transform']);
    }

    public function test_목록_폭이_없으면_1차_자리에_그대로_넣는다(): void
    {
        $out = B::place($this->branch(false), ['id' => 'box'], false);
        $ids = array_map(fn ($n) => $n['id'] ?? $n['name'], $out['children'][0]['children']);

        $this->assertSame(['h', 'f', 'box', 'list', 'empty', 'Pagination', 'write'], $ids);
        $this->assertSame('inline', B::placement($this->branch(false), false));
    }

    public function test_목록과_같게면_목록_폭이_있어도_나누지_않는다(): void
    {
        $out = B::place($this->branch(true), ['id' => 'box'], true);

        $this->assertSame('896px', $out['props']['style']['maxWidth']);
        $this->assertSame('box', $out['children'][0]['children'][2]['id']);
    }

    public function test_목록_폭이_있고_박스가_다르면_묶음으로_나눠_폭을_옮긴다(): void
    {
        $in = $this->branch(true);
        $out = B::place($in, ['id' => 'box'], false);
        $inner = $out['children'][0]['children'];

        $this->assertSame('split', B::placement($in, false));
        $this->assertCount(3, $inner);
        $this->assertArrayNotHasKey('style', $out['props']);
        $this->assertArrayNotHasKey('responsive', $out);
        $this->assertSame('x', $out['props']['data-g7wz-controls']);
        $this->assertSame($in['props']['style'], $inner[0]['props']['style']);
        $this->assertSame($in['responsive'], $inner[0]['responsive']);
        $this->assertSame($in['props']['style'], $inner[2]['props']['style']);
        $this->assertSame($in['responsive'], $inner[2]['responsive']);
        $this->assertSame(['h', 'f'], array_column($inner[0]['children'], 'id'));
        $this->assertSame('box', $inner[1]['id']);
        $this->assertCount(4, $inner[2]['children']);
        $this->assertSame('Pagination', $inner[2]['children'][2]['name']);
    }

    public function test_자리가_없는_분기는_그대로_둔다(): void
    {
        $branch = ['name' => 'Div', 'children' => []];

        $this->assertSame('none', B::placement($branch, false));
        $this->assertSame($branch, B::place($branch, ['id' => 'box'], false));
    }

    public function test_행_글_영역_높이는_제목_요약_작성자_줄의_합이다(): void
    {
        $this->assertSame('4.875rem', M::css(1));
        $this->assertSame('6.125rem', M::css(2));
        $this->assertSame('7.375rem', M::css(3));
        $this->assertSame(78, M::px(1));
        $this->assertSame(98, M::px(2));
        $this->assertSame(118, M::px(3));
    }

    public function test_행_높이_줄_수는_1에서_3으로_조인다(): void
    {
        $this->assertSame(M::css(1), M::css(0));
        $this->assertSame(M::css(3), M::css(9));
    }
}
