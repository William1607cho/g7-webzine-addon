<?php

namespace Plugins\G7\Webzine\Addon\Console\Commands;

use Illuminate\Console\Command;
use Plugins\G7\Webzine\Addon\Services\FallbackImageService;
use Plugins\G7\Webzine\Addon\Services\FallbackThumbBuilder;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 이미 저장된 대체 이미지의 목록용 파생본을 만든다 (1.2.0 신설).
 *
 * 1.2.0 부터는 관리자 설정에서 대체 이미지를 저장할 때 파생본이 함께 만들어진다.
 * 하지만 **1.1.0 에서 이미 올려 둔 이미지**는 그 경로를 타지 않았으므로 파생본이 없다.
 * 이 명령이 그 한 번을 메운다. 이미지를 다시 올리게 하지 않으려는 것이다.
 *
 * 파생본이 없으면 목록은 원본 주소를 그대로 쓴다 — 화면은 정상이고 바이트만 크다.
 * 그래서 이 명령은 **선택 사항**이며, 돌리지 않아도 1.1.0 과 같은 동작이 유지된다.
 *
 * 공개 경로에서는 만들지 않는다. 생성 지점은 관리자 설정 저장과 이 명령뿐이다.
 */
class BuildFallbackThumbCommand extends Command
{
    protected $signature = 'g7-webzine-addon:build-fallback-thumb
        {--dry-run : 만들지 않고 계획만 출력한다}';

    protected $description = '대체 이미지의 목록용 파생본(가로 240 WebP)을 만듭니다 (기존 설치 1회용)';

    public function handle(FallbackThumbBuilder $builder, FallbackImageService $images): int
    {
        $settings = WebzineSettings::all();
        $dryRun = (bool) $this->option('dry-run');

        if ($settings['fallback_source'] !== WebzineSettings::SOURCE_UPLOAD) {
            $this->info('대체 이미지가 URL 방식이라 파생본을 만들 것이 없습니다.');

            return self::SUCCESS;
        }

        $source = $settings['fallback_upload_path'];

        if ($source === '' || ! $images->exists($source)) {
            $this->info('저장된 대체 이미지가 없습니다.');

            return self::SUCCESS;
        }

        if ($settings['fallback_thumb_path'] !== '' && $images->exists($settings['fallback_thumb_path'])) {
            $this->info('파생본이 이미 있습니다. 할 일이 없습니다.');
            $this->line('  '.WebzineSettings::SERVE_PREFIX.$settings['fallback_thumb_version']);

            return self::SUCCESS;
        }

        if (! $builder->isUsable()) {
            $this->error('imagick 을 쓸 수 없어 파생본을 만들 수 없습니다. 목록은 원본 주소를 계속 씁니다.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('[모의] 저장된 대체 이미지 1건에 대해 가로 '.FallbackThumbBuilder::WIDTH.' WebP 파생본을 만듭니다.');
            $this->line('  원본 경로: '.$source);
            $this->line('  ※ 원본 가로가 '.FallbackThumbBuilder::WIDTH.' 이하이면 만들지 않고 원본을 그대로 씁니다.');

            return self::SUCCESS;
        }

        $thumb = $builder->build($source);

        if ($thumb === null) {
            $this->warn('파생본을 만들지 않았습니다 (원본이 이미 작거나 읽지 못했습니다). 목록은 원본 주소를 씁니다.');

            return self::SUCCESS;
        }

        // 설정에 경로·버전을 적어야 목록이 파생본 주소를 쓴다.
        $saved = $this->persist($thumb['path'], $thumb['version']);

        $this->info(sprintf(
            '파생본 생성 완료 — %dx%d, %s bytes',
            $thumb['width'],
            $thumb['height'],
            number_format($thumb['bytes'])
        ));
        $this->line('  '.WebzineSettings::SERVE_PREFIX.$thumb['version']);

        if (! $saved) {
            $this->warn('설정에 파생본 경로를 저장하지 못했습니다. 관리자 설정 화면에서 한 번 저장하면 반영됩니다.');
        }

        return self::SUCCESS;
    }

    /**
     * 파생본 경로·버전을 플러그인 설정에 기록합니다.
     *
     * 코어의 정식 저장 경로(`PluginSettingsService::save()`)를 쓴다 — 관리자 화면이 쓰는
     * 것과 같은 경로라 설정 파일 형식·훅·캐시 무효화가 모두 따라온다.
     *
     * **기존 설정을 통째로 덮지 않는다.** 현재 값을 읽어 두 키만 더한 뒤 저장한다.
     * 실패해도 명령을 실패로 만들지 않는다 — 파생본 파일은 이미 만들어졌고, 관리자 화면에서
     * 한 번 저장하면 채워진다.
     */
    private function persist(string $path, string $version): bool
    {
        try {
            $service = app(\App\Services\PluginSettingsService::class);
            $current = $service->get(WebzineSettings::IDENTIFIER) ?? [];

            return $service->save(WebzineSettings::IDENTIFIER, array_merge($current, [
                'fallback_thumb_path' => $path,
                'fallback_thumb_version' => $version,
            ]));
        } catch (\Throwable $e) {
            return false;
        }
    }
}
