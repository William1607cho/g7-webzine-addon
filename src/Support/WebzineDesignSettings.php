<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 디자인 설정 정규화 (1.5.0).
 *
 * 저장 API 가 스키마(min/max/enum)로 한 번 거르지만, 설정 파일은 이전 판·수동 편집 값이
 * 남아 있을 수 있어 읽는 쪽에서 한 번 더 조인다. 레이아웃 props 와 표현식으로 흘러가는 값이라
 * {@see WebzineSettings} 와 같은 이유로 여기 한 곳에서만 정규화한다.
 *
 * 프레임워크에 기대지 않는 순수 배열 연산이라 단위 테스트로 검증한다.
 */
final class WebzineDesignSettings
{
    /** 목록 폭: 템플릿 그대로(폭 지정 없음) */
    public const WIDTH_CURRENT = 'current';

    /** 목록 폭: 글 본문 폭과 같게 */
    public const WIDTH_BODY = 'body';

    /** 목록 폭: 직접 입력 */
    public const WIDTH_CUSTOM = 'custom';

    public const WIDTH_MODES = [self::WIDTH_CURRENT, self::WIDTH_BODY, self::WIDTH_CUSTOM];

    /** 박스 영역 폭: 목록과 같게 (1.5.0 1차의 "목록 폭과 같게"와 같은 값) */
    public const AREA_LIST = 'list';

    /** 박스 영역 폭: 원래 폭 (목록 폭 지정이 없을 때의 템플릿 폭) */
    public const AREA_ORIGINAL = 'original';

    /** 박스 영역 폭: 글 보기 본문 폭 따라 */
    public const AREA_BODY = 'body';

    /** 박스 영역 폭: 직접 입력 */
    public const AREA_CUSTOM = 'custom';

    public const AREA_MODES = [self::AREA_LIST, self::AREA_ORIGINAL, self::AREA_BODY, self::AREA_CUSTOM];

    /** 목록 썸네일: 행 높이에 맞춤 (768px 이상, 기본) */
    public const THUMB_FIT = 'fit';

    /** 목록 썸네일: 직접 입력(px) */
    public const THUMB_PX = 'px';

    public const THUMB_MODES = [self::THUMB_FIT, self::THUMB_PX];

    /** 배지 기본 문구 */
    public const BADGE_DEFAULT = '추천게시물';

    /** 배지 문구 상한(글자 수) — 저장 API 스키마 max 와 같다 */
    public const BADGE_MAX = 20;

    /** 후보 범위: 전체 ({@see self::ALL_CAP} 건까지) */
    public const RANGE_ALL = 'all';

    /** 후보 범위: 최근 N개 */
    public const RANGE_RECENT = 'recent';

    public const RANGE_MODES = [self::RANGE_ALL, self::RANGE_RECENT];

    /** 고정 시간: 새로고침마다 */
    public const TTL_REQUEST = 'per_request';

    /** 고정 시간: N분 고정 */
    public const TTL_FIXED = 'fixed';

    public const TTL_MODES = [self::TTL_REQUEST, self::TTL_FIXED];

    public const RATIOS = ['1:1', '4:3', '16:9'];

    /** "전체" 후보의 상한 (성능 — 설정 화면에도 안내한다) */
    public const ALL_CAP = 500;

    public const WIDTH_MIN = 480;

    public const WIDTH_MAX = 1600;

    public const THUMB_DEFAULT = 80;

    public const LINES_DEFAULT = 2;

    private const HEADING_MAX = 100;

