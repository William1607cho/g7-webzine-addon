<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\ListSummary;

/**
 * 재계산 대상 판정 테스트 (1.2.0).
 *
 * 이 판정이 틀리면 **비밀글·블라인드 글의 본문이 목록에 샌다.** 코어가 가린 것을 우리가
 * 도로 열어 주는 셈이기 때문이다. 그래서 "무엇을 재계산하는가" 보다 **"무엇을 건드리지
 * 않는가"** 를 촘촘히 시험한다.
 */
class ListSummaryEligibilityTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'content_preview' => '코어가 낸 요약',
            'is_secret' => false,
            'status' => 'published',
            'deleted_at' => null,
        ];
    }

    public function test_평범한_공개글은_대상이다(): void
    {
        $this->assertTrue(ListSummary::eligible($this->item()));
    }

    public function test_코어가_빈_요약을_낸_공개글도_대상이다(): void
    {
        // 이것이 확장안의 핵심이다 — 결함으로 비어 버린 요약을 고칠 수 있다.
        $this->assertTrue(ListSummary::eligible($this->item(['content_preview' => ''])));
    }

    public function test_비밀글은_대상이_아니다(): void
    {
        $this->assertFalse(ListSummary::eligible($this->item(['is_secret' => true, 'content_preview' => ''])));
        $this->assertFalse(ListSummary::eligible($this->item(['is_secret' => 1, 'content_preview' => ''])));
    }

    public function test_블라인드_글은_대상이_아니다(): void
    {
        $this->assertFalse(ListSummary::eligible($this->item(['status' => 'blinded', 'content_preview' => ''])));
    }

    public function test_삭제된_글은_대상이_아니다(): void
    {
        $this->assertFalse(ListSummary::eligible($this->item(['status' => 'deleted'])));
        $this->assertFalse(ListSummary::eligible($this->item(['deleted_at' => '2026-09-21 00:00:00'])));
    }

    public function test_상태값이_없으면_대상이_아니다(): void
    {
        // 코어 응답 형태가 바뀌어 판정 근거가 사라지면 **건드리지 않는 쪽**으로 넘어진다.
        $item = $this->item();
        unset($item['status']);

        $this->assertFalse(ListSummary::eligible($item));
    }

    public function test_content_preview_필드가_없으면_대상이_아니다(): void
    {
        $item = $this->item();
        unset($item['content_preview']);

        $this->assertFalse(ListSummary::eligible($item));
    }

    public function test_content_preview_가_문자열이_아니면_대상이_아니다(): void
    {
        $this->assertFalse(ListSummary::eligible($this->item(['content_preview' => null])));
        $this->assertFalse(ListSummary::eligible($this->item(['content_preview' => ['x']])));
    }

    public function test_알_수_없는_상태값은_대상이_아니다(): void
    {
        $this->assertFalse(ListSummary::eligible($this->item(['status' => 'draft'])));
    }
}
