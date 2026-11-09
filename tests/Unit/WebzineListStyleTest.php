<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\BodyWidthResolver;
use Plugins\G7\Webzine\Addon\Support\GalleryCardShape;
use Plugins\G7\Webzine\Addon\Support\WebzineDesignSettings as D;
use Plugins\G7\Webzine\Addon\Support\WebzineListStyle as S;

/**
 * 디자인 설정의 트리 반영·글 본문 폭·갤러리 카드 테스트 (1.5.0).
 */
class WebzineListStyleTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function branch(): array
    {
        return [
            'name' => 'Div',
            'props' => ['data-g7wz-controls' => 'x'],
            'children' => [[
                'name' => 'Div',
                'props' => ['className' => 'relative w-20 h-20 rounded-lg'],
                'children' => [['name' => 'Img', 'props' => ['width' => '80', 'height' => '80']]],
            ], [
                'name' => 'HtmlContent',
                'props' => ['className' => 'text-sm line-clamp-2 overflow-hidden'],
            ]],
        ];
    }

    public function test_기본값이면_트리가_그대로이고_속성만_붙는다(): void
    {
        $out = S::apply($this->branch(), D::normalize([]), null);

        $this->assertArrayNotHasKey('style', $out['props']);
        $this->assertArrayNotHasKey('responsive', $out);
        $this->assertSame($this->branch()['children'], $out['children']);
        $this->assertSame('width=current:none,thumb=80,lines=2', $out['props']['data-g7wz-design']);
    }

    public function test_직접_입력_폭은_최대폭으로_걸리고_모바일은_100퍼센트다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_width_mode' => 'custom', 'list_width_px' => 800]), null);

        $this->assertSame('800px', $out['props']['style']['maxWidth']);
        $this->assertSame('100%', $out['responsive']['mobile']['props']['style']['maxWidth']);
    }

    public function test_글_본문_폭_실측값을_쓴다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_width_mode' => 'body']), 896);

        $this->assertSame('896px', $out['props']['style']['maxWidth']);
    }

    public function test_글_본문_폭_실측에_실패하면_현재값이다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_width_mode' => 'body']), null);

        $this->assertArrayNotHasKey('style', $out['props']);
        $this->assertSame('width=body:none,thumb=80,lines=2', $out['props']['data-g7wz-design']);
    }

    public function test_썸네일_크기가_박스와_이미지에_적용된다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_thumb_px' => 120]), null);

        $this->assertSame('120px', $out['children'][0]['props']['style']['width']);
        $this->assertSame('120', $out['children'][0]['children'][0]['props']['width']);
    }

    public function test_요약_줄_수가_적용된다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_summary_lines' => 3]), null);

        $this->assertSame('3', $out['children'][1]['props']['style']['WebkitLineClamp']);
        $this->assertSame('text-sm  overflow-hidden', $out['children'][1]['props']['className']);
    }

    public function test_글_보기_레이아웃_폭을_읽는다(): void
    {
        $layout = ['slots' => ['content' => [['props' => ['className' => 'py-8 max-w-4xl mx-auto']]]]];

        $this->assertSame(896, BodyWidthResolver::fromShowLayout($layout));
    }

    public function test_폭_지정이_없으면_null_이다(): void
    {
        $this->assertNull(BodyWidthResolver::fromShowLayout(['slots' => ['content' => [['props' => ['className' => 'py-8']]]]]));
        $this->assertNull(BodyWidthResolver::fromShowLayout([]));
    }

    public function test_템플릿_갤러리_카드를_찾아_복사한다(): void
    {
        $tree = [[
            'if' => "{{posts?.data?.board?.type === 'gallery'}}",
            'children' => [['name' => 'Grid', 'children' => [[
                'name' => 'Button',
                'iteration' => ['source' => '{{posts}}', 'item_var' => 'post'],
                'props' => ['className' => 'block aspect-[4/3] group'],
                'comment' => 'x',
            ]]]],
        ]];
        $card = GalleryCardShape::adapt(GalleryCardShape::locate($tree), '16:9');

        $this->assertSame('{{g7wz_random_box?.data?.items ?? []}}', $card['iteration']['source']);
        $this->assertStringNotContainsString('aspect-', $card['props']['className']);
        $this->assertSame('16 / 9', $card['props']['style']['aspectRatio']);
        $this->assertArrayNotHasKey('comment', $card);
    }

    public function test_갤러리_분기가_없으면_null_이고_자체_카드를_쓴다(): void
    {
        $this->assertNull(GalleryCardShape::locate([['if' => "x", 'children' => []]]));
        $card = GalleryCardShape::adapt(null, '1:1');

        $this->assertSame('1 / 1', $card['props']['style']['aspectRatio']);
        $this->assertSame('post', $card['iteration']['item_var']);
    }

    public function test_박스_노드는_768_미만에서_숨고_첫_페이지_조건이_붙는다(): void
    {
        $box = GalleryCardShape::boxNode(['name' => 'Button'], D::normalize(['random_columns' => 3, 'random_heading_enabled' => true, 'random_heading_text' => '추천']), 'template');

        $this->assertStringContainsString('hidden md:block', $box['props']['className']);
        $this->assertSame('template', $box['props']['data-g7wz-randombox']);
        $this->assertStringContainsString('!query.search', $box['if']);
        $this->assertStringContainsString('Number(query.page) <= 1', $box['if']);
        $this->assertSame('추천', $box['children'][0]['text']);
        $this->assertStringContainsString('grid-cols-3', $box['children'][1]['props']['className']);
    }

    public function test_데이터소스는_선택한_slug_만_요청한다(): void
    {
        $src = GalleryCardShape::dataSource(['test-webzine', 'bad slug']);

        $this->assertStringContainsString("['test-webzine'].includes(route.slug)", $src['if']);
        $this->assertSame([], $src['fallback']['data']['items']);
    }
}
