<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 웹진 목록 한 행의 글 영역 높이 계산 (1.5.0).
 *
 * 행 오른쪽 열은 [제목 1줄 / 요약 N줄 / 작성자 줄]이 `gap-1` 로 쌓인 구조다
 * ({@see \Plugins\G7\Webzine\Addon\Listeners\WebzineIndexWidgetListener} 의 웹진 분기 리터럴).
 * 상수는 그 클래스와 템플릿 토큰에서 온 값이다 — 제목 `text-base`(줄 높이 1.5rem),
 * 요약 `text-sm`(1.25rem), 간격 `gap-1`(.25rem), 작성자 줄 `mt-0.5`(.125rem) + Avatar xs(1.5rem).
 * 템플릿이 이 값을 바꾸면 이 클래스만 고친다.
 *
 * 프레임워크에 기대지 않는 순수 계산이라 단위 테스트로 검증한다.
 */
final class WebzineRowMetrics
{
    private const TITLE_REM = 1.5;

    private const SUMMARY_LINE_REM = 1.25;

    private const GAP_REM = 0.25;

    private const META_REM = 0.125 + 1.5;

    private const PX_PER_REM = 16;

    /**
     * 글 영역 높이(rem). 요약 줄 수 N 은 1~3 으로 조인다.
     */
    public static function contentRem(int $lines): float
    {
        $lines = max(1, min(3, $lines));

        return self::TITLE_REM + self::GAP_REM + $lines * self::SUMMARY_LINE_REM + self::GAP_REM + self::META_REM;
    }

    /**
     * CSS 길이 (예: `6.125rem`).
     */
    public static function css(int $lines): string
    {
        return rtrim(rtrim(number_format(self::contentRem($lines), 3, '.', ''), '0'), '.').'rem';
    }

    /**
     * 기본 글자 크기(16px) 기준 px 값 — 확인용 속성과 이미지 크기 힌트에만 쓴다.
     */
    public static function px(int $lines): int
    {
        return (int) round(self::contentRem($lines) * self::PX_PER_REM);
    }
}
