<?php

namespace Plugins\G7\Webzine\Addon\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 대체 이미지 파일 저장·정리·서빙 서비스 (v1.1.0 신설).
 *
 * 파일은 이 플러그인 스토리지의 단일 카테고리(`fallback`)에만 저장한다. 저장 파일명은
 * **서버가 생성**한다(UUID + 검증된 확장자) — 원본 파일명은 표시용으로 설정에만 남기고
 * 경로에 쓰지 않아, 원본 이름을 통한 경로 조작·확장자 위장 경로가 원천적으로 불가능하다.
 *
 * 파일 내용의 sha256 앞 16자를 "버전"으로 삼아 공개 URL 경로에 넣는다
 * ({@see WebzineSettings::fallbackImageUrl()}). 이미지를 교체하면 내용이 달라져 URL 이
 * 바뀌므로, 1년 immutable 캐시를 걸어 두고도 교체 즉시 새 이미지가 보인다.
 */
class FallbackImageService
{
    /**
     * @param  StorageInterface  $storage  플러그인 스토리지 드라이버(BasePluginServiceProvider 주입)
     */
    public function __construct(
        protected StorageInterface $storage,
        protected FallbackThumbBuilder $thumbBuilder,
    ) {}

    /**
     * 업로드된 이미지를 저장하고 설정에 넣을 메타를 돌려줍니다.
     *
     * 호출 전에 {@see \Plugins\G7\Webzine\Addon\Http\Requests\FallbackImageUploadRequest}
     * 가 확장자·실제 MIME·크기를 모두 검증한다.
     *
     * 1.2.0 부터 목록용 파생본(가로 240 WebP)도 함께 만든다. 만들지 못한 경우
     * (imagick 없음·원본이 240 이하)에는 `thumb_*` 가 빈 문자열이고, 목록은 원본 주소를 쓴다.
     *
     * @param  UploadedFile  $file  검증이 끝난 업로드 파일
     * @return array{path: string, version: string, name: string, mime: string, url: string,
     *               thumb_path: string, thumb_version: string, thumb_url: string}
     */
    public function store(UploadedFile $file): array
    {
        $binary = file_get_contents($file->getRealPath());
        $extension = $this->resolveExtension($file);
        $version = substr(hash('sha256', $binary), 0, 16);

        // 저장 파일명은 서버 생성 — 원본 파일명은 경로에 쓰지 않는다.
        $path = Str::uuid()->toString().'.'.$extension;

        $this->storage->put(WebzineSettings::STORAGE_CATEGORY, $path, $binary);

        $thumb = $this->thumbBuilder->build($path);

        return [
            'path' => $path,
            'version' => $version,
            'name' => $file->getClientOriginalName(),
            'mime' => WebzineSettings::MIME_MAP[$extension],
            'url' => WebzineSettings::SERVE_PREFIX.$version,
            'thumb_path' => $thumb['path'] ?? '',
            'thumb_version' => $thumb['version'] ?? '',
            'thumb_url' => isset($thumb['version']) ? WebzineSettings::SERVE_PREFIX.$thumb['version'] : '',
        ];
    }

