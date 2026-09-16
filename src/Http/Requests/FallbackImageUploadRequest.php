<?php

namespace Plugins\G7\Webzine\Addon\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Plugins\G7\Webzine\Addon\Support\WebzineSettings;

/**
 * 대체 이미지 업로드 검증 (v1.1.0 신설).
 *
 * 세 겹으로 막는다 — 어느 한 겹만으로는 확장자 위장을 못 거르기 때문이다:
 *
 *  1. `extensions:` — 클라이언트 파일명 확장자
 *  2. `mimetypes:`  — 파일 **내용**에서 추론한 실제 MIME (Laravel 이 finfo 로 판정)
 *  3. `getimagesize()` — 실제로 디코드 가능한 래스터 이미지인지 + 판정된 타입이
 *     확장자와 같은 계열인지 (`.png` 로 위장한 SVG/HTML/PHP 는 여기서도 탈락)
 *
 * **SVG 는 의도적으로 불허**한다 — SVG 는 스크립트를 품을 수 있어, 방문자 화면에
 * `<img>` 로 직접 걸리는 이미지로 허용하면 XSS 표면이 된다.
 */
class FallbackImageUploadRequest extends FormRequest
{
    /**
     * 확장자 → 허용되는 `getimagesize()` 판정 타입.
     *
     * @var array<string, array<int, int>>
     */
    private const IMAGE_TYPE_MAP = [
        'png' => [IMAGETYPE_PNG],
        'jpg' => [IMAGETYPE_JPEG],
        'jpeg' => [IMAGETYPE_JPEG],
        'gif' => [IMAGETYPE_GIF],
        'webp' => [IMAGETYPE_WEBP],
    ];

    /**
     * 권한은 라우트 미들웨어(`permission:admin,core.plugins.update`)가 검증한다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fallback_image' => [
                'required',
                'file',
                'extensions:'.implode(',', WebzineSettings::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', array_values(array_unique(WebzineSettings::MIME_MAP))),
                'max:'.(WebzineSettings::MAX_MB * 1024),
            ],
        ];
    }

    /**
     * 내용 기반 최종 재검사 — 실제로 디코드되는 이미지인지, 확장자와 내용이 같은지.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('fallback_image')) {
                return; // 앞 규칙에서 이미 탈락 — 메시지를 겹쳐 쌓지 않는다.
            }

            $file = $this->file('fallback_image');

            if ($file === null) {
                return;
            }

            $extension = strtolower($file->getClientOriginalExtension());

            if (! array_key_exists($extension, self::IMAGE_TYPE_MAP)) {
                $validator->errors()->add('fallback_image', $this->extensionMessage());

                return;
            }

            $info = @getimagesize($file->getRealPath());

            if ($info === false || ! isset($info[2])) {
                $validator->errors()->add(
                    'fallback_image',
                    __('g7-webzine-addon::messages.fallback.not_an_image')
                );

                return;
            }

            if (! in_array((int) $info[2], self::IMAGE_TYPE_MAP[$extension], true)) {
                $validator->errors()->add(
                    'fallback_image',
                    __('g7-webzine-addon::messages.fallback.extension_mismatch')
                );
            }
        });
    }

    /**
     * 오류 메시지.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fallback_image.required' => __('g7-webzine-addon::messages.fallback.required'),
            'fallback_image.file' => __('g7-webzine-addon::messages.fallback.invalid_file'),
            'fallback_image.extensions' => $this->extensionMessage(),
            'fallback_image.mimetypes' => $this->extensionMessage(),
            'fallback_image.max' => __('g7-webzine-addon::messages.fallback.too_large', [
                'max' => WebzineSettings::MAX_MB,
            ]),
        ];
    }

    /**
     * 확장자 거부 메시지.
     */
    private function extensionMessage(): string
    {
        return __('g7-webzine-addon::messages.fallback.invalid_extension', [
            'allowed' => implode(', ', WebzineSettings::ALLOWED_EXTENSIONS),
        ]);
    }
}
