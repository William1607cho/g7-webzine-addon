<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\RandomBoxPicker as P;
use Plugins\G7\Webzine\Addon\Support\WebzineDesignSettings as D;

/**
 * 랜덤 박스 후보·묶음·응답 값 테스트 (1.5.0).
 *
 * 비밀글 이미지·작성자 이메일이 응답으로 새지 않는 것을 먼저 시험한다.
 */
class RandomBoxPickerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(int $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'title' => '글'.$id,
            'status' => 'published',
            'is_secret' => false,
            'is_notice' => false,
            'is_reply' => false,
            'parent_id' => null,
            'deleted_at' => null,
            'thumbnail' => '/img/'.$id,
            'author' => ['uuid' => 'u1', 'name' => '작성자', 'avatar' => null, 'is_guest' => false, 'email' => 'a@example.com'],
        ];
    }

    public function test_답글과_미발행과_삭제글은_후보에서_빠진다(): void
    {
        $rows = [
            $this->row(1),
            $this->row(2, ['parent_id' => 1]),
            $this->row(3, ['status' => 'blinded']),
            $this->row(4, ['deleted_at' => '2026-01-01']),
        ];

        $this->assertSame([1], array_column(P::candidates($rows, D::normalize([])), 'id'));
    }

    public function test_공지_제외_설정을_따른다(): void
    {
        $rows = [$this->row(1, ['is_notice' => true]), $this->row(2)];

        $this->assertSame([2], array_column(P::candidates($rows, D::normalize([])), 'id'));
        $this->assertSame([1, 2], array_column(P::candidates($rows, D::normalize(['random_exclude_notice' => false])), 'id'));
    }

    public function test_이미지_있는_글만이면_썸네일_없는_글과_비밀글이_빠진다(): void
    {
        $rows = [$this->row(1), $this->row(2, ['thumbnail' => null]), $this->row(3, ['is_secret' => true])];

        $this->assertSame([1], array_column(P::candidates($rows, D::normalize(['random_only_images' => true])), 'id'));
    }

    public function test_같은_시드면_같은_묶음이고_후보_순서와_무관하다(): void
    {
        $rows = array_map(fn ($i) => $this->row($i), range(1, 30));
        $a = P::pick($rows, 5, 'seed-a');
        $b = P::pick(array_reverse($rows), 5, 'seed-a');

        $this->assertSame(array_column($a, 'id'), array_column($b, 'id'));
        $this->assertCount(5, $a);
    }

    public function test_시드가_다르면_묶음이_달라진다(): void
    {
        $rows = array_map(fn ($i) => $this->row($i), range(1, 30));

        $this->assertNotSame(array_column(P::pick($rows, 5, 'a'), 'id'), array_column(P::pick($rows, 5, 'b'), 'id'));
    }

    public function test_후보보다_많이_달라고_해도_있는_만큼만_준다(): void
    {
        $this->assertCount(2, P::pick([$this->row(1), $this->row(2)], 5, 's'));
    }

    public function test_같은_구간이면_같은_번호다(): void
    {
        $this->assertSame(P::bucket(1200, 10), P::bucket(1799, 10));
        $this->assertNotSame(P::bucket(1200, 10), P::bucket(1800, 10));
    }

    public function test_구간_끝까지_남은_초는_항상_양수다(): void
    {
        $this->assertSame(600, P::secondsToBucketEnd(1200, 10));
        $this->assertSame(1, P::secondsToBucketEnd(1799, 10));
    }

    public function test_비밀글은_이미지가_null_이고_대체_이미지도_넣지_않는다(): void
    {
        $item = P::toItem($this->row(1, ['is_secret' => true]), '/img/1', '/fallback');

        $this->assertNull($item['thumbnail']);
        $this->assertTrue($item['is_secret']);
    }

    public function test_썸네일_없는_글에만_대체_이미지가_들어간다(): void
    {
        $this->assertSame('/fallback', P::toItem($this->row(1, ['thumbnail' => null]), null, '/fallback')['thumbnail']);
        $this->assertSame('/img/1', P::toItem($this->row(1), '/img/1', '/fallback')['thumbnail']);
    }

    public function test_작성자_이메일은_응답에_없다(): void
    {
        $item = P::toItem($this->row(1), '/img/1', null);

        $this->assertArrayNotHasKey('email', $item['author']);
        $this->assertSame('작성자', $item['author']['name']);
    }

    public function test_응답에_본문_요약이_없다(): void
    {
        $this->assertArrayNotHasKey('content_preview', P::toItem($this->row(1, ['content_preview' => '본문']), '/img/1', null));
    }

    public function test_변환본은_960_우선_없으면_1600_240뿐이면_원본이다(): void
    {
        $v = fn (array $widths) => ['available' => true, 'variants' => array_fill_keys($widths, ['url' => '/v']) ];
        $this->assertSame('/v', P::chooseVariantUrl($v([240, 960])));
        $this->assertSame('/v', P::chooseVariantUrl($v([1600])));
        $this->assertNull(P::chooseVariantUrl($v([240])));
    }

    public function test_준비_안_된_변환본은_원본이다(): void
    {
        $this->assertNull(P::chooseVariantUrl(['available' => false, 'variants' => [], 'source' => null]));
        $this->assertNull(P::chooseVariantUrl(null));
    }
}
