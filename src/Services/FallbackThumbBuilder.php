<?php

namespace Plugins\G7\Webzine\Addon\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 대체 이미지의 목록용 파생본(가로 240 WebP)을 만든다 (1.2.0 신설).
 *
 * ## 왜 필요한가
 *
 * 대체 이미지는 목록의 **80×80 CSS px** 박스에 그려진다. 그런데 운영자가 올리는 원본은
 * 그보다 훨씬 크다(blog 실측: 335×335, 135,548 B). 목록 한 페이지마다 그 바이트가
 * 그대로 나간다 — 썸네일이 있는 행에서는 가려져 보이지도 않는데.
 *
 * 그래서 저장 시점에 가로 240(80 CSS px × DPR3) WebP 파생본을 함께 만들어 목록에 쓴다.
 * **원본은 지우지 않는다** — 설정 화면 미리보기와 원본 교체 흐름이 그대로 동작해야 한다.
 *
 * ## imagick 을 쓴다
 *
 * 이 환경의 GD 는 번들 빌드라 **WebP 인코더가 없다**(같은 이유로 g7-image-delivery 도
 * imagick 을 쓴다). imagick 이 없는 사이트에서는 파생본을 만들지 않고 경고만 남긴다 —
 * 그런 사이트는 목록이 원본 주소를 그대로 쓰며, 1.1.0 과 같은 동작이 된다.
 *
 * ## 공개 경로에서 만들지 않는다
 *
 * 생성 지점은 두 곳뿐이다: 관리자 설정 저장(업로드 직후)과 artisan 명령
 * ({@see \Plugins\G7\Webzine\Addon\Console\Commands\BuildFallbackThumbCommand}).
 * 방문자가 때리는 서빙 경로는 이미 만들어 둔 파일만 내보낸다.
 */
class FallbackThumbBuilder
{
    /**
     * 파생본 가로 (px).
     *
     * 목록 썸네일 박스가 `w-20 h-20` = 80×80 CSS px 이므로 DPR3 까지 덮는다.
     * g7-image-delivery 의 목록 썸네일 폭과 같은 값이고 같은 근거다.
     */
    public const WIDTH = 240;

    /**
     * 파생본 품질 (WebP).
     */
    private const QUALITY = 82;

    public function __construct(
        protected StorageInterface $storage,
    ) {}

    /**
     * imagick 을 쓸 수 있는지.
     */
    public function isUsable(): bool
    {
        return class_exists(\Imagick::class);
    }

    /**
     * 원본에서 파생본을 만들어 저장하고 메타를 돌려줍니다.
     *
     * 아래 경우에는 **파생본을 만들지 않고 `null` 을 돌려준다.** 호출측은 그때 원본
     * 주소를 그대로 쓰면 된다.
     *
     *  - imagick 이 없다
     *  - 원본 가로가 {@see self::WIDTH} 이하다 (확대하지 않는다)
     *  - 원본을 읽거나 인코딩하지 못했다
     *
     * @param  string  $sourcePath  원본의 스토리지 상대 경로
     * @return array{path: string, version: string, width: int, height: int, bytes: int}|null
     */
    public function build(string $sourcePath): ?array
    {
        if ($sourcePath === '' || ! $this->storage->exists(WebzineSettings::STORAGE_CATEGORY, $sourcePath)) {
            return null;
        }

        if (! $this->isUsable()) {
            Log::warning('[g7-webzine-addon] imagick 이 없어 대체 이미지 파생본을 만들지 못했습니다 — 목록은 원본 주소를 씁니다.');

            return null;
        }

        $binary = $this->storage->get(WebzineSettings::STORAGE_CATEGORY, $sourcePath);

        if ($binary === null || $binary === '') {
            return null;
        }

        try {
            $image = new \Imagick;
            $image->readImageBlob($binary);

            // 애니메이션 GIF 는 첫 프레임만 쓴다 — 80×80 목록 썸네일에 애니메이션은 불필요하고,
            // 프레임을 모두 담으면 파생본이 원본보다 커질 수 있다.
            $image = $image->coalesceImages();
            $image->setIteratorIndex(0);

            $srcWidth = (int) $image->getImageWidth();
            $srcHeight = (int) $image->getImageHeight();

            if ($srcWidth <= 0 || $srcHeight <= 0) {
                $image->clear();

                return null;
            }

            if ($srcWidth <= self::WIDTH) {
                // 확대하지 않는다. 원본이 이미 목록에 알맞다.
                $image->clear();

                return null;
            }

            $outHeight = max(1, (int) round($srcHeight * self::WIDTH / $srcWidth));

            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(self::QUALITY);
            $image->stripImage();
            $image->resizeImage(self::WIDTH, $outHeight, \Imagick::FILTER_LANCZOS, 1);

            $blob = $image->getImageBlob();
            $image->clear();
        } catch (\Throwable $e) {
            Log::warning('[g7-webzine-addon] 대체 이미지 파생본 생성 실패', ['error' => $e->getMessage()]);

            return null;
        }

        if ($blob === '' ) {
            return null;
        }

        $version = substr(hash('sha256', $blob), 0, 16);
        $path = pathinfo($sourcePath, PATHINFO_FILENAME).'-'.self::WIDTH.'.webp';

        if (! $this->storage->put(WebzineSettings::STORAGE_CATEGORY, $path, $blob)) {
            return null;
        }

        return [
            'path' => $path,
            'version' => $version,
            'width' => self::WIDTH,
            'height' => $outHeight,
            'bytes' => strlen($blob),
        ];
    }
}
