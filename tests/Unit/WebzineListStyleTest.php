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
            ], [
                'name' => 'Div',
                'props' => ['className' => 'flex-1 min-w-0 flex flex-col gap-1'],
            ]],
        ];
    }

    /**
     * 2차(1110) 사양 변경: 기본값이 "썸네일 행 높이에 맞춤 + 행 높이 고정(768px 이상)"이 되어
     * 1차의 "기본값이면 트리 무변경"을 이 기대값으로 바꿨다(DECISIONS D19).
     */
    public function test_기본값이면_폭은_그대로이고_768_이상에서만_행_높이와_썸네일이_맞춰진다(): void
    {
        $out = S::apply($this->branch(), D::normalize([]), null);

        $this->assertArrayNotHasKey('style', $out['props']);
        $this->assertArrayNotHasKey('responsive', $out);
        $this->assertSame(['width' => '6.125rem', 'height' => '6.125rem'], $out['children'][0]['props']['style']);
        $this->assertSame(['width' => '', 'height' => ''], $out['children'][0]['responsive']['mobile']['props']['style']);
        $this->assertSame('80', $out['children'][0]['children'][0]['props']['width']);
        $this->assertSame(['minHeight' => '6.125rem'], $out['children'][2]['props']['style']);
        $this->assertSame(['minHeight' => ''], $out['children'][2]['responsive']['mobile']['props']['style']);
        $this->assertSame($this->branch()['children'][1], $out['children'][1]);
        $this->assertSame('width=current:none,thumb=fit:98,lines=2,row=98', $out['props']['data-g7wz-design']);
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
        $this->assertSame('width=body:none,thumb=fit:98,lines=2,row=98', $out['props']['data-g7wz-design']);
    }

    public function test_썸네일_크기가_박스와_이미지에_적용된다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_thumb_mode' => 'px', 'list_thumb_px' => 120]), null);

        $this->assertSame('120px', $out['children'][0]['props']['style']['width']);
        $this->assertSame('120', $out['children'][0]['children'][0]['props']['width']);
    }

    public function test_요약_줄_수가_적용된다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_summary_lines' => 3]), null);

        $this->assertSame('text-sm line-clamp-3 overflow-hidden', $out['children'][1]['props']['className']);
        $this->assertArrayNotHasKey('style', $out['children'][1]['props']);
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

    public function test_직접_입력_80이면_썸네일은_그대로이고_행_높이만_고정된다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_thumb_mode' => 'px', 'list_thumb_px' => 80]), null);

        $this->assertArrayNotHasKey('style', $out['children'][0]['props']);
        $this->assertArrayNotHasKey('responsive', $out['children'][0]);
        $this->assertSame(['minHeight' => '6.125rem'], $out['children'][2]['props']['style']);
        $this->assertSame('width=current:none,thumb=px:80,lines=2,row=98', $out['props']['data-g7wz-design']);
    }

    public function test_직접_입력_썸네일은_768_미만에서도_같은_크기다(): void
    {
        $out = S::apply($this->branch(), D::normalize(['list_thumb_mode' => 'px', 'list_thumb_px' => 200]), null);

        $this->assertSame(['width' => '200px', 'height' => '200px'], $out['children'][0]['props']['style']);
        $this->assertArrayNotHasKey('responsive', $out['children'][0]);
    }

    public function test_요약_줄_수를_바꾸면_맞춤_썸네일과_행_높이가_같이_바뀐다(): void
    {
        $one = S::apply($this->branch(), D::normalize(['list_summary_lines' => 1]), null);
        $three = S::apply($this->branch(), D::normalize(['list_summary_lines' => 3]), null);

        $this->assertSame('4.875rem', $one['children'][0]['props']['style']['width']);
        $this->assertSame('4.875rem', $one['children'][2]['props']['style']['minHeight']);
        $this->assertSame('7.375rem', $three['children'][0]['props']['style']['height']);
        $this->assertSame('7.375rem', $three['children'][2]['props']['style']['minHeight']);
        $this->assertStringContainsString('row=118', $three['props']['data-g7wz-design']);
    }

    public function test_박스_카드는_메타_줄을_빼고_제목은_남긴다(): void
    {
        $card = GalleryCardShape::adapt($this->galleryCard(), '1:1');
        $flat = json_encode($card, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Avatar', $flat);
        $this->assertStringNotContainsString('UserInfo', $flat);
        $this->assertStringNotContainsString('created_at', $flat);
        $this->assertStringNotContainsString('view_count', $flat);
        $this->assertStringContainsString('{{post?.title}}', $flat);
        $this->assertStringContainsString('fa-lock-overlay', $flat);
        $this->assertStringNotContainsString('data-g7wz-badge', $flat);
    }

    public function test_배지를_켜면_좌상단에_붙고_템플릿_상단_배지_행이_내려간다(): void
    {
        $card = GalleryCardShape::adapt($this->galleryCard(), '1:1', '  추천 <b>글</b>  ');
        $badge = $card['children'][0];

        $this->assertSame('1', $badge['props']['data-g7wz-badge']);
        $this->assertStringContainsString('absolute top-2 left-2', $badge['props']['className']);
        $this->assertStringContainsString('bg-primary-600', $badge['props']['className']);
        $this->assertSame('trophy', $badge['children'][0]['props']['name']);
        $this->assertSame('추천 <b>글</b>', $badge['children'][1]['text']);
        $this->assertArrayNotHasKey('children', $badge['children'][1]);
        $this->assertSame('1.75rem', $card['children'][4]['props']['style']['top']);
    }

    public function test_자체_카드에도_배지가_붙고_메타가_없다(): void
    {
        $card = GalleryCardShape::adapt(null, '1:1', '');

        $this->assertSame('추천게시물', $card['children'][0]['children'][1]['text']);
        $this->assertStringNotContainsString('created_at', json_encode($card));
    }

    public function test_박스_노드에_폭_style_과_확인용_속성이_붙는다(): void
    {
        $box = GalleryCardShape::boxNode(['name' => 'Button'], D::normalize([]), 'builtin', ['maxWidth' => '896px'], 'area=body:inline,badge=on');

        $this->assertSame('896px', $box['props']['style']['maxWidth']);
        $this->assertSame('area=body:inline,badge=on', $box['props']['data-g7wz-boxdesign']);
    }

    /**
     * wc-community 갤러리 카드와 같은 골격.
     *
     * @return array<string, mixed>
     */
    private function galleryCard(): array
    {
        return [
            'name' => 'Button',
            'iteration' => ['source' => '{{posts}}', 'item_var' => 'post'],
            'props' => ['className' => 'block relative aspect-[4/3] group'],
            'children' => [
                ['name' => 'Div', 'props' => ['className' => 'absolute inset-0'], 'children' => [['name' => 'Img', 'props' => ['alt' => '{{post?.title}}']]]],
                ['name' => 'Div', 'if' => "{{post?.status === 'blinded' || post?.deleted_at}}", 'props' => ['className' => 'absolute inset-0 z-10']],
                ['name' => 'Div', 'if' => '{{post?.is_secret}}', 'props' => ['className' => 'absolute inset-0 z-10 fa-lock-overlay'], 'children' => [['name' => 'Icon', 'props' => ['name' => 'lock']]]],
                ['name' => 'Div', 'props' => ['className' => 'absolute inset-x-0 top-0 flex p-2 z-20']],
                ['name' => 'Div', 'props' => ['className' => 'absolute inset-x-0 bottom-0 z-30'], 'children' => [
                    ['name' => 'Div', 'children' => [['name' => 'Span', 'text' => '{{post?.title}}'], ['name' => 'Span', 'if' => '{{post?.deleted_at}}']]],
                    ['name' => 'Div', 'children' => [
                        ['name' => 'Div', 'children' => [['type' => 'composite', 'name' => 'Avatar'], ['type' => 'composite', 'name' => 'UserInfo'], ['name' => 'Span', 'text' => "{{post?.created_at_formatted ?? ''}}"]]],
                        ['name' => 'Div', 'children' => [['name' => 'Span', 'text' => '{{post?.view_count ?? 0}}'], ['name' => 'Span', 'text' => '{{post?.comment_count ?? 0}}']]],
                    ]],
                ]],
            ],
        ];
    }

    public function test_데이터소스는_선택한_slug_만_요청한다(): void
    {
        $src = GalleryCardShape::dataSource(['test-webzine', 'bad slug']);

        $this->assertStringContainsString("['test-webzine'].includes(route.slug)", $src['if']);
        $this->assertSame([], $src['fallback']['data']['items']);
    }
}
