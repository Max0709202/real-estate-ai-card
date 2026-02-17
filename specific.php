<?php
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/includes/functions.php';

startSessionIfNotStarted();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>特定商取引法に基づく表示 - 不動産AI名刺</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>
<body>
    <?php
    $showNavLinks = true;
    include __DIR__ . '/includes/header.php';
    ?>

    <main class="static-page">
        <div class="container" style="max-width: 960px; margin: 3rem auto; padding: 2rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); line-height: 1.8;">
            <h1 style="font-size: 1.8rem; margin-bottom: 1.5rem;">特定商取引法に基づく表示</h1>

            <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
                <tbody>
                    <tr>
                        <th style="width: 30%; padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">事業者名</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">リニュアル仲介株式会社</td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">代表者名</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">西生　建</td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">住所</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">〒163-0638 東京都新宿区西新宿1-25-1 新宿センタービル38F</td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">電話番号</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">
                            電話番号についてはお問い合わせ先メールアドレスにてご請求をいただければ、遅滞なく開示いたします。
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">メールアドレス</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">
                            <a href="mailto:ask@mdbank.jp">ask@mdbank.jp</a>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">販売価格</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">
                            各サービスページに記載
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">支払い方法と支払い時期</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">
                            クレジットカード決済（注文時に直ちに処理されます）
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">サービスの提供時期</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">
                            お申し込みから1週間以内にメールでお届けします
                        </td>
                    </tr>
                    <tr>
                        <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; text-align: left; background: #f7fafc;">その他</th>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">
                            原則としてお申し込み後のキャンセルは承ることができません。ご了承ください。
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 2.5rem; text-align: center;">
                <a href="index.php" class="btn-primary" style="display: inline-block; padding: 0.75rem 2rem; border-radius: 999px; text-decoration: none;">
                    トップページへ戻る
                </a>
            </div>
        </div>
    </main>
</body>
</html>