    /**
     * 설정 키 => [기본값, 최소, 최대] (정수 항목만)
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    public const INT_RANGES = [
        'list_width_px' => [960, self::WIDTH_MIN, self::WIDTH_MAX],
        'list_thumb_px' => [self::THUMB_DEFAULT, 48, 200],
        // 템플릿 CSS 에 있는 line-clamp 범위까지만 (HtmlContent 는 style 을 받지 않는다).
        'list_summary_lines' => [self::LINES_DEFAULT, 1, 3],
        'random_count' => [4, 1, 12],
        'random_area_px' => [960, self::WIDTH_MIN, self::WIDTH_MAX],
        'random_columns' => [4, 2, 6],
        'random_recent_n' => [100, 10, self::ALL_CAP],
        'random_ttl_minutes' => [10, 1, 120],
    ];

    /**
     * 정규화된 디자인 설정.
     *
     * @param  array<string, mixed>  $raw  설정 파일 값
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $out = [
            'list_width_mode' => self::oneOf($raw['list_width_mode'] ?? null, self::WIDTH_MODES, self::WIDTH_CURRENT),
            'list_thumb_mode' => self::oneOf($raw['list_thumb_mode'] ?? null, self::THUMB_MODES, self::THUMB_FIT),
            'random_badge_enabled' => self::bool($raw['random_badge_enabled'] ?? true),
            'random_badge_text' => self::badgeText($raw['random_badge_text'] ?? null),
            'random_enabled' => self::bool($raw['random_enabled'] ?? false),
            'random_board_ids' => self::boardIds($raw['random_board_ids'] ?? []),
            'random_area_mode' => self::oneOf($raw['random_area_mode'] ?? null, self::AREA_MODES, self::AREA_LIST),
            'random_ratio' => self::oneOf($raw['random_ratio'] ?? null, self::RATIOS, '1:1'),
            'random_heading_enabled' => self::bool($raw['random_heading_enabled'] ?? false),
            'random_heading_text' => self::safeHeading((string) ($raw['random_heading_text'] ?? '')),
            'random_range_mode' => self::oneOf($raw['random_range_mode'] ?? null, self::RANGE_MODES, self::RANGE_RECENT),
            'random_only_images' => self::bool($raw['random_only_images'] ?? false),
            'random_exclude_notice' => self::bool($raw['random_exclude_notice'] ?? true),
            'random_ttl_mode' => self::oneOf($raw['random_ttl_mode'] ?? null, self::TTL_MODES, self::TTL_FIXED),
        ];

        foreach (self::INT_RANGES as $key => [$default, $min, $max]) {
            $out[$key] = self::clampInt($raw[$key] ?? null, $default, $min, $max);
        }

        return $out;
    }

    /**
     * 현재 저장된 설정의 정규화 값.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $raw = function_exists('plugin_settings') ? plugin_settings(WebzineSettings::IDENTIFIER) : [];

        return self::normalize(is_array($raw) ? $raw : []);
    }

    /**
     * 랜덤 박스 후보 범위(건수). "전체" 는 {@see self::ALL_CAP} 건까지다.
     *
     * @param  array<string, mixed>  $design
     */
    public static function candidateLimit(array $design): int
    {
        return $design['random_range_mode'] === self::RANGE_ALL ? self::ALL_CAP : (int) $design['random_recent_n'];
    }

    /**
     * 캐시 키에 쓰는 설정 지문 (랜덤 결과에 영향을 주는 값만).
     *
     * @param  array<string, mixed>  $design
     */
    public static function randomFingerprint(array $design): string
    {
        return substr(sha1(json_encode([
            $design['random_count'],
            $design['random_range_mode'],
            $design['random_recent_n'],
            $design['random_only_images'],
            $design['random_exclude_notice'],
            $design['random_ttl_mode'],
            $design['random_ttl_minutes'],
        ])), 0, 12);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (! is_numeric($value)) {
            return $default;
        }
        $n = (int) $value;

        return min($max, max($min, $n));
    }

    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    /**
     * @return array<int, int>
     */
    private static function boardIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 배지 문구 정리 — 레이아웃 text(글자 노드)로만 나가며, 표현식·다국어 표식·제어 문자를 지우고
     * 연속 공백을 하나로 줄인 뒤 {@see self::BADGE_MAX} 자로 자른다. 비면 기본 문구.
     */
    public static function badgeText(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = str_replace(['{{', '}}'], '', $text);
        $text = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text);
        while (str_starts_with($text, '$')) {
            $text = ltrim(substr($text, 1));
        }
        $text = trim(mb_substr($text, 0, self::BADGE_MAX));

        return $text === '' ? self::BADGE_DEFAULT : $text;
    }

    /**
     * 머리 문구 — 레이아웃 text 로 흘러가므로 표현식·다국어 표식을 지운다.
     */
    private static function safeHeading(string $text): string
    {
        $text = str_replace(['{{', '}}'], '', $text);
        $text = preg_replace('/[\x00-\x1f]+/', ' ', $text) ?? '';
        $text = trim($text);
        if (str_starts_with($text, '$')) {
            $text = ltrim($text, '$ ');
        }

        return mb_substr($text, 0, self::HEADING_MAX);
    }
}
