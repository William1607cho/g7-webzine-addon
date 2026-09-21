<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Support\ListSummary;

/**
 * 목록 요약 재계산 테스트 (1.2.0).
 *
 * 이 클래스가 고치려는 것은 코어의 결함 셋이다 — 이미지로 시작하는 본문, 선두 유니코드
 * 공백, 150자를 넘는 길이. 각각에 대응하는 시험이 아래에 있다.
 */
class ListSummaryTest extends TestCase
{
    // ── 이미지로 시작하는 본문 (코어 결함 ①) ────────────────────────────────

    public function test_이미지로_시작해도_뒤의_글이_요약에_들어간다(): void
    {
        // 코어는 태그 포함 앞 200자만 보므로 figure/img 가 예산을 다 먹어 빈 요약이 됐다.
        $body = '<p><figure class="image"><img src="/api/plugins/sirsoft-ckeditor5/images/aabbccddeeff" alt="image.png"></figure></p>'
            .'<p>이미지 뒤에 오는 본문입니다.</p>';

        $this->assertSame('이미지 뒤에 오는 본문입니다.', ListSummary::build($body, 'html'));
    }

    public function test_본문이_이미지뿐이면_빈_요약이_맞다(): void
    {
        $body = '<p><figure class="image"><img src="/x" alt="image.png"></figure></p>';

        $this->assertSame('', ListSummary::build($body, 'html'));
    }

    // ── 유니코드 공백 (코어 결함 ②) ─────────────────────────────────────────

    public function test_선두_nbsp_가_제거된다(): void
    {
        $out = ListSummary::build('<p>&nbsp;&nbsp;본문 시작</p>', 'html');

        $this->assertSame('본문 시작', $out);
        $this->assertStringStartsNotWith("\u{00A0}", $out);
    }

    public function test_선두_폭_없는_공백이_제거된다(): void
    {
        $out = ListSummary::build("<p>\u{200B}본문 시작</p>", 'html');

        $this->assertSame('본문 시작', $out);
    }

    public function test_전각_공백과_BOM_도_공백으로_정리된다(): void
    {
        $this->assertSame('가 나', ListSummary::build("<p>\u{3000}가\u{FEFF} 나\u{3000}</p>", 'html'));
    }

    public function test_연속_공백이_하나로_합쳐진다(): void
    {
        $this->assertSame('가 나 다', ListSummary::build("<p>가  \n\t나&nbsp;&nbsp;다</p>", 'html'));
    }

    // ── 엔티티 ──────────────────────────────────────────────────────────────

    public function test_엔티티가_디코드된다(): void
    {
        $this->assertSame('A&B "인용"', ListSummary::build('<p>A&amp;B &quot;인용&quot;</p>', 'html'));
    }

    public function test_엔티티로_인코딩된_태그는_디코드_뒤_제거된다(): void
    {
        // 코어 SearchHighlighter::toPlainText 와 같은 순서(디코드 → 태그 제거)를 따른 결과다.
        $this->assertSame('안전', ListSummary::build('<p>&lt;script&gt;안전&lt;/script&gt;</p>', 'html'));
    }

    // ── 150자 경계 (코어 결함 ③) ────────────────────────────────────────────

    public function test_정확히_150자면_말줄임표가_붙지_않는다(): void
    {
        $out = ListSummary::build('<p>'.str_repeat('가', 150).'</p>', 'html');

        $this->assertSame(150, mb_strlen($out));
        $this->assertStringEndsNotWith('...', $out);
    }

    public function test_151자면_말줄임표를_포함해_150자다(): void
    {
        $out = ListSummary::build('<p>'.str_repeat('가', 151).'</p>', 'html');

        $this->assertSame(150, mb_strlen($out), '말줄임표를 상한 밖에 덧붙이면 153자가 된다');
        $this->assertStringEndsWith('...', $out);
        $this->assertSame(str_repeat('가', 147).'...', $out);
    }

    public function test_아주_긴_본문도_150자를_넘지_않는다(): void
    {
        $out = ListSummary::build('<p>'.str_repeat('나', 5000).'</p>', 'html');

        $this->assertSame(150, mb_strlen($out));
    }

    // ── 빈 본문·모드 ────────────────────────────────────────────────────────

    public function test_빈_본문은_빈_요약이다(): void
    {
        $this->assertSame('', ListSummary::build('', 'html'));
        $this->assertSame('', ListSummary::build(null, 'html'));
        $this->assertSame('', ListSummary::build('   ', 'html'));
    }

    public function test_html_모드가_아니면_태그를_건드리지_않는다(): void
    {
        // text 모드 본문은 이스케이프되어 렌더되므로 리터럴 태그가 사용자가 쓴 내용이다.
        $this->assertSame('<b>굵게</b>', ListSummary::build('<b>굵게</b>', 'text'));
    }

    public function test_text_모드에서도_유니코드_공백은_정리된다(): void
    {
        $this->assertSame('가 나', ListSummary::build("\u{00A0}가\u{200B} 나", 'text'));
    }

    // ── 원본 길이 상한 ──────────────────────────────────────────────────────

    public function test_본문은_상한까지만_읽는다(): void
    {
        // 상한 밖의 글자는 요약에 들어오지 않는다. 150자를 채우기에는 충분하다.
        $body = '<p>'.str_repeat('마', ListSummary::SOURCE_LIMIT + 500).'</p>';

        $this->assertSame(150, mb_strlen(ListSummary::build($body, 'html')));
    }

    public function test_상한이_실측_최대치보다_넉넉하다(): void
    {
        // 2026-09-21 실측: 150자를 확보하는 데 필요한 HTML 길이 최대 932자.
        $this->assertGreaterThanOrEqual(1200, ListSummary::SOURCE_LIMIT);
    }
}
