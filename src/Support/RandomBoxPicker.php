<?php

namespace Plugins\G7\Webzine\Addon\Support;

/**
 * 랜덤 박스 후보 선정·묶음 뽑기·응답 값 계산 (1.5.0).
 *
 * DB·HTTP·캐시를 건드리지 않는다. 글 항목은 호출자가 코어 경로(`PostService`·`PostResource`)로
 * 이미 거른 것만 넘긴다는 전제다 — 여기서는 권한을 판정하지 않고, 응답에 실린 상태 값만 본다.
 *
 * 묶음 뽑기는 시드 해시 정렬이다. `mt_srand` 같은 전역 상태를 쓰지 않아 같은 시드면 항상 같은
 * 묶음이 나오고, 전체 글을 ORDER BY RAND 하지 않는다.
 */
final class RandomBoxPicker
{
    /**
     * 후보 필터: 원글·발행 상태만, 설정에 따라 공지·이미지 없는 글 제외.
     *
     * @param  array<int, array<string, mixed>>  $rows  코어 목록 항목
     * @param  array<string, mixed>  $design  정규화 설정
     * @return array<int, array<string, mixed>>
     */
    public static function candidates(array $rows, array $design): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            if (! empty($row['parent_id']) || ! empty($row['is_reply']) || ($row['status'] ?? null) !== 'published') {
                continue;
            }
            if (! empty($row['deleted_at'])) {
                continue;
            }
            if (! empty($design['random_exclude_notice']) && ! empty($row['is_notice'])) {
                continue;
            }
            if (! empty($design['random_only_images']) && (! empty($row['is_secret']) || empty($row['thumbnail']))) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * 시드로 묶음을 뽑는다. 후보 순서와 무관하게 같은 시드면 같은 결과다.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function pick(array $rows, int $count, string $seed): array
    {
        usort($rows, static fn (array $a, array $b) => strcmp(
            hash('sha256', $seed.':'.$a['id']),
            hash('sha256', $seed.':'.$b['id'])
        ));

        return array_slice($rows, 0, max(0, $count));
    }

    /**
     * 고정 시간 구간 번호 (N분 고정일 때 같은 구간이면 같은 묶음).
     */
    public static function bucket(int $now, int $ttlMinutes): int
    {
        return (int) floor($now / max(60, $ttlMinutes * 60));
    }

    /**
     * 구간이 끝날 때까지 남은 초(캐시 TTL, 최소 1).
     */
    public static function secondsToBucketEnd(int $now, int $ttlMinutes): int
    {
        $size = max(60, $ttlMinutes * 60);

        return max(1, $size - ($now % $size));
    }

    /**
     * 응답 항목으로 변환: 화이트리스트 키만 남기고, 비밀글 이미지·이메일을 제거한다.
     *
     * 비밀글은 이미지를 볼 수 있는 사용자에게도 `thumbnail` 을 null 로 둔다(자물쇠를 그리고, 캐시를 공유한다).
     * 썸네일이 없는 공개글에만 대체 이미지를 넣는다.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function toItem(array $row, ?string $thumbnail, ?string $fallbackImage): array
    {
        $secret = ! empty($row['is_secret']);
        $image = $secret ? null : ($thumbnail ?? $fallbackImage);
        $author = is_array($row['author'] ?? null) ? $row['author'] : [];

        return [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'category' => $row['category'] ?? null,
            'is_notice' => ! empty($row['is_notice']),
            'is_secret' => $secret,
            'is_new' => ! empty($row['is_new']),
            'is_reply' => false,
            'status' => 'published',
            'deleted_at' => null,
            'has_attachment' => ! empty($row['has_attachment']),
            'view_count' => (int) ($row['view_count'] ?? 0),
            'comment_count' => (int) ($row['comment_count'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'created_at_formatted' => $row['created_at_formatted'] ?? null,
            'thumbnail' => $image,
            'author' => [
                'uuid' => $author['uuid'] ?? null,
                'name' => $author['name'] ?? null,
                'avatar' => $author['avatar'] ?? null,
                'is_guest' => (bool) ($author['is_guest'] ?? false),
            ],
        ];
    }

    /**
     * 변환본 주소 고르기: 960 → 1600 순. 240 만 있거나 준비 전이면 null(원본 사용).
     *
     * @param  array<string, mixed>|null  $variant  `ThumbnailVariants::lookup()` 항목
     */
    public static function chooseVariantUrl(?array $variant): ?string
    {
        if (! is_array($variant) || empty($variant['available']) || ! is_array($variant['variants'] ?? null)) {
            return null;
        }
        foreach ([960, 1600] as $width) {
            $url = $variant['variants'][$width]['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
