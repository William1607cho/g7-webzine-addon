<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 웹진게시판 애드온 설정 해석기 (v1.1.0 신설).
 *
 * 플러그인 설정(`plugin_settings('g7-webzine-addon')`)은 관리자가 자유롭게 넣는 값이고,
 * 이 값들은 곧바로 **레이아웃 JSON 의 props(`src`/`alt`) 와 방문자 화면 DOM** 으로
 * 흘러간다. 그래서 읽는 쪽(레이아웃 리스너·서빙 컨트롤러)이 각자 정규화하지 않고,
 * 이 클래스 한 곳에서만 화이트리스트로 정규화한 값을 돌려준다:
 *
 *  - `no_thumbnail_mode` / `fallback_source` : 정해진 값 목록 밖이면 기본값으로 강등
 *  - `fallback_image_url`                    : `http(s)://` 또는 사이트 내 `/` 로 시작하는
 *                                              것만 허용 (`javascript:`·`data:` 차단)
 *  - `fallback_alt`                          : 표현식 구분자(`{{`·`}}`) 제거 + 길이 제한
 *
 * 특히 `{{`/`}}` 제거는 단순 미관 문제가 아니다 — 레이아웃 렌더러가 props 문자열의
 * `{{...}}` 를 데이터 바인딩 표현식으로 평가하므로, 정규화하지 않으면 관리자 입력이
 * 표현식으로 실행되거나 깨진 값이 화면에 노출된다.
 */
class WebzineSettings
{
    /** 플러그인 식별자 */
    public const IDENTIFIER = 'g7-webzine-addon';

    /** 대체 이미지 파일이 저장되는 플러그인 스토리지 카테고리 */
    public const STORAGE_CATEGORY = 'fallback';

    /** 썸네일 없을 때: 요약만 (썸네일 영역 자체를 렌더링하지 않음) — 기본값 */
    public const MODE_SUMMARY = 'summary';

    /** 썸네일 없을 때: 기본 자리표시자("이미지 없음" 박스) */
    public const MODE_PLACEHOLDER = 'placeholder';

    /** 썸네일 없을 때: 관리자가 지정한 대체 이미지 */
    public const MODE_IMAGE = 'image';

    /** 허용 모드 목록 */
    public const MODES = [self::MODE_SUMMARY, self::MODE_PLACEHOLDER, self::MODE_IMAGE];

    /** 대체 이미지 지정 방식: 업로드 */
    public const SOURCE_UPLOAD = 'upload';

    /** 대체 이미지 지정 방식: URL 입력 */
    public const SOURCE_URL = 'url';

    /** 허용 방식 목록 */
    public const SOURCES = [self::SOURCE_UPLOAD, self::SOURCE_URL];

    /** 업로드 허용 확장자 (SVG 는 스크립트 실행 위험으로 의도적 제외) */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    /** 확장자 → 서빙 Content-Type */
    public const MIME_MAP = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** 업로드 최대 크기 (MB) */
    public const MAX_MB = 2;

    /** 대체 이미지 공개 서빙 라우트 접두사 */
    public const SERVE_PREFIX = '/api/plugins/g7-webzine-addon/fallback-image/';

    /** 대체 텍스트 최대 길이 */
    private const ALT_MAX = 200;

    /**
     * 정규화된 현재 설정을 돌려줍니다.
     *
     * @return array{
     *     no_thumbnail_mode: string,
     *     fallback_source: string,
     *     fallback_upload_path: string,
     *     fallback_upload_name: string,
     *     fallback_upload_version: string,
     *     fallback_image_url: string,
     *     fallback_alt: string
     * }
     */
    public static function all(): array
    {
        $raw = function_exists('plugin_settings') ? plugin_settings(self::IDENTIFIER) : [];
        $raw = is_array($raw) ? $raw : [];

        $mode = (string) ($raw['no_thumbnail_mode'] ?? self::MODE_SUMMARY);
        $source = (string) ($raw['fallback_source'] ?? self::SOURCE_UPLOAD);

        return [
            'no_thumbnail_mode' => in_array($mode, self::MODES, true) ? $mode : self::MODE_SUMMARY,
            'fallback_source' => in_array($source, self::SOURCES, true) ? $source : self::SOURCE_UPLOAD,
            'fallback_upload_path' => self::safeStoragePath((string) ($raw['fallback_upload_path'] ?? '')),
            'fallback_upload_name' => trim((string) ($raw['fallback_upload_name'] ?? '')),
            'fallback_upload_version' => self::safeVersion((string) ($raw['fallback_upload_version'] ?? '')),
            'fallback_image_url' => self::safeUrl((string) ($raw['fallback_image_url'] ?? '')),
            'fallback_alt' => self::safeAlt((string) ($raw['fallback_alt'] ?? '')),
        ];
    }

