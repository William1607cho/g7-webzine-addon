<?php

namespace Plugins\G7\Webzine\Addon\Services;

use App\Contracts\Repositories\PluginRepositoryInterface;
use Throwable;

/**
 * g7-image-delivery 공개 계약 연결부 (1.5.0).
 *
 * 내부 모델·표는 읽지 않는다. `Plugins\G7\Image\Delivery\PublicApi\ThumbnailVariants`
 * (`VERSION >= 1`, `lookup(urls)`)만 쓴다. 플러그인이 없거나 꺼져 있거나 계약이 없는 옛 판이면 빈
 * 결과를 돌려주고, 호출자는 원본 썸네일 주소를 쓴다 (필수 의존이 아니다).
 */
final class ThumbnailLookup
{
    private const IDENTIFIER = 'g7-image-delivery';

    /** 계약 클래스(문자열로 둔다 — 플러그인이 없어도 이 파일은 읽혀야 한다) */
    private const CONTRACT = 'Plugins\\G7\\Image\\Delivery\\PublicApi\\ThumbnailVariants';

    public function __construct(private readonly PluginRepositoryInterface $plugins) {}

    /**
     * @param  array<int, string>  $urls
     * @return array<string, mixed> 주소 => 계약 출력, 없으면 빈 배열
     */
    public function lookup(array $urls): array
    {
        if ($urls === [] || ! $this->available()) {
            return [];
        }
        try {
            $found = (self::CONTRACT)::lookup($urls);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        return is_array($found) ? $found : [];
    }

    private function available(): bool
    {
        try {
            if ($this->plugins->findActiveByIdentifier(self::IDENTIFIER) === null) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return class_exists(self::CONTRACT) && defined(self::CONTRACT.'::VERSION') && constant(self::CONTRACT.'::VERSION') >= 1;
    }
}
