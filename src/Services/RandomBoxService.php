<?php

namespace Plugins\G7\Webzine\Addon\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Plugins\G7\Webzine\Addon\Plugin;
use Plugins\G7\Webzine\Addon\Support\RandomBoxPicker;
use Plugins\G7\Webzine\Addon\Support\WebzineDesignSettings;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 랜덤 박스 데이터 (1.5.0).
 *
 * 열람 권한은 라우트 미들웨어(코어 `posts.read`)가 이미 판정했다. 글 목록은 코어 경로
 * (`PostService::getPosts` → `PostResource::toListArray`)만 쓰고, 비밀글 판정·마스킹을 다시
 * 구현하지 않는다(코어 값을 쓰고, 응답에서 더 가리기만 한다).
 *
 * 캐시 키는 게시판·설정 지문·사용자 단위다(권한 범위가 사용자마다 다를 수 있다). 결과에는 비밀글
 * 썸네일이 들어가지 않는다({@see RandomBoxPicker::toItem()}).
 */
final class RandomBoxService
{
    private const CACHE_PREFIX = 'g7wz:randombox:';

    /** 새로고침 모드에서 후보를 재사용하는 시간(초) — 요청마다 후보를 다시 읽지 않는다 */
    private const POOL_TTL = 60;

    public function __construct(
        private readonly BoardService $boards,
        private readonly PostService $posts,
        private readonly ThumbnailLookup $thumbnails,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, cache: string} 응답 값
     */
    public function forBoard(string $slug): array
    {
        $design = WebzineDesignSettings::current();
        $board = $design['random_enabled'] ? $this->targetBoard($slug, $design) : null;
        if ($board === null) {
            return ['items' => [], 'cache' => 'off'];
        }

        $base = self::CACHE_PREFIX.$board->id.':'.WebzineDesignSettings::randomFingerprint($design).':u'.(Auth::id() ?? 'guest');

        if ($design['random_ttl_mode'] === WebzineDesignSettings::TTL_FIXED) {
            return $this->fixedBundle($base, $board, $design);
        }

        return $this->freshBundle($base, $board, $design);
    }

    /**
     * @param  array<string, mixed>  $design
     */
    private function targetBoard(string $slug, array $design): ?Board
    {
        $board = $this->boards->getBoardBySlug($slug, checkScope: false);
        if ($board === null || ! $board->is_active || $board->type !== Plugin::WEBZINE_BOARD_TYPE) {
            return null;
        }

        return in_array((int) $board->id, $design['random_board_ids'], true) ? $board : null;
    }

    /**
     * 고정 시간 모드 — 같은 시간 구간에는 같은 묶음을 돌려준다.
     *
     * @param  array<string, mixed>  $design
     * @return array{items: array<int, array<string, mixed>>, cache: string}
     */
    private function fixedBundle(string $base, Board $board, array $design): array
    {
        $now = time();
        $ttl = (int) $design['random_ttl_minutes'];
        $bucket = RandomBoxPicker::bucket($now, $ttl);
        $key = $base.':f:'.$bucket;

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ['items' => $cached, 'cache' => 'hit'];
        }

        $items = $this->buildItems($this->loadPool($board, $design), $design, 'b'.$bucket.':'.$board->id);
        Cache::put($key, $items, RandomBoxPicker::secondsToBucketEnd($now, $ttl));

        return ['items' => $items, 'cache' => 'miss'];
    }

    /**
     * 새로고침 모드 — 묶음은 매번 새로, 후보만 잠깐 재사용한다.
     *
     * @param  array<string, mixed>  $design
     * @return array{items: array<int, array<string, mixed>>, cache: string}
     */
    private function freshBundle(string $base, Board $board, array $design): array
    {
        $key = $base.':p';
        $pool = Cache::get($key);
        $hit = is_array($pool);
        if (! $hit) {
            $pool = $this->loadPool($board, $design);
            Cache::put($key, $pool, self::POOL_TTL);
        }

        return ['items' => $this->buildItems($pool, $design, bin2hex(random_bytes(8))), 'cache' => $hit ? 'hit' : 'miss'];
    }

    /**
     * 후보 불러오기 (코어 경로).
     *
     * @param  array<string, mixed>  $design
     * @return array<int, array<string, mixed>>
     */
    private function loadPool(Board $board, array $design): array
    {
        $params = $this->posts->buildListParams([], ['context' => 'user', 'board' => $board]);
        $page = $this->posts->getPosts($board->slug, $params['filters'], WebzineDesignSettings::candidateLimit($design), false, 'user', $board);

        $rows = [];
        foreach ($page as $post) {
            $post->setRelation('board', $board);
            $rows[] = (new PostResource($post))->toListArray(request());
        }

        return RandomBoxPicker::candidates($rows, $design);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pool
     * @param  array<string, mixed>  $design
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $pool, array $design, string $seed): array
    {
        $picked = RandomBoxPicker::pick($pool, (int) $design['random_count'], $seed);

        $urls = [];
        foreach ($picked as $row) {
            if (empty($row['is_secret']) && is_string($row['thumbnail'] ?? null)) {
                $urls[] = $row['thumbnail'];
            }
        }
        $variants = $this->thumbnails->lookup(array_values(array_unique($urls)));

        $settings = WebzineSettings::all();
        $fallback = WebzineSettings::effectiveMode($settings) === WebzineSettings::MODE_IMAGE
            ? WebzineSettings::fallbackImageUrl($settings)
            : null;

        $items = [];
        foreach ($picked as $row) {
            $thumb = is_string($row['thumbnail'] ?? null) ? $row['thumbnail'] : null;
            $url = $thumb !== null ? (RandomBoxPicker::chooseVariantUrl($variants[$thumb] ?? null) ?? $thumb) : null;
            $items[] = RandomBoxPicker::toItem($row, $url, $fallback);
        }

        return $items;
    }
}