    /**
     * 남겨 둘 경로를 뺀 나머지 대체 이미지 파일을 모두 지웁니다 (교체 시 이전 파일 정리).
     *
     * 파생본도 이 저장소에 함께 있으므로, 호출측은 **원본과 파생본 경로를 모두** 넘겨야 한다.
     *
     * @param  array<int, string>  $keep  남길 상대 경로 목록
     * @return int 삭제한 파일 수
     */
    public function pruneExcept(array $keep): int
    {
        $keep = array_filter(array_map('strval', $keep));
        $deleted = 0;

        foreach ($this->storage->files(WebzineSettings::STORAGE_CATEGORY) as $file) {
            $relative = $this->toRelative($file);

            if ($relative === '' || in_array($relative, $keep, true)) {
                continue;
            }

            if ($this->storage->delete(WebzineSettings::STORAGE_CATEGORY, $relative)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 내용 해시가 `$version` 인 파일을 저장소에서 찾습니다 (없으면 null).
     *
     * 평소에는 쓰지 않는 느린 경로다 — 설정에 저장된 버전은 경로가 함께 저장돼 있어
     * 곧바로 열면 된다. 이 스캔은 **아직 저장하지 않은 업로드를 관리자 설정 화면이
     * 미리보기로 부를 때**만 탄다. {@see self::pruneExcept()} 가 저장소를 한두 개로
     * 유지하므로 스캔 비용은 사실상 고정이다.
     */
    public function findByVersion(string $version): ?string
    {
        if ($version === '') {
            return null;
        }

        foreach ($this->storage->files(WebzineSettings::STORAGE_CATEGORY) as $file) {
            $relative = $this->toRelative($file);

            if ($relative === '') {
                continue;
            }

            $binary = $this->storage->get(WebzineSettings::STORAGE_CATEGORY, $relative);

            if ($binary !== null && substr(hash('sha256', $binary), 0, 16) === $version) {
                return $relative;
            }
        }

        return null;
    }

    /**
     * 저장된 대체 이미지를 스트리밍 응답으로 만듭니다.
     *
     * URL 에 내용 해시가 들어 있으므로 1년 immutable 로 캐시해도 교체가 즉시 반영된다.
     *
     * @param  string  $path  스토리지 상대 경로 (정규화 완료 값)
     * @param  string  $downloadName  내려보낼 파일명
     */
    public function response(string $path, string $downloadName): ?StreamedResponse
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = WebzineSettings::MIME_MAP[$extension] ?? 'application/octet-stream';

        return $this->storage->response(
            WebzineSettings::STORAGE_CATEGORY,
            $path,
            $downloadName !== '' ? $downloadName : basename($path),
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * 대체 이미지 저장소를 통째로 비웁니다 (플러그인 제거 시).
     */
    public function deleteAll(): void
    {
        $this->storage->deleteAll(WebzineSettings::STORAGE_CATEGORY);
    }

    /**
     * 파일이 스토리지에 있는지 확인합니다.
     */
    public function exists(string $path): bool
    {
        return $path !== '' && $this->storage->exists(WebzineSettings::STORAGE_CATEGORY, $path);
    }

    /**
     * 업로드 파일의 확장자를 허용 목록 안으로 확정합니다.
     *
     * 클라이언트 확장자를 먼저 보되, 허용 목록에 없으면 실제 MIME 으로 되돌린다
     * (검증을 통과했으므로 둘 중 하나는 반드시 허용 값이다).
     */
    private function resolveExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, WebzineSettings::ALLOWED_EXTENSIONS, true)) {
            return $extension;
        }

        $byMime = array_search((string) $file->getMimeType(), WebzineSettings::MIME_MAP, true);

        return is_string($byMime) ? $byMime : 'png';
    }

    /**
     * `files()` 가 돌려주는 경로를 카테고리 기준 상대 경로로 바꿉니다.
     *
     * 드라이버는 디스크 루트 기준 경로(`g7-webzine-addon/fallback/<파일>`)를 돌려주는데,
     * `delete()`·`exists()` 는 **카테고리 기준 상대 경로**를 받는다. 그대로 넘기면 경로가
     * 이중으로 겹쳐(`…/fallback/g7-webzine-addon/fallback/<파일>`) 아무것도 지워지지 않는다.
     * 마지막 `/fallback/` 뒤를 잘라 두 표기(디스크 기준·절대경로) 모두를 흡수한다.
     */
    private function toRelative(string $file): string
    {
        $needle = '/'.WebzineSettings::STORAGE_CATEGORY.'/';
        $position = strrpos($file, $needle);

        if ($position !== false) {
            $file = substr($file, $position + strlen($needle));
        }

        return ltrim($file, '/');
    }
}
