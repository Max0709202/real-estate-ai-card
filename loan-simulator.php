<?php
/**
 * Standalone Loan Simulator page.
 * Two blocks: 返済額の試算, 借入可能額の試算.
 * Optional ?slug= for card context (enables plan check when calling from card).
 */
require_once __DIR__ . '/backend/config/config.php';

$cardSlug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$apiBase = rtrim(BASE_URL, '/') . '/backend/api/loan';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>住宅ローンシミュレーター | 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/loan-simulator.css">
</head>
<body class="loan-sim-page">
    <a href="<?php echo $cardSlug ? htmlspecialchars('card.php?slug=' . urlencode($cardSlug)) : 'index.php'; ?>" class="loan-sim-back-link">← 戻る</a>
    <h1>住宅ローンシミュレーター</h1>

    <div class="loan-sim-grid">
        <!-- 返済額の試算 -->
        <div class="loan-sim-card">
            <div class="loan-sim-card-header">返済額の試算</div>
            <div class="loan-sim-card-body">
                <p class="loan-sim-desc">金利など一定の返済条件を入力し、総返済額や毎月の返済額を試算します。</p>
                <button type="button" class="loan-sim-btn" data-form="repayment">→ 計算する</button>
            </div>
        </div>

        <!-- 借入可能額の試算 -->
        <div class="loan-sim-card">
            <div class="loan-sim-card-header">借入可能額の試算</div>
            <div class="loan-sim-card-body">
                <p class="loan-sim-desc">年収や毎月の返済希望額から借入可能額の試算を行います。</p>
                <div class="loan-sim-btns-row">
                    <button type="button" class="loan-sim-btn" data-form="borrow-income">→ 年収より計算する</button>
                    <button type="button" class="loan-sim-btn" data-form="borrow-monthly">→ 返済額より計算する</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Form overlay: 返済額の試算 -->
    <div id="overlay-repayment" class="loan-sim-form-overlay" hidden>
        <div class="loan-sim-form-box" role="dialog" aria-modal="true" aria-labelledby="title-repayment" tabindex="-1">
            <div class="loan-sim-form-header" id="title-repayment">返済額の試算 <button type="button" class="loan-sim-form-close" aria-label="閉じる">&times;</button></div>
            <div class="loan-sim-form-body">
                <div class="loan-sim-field">
                    <label>借入額（万円）</label>
                    <input type="number" id="repayment-loan-amount" min="100" max="99999" step="100" value="5000" placeholder="5000">
                    <span class="hint">借りたい金額を入力してください。</span>
                </div>
                <div class="loan-sim-field">
                    <label>頭金（万円）任意</label>
                    <input type="number" id="repayment-down-payment" min="0" max="99999" step="100" value="0" placeholder="0">
                </div>
                <div class="loan-sim-field">
                    <label>返済方式</label>
                    <div class="loan-sim-radio-group">
                        <label><input type="radio" name="repayment-type" value="equal_installment" checked> 元利均等</label>
                        <label><input type="radio" name="repayment-type" value="equal_principal"> 元金均等</label>
                    </div>
                </div>
                <div class="loan-sim-field">
                    <label>金利（%）</label>
                    <input type="number" id="repayment-rate" min="0" max="15" step="0.01" value="2.5" placeholder="2.5">
                    <span class="hint">※適用金利は金融機関にご確認ください</span>
                </div>
                <div class="loan-sim-field">
                    <label>返済期間（年）</label>
                    <input type="number" id="repayment-term" min="1" max="50" value="35" placeholder="35">
                </div>
                <button type="button" class="loan-sim-submit" id="submit-repayment">シミュレーションを実行</button>
                <div id="result-repayment" class="loan-sim-result-box" hidden></div>
                <div id="error-repayment" class="loan-sim-error" hidden></div>
            </div>
        </div>
    </div>

    <!-- Form overlay: 借入可能額（年収から） -->
    <div id="overlay-borrow-income" class="loan-sim-form-overlay" hidden>
        <div class="loan-sim-form-box" role="dialog" aria-modal="true" aria-labelledby="title-borrow-income" tabindex="-1">
            <div class="loan-sim-form-header" id="title-borrow-income">借入可能額の試算（年収から） <button type="button" class="loan-sim-form-close" aria-label="閉じる">&times;</button></div>
            <div class="loan-sim-form-body">
                <div class="loan-sim-field">
                    <label>年収（万円）</label>
                    <input type="number" id="borrow-income-amount" min="100" max="99999" step="50" value="500" placeholder="500">
                </div>
                <div class="loan-sim-field">
                    <label>返済負担率（%）</label>
                    <input type="number" id="borrow-dbr" min="20" max="50" value="35" placeholder="35">
                    <span class="hint">目安として35%が一般的です。</span>
                </div>
                <div class="loan-sim-field">
                    <label>金利（%）</label>
                    <input type="number" id="borrow-income-rate" min="0" max="15" step="0.01" value="2.5">
                </div>
                <div class="loan-sim-field">
                    <label>返済期間（年）</label>
                    <input type="number" id="borrow-income-term" min="1" max="50" value="35">
                </div>
                <button type="button" class="loan-sim-submit" id="submit-borrow-income">シミュレーションを実行</button>
                <div id="result-borrow-income" class="loan-sim-result-box" hidden></div>
                <div id="error-borrow-income" class="loan-sim-error" hidden></div>
            </div>
        </div>
    </div>

    <!-- Form overlay: 借入可能額（返済額から） -->
    <div id="overlay-borrow-monthly" class="loan-sim-form-overlay" hidden>
        <div class="loan-sim-form-box" role="dialog" aria-modal="true" aria-labelledby="title-borrow-monthly" tabindex="-1">
            <div class="loan-sim-form-header" id="title-borrow-monthly">借入可能額の試算（返済額から） <button type="button" class="loan-sim-form-close" aria-label="閉じる">&times;</button></div>
            <div class="loan-sim-form-body">
                <div class="loan-sim-field">
                    <label>希望月額返済（円）</label>
                    <input type="number" id="borrow-monthly-amount" min="50000" max="500000" step="10000" value="150000" placeholder="150000">
                </div>
                <div class="loan-sim-field">
                    <label>金利（%）</label>
                    <input type="number" id="borrow-monthly-rate" min="0" max="15" step="0.01" value="2.5">
                </div>
                <div class="loan-sim-field">
                    <label>返済期間（年）</label>
                    <input type="number" id="borrow-monthly-term" min="1" max="50" value="35">
                </div>
                <button type="button" class="loan-sim-submit" id="submit-borrow-monthly">シミュレーションを実行</button>
                <div id="result-borrow-monthly" class="loan-sim-result-box" hidden></div>
                <div id="error-borrow-monthly" class="loan-sim-error" hidden></div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var apiBase = <?php echo json_encode($apiBase); ?>;
        var cardSlug = <?php echo json_encode($cardSlug); ?>;

        var overlays = Array.prototype.slice.call(document.querySelectorAll('.loan-sim-form-overlay'));

        function getOverlay(id) {
            return document.getElementById(id);
        }
        function closeAllOverlays() {
            overlays.forEach(function(overlay) {
                overlay.setAttribute('hidden', '');
            });
            document.body.classList.remove('loan-sim-modal-open');
        }
        function openOverlay(id) {
            var overlay = getOverlay(id);
            if (!overlay) return;
            closeAllOverlays();
            overlay.removeAttribute('hidden');
            document.body.classList.add('loan-sim-modal-open');
            var dialog = overlay.querySelector('.loan-sim-form-box');
            if (dialog) dialog.focus();
        }
        function closeOverlay(overlay) {
            if (!overlay) return;
            overlay.setAttribute('hidden', '');
            if (!overlays.some(function(item) { return !item.hasAttribute('hidden'); })) {
                document.body.classList.remove('loan-sim-modal-open');
            }
        }
        function formatYen(n) { return Number(n).toLocaleString() + '円'; }
        function showError(el, message) {
            el.textContent = message || '入力内容をご確認ください。';
            el.removeAttribute('hidden');
        }
        function hideFeedback(resultEl, errEl) {
            resultEl.setAttribute('hidden', '');
            errEl.setAttribute('hidden', '');
            resultEl.innerHTML = '';
            errEl.textContent = '';
        }
        function readNumber(id) {
            var el = document.getElementById(id);
            var value = el ? parseFloat(el.value) : NaN;
            return Number.isFinite(value) ? value : NaN;
        }
        function setSubmitting(btn, submitting) {
            if (!btn) return;
            if (submitting) {
                btn.dataset.originalText = btn.textContent;
                btn.textContent = '計算中...';
                btn.disabled = true;
            } else {
                btn.textContent = btn.dataset.originalText || 'シミュレーションを実行';
                btn.disabled = false;
            }
        }
        function postJson(path, body, submitBtn) {
            setSubmitting(submitBtn, true);
            return fetch(apiBase + path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function(r) {
                return r.json().catch(function() {
                    return { success: false, message: 'サーバーから正しい応答を受け取れませんでした。' };
                });
            }).finally(function() {
                setSubmitting(submitBtn, false);
            });
        }

        closeAllOverlays();

        document.querySelectorAll('.loan-sim-btn[data-form]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var form = this.getAttribute('data-form');
                var id = 'overlay-repayment';
                if (form === 'borrow-income') id = 'overlay-borrow-income';
                if (form === 'borrow-monthly') id = 'overlay-borrow-monthly';
                openOverlay(id);
            });
        });
        document.querySelectorAll('.loan-sim-form-close').forEach(function(btn) {
            btn.addEventListener('click', function() {
                closeOverlay(this.closest('.loan-sim-form-overlay'));
            });
        });
        overlays.forEach(function(overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeOverlay(overlay);
            });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeAllOverlays();
        });

        var payload = { card_slug: cardSlug || '' };
        if (!payload.card_slug) delete payload.card_slug;

        // 返済額の試算
        document.getElementById('submit-repayment').addEventListener('click', function() {
            var loanAmountMan = readNumber('repayment-loan-amount');
            var downPaymentMan = readNumber('repayment-down-payment');
            var rate = readNumber('repayment-rate');
            var term = parseInt(document.getElementById('repayment-term').value, 10);
            var type = document.querySelector('input[name="repayment-type"]:checked').value;
            var resultEl = document.getElementById('result-repayment');
            var errEl = document.getElementById('error-repayment');
            hideFeedback(resultEl, errEl);

            if (!Number.isFinite(loanAmountMan) || !Number.isFinite(downPaymentMan) || !Number.isFinite(rate) || !Number.isFinite(term)) {
                showError(errEl, 'すべての入力項目を正しく入力してください。');
                return;
            }
            if (loanAmountMan <= 0 || downPaymentMan < 0 || downPaymentMan >= loanAmountMan || rate < 0 || rate > 15 || term < 1 || term > 50) {
                showError(errEl, '借入額・頭金・金利・返済期間の範囲をご確認ください。');
                return;
            }

            var body = Object.assign({}, payload, {
                loan_amount: loanAmountMan * 10000,
                down_payment: downPaymentMan * 10000,
                rate_year: rate,
                term_years: term,
                repayment_type: type
            });
            postJson('/calc-repayment.php', body, this).then(function(data) {
                if (data.success && data.data) {
                    var d = data.data;
                    resultEl.innerHTML = '<h4>結果</h4>' +
                        '<div class="loan-sim-result-line highlight">毎月のご返済額: 約' + formatYen(d.monthly_payment) + '</div>' +
                        '<div class="loan-sim-result-line">総支払額: ' + formatYen(d.total_repayment) + '</div>' +
                        '<div class="loan-sim-result-line">総利息: ' + formatYen(d.total_interest) + '</div>' +
                        '<div class="loan-sim-result-line">返済期間: ' + d.term_years + '年</div>';
                    resultEl.removeAttribute('hidden');
                } else {
                    showError(errEl, data.message || 'エラーが発生しました。');
                }
            }).catch(function() {
                showError(errEl, '通信エラーです。');
            });
        });

        // 借入可能額（年収から）
        document.getElementById('submit-borrow-income').addEventListener('click', function() {
            var incomeMan = readNumber('borrow-income-amount');
            var dbrPercent = readNumber('borrow-dbr');
            var rate = readNumber('borrow-income-rate');
            var term = parseInt(document.getElementById('borrow-income-term').value, 10);
            var resultEl = document.getElementById('result-borrow-income');
            var errEl = document.getElementById('error-borrow-income');
            hideFeedback(resultEl, errEl);

            if (!Number.isFinite(incomeMan) || !Number.isFinite(dbrPercent) || !Number.isFinite(rate) || !Number.isFinite(term)) {
                showError(errEl, 'すべての入力項目を正しく入力してください。');
                return;
            }
            if (incomeMan <= 0 || dbrPercent <= 0 || dbrPercent > 100 || rate < 0 || rate > 15 || term < 1 || term > 50) {
                showError(errEl, '年収・返済負担率・金利・返済期間の範囲をご確認ください。');
                return;
            }

            var body = Object.assign({}, payload, { annual_income: incomeMan * 10000, dbr_ratio: dbrPercent / 100, rate_year: rate, term_years: term });
            postJson('/calc-borrowable.php', body, this).then(function(data) {
                if (data.success && data.data) {
                    var d = data.data;
                    resultEl.innerHTML = '<h4>結果</h4>' +
                        '<div class="loan-sim-result-line highlight">借入可能額: 約' + formatYen(d.max_borrowable) + '</div>' +
                        '<div class="loan-sim-result-line">想定月額返済: 約' + formatYen(d.max_monthly_payment) + '</div>' +
                        '<div class="loan-sim-result-line">年収: ' + formatYen(d.annual_income) + '</div>' +
                        '<div class="loan-sim-result-line">返済期間: ' + d.term_years + '年</div>';
                    resultEl.removeAttribute('hidden');
                } else {
                    showError(errEl, data.message || 'エラーが発生しました。');
                }
            }).catch(function() {
                showError(errEl, '通信エラーです。');
            });
        });

        // 借入可能額（返済額から）
        document.getElementById('submit-borrow-monthly').addEventListener('click', function() {
            var monthly = readNumber('borrow-monthly-amount');
            var rate = readNumber('borrow-monthly-rate');
            var term = parseInt(document.getElementById('borrow-monthly-term').value, 10);
            var resultEl = document.getElementById('result-borrow-monthly');
            var errEl = document.getElementById('error-borrow-monthly');
            hideFeedback(resultEl, errEl);

            if (!Number.isFinite(monthly) || !Number.isFinite(rate) || !Number.isFinite(term)) {
                showError(errEl, 'すべての入力項目を正しく入力してください。');
                return;
            }
            if (monthly <= 0 || rate < 0 || rate > 15 || term < 1 || term > 50) {
                showError(errEl, '希望月額返済・金利・返済期間の範囲をご確認ください。');
                return;
            }

            var body = Object.assign({}, payload, { desired_monthly_payment: monthly, rate_year: rate, term_years: term });
            postJson('/calc-borrowable.php', body, this).then(function(data) {
                if (data.success && data.data) {
                    var d = data.data;
                    resultEl.innerHTML = '<h4>結果</h4>' +
                        '<div class="loan-sim-result-line highlight">借入可能額: 約' + formatYen(d.max_borrowable) + '</div>' +
                        '<div class="loan-sim-result-line">希望月額返済: ' + formatYen(d.desired_monthly_payment) + '</div>' +
                        '<div class="loan-sim-result-line">返済期間: ' + d.term_years + '年</div>';
                    resultEl.removeAttribute('hidden');
                } else {
                    showError(errEl, data.message || 'エラーが発生しました。');
                }
            }).catch(function() {
                showError(errEl, '通信エラーです。');
            });
        });
    })();
    </script>
</body>
</html>
