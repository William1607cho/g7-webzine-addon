<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Webzine\Addon\Services\FallbackThumbBuilder;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 대체 이미지 파생본의 크기 계산과 URL 선택 테스트 (1.2.0).
 *
 * 실제 인코딩은 imagick 이 하므로, 여기서는 **파생본을 만들지 말지**와 **어떤 치수가
 * 나와야 하는지**라는 규칙만 고정한다.
 */
class FallbackThumbPlanTest extends TestCase
{
    /**
     * 빌더와 같은 규칙으로 파생본 치수를 계산한다.
     *
     * @return array{int, int}|null 만들지 않으면 null
     */
    private function plan(int $srcWidth, int $srcHeight): ?array
    {
        if ($srcWidth <= 0 || $srcHeight <= 0) {
            return null;
        }

        if ($srcWidth <= FallbackThumbBuilder::WIDTH) {
            return null;   // 확대하지 않는다
        }

        return [
            FallbackThumbBuilder::WIDTH,
            max(1, (int) round($srcHeight * FallbackThumbBuilder::WIDTH / $srcWidth)),
        ];
    }

    public function test_파생본_폭은_목록_썸네일_3배율을_덮는다(): void
    {
        // 목록 썸네일 박스가 80×80 CSS px 이므로 240 이 DPR3 까지 덮는다.
        $this->assertSame(240, FallbackThumbBuilder::WIDTH);
        $this->assertSame(80 * 3, FallbackThumbBuilder::WIDTH);
    }

    public function test_정사각_원본은_정사각_파생본이_된다(): void
    {
        // blog 실측 대체 이미지: 335×335
        $this->assertSame([240, 240], $this->plan(335, 335));
    }

    public function test_가로가_긴_원본은_비율을_지킨다(): void
    {
        $this->assertSame([240, 135], $this->plan(1280, 720));
        $this->assertSame([240, 160], $this->plan(1200, 800));
    }

    public function test_세로가_긴_원본도_비율을_지킨다(): void
    {
        $this->assertSame([240, 320], $this->plan(600, 800));
    }

    public function test_240_이하_원본은_파생본을_만들지_않는다(): void
    {
        $this->assertNull($this->plan(240, 240));
        $this->assertNull($this->plan(120, 90));
    }

    public function test_241_원본은_파생본을_만든다(): void
    {
        $this->assertSame([240, 240], $this->plan(241, 241));
    }

    public function test_치수를_읽지_못하면_만들지_않는다(): void
    {
        $this->assertNull($this->plan(0, 0));
        $this->assertNull($this->plan(500, 0));
    }

    public function test_아주_납작한_원본도_세로가_최소_1이다(): void
    {
        $this->assertSame([240, 1], $this->plan(4000, 3));
    }

    // ── 목록 URL 선택 ───────────────────────────────────────────────────────

    public function test_파생본이_있으면_목록은_파생본_주소를_쓴다(): void
    {
        $settings = $this->settings(['fallback_thumb_version' => 'abcdef0123456789', 'fallback_thumb_path' => 'x-240.webp']);

        $this->assertSame(
            WebzineSettings::SERVE_PREFIX.'abcdef0123456789',
            WebzineSettings::listImageUrl($settings)
        );
    }

    public function test_파생본이_없으면_원본_주소로_폴백한다(): void
    {
        $settings = $this->settings();

        $this->assertSame(
            WebzineSettings::SERVE_PREFIX.'0123456789abcdef',
            WebzineSettings::listImageUrl($settings)
        );
    }

    public function test_URL_방식이면_파생본을_쓰지_않는다(): void
    {
        // 파일이 우리 저장소에 없으므로 파생본이 있을 수 없다.
        $settings = $this->settings([
            'fallback_source' => WebzineSettings::SOURCE_URL,
            'fallback_image_url' => 'https://example.com/a.png',
            'fallback_thumb_version' => 'abcdef0123456789',
            'fallback_thumb_path' => 'x-240.webp',
        ]);

        $this->assertSame('https://example.com/a.png', WebzineSettings::listImageUrl($settings));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return $overrides + [
            'no_thumbnail_mode' => WebzineSettings::MODE_IMAGE,
            'fallback_source' => WebzineSettings::SOURCE_UPLOAD,
            'fallback_upload_path' => 'x.webp',
            'fallback_upload_name' => 'x.webp',
            'fallback_upload_version' => '0123456789abcdef',
            'fallback_image_url' => '',
            'fallback_alt' => '',
            'fallback_thumb_path' => '',
            'fallback_thumb_version' => '',
        ];
    }
}
