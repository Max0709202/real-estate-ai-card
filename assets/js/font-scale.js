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
    var SCALES = ['normal', 'large', 'xlarge'];
    var LABELS = { normal: '標準', large: '大', xlarge: '特大' };

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

        var label = document.createElement('span');
        label.className = 'font-scale-switch-label';
        label.textContent = '文字サイズ';
        wrap.appendChild(label);

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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildSwitch);
    } else {
        buildSwitch();
    }
})();
