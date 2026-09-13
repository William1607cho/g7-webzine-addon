<?php

namespace Plugins\G7\Webzine\Addon\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Board\Models\Post;
use Plugins\G7\Webzine\Addon\Plugin;

/**
 * webzine 게시판에 한해 본문 첫 이미지 썸네일 캐시(`content_thumbnail_url`)가
 * 외부(타 사이트) origin 이미지도 후보로 삼도록 허용하는 필터 리스너.
 *
 * `App\Support\HtmlImageExtractor::firstInternal()` (sirsoft-board 코어가 Post 저장 시점에
 * 호출)은 기본적으로 **동일 origin(자체 업로드) 이미지만** 통과시킨다
 * ({@see \App\Support\HtmlImageExtractor} 클래스 docblock). `Post` 모델의 `saving` 이벤트가
 * 그 결과를 `sirsoft-board.post.filter_content_thumbnail` 필터에 태워 확장이 후보를 대체할
 * 여지를 열어두고 있어, 이 리스너는 firstInternal 이 내부 이미지를 찾지 못했을 때
 * (= $value 가 null)만 개입해 webzine 게시판의 raw 후보 목록(`candidates`, 필터링 전 원본
 * img src 목록, 문서 순서)에서 안전한 http(s) 외부 이미지를 찾아 대신 반환한다.
 *
 * 다른 board 타입(basic/forum/gallery/card 등)의 기존 "내부 이미지만" 동작에는 전혀
 * 영향이 없다 — `$post->board->type` 이 webzine 이 아니면 원래 값을 그대로 돌려준다.
 *
 * data:/javascript:/blob: 등 위험 스킴은 "외부 이미지 허용" 범위 밖이므로 여기서도 계속
 * 제외한다 — 원본 유틸이 이미 이 스킴들을 제외해 두고 있으므로 raw candidates 목록에서
 * 이 리스너가 다시 스킴 검사를 해야 한다.
 */
class WebzineThumbnailListener implements HookListenerInterface
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.filter_content_thumbnail' => [
                'method' => 'allowExternalCandidateForWebzine',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void
    {
        // 이 리스너는 filter 훅만 구독한다.
    }

    /**
     * webzine 게시판에 한해, 내부 이미지 후보가 없을 때 외부 이미지 후보를 대신 허용한다.
     *
     * @param  mixed  $value  코어가 먼저 계산한 내부 이미지 후보 (없으면 null)
     * @param  Post  $post  대상 게시글 (저장 중, board 관계는 지연 로딩됨)
     * @param  array<int, string>  $candidates  본문의 모든 img src 원본 목록 (문서 순서, 필터링 전)
     * @return mixed 최종 썸네일 후보 (Post::booted() 의 saving 리스너가 길이·타입을 다시 검증한다)
     */
    public function allowExternalCandidateForWebzine(mixed $value, Post $post, array $candidates): mixed
    {
        // 코어가 이미 내부 이미지를 찾았으면 그대로 둔다 — 내부 이미지 우선 정책 유지.
        if ($value !== null) {
            return $value;
        }

        if (($post->board?->type) !== Plugin::WEBZINE_BOARD_TYPE) {
            return $value;
        }

        return $this->firstSafeExternalCandidate($candidates) ?? $value;
    }

    /**
     * 원본 후보 목록에서 안전한 http(s) 외부 이미지 URL을 문서 순서로 첫 번째만 반환한다.
     *
     * data:/javascript:/blob: 등 http(s) 가 아닌 스킴은 제외한다. protocol-relative(`//host/x`)
     * 는 https 로 정규화해 반환한다 — 상세 페이지 렌더러가 이미 같은 방식으로 해석한다.
     *
     * @param  array<int, string>  $candidates
     */
    private function firstSafeExternalCandidate(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $trimmed = trim((string) $candidate);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '//')) {
                return 'https:'.$trimmed;
            }

            $scheme = parse_url($trimmed, PHP_URL_SCHEME);

            if (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
                return $trimmed;
            }
        }

        return null;
    }
}
