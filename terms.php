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
    <title>利用規約 - 不動産AI名刺</title>
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
            <h1 style="font-size: 1.8rem; margin-bottom: 1.5rem;">不動産AI名刺 利用規約</h1>

            <p style="margin-bottom: 1.5rem;">
                本規約（以下「本規約」といいます。）は、リニュアル仲介株式会社（以下「当社」といいます。）が提供する「動産AI名刺」（以下「本サービス」、の利用条件および当社による個人情報等の取り扱いについて定めるものです。
                本サービスには不動産営業支援DXツール「SelFinPRO」が組み込まれており、本サービスを利用することにより、本規約に同意したものとみなします。また、当社で通常提供している「SelFinPRO」の一部のサービスのみ「不動産AI名刺」でご利用可能となります。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第1条（サービスの目的および性質）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0;">
                <li style="margin-bottom: 0.5rem; list-style: decimal;">
                    本サービスは、不動産事業者が顧客とのコミュニケーション、提案、資料共有、営業支援を円滑に行うことを目的とするオンラインツール（DXツール＋不動産AI名刺）です。ご希望に応じて、SNSや自社HP等の掲出が可能なサービスです。<br>
                    ※名刺の発注が出来るシステムでは無く、名刺内等に表示するQRコード（株式会社デンソーウェーブの登録商標）を発行、表示するサービスとなります。QRコード発行後はご自身で名刺内等に入れて頂くなどしてご活用ください。
                </li>
                <li style="margin-bottom: 0.5rem; list-style: decimal;">
                    本サービスのお申込み、及び不動産AI名刺のレイアウト作成はご自身でWEB上にある申込システムより、ご注文ください。その後はWEB上のマイページにて、ID/パスワード（ご自身で設定）を用いてデータ修正・変更を行い、ご利用いただく事が可能です。
                </li>
                <li style="list-style: decimal;">
                    本サービスの一部機能は、当社および提携先が保有する公開情報・独自調査データおよびSelFinPROの分析機能に基づいて提供されます。これらの情報は参考情報であり、不動産鑑定評価に基づくものではなく、その正確性・完全性・最新性・有用性を当社が保証するものではありません。利用者は自己の判断と責任で本サービスを利用するものとします。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第2条（適用範囲および規約の変更）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0;">
                <li style="margin-bottom: 0.5rem; list-style: decimal;">
                    本規約は本サービスの利用者（個人・法人）に適用されます。
                </li>
                <li style="list-style: decimal;">
                    当社は必要に応じて本規約およびプライバシーに関する方針を変更でき、変更後の規約は当社ウェブサイト上に掲載した時点で効力を生じます。利用者が変更後も本サービスを利用する場合、変更に同意したものとみなします。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第3条（利用申込および登録拒否）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0;">
                <li style="margin-bottom: 0.5rem; list-style: decimal;">
                    本サービスの利用申込は当社所定の手続きを経て行います。
                </li>
                <li style="list-style: decimal;">
                    当社は、以下の場合に申込を拒否、または登録を取消すことがあります。登録事項に虚偽がある場合、本規約違反の疑いがある場合、過去の不正利用がある場合、その他当社が不適当と判断した場合。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第4条（利用料金および支払）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0;">
                <li style="margin-bottom: 0.5rem; list-style: decimal;">
                    本サービスの料金は当社ウェブサイトに掲載する料金体系または当社が別途定めるものとします。
                </li>
                <li style="list-style: decimal;">
                    利用料金は当社が指定する支払方法により支払うものとし、一度支払われた料金は特に定めがない限り原則返金いたしません。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第5条（収集する利用者情報および収集方法）</h2>
            <p>
                当社は、本サービスの提供・改善および関連する業務遂行のため、以下の利用者情報を収集します。収集は、利用者の同意のもとでかつ適法かつ公正な手段で行います。
            </p>
            <p style="margin-top: 0.75rem; font-weight: bold;">1. 利用者が直接提供する情報</p>
            <ul style="margin-left: 1.5rem; list-style: disc;">
                <li>氏名、所属、役職、連絡先（電話番号、メールアドレス、住所等）、生年月日等のプロフィール情報。</li>
                <li>物件情報、契約に必要な本人確認情報、取引履歴、資金情報等（利用者が提供した場合）。</li>
                <li>お問い合わせ・資料請求・アンケート等で提供される情報。</li>
                <li>採用に関する応募書類等（該当する場合）。</li>
            </ul>

            <p style="margin-top: 0.75rem; font-weight: bold;">2. 自動収集される情報</p>
            <ul style="margin-left: 1.5rem; list-style: disc;">
                <li>アクセスログ（アクセス日時、閲覧ページ、操作履歴等）、IPアドレス、リファラ。</li>
                <li>Cookie、広告識別子（ADID、IDFA等）、デバイス識別子等。</li>
                <li>SelFinPRO利用に伴い発生する操作履歴・利用状況（行動履歴データ）。</li>
            </ul>
            <p style="margin-left: 1.5rem; font-size: 0.95rem;">
                ※Cookie等の設定により一部情報の収集を無効化できますが、当該機能の利用により本サービスの一部が利用できなくなる場合があります。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第6条（利用目的）</h2>
            <p>当社は収集した利用者情報を、以下の目的の範囲内で利用します。</p>
            <ol style="margin-left: 1.5rem; padding-left: 0; list-style: decimal;">
                <li style="margin-bottom: 0.5rem;">
                    本サービスの提供、運営、維持、改善、本人確認、利用者サポートのため。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    SelFinPROを通じた物件提案、資料送付、契約手続き支援、アフターサービス等の不動産取引関連業務のため。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    お問い合わせ・ご要望へ対応するため。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    本サービスの品質向上や機能改善、新サービス開発のための分析・マーケティング（行動解析、効果測定等）。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    メールマガジン、DM、各種通知の配信、キャンペーン案内等のため（同意に基づく場合）。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    当社の業務委託先・提携先（リニュアル仲介ネットワーク等）との連携のため（第5条に定める範囲で必要情報を共有）。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    法令等に基づく開示請求への対応、規約違反対応、不正行為防止のため。
                </li>
                <li>
                    採用業務、その他あらかじめ同意を得た目的のため。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第7条（第三者提供・共同利用）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0; list-style: decimal;">
                <li style="margin-bottom: 0.5rem;">
                    当社は、原則として個人情報を利用者の同意なしに第三者へ提供しません。ただし、以下の場合は例外とします。法令に基づく場合、利用者の同意がある場合、事業承継時等。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    本サービスの性質上、当社は利用者の氏名、連絡先、問い合わせ内容、興味物件情報等を、利用者への迅速かつ適切な対応のために提携事業者（販売代理店、システム運用会社、広告代理店等）と共同利用または提供する場合があります。提供先とは個人情報の取扱いに関する規約を守り、適切な管理を義務付けます。
                </li>
                <li>
                    第三者提供される個人データの項目、提供の手段、提供先等については、当社ウェブサイトおよび本規約にて明示します。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第8条（委託）</h2>
            <p>
                当社は、本サービスの提供に必要な業務（システム開発・運用、配送、広告配信、決済等）を外部委託する場合があります。委託先選定に際しては、法令および当社基準に従い安全管理措置を講じ、必要な契約を締結します。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第9条（保管期間および削除）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0; list-style: decimal;">
                <li style="margin-bottom: 0.5rem;">
                    利用者情報は、利用目的達成に必要な期間保存します。法令に定める保存期間または当社で別途定める保存期間がある場合はそれに従います。
                </li>
                <li>
                    利用者からの退会・削除依頼、または登録情報が事実と異なると判明した場合等、対応基準に従い速やかに削除または消去します（例：退会後30日以内の削除等、ただし法令上の保存義務がある場合を除く）。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第10条（開示・訂正・利用停止等の請求）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0; list-style: decimal;">
                <li style="margin-bottom: 0.5rem;">
                    利用者は、当社に対して自己の個人情報の開示、訂正、追加、削除、利用停止、第三者提供の停止等を求めることができます。手続きは当社所定の方法に従って行ってください。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    開示請求に係る手数料、必要書類、申請先および回答方法等は当社ウェブサイトに記載します。開示請求の一部（開示手数料等）は当社所定の規定に従うものとします。
                </li>
                <li>
                    開示等の請求に必要な本人確認書類、代理人による申請手続き等の詳細は当社所定の基準に従います。
                </li>
            </ol>
            <p style="margin-left: 1.5rem;">
                （参考）申請先・問合せ窓口：<br>
                リニュアル仲介株式会社 個人情報保護担当<br>
                〒163-0638 東京都新宿区西新宿1-25-1 新宿センタービル38階<br>
                TEL:03-3346-4329　E-mail：web@rchukai.jp
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第11条（安全管理措置）</h2>
            <p>
                当社は、収集した利用者情報の正確性を保ち、漏洩・改ざん・不正アクセス等を防止するために、組織的・人的・技術的な安全管理措置を講じます。必要に応じて監査・教育・アクセス制御・暗号化等の適切な対策を実施します。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第12条（Cookie等の取扱い）</h2>
            <p>
                当社は、本サービスの利便性向上および行動解析、広告表示の最適化のためにCookie等の技術を使用します。利用者はブラウザや端末設定でCookieの受け入れを無効にできますが、一部機能がご利用いただけなくなる場合があります。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第13条（利用者の責任・禁止行為）</h2>
            <p>
                利用者は本サービスを適正に利用するものとし、以下の行為を行ってはなりません。不正目的での利用、法令・公序良俗違反、当社や第三者の権利侵害、サービスデータの無断転用・再配布・販売、なりすまし、サービス運営を妨害する行為、反社会的勢力との関与等。当社は違反行為があった場合、利用停止・契約解除・損害賠償等の措置をとることがあります。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第14条（免責）</h2>
            <ol style="margin-left: 1.5rem; padding-left: 0; list-style: decimal;">
                <li style="margin-bottom: 0.5rem;">
                    当社は、本サービスおよびSelFinPROで提供される情報の正確性・完全性・最新性について保証せず、利用者が本サービスに基づき行った判断または行為による損害について、当社の故意または重大な過失がある場合を除き、一切責任を負いません。
                </li>
                <li style="margin-bottom: 0.5rem;">
                    本サービスの提供の一時停止、中断、終了、データ消失、第三者による不正利用等により生じた損害について、当社は一切責任を負わないものとします（ただし当社の故意または重大な過失がある場合を除く）。
                </li>
                <li>
                    当社の賠償責任は、当社に故意または重過失がある場合を除き、利用者が当社に支払った直近の利用料金を上限とします。
                </li>
            </ol>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第15条（サービスの停止・終了）</h2>
            <p>
                当社は、システム保守、障害対応、法的義務、事業方針変更、その他やむを得ない事由がある場合に、事前通知なしに本サービスの全部または一部を停止・中断・終了することがあります。これにより利用者に生じた損害について、当社は一切の責任を負いません（ただし当社の故意または重大な過失がある場合を除く）。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第16条（知的財産権）</h2>
            <p>
                本サービスおよびSelFinPROに関する著作権、商標権、その他の知的財産権は当社または正当な権利者に帰属します。利用者はこれらを侵害する行為を行ってはなりません。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第17条（反社会的勢力の排除）</h2>
            <p>
                利用者は暴力団、暴力団関係者、反社会的勢力等に該当しないこと、またこれらと一切関係を有しないことを表明・保証するものとします。当社は違反が判明した場合、直ちに本サービスの提供を停止・解除することができます。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem;">第18条（準拠法・管轄）</h2>
            <p>
                本規約は日本法に準拠します。本サービスに関して生じた紛争については、東京地方裁判所または東京簡易裁判所を第一審の専属合意管轄裁判所とします。
            </p>

            <h2 style="font-size: 1.2rem; margin-top: 2rem; margin-bottom: 0.75rem; text-align: end;">附則</h2>
            <p style="text-align: end;">
                施行日：2026年2月1日
            </p>

            <div style="margin-top: 2.5rem; text-align: center;">
                <a href="index.php" class="btn-primary" style="display: inline-block; padding: 0.75rem 2rem; border-radius: 999px; text-decoration: none;">
                    トップページへ戻る
                </a>
            </div>
        </div>
    </main>
</body>
</html>

