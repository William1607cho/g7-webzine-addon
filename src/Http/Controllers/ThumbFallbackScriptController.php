<?php

namespace Plugins\G7\Webzine\Addon\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\Response;

/**
 * 썸네일 로드 실패 폴백 스크립트 서빙 컨트롤러 (공개, v1.1.0 신설).
 *
 * GET /api/plugins/g7-webzine-addon/thumb-fallback.js
 *
 * **왜 스크립트가 필요한가**: 레이아웃 JSON 의 액션 이벤트 타입에는 `error` 가 없다
 * (지원 목록: click, change, input, submit, focus, blur, keydown, keyup, keypress, mouseenter, mouseleave). 그래서 "썸네일 URL 은
 * 있는데 그 이미지가 404 로 죽은" 경우 — 외부 이미지가 지워진 글 — 는 JSON 만으로는
 * 감지할 수 없고, 회색 깨진 이미지 박스가 그대로 남는다.
 *
 * **왜 이렇게 작은가**: 레이아웃 쪽에서 세 모드를 이미 서버 렌더로 갈라 두었고(설정에
 * 따라 자리표시자/대체 이미지를 *바탕 레이어*로 깔아 둔다), 썸네일 `<img>` 는 그 위에
 * 겹쳐 그린다. 그래서 이 스크립트가 할 일은 **실패한 이미지를 감추는 것뿐**이다 —
 * DOM 을 새로 만들지 않으므로 템플릿·모듈의 렌더 트리와 경합하지 않는다.
 *
 *  - 썸네일 실패 → 감춤 → 아래 바탕 레이어(자리표시자 또는 대체 이미지)가 드러남
 *  - "요약만" 모드는 바탕 레이어가 없으므로 썸네일 박스째 감춤 → 텍스트가 전체 폭 사용
 *  - 썸네일과 바탕이 **둘 다** 죽었을 때만 박스째 감춰 "요약만" 으로 수렴한다. 대체
 *    이미지 하나가 깨졌다고 썸네일이 멀쩡한 행까지 접어 버리면 안 되므로, 실패를 표시해
 *    두고 박스 단위로 다시 판단한다.
 *
 * URL 에 설정 지문(`?v=`)이 붙어 있어 설정이 바뀌면 새 파일로 받는다.
 */
class ThumbFallbackScriptController extends PublicBaseController
{
    /**
     * 폴백 스크립트를 내려줍니다.
     */
    public function show(): Response
    {
        $script = $this->buildScript();

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * 스크립트 본문을 조립합니다.
     *
     * **설정값을 주입하지 않는다**(1.2.0). 1.1.0 은 모드 문자열을 리터럴로 박아 넣어
     * 바탕 레이어의 존재를 추론했는데, 이제는 스크립트가 DOM 에서 바탕을 직접 찾는다
     * (`.g7-webzine-base`). 주입할 값이 없어지면서 설정값이 스크립트 문법에 닿을 여지도
     * 함께 사라졌다.
     */
    private function buildScript(): string
    {
        return <<<JS
        /* g7-webzine-addon — 웹진 목록 썸네일 로드 실패 폴백 */
        (function () {
            'use strict';

            if (window.__g7WebzineThumbFallback) {
                return;
            }
            window.__g7WebzineThumbFallback = true;

            var THUMB_CLASS = 'g7-webzine-thumb';
            var FALLBACK_CLASS = 'g7-webzine-fallback';
            var BASE_CLASS = 'g7-webzine-base';
            var BOX_SELECTOR = '.g7-webzine-thumbbox';
            var FAILED_ATTR = 'data-g7wz-failed';

            function failed(el) {
                return !!el && el.getAttribute(FAILED_ATTR) === '1';
            }

            /**
             * 이 박스에 "살아 있는 바탕 레이어" 가 있는지.
             *
             * 1.2.0 부터 바탕은 **썸네일이 없는 행에만** 그려진다. 그래서 모드만 보고
             * 판단할 수 없고 **실제로 DOM 에 있는지** 봐야 한다 — 썸네일이 있는 행에는
             * 바탕이 아예 없으므로, 그 썸네일이 죽으면 박스를 감춰 "요약만" 으로 수렴시킨다.
             *
             * 자리표시자 바탕은 이미지가 아니라 실패할 수 없고, 대체 이미지 바탕은 그
             * 이미지가 살아 있을 때만 유효하다.
             */
            function hasLivingBase(box) {
                var base = box.querySelector('.' + BASE_CLASS);

                if (!base) {
                    return false;
                }

                return base.nodeName !== 'IMG' || !failed(base);
            }

            /**
             * 박스에 더 보여 줄 것이 남았는지 다시 판단한다.
             * 썸네일도 죽고 바탕도 없으면(또는 함께 죽었으면) 영역째 감춰 "요약만" 으로
             * 수렴시킨다. 썸네일이 멀쩡한 행은 대체 이미지가 깨져도 그대로 둔다.
             */
            function reevaluate(box) {
                var thumb = box.querySelector('img.' + THUMB_CLASS);
                var thumbAlive = !!thumb && !failed(thumb);

                if (!thumbAlive && !hasLivingBase(box)) {
                    box.style.display = 'none';
                }
            }

            function handleFailure(img) {
                if (!img || !img.classList) {
                    return;
                }
                if (!img.classList.contains(THUMB_CLASS) && !img.classList.contains(FALLBACK_CLASS)) {
                    return;
                }
                if (failed(img)) {
                    return;
                }

                img.setAttribute(FAILED_ATTR, '1');
                img.style.display = 'none';

                var box = img.closest ? img.closest(BOX_SELECTOR) : null;
                if (box) {
                    reevaluate(box);
                }
            }

            // 이미지 error 는 버블링하지 않으므로 캡처 단계에서 하나만 건다.
            document.addEventListener('error', function (event) {
                var target = event.target;
                if (target && target.nodeName === 'IMG') {
                    handleFailure(target);
                }
            }, true);

            // 스크립트가 붙기 전에 이미 실패한 이미지 회수 (async 로드 경합 대비).
            function sweep() {
                var images = document.querySelectorAll('img.' + THUMB_CLASS + ', img.' + FALLBACK_CLASS);
                for (var i = 0; i < images.length; i++) {
                    var img = images[i];
                    if (img.complete && img.naturalWidth === 0) {
                        handleFailure(img);
                    }
                }
            }

            sweep();
            window.addEventListener('load', sweep);
        })();
        JS;
    }
}
