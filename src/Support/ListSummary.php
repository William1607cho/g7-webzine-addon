<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 목록 본문 요약을 다시 계산하는 순수 클래스 (1.2.0 신설).
 *
 * ## 이것은 임시 우회다
 *
 * 요약을 만드는 것은 원래 코어(`sirsoft-board`)다. 그 계산에 결함 세 가지가 있어
 * 웹진 목록에서 눈에 띄게 드러나 여기서 다시 계산한다:
 *
 *  1. **본문이 이미지로 시작하면 요약이 빈다.** 코어는 목록 질의에서
 *     `SUBSTRING(content, 1, 200)` 으로 **태그를 포함한 HTML 앞 200자**만 가져온다.
 *     CKEditor5 가 이미지를 `<figure class="image"><img src="…" alt="…"></figure>` 로
 *     감싸므로 이미지 하나가 그 예산을 다 먹는다.
 *  2. **선두 유니코드 공백이 남는다.** `html_entity_decode` 가 `&nbsp;` 를 U+00A0 으로
 *     바꾼 뒤 `preg_replace('/\s+/', …)` 에 `/u` 가 없어 그 문자를 공백으로 보지 않고,
 *     `trim()` 도 ASCII 공백만 깎는다.
 *  3. **길이가 150자가 아니라 153자다.** 150자로 자른 뒤 `'...'` 를 덧붙인다.
 *
 * 실측(운영 게시판 127건): 본문에 텍스트가 있는 125건 중 **66건(52.8%)만** 150자를
 * 채울 수 있었다. 나머지 47% 는 본문이 충분히 긴데도 요약이 짧게 끊긴다.
 *
 * **코어가 고쳐지면 이 클래스와 미들웨어 등록을 통째로 지운다.** 그래서 파일도 DB 도
 * HTTP 도 건드리지 않는 순수 계산만 여기 둔다 — 지울 때 딸려 나올 것이 없게.
 *
 * ## 순서는 코어를 따른다
 *
 * 엔티티 디코드를 태그 제거보다 **먼저** 한다. 코어의 검색 요약
 * (`App\Search\SearchHighlighter::toPlainText()`)이 같은 순서이고, 그 이유가 주석에
 * 적혀 있다 — 엔티티로 인코딩된 태그가 평문화 단계에서 실제 태그로 부활하지 못하게 한다.
 * 목록 요약 쪽만 순서가 반대였다.
 */
final class ListSummary
{
    /**
     * 요약 최대 길이 (말줄임표 포함).
     *
     * 코어가 쓰는 값과 같다. 다른 점은 **말줄임표를 이 안에 넣는다**는 것뿐이다.
     */
    public const LENGTH = 150;

    /**
     * 말줄임표.
     */
    public const ELLIPSIS = '...';

    /**
     * 평문화할 때 잘라 올 본문 길이 (문자 수).
     *
     * 근거(2026-09-21 실측, 웹진 게시판 10곳 127건): 150자를 확보하는 데 필요한 HTML
     * 길이는 중앙값 195자, 90분위 338자, **최대 932자**였다. 1200자면 127건 전부가
     * 충족된다. 2배 여유를 둬 2000자로 잡는다.
     *
     * 본문 전체를 읽지 않는 이유는 코어의 컬럼 프루닝 의도와 같다 — 목록 한 페이지가
     * 본문 전체를 끌고 오면 안 된다.
     */
    public const SOURCE_LIMIT = 2000;

    /**
     * 공백으로 취급할 유니코드 문자.
     *
     * `\s` 만으로는 U+00A0(`&nbsp;`)·U+200B(폭 없는 공백)·U+FEFF(BOM)·U+3000(전각 공백)이
     * 걸리지 않는다. `/u` 수식자와 함께 써야 의미가 있다.
     */
    private const SPACE_CLASS = '/[\s\x{00A0}\x{200B}\x{FEFF}\x{3000}]+/u';

    /**
     * 본문에서 목록 요약을 만듭니다.
     *
     * @param  string|null  $content  본문 원문 (HTML 또는 평문)
     * @param  string  $contentMode  `html` 이면 태그를 제거한다. 그 밖(text·markdown)은 원문 그대로
     * @return string 요약 (최대 {@see self::LENGTH} 자, 말줄임표 포함)
     */
    public static function build(?string $content, string $contentMode): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        $plain = self::toPlainText(mb_substr($content, 0, self::SOURCE_LIMIT), $contentMode);

        if ($plain === '') {
            return '';
        }

        if (mb_strlen($plain) <= self::LENGTH) {
            return $plain;
        }

        return mb_substr($plain, 0, self::LENGTH - mb_strlen(self::ELLIPSIS)).self::ELLIPSIS;
    }

    /**
     * 평문화 — 엔티티 디코드 → 태그 제거 → 유니코드 공백 정리.
     *
     * `html` 모드가 아니면 태그를 건드리지 않는다. 코어와 같은 판단이다 — text 모드 본문은
     * 이스케이프되어 렌더되므로 리터럴 태그 문자열이 사용자가 쓴 내용이다.
     */
    private static function toPlainText(string $source, string $contentMode): string
    {
        $plain = $contentMode === 'html'
            ? strip_tags(html_entity_decode($source, ENT_QUOTES, 'UTF-8'))
            : $source;

        return trim((string) preg_replace(self::SPACE_CLASS, ' ', $plain));
    }

    /**
     * 이 글의 요약을 다시 계산해도 되는지 판정합니다.
     *
     * **코어가 내보낸 값만 본다.** DB 를 다시 읽지도, 권한을 다시 판정하지도 않는다.
     * 코어는 블라인드·비밀글의 요약을 빈 문자열로 가리는데(`getMaskedContentPreviewForList`),
     * 가려진 빈 문자열과 결함으로 비어 버린 빈 문자열은 응답만 봐서는 구분되지 않는다.
     * 그래서 **응답이 함께 실어 보낸 상태 값**으로 가른다.
     *
     * 삭제된 글은 코어가 가리지 않지만 여기서도 건드리지 않는다 — 목록에 보이는 것 자체가
     * 예외 상황(`withTrashed`)이라 코어가 낸 값을 그대로 두는 편이 안전하다.
     *
     * @param  array<string, mixed>  $item  목록 응답의 글 항목 하나
     */
    public static function eligible(array $item): bool
    {
        if (! array_key_exists('content_preview', $item) || ! is_string($item['content_preview'])) {
            return false;
        }

        if (! empty($item['is_secret'])) {
            return false;
        }

        if (($item['status'] ?? null) !== 'published') {
            return false;
        }

        if (($item['deleted_at'] ?? null) !== null) {
            return false;
        }

        return true;
    }
}