    /**
     * 실제로 적용할 모드.
     *
     * "대체 이미지" 모드인데 쓸 수 있는 이미지가 없으면(업로드 전, URL 미입력, 값이
     * 검증에 걸려 버려짐) 화면이 빈 회색 박스로 남지 않도록 기본값인 "요약만" 으로
     * 강등한다.
     *
     * @param  array<string, mixed>|null  $settings  미리 읽어 둔 정규화 설정
     */
    public static function effectiveMode(?array $settings = null): string
    {
        $settings ??= self::all();
        $mode = (string) $settings['no_thumbnail_mode'];

        if ($mode === self::MODE_IMAGE && self::fallbackImageUrl($settings) === null) {
            return self::MODE_SUMMARY;
        }

        return $mode;
    }

    /**
     * 방문자 화면에 노출할 대체 이미지 URL (없으면 null).
     *
     * 업로드 방식의 URL 에는 파일 내용 해시(`fallback_upload_version`)가 경로로 들어간다 —
     * 이미지를 교체하면 URL 자체가 바뀌므로 브라우저·CDN 캐시가 즉시 무효화된다.
     *
     * @param  array<string, mixed>|null  $settings  미리 읽어 둔 정규화 설정
     */
    public static function fallbackImageUrl(?array $settings = null): ?string
    {
        $settings ??= self::all();

        if ($settings['fallback_source'] === self::SOURCE_URL) {
            return $settings['fallback_image_url'] !== '' ? $settings['fallback_image_url'] : null;
        }

        if ($settings['fallback_upload_path'] === '' || $settings['fallback_upload_version'] === '') {
            return null;
        }

        return self::SERVE_PREFIX.$settings['fallback_upload_version'];
    }

    /**
     * 대체 이미지의 대체 텍스트(alt). 비어 있으면 사이트명을 쓴다.
     *
     * @param  array<string, mixed>|null  $settings  미리 읽어 둔 정규화 설정
     */
    public static function altText(?array $settings = null): string
    {
        $settings ??= self::all();

        if ($settings['fallback_alt'] !== '') {
            return $settings['fallback_alt'];
        }

        $siteName = function_exists('g7_core_settings')
            ? (string) g7_core_settings('general.site_name', '')
            : '';

        return self::safeAlt($siteName);
    }

    /**
     * 레이아웃에 주입하는 스크립트 URL 의 캐시 지문.
     *
     * 설정이 바뀌면 값이 바뀌어 폴백 스크립트가 새로 로드된다.
     *
     * @param  array<string, mixed>|null  $settings  미리 읽어 둔 정규화 설정
     */
    public static function fingerprint(?array $settings = null): string
    {
        $settings ??= self::all();

        return substr(sha1(json_encode([
            self::effectiveMode($settings),
            self::fallbackImageUrl($settings),
        ])), 0, 12);
    }

    /**
     * 스토리지 상대경로 정규화 — 경로 탈출(`..`)·절대경로·역슬래시를 막는다.
     */
    private static function safeStoragePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9._\/-]{1,180}$/', $path) === 1 ? $path : '';
    }

    /**
     * 버전 해시 정규화 — 16진수 문자열만 허용(서빙 라우트 경로에 그대로 들어감).
     */
    private static function safeVersion(string $version): string
    {
        $version = trim($version);

        return preg_match('/^[a-f0-9]{8,64}$/', $version) === 1 ? $version : '';
    }

    /**
     * 이미지 URL 정규화.
     *
     * 허용: `https://…`, `http://…`, 사이트 내 절대경로 `/…`.
     * 차단: `javascript:`·`data:` 등 위험 스킴, 프로토콜 상대 URL(`//host`), 공백·개행
     * 포함 값, 표현식 구분자(`{{`·`}}`) 포함 값.
     */
    private static function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2000) {
            return '';
        }

        if (preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            return '';
        }

        if (str_contains($url, '{{') || str_contains($url, '}}')) {
            return '';
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        // 사이트 내 절대경로만 허용 — `//host` 형태(프로토콜 상대)는 외부 호스트라 제외.
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return '';
    }

    /**
     * 대체 텍스트 정규화 — 표현식 구분자 제거 + 개행 제거 + 길이 제한.
     */
    private static function safeAlt(string $alt): string
    {
        $alt = str_replace(['{{', '}}'], '', $alt);
        $alt = preg_replace('/[\x00-\x1f]+/', ' ', $alt) ?? '';
        $alt = trim($alt);

        return mb_substr($alt, 0, self::ALT_MAX);
    }
}
