/*
 * 文字サイズ切替（標準／大／特大）
 *
 * 50代以上の決裁者がスマートフォンで見づらい、というご指摘への対応。
 * 既定は「標準」＝従来と同じ表示。利用者が選んだ場合だけ localStorage に保存し、
 * 次回以降も同じ大きさで表示する。
 *
 * このスクリプトは <head> で defer なしに読み込む前提。属性の適用だけを即時に行い、
 * 切替ボタンの生成は DOMContentLoaded まで遅らせることで、
 * 「一瞬 標準サイズで描画されてから大きくなる」ちらつきを防ぐ。
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'app_font_scale';
    var POSITION_KEY = 'app_font_scale_pos';
    var SCALES = ['normal', 'large', 'xlarge'];
    var LABELS = { normal: '標準', large: '大', xlarge: '特大' };
    // これ以下の動きは「押した」と見なす。指が少しぶれてもボタンが反応するようにする。
    var DRAG_THRESHOLD = 4;

    function readStored() {
        try {
            var v = window.localStorage.getItem(STORAGE_KEY);
            return SCALES.indexOf(v) >= 0 ? v : 'normal';
        } catch (e) {
            // プライベートブラウズ等で localStorage が使えない場合は既定値で動かす。
            return 'normal';
        }
    }

    function store(scale) {
        try {
            window.localStorage.setItem(STORAGE_KEY, scale);
        } catch (e) { /* 保存できなくても表示自体は切り替わる */ }
    }

    function apply(scale) {
        document.documentElement.setAttribute('data-font-scale', scale);
    }

    // 描画前に適用する（ちらつき防止）。
    var current = readStored();
    apply(current);

    /* --- つまんで移動できるようにする（改善要望 1-8） ---------------------
     * 切替UIは画面左下に固定しているが、ページによってはその位置に読みたい情報が
     * 重なってしまう。掴んで好きな場所へ動かせるようにし、動かした位置は次回も使う。
     * マウスとタッチの両方を同じ処理で扱えるよう Pointer Events を使う。
     */

    function readStoredPosition(key) {
        try {
            var raw = window.localStorage.getItem(key || POSITION_KEY);
            if (!raw) return null;
            var pos = JSON.parse(raw);
            if (!pos || typeof pos.left !== 'number' || typeof pos.top !== 'number') return null;
            if (!isFinite(pos.left) || !isFinite(pos.top)) return null;
            return pos;
        } catch (e) {
            return null;
        }
    }

    function storePosition(pos, key) {
        try {
            window.localStorage.setItem(key || POSITION_KEY, JSON.stringify(pos));
        } catch (e) { /* 保存できなくても移動自体はできる */ }
    }

    // 画面の外へ出て掴めなくならないよう、必ず表示領域内に収める。
    // 画面の回転や表示サイズの変更で範囲が変わったときも、この関数で引き戻す。
    function clampPosition(el, left, top) {
        var maxLeft = Math.max(0, window.innerWidth - el.offsetWidth);
        var maxTop = Math.max(0, window.innerHeight - el.offsetHeight);
        return {
            left: Math.min(Math.max(0, left), maxLeft),
            top: Math.min(Math.max(0, top), maxTop)
        };
    }

    // CSS 側の left/bottom 指定より内側の指定（インラインスタイル）で位置を決める。
    function applyPosition(el, pos) {
        var clamped = clampPosition(el, pos.left, pos.top);
        el.style.left = clamped.left + 'px';
        el.style.top = clamped.top + 'px';
        el.style.right = 'auto';
        el.style.bottom = 'auto';
        return clamped;
    }

    // 非表示（display:none）の要素は大きさが測れないため、位置の調整を見送る。
    function isShown(el) {
        return el.getClientRects().length > 0;
    }

    function makeDraggable(el, key) {
        // Pointer Events が無い環境では、これまでどおり左下固定のまま使えるようにする。
        if (!window.PointerEvent) return;

        var dragging = false;
        var moved = false;
        var startX = 0;
        var startY = 0;
        var originLeft = 0;
        var originTop = 0;

        el.addEventListener('pointerdown', function (e) {
            // 右クリックや副ボタンでは動かさない。
            if (e.button !== 0 && e.pointerType === 'mouse') return;
            var rect = el.getBoundingClientRect();
            dragging = true;
            moved = false;
            startX = e.clientX;
            startY = e.clientY;
            originLeft = rect.left;
            originTop = rect.top;
        });

        el.addEventListener('pointermove', function (e) {
            if (!dragging) return;
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            if (!moved && Math.abs(dx) < DRAG_THRESHOLD && Math.abs(dy) < DRAG_THRESHOLD) return;
            if (!moved) {
                // 動き始めてから捕まえる。押しただけのときは中のボタンへ click を届けるため。
                moved = true;
                el.classList.add('is-dragging');
                try { el.setPointerCapture(e.pointerId); } catch (err) {}
            }
            // 指やマウスで運んでいる間は選択やスクロールを起こさない。
            e.preventDefault();
            applyPosition(el, { left: originLeft + dx, top: originTop + dy });
        });

        function endDrag(e) {
            if (!dragging) return;
            dragging = false;
            el.classList.remove('is-dragging');
            try { el.releasePointerCapture(e.pointerId); } catch (err) {}
            if (!moved) return;
            var rect = el.getBoundingClientRect();
            storePosition(applyPosition(el, { left: rect.left, top: rect.top }), key);
        }

        el.addEventListener('pointerup', endDrag);
        el.addEventListener('pointercancel', endDrag);

        // 運んだ直後の click は、ボタンを押したのではなく移動の終わりなので取り消す。
        el.addEventListener('click', function (e) {
            if (!moved) return;
            moved = false;
            e.preventDefault();
            // el 自身がボタンの場合（「顧客一覧に戻る」）も本来の動作を止める。
            e.stopImmediatePropagation();
        }, true);

        // 画面サイズが変わっても掴める位置に留める（保存済みの位置も入れ直す）。
        window.addEventListener('resize', function () {
            if (!el.style.left || !isShown(el)) return;
            storePosition(applyPosition(el, {
                left: parseFloat(el.style.left) || 0,
                top: parseFloat(el.style.top) || 0
            }), key);
        });
    }

    function buildSwitch() {
        if (document.querySelector('.font-scale-switch')) return;

        var wrap = document.createElement('div');
        wrap.className = 'font-scale-switch';
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-label', '文字サイズの変更');

        // スマホでは右下のAIエージェント起動ボタンが画面幅の大半を占め、同じ高さにある
        // 切替ボタンを覆って押せなくなる。チャットのあるページだけ上へ逃がす（位置はCSS側）。
        if (document.getElementById('chat-widget-root')) {
            wrap.classList.add('font-scale-switch-above-chat');
        }

        // 最初は「文字サイズ」だけを表示し、タップで「標準・大・特大」を開閉する。
        var label = document.createElement('span');
        label.className = 'font-scale-switch-label';
        label.textContent = '文字サイズ';
        label.setAttribute('role', 'button');
        label.setAttribute('tabindex', '0');
        label.setAttribute('aria-expanded', 'false');
        wrap.appendChild(label);

        function setOpen(open) {
            wrap.classList.toggle('is-open', open);
            label.setAttribute('aria-expanded', String(open));
            // 開いて横幅が広がったときに画面の外へはみ出さないよう、移動済みの位置を収め直す。
            if (wrap.style.left) {
                applyPosition(wrap, {
                    left: parseFloat(wrap.style.left) || 0,
                    top: parseFloat(wrap.style.top) || 0
                });
            }
        }
        label.addEventListener('click', function () {
            setOpen(!wrap.classList.contains('is-open'));
        });
        label.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                setOpen(!wrap.classList.contains('is-open'));
            }
        });
        // 切替UIの外をタップしたら閉じる。
        document.addEventListener('pointerdown', function (e) {
            if (wrap.classList.contains('is-open') && !wrap.contains(e.target)) setOpen(false);
        });

        var buttons = document.createElement('div');
        buttons.className = 'font-scale-switch-buttons';

        var nodes = [];
        SCALES.forEach(function (scale) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.dataset.scale = scale;
            btn.textContent = LABELS[scale];
            btn.setAttribute('aria-label', '文字サイズを' + LABELS[scale] + 'にする');
            btn.setAttribute('aria-pressed', String(scale === current));
            btn.addEventListener('click', function () {
                current = scale;
                apply(scale);
                store(scale);
                nodes.forEach(function (n) {
                    n.setAttribute('aria-pressed', String(n.dataset.scale === scale));
                });
            });
            nodes.push(btn);
            buttons.appendChild(btn);
        });

        wrap.appendChild(buttons);
        document.body.appendChild(wrap);

        // 前回つまんで動かした位置があれば、そこへ戻す（body へ追加したあとに測る）。
        var stored = readStoredPosition();
        if (stored) applyPosition(wrap, stored);
        makeDraggable(wrap);
    }

    // 他の画面部品（顧客詳細の「顧客一覧に戻る」など）も、文字サイズ切替と同じように
    // つまんで移動でき、動かした位置を次回も使えるようにする。key は位置の保存名。
    function restorePosition(el, key) {
        var stored = readStoredPosition(key);
        if (stored && isShown(el)) applyPosition(el, stored);
    }

    window.AppFloating = {
        makeDraggable: function (el, key) {
            if (!el || !key || el.dataset.floatingReady === '1') return;
            el.dataset.floatingReady = '1';
            restorePosition(el, key);
            makeDraggable(el, key);
        },
        // 非表示から表示に切り替えたあとに呼ぶ（保存位置を画面内に収めて入れ直す）。
        restorePosition: restorePosition
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildSwitch);
    } else {
        buildSwitch();
    }
})();
