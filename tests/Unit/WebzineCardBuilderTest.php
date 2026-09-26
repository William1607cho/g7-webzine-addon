<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\WebzineCardBuilder;

/**
 * 공개 계약 카드 값 계산 테스트 (1.3.0).
 *
 * 이 계산이 틀리면 **비밀글의 요약·썸네일이 다른 확장 화면(홈 위젯 등)으로 샌다.** 그래서
 * "무엇을 비우는가" 를 먼저 시험한다.
 */
class WebzineCardBuilderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'is_secret' => false,
            'status' => 'published',
            'deleted_at' => null,
            'thumbnail' => null,
        ];
    }

    /**
     * @return array<int, array{content: ?string, content_mode: string}>
     */
    private function bodies(): array
    {
        return [1 => ['content' => '<p>본문 첫 문장</p>', 'content_mode' => 'html']];
    }

    public function test_secret_post_gets_nothing_even_with_thumbnail_and_body(): void
    {
        $cards = WebzineCardBuilder::build([$this->item(['is_secret' => true, 'thumbnail' => '/a.jpg'])], $this->bodies(), '/fb.webp');

        $this->assertSame(['summary' => null, 'thumbnail' => null, 'fallback_image' => null], $cards[1]);
    }

    public function test_secret_post_body_is_not_requested(): void
    {
        $this->assertSame([], WebzineCardBuilder::idsNeedingBody([$this->item(['is_secret' => true])]));
    }

    public function test_published_post_gets_recalculated_summary(): void
    {
        $cards = WebzineCardBuilder::build([$this->item()], $this->bodies(), null);

        $this->assertSame('본문 첫 문장', $cards[1]['summary']);
    }

    public function test_unpublished_or_deleted_or_missing_status_has_no_summary(): void
    {
        foreach ([['status' => 'blinded'], ['deleted_at' => '2026-09-26 10:00'], ['status' => null]] as $o) {
            $cards = WebzineCardBuilder::build([$this->item($o)], $this->bodies(), null);
            $this->assertNull($cards[1]['summary'], json_encode($o));
        }
        $this->assertSame([], WebzineCardBuilder::idsNeedingBody([$this->item(['status' => 'blinded'])]));
    }

    public function test_missing_body_gives_null_summary(): void
    {
        $cards = WebzineCardBuilder::build([$this->item(['id' => 2])], $this->bodies(), null);

        $this->assertNull($cards[2]['summary']);
    }

    public function test_fallback_image_only_without_thumbnail(): void
    {
        $cards = WebzineCardBuilder::build([
            $this->item(['id' => 1, 'thumbnail' => '/t.jpg']),
            $this->item(['id' => 2, 'thumbnail' => '']),
        ], [], '/fb.webp');

        $this->assertSame('/t.jpg', $cards[1]['thumbnail']);
        $this->assertNull($cards[1]['fallback_image']);
        $this->assertNull($cards[2]['thumbnail']);
        $this->assertSame('/fb.webp', $cards[2]['fallback_image']);
    }

    public function test_items_without_valid_id_are_skipped(): void
    {
        $cards = WebzineCardBuilder::build([['title' => 'x'], $this->item(['id' => 'abc']), $this->item(['id' => 0]), 'str'], [], null);

        $this->assertSame([], $cards);
    }
}
