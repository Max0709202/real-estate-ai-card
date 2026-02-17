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
    <title>会社概要 - 不動産AI名刺</title>
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
            <h1 style="font-size: 1.8rem; margin-bottom: 1.5rem;">会社概要</h1>

            <div style="font-size: 0.95rem;">
                <p style="margin-bottom: 0.75rem;">
                    【企業名】　リニュアル仲介株式会社　<span style="font-size: 0.9rem;">[Renewal Brokerage Agency Inc.]</span><br>
                    【所在地】　〒163-0638　東京都新宿区西新宿1-25-1新宿センタービル38階<br>
                    【設立年月】　2011年11月<br>
                    【代表者】　西生　建　<span style="font-size: 0.9rem;">Takeshi Nishio</span>
                </p>

                <p style="margin-top: 1.5rem; font-weight: bold;">【事業案内】</p>

                <p style="margin-top: 0.75rem; font-weight: bold;">■不動産フランチャイズ本部事業（リニュアル仲介本部）</p>
                <p style="margin-left: 1.5rem;">
                    健全な既存住宅流通活性化の為に、不動産仲介事業者とリフォーム事業者の連携を図り、「瑕疵保険」「住宅履歴」「インスペクション」「リフォームと住宅購入資金の一体ローン」「アフターサービス」等のサービス事業を提供。中古住宅購入者に、資産価値が高く、高品質の中古住宅を購入していただく為のサポート事業。
                </p>

                <p style="margin-top: 0.75rem; font-weight: bold;">■不動産仲介事業（リニュアル仲介パイロット店）</p>
                <p style="margin-left: 1.5rem;">
                    パイロット店（試験店）としての不動産仲介事業。<br>
                    ​トランクルーム用地開発事業
                </p>

                <p style="margin-top: 0.75rem; font-weight: bold;">■各種システム開発事業</p>
                <ul style="margin-left: 1.5rem; list-style: disc;">
                    <li>物件のリスクを瞬時に判定「SelFin（セルフィン）」</li>
                    <li>AI評価付き自動物件配信サービス「物件提案ロボ」</li>
                    <li>「全国マンションデータベース」</li>
                    <li>個人情報不要の査定システム「AIマンション査定」</li>
                    <li>顧客管理システム「SelFinPro」</li>
                    <li>マンション資産ウォッチツール「オーナーコネクト」</li>
                    <li>不動産AI名刺</li>
                    <li>既存住宅アドバイザーツール</li>
                </ul>

                <p style="margin-top: 0.75rem; font-weight: bold;">■住まいるサポート事業（24時間365日対応）</p>
                <p style="margin-left: 1.5rem;">
                    24時間365日の緊急電話受付・駆け付けサービス事業。
                </p>

                <p style="margin-top: 0.75rem; font-weight: bold;">■モーゲージバンク代理店事業</p>
                <p style="margin-left: 1.5rem;">
                    フラット35融資のモーゲージバンクの代理店事業
                </p>

                <p style="margin-top: 0.75rem; font-weight: bold;">■保険代理店事業</p>
                <p style="margin-left: 1.5rem;">
                    火災保険や瑕疵保険の取次代理店事業。
                </p>

                <p style="margin-top: 0.75rem; font-weight: bold;">■トランクルーム店舗開発事業</p>

                <p style="margin-top: 1.5rem;">
                    【免許】　宅地建物取引業者 東京都知事（3）93700号
                </p>

                <p style="margin-top: 1.5rem; font-weight: bold;">【所属団体】</p>
                <ul style="margin-left: 1.5rem; list-style: disc;">
                    <li>首都圏既存住宅流通推進協議会</li>
                    <li>日本木造住宅耐震補強事業者協同組合</li>
                    <li>公益社団法人　東京都宅地建物取引業協会</li>
                    <li>公益社団法人　全国宅地建物取引業保証協会</li>
                    <li>東京都不動産協同組合</li>
                    <li>​一般社団法人　リノベーション協議会</li>
                </ul>
            </div>

            <div style="margin-top: 2.5rem; text-align: center;">
                <a href="index.php" class="btn-primary" style="display: inline-block; padding: 0.75rem 2rem; border-radius: 999px; text-decoration: none;">
                    トップページへ戻る
                </a>
            </div>
        </div>
    </main>
</body>
</html>

