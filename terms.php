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
    <style>
        .terms-container {
            max-width: 960px;
            margin: 3rem auto;
            padding: 2rem;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            line-height: 1.8;
        }

        .terms-container h1 {
            font-size: 1.8rem;
            margin: 0 0 1.5rem;
            text-align: center;
        }

        .terms-container h2,
        .terms-container h3 {
            font-size: 1.2rem;
            margin: 2rem 0 0.75rem;
        }

        .terms-container .terms-chapter {
            font-size: 1.28rem;
            padding-left: 0.75rem;
        }

        .terms-container p {
            margin: 0.55rem 0;
        }

        .terms-list {
            margin: 0.5rem 0 0.75rem 1.5rem;
            padding-left: 1.25rem;
        }

        .terms-list li {
            margin-bottom: 0.45rem;
        }

        .terms-subitem {
            margin-left: 1.5rem !important;
        }

        .terms-divider {
            border: 0;
            border-top: 1px solid #e5e7eb;
            margin: 1.5rem 0 0.25rem;
        }

        .terms-supplement,
        .terms-date {
            text-align: right;
        }

        @media (max-width: 768px) {
            .terms-container {
                margin: 1.5rem 1rem;
                padding: 1.25rem;
            }

            .terms-container h1 {
                font-size: 1.45rem;
            }
        }
    </style>
</head>
<body>
    <?php
    $showNavLinks = true;
    include __DIR__ . '/includes/header.php';
    ?>

    <main class="static-page">
        <div class="container terms-container">
            <h1>不動産AI名刺利用規約</h1>
            <h2 class="terms-chapter">第1章 総則</h2>
            <h3>第1条（目的）</h3>
            <p>本規約は、リニュアル仲介株式会社（以下「当社」といいます。）が提供する「不動産AI名刺」ならびにこれに付随するAIチャット機能、顧客管理機能、進捗管理機能、住宅ローンシミュレーター、AI査定機能、SelFinPRO連携機能、全国マンションデータベース連携機能、PDF解析機能その他関連サービス（以下総称して「本サービス」といいます。）の利用条件を定めるものです。</p>
            <p>利用者は、本規約に同意したうえで本サービスを利用するものとします。</p>
            <p>本サービスは不動産事業者向け営業支援ツールとして提供されるものであり、当社は利用者の営業成果、顧客獲得、売上向上その他の成果を保証するものではありません。</p>
            <hr class="terms-divider">
            <h3>第2条（定義）</h3>
            <p>本規約において使用する用語の定義は、次の各号のとおりとします。</p>
            <p class="terms-subitem">①「利用者」とは、本サービスを利用する宅地建物取引業者または当社が認めた法人もしくは個人事業主をいいます。</p>
            <p class="terms-subitem">②「契約者」とは、当社との間で本サービス利用契約を締結した者をいいます。</p>
            <p class="terms-subitem">③「エンドユーザー」とは、契約者が発行した不動産AI名刺または本サービスを利用する顧客その他第三者をいいます。</p>
            <p class="terms-subitem">④「AI機能」とは、生成AIその他人工知能技術を利用したチャット、要約、提案、文章生成、分析その他の機能をいいます。</p>
            <p class="terms-subitem">⑤「利用データ」とは、利用者またはエンドユーザーが本サービスへ入力、登録、送信または生成した情報をいいます。</p>
            <p class="terms-subitem">⑥「外部サービス」とは、OpenAI、Google、Firebase、Stripe、Amazon Web Services（AWS）、Xserverその他第三者が提供するサービスをいいます。</p>
            <p class="terms-subitem">⑦「知的財産権」とは、著作権、商標権、特許権、ノウハウその他一切の知的財産権をいいます。</p>
            <hr class="terms-divider">
            <h3>第3条（適用）</h3>
            <p>本規約は、本サービス利用に関する当社と利用者との一切の関係に適用されます。</p>
            <p>当社が本サービス上または当社ウェブサイト上に掲載するガイドライン、マニュアル、ヘルプページ、FAQ、運用ルールその他の規定は、本規約の一部を構成するものとします。</p>
            <p>本規約と個別契約の内容が異なる場合は、個別契約の内容が優先されます。</p>
            <hr class="terms-divider">
            <h3>第4条（規約の変更）</h3>
            <p>当社は、法令改正、サービス内容変更、事業上の必要性その他合理的な理由がある場合、本規約を変更することができます。</p>
            <p>当社は変更後の規約を本サービスまたは当社ウェブサイト上に掲載するものとします。</p>
            <p>利用者が変更後も本サービスを利用した場合、変更後の規約に同意したものとみなします。</p>
            <hr class="terms-divider">
            <h3>第5条（通知）</h3>
            <p>当社から利用者への通知は、本サービス上での掲示、電子メールその他当社が適当と認める方法により行います。</p>
            <p>利用者が登録したメールアドレス宛に通知を送信した時点で、当該通知は到達したものとみなします。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第2章 利用契約</h2>
            <h3>第6条（利用申込）</h3>
            <p>本サービスの利用を希望する者は、本規約に同意のうえ、当社所定の方法により利用申込みを行うものとします。</p>
            <p>利用希望者は、申込時に真実かつ正確な情報を提供しなければなりません。</p>
            <p>利用者は、登録情報に変更が生じた場合、速やかに変更手続きを行うものとします。</p>
            <hr class="terms-divider">
            <h3>第7条（利用契約の成立）</h3>
            <p>当社が申込みを承諾した時点で利用契約が成立します。</p>
            <p>当社は以下の場合、申込みを拒否できるものとします。</p>
            <p class="terms-subitem">①虚偽情報が登録された場合</p>
            <p class="terms-subitem">②過去に規約違反があった場合</p>
            <p class="terms-subitem">③反社会的勢力との関係が認められる場合</p>
            <p class="terms-subitem">④当社の競合企業である場合</p>
            <p class="terms-subitem">⑤その他当社が不適当と判断した場合</p>
            <hr class="terms-divider">
            <h3>第8条（利用料金）</h3>
            <p>利用者は当社が別途定める利用料金を支払うものとします。</p>
            <p>利用料金は当社ウェブサイト、申込書または個別契約に定めるものとします。</p>
            <p>利用料金は前払いとし、当社が別途認める場合を除き日割精算は行いません。</p>
            <hr class="terms-divider">
            <h3>第9条（支払方法）</h3>
            <p>利用料金は以下の方法で支払うものとします。</p>
            <p class="terms-subitem">①Stripe決済</p>
            <p class="terms-subitem">②クレジットカード決済</p>
            <p class="terms-subitem">③銀行振込</p>
            <p class="terms-subitem">④その他当社が認める方法</p>
            <p>決済手数料は利用者負担とします。</p>
            <p>支払済みの利用料金は、本規約に明示的に定める場合を除き返金しません。</p>
            <hr class="terms-divider">
            <h3>第10条（契約期間）</h3>
            <p>契約期間は当社が別途定める期間とします。</p>
            <p>月額契約は利用者から解約手続きが行われない限り自動更新されます。</p>
            <p>利用停止期間中であっても利用料金は発生するものとします。</p>
            <hr class="terms-divider">
            <h3>第11条（解約）</h3>
            <p>利用者は当社所定の方法により解約できます。</p>
            <p>解約月の利用料金について返金は行いません。</p>
            <p>解約後であっても、利用料金の未払債務は消滅しません。</p>
            <hr class="terms-divider">
            <h3>第12条（即時解除）</h3>
            <p>当社は利用者が以下に該当した場合、事前通知なく契約を解除できます。</p>
            <p class="terms-subitem">①本規約違反</p>
            <p class="terms-subitem">②利用料金未払い</p>
            <p class="terms-subitem">③差押え、破産、民事再生</p>
            <p class="terms-subitem">④反社会的勢力との関係</p>
            <p class="terms-subitem">⑤当社サービス運営に重大な支障を及ぼした場合</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第3章 サービス内容</h2>
            <h3>第13条（本サービスの内容）</h3>
            <p>本サービスは、不動産事業者向けのデジタル名刺および営業支援プラットフォームです。</p>
            <p>本サービスでは、デジタル名刺機能、AIチャット機能、営業支援機能、不動産関連情報の提供機能ならびに当社または提携事業者が提供する各種サービスとの連携機能を提供します。</p>
            <p>当社は、本サービスの品質向上および機能改善のため、機能の追加、変更、停止または終了を行うことがあります。</p>
            <hr class="terms-divider">
            <h3>第14条（サービス内容の変更）</h3>
            <p>当社は、利用者への事前通知なく、本サービスの全部または一部の追加、変更または廃止を行うことができます。</p>
            <hr class="terms-divider">
            <h3>第15条（サービスの終了）</h3>
            <p>当社は、事業上の理由その他やむを得ない理由により、本サービスの全部または一部を終了することができます。</p>
            <p>当社は、本サービスを終了する場合、原則として30日前までに利用者へ通知するものとします。ただし、緊急その他やむを得ない事由がある場合はこの限りではありません。</p>
            <p>本サービスの終了に伴い、終了対象となるサービスに関する利用契約は終了するものとします。</p>
            <p>当社は、本サービスの終了により利用者または第三者に生じた損害について、当社の故意または重過失による場合を除き、責任を負わないものとします。</p>
            <hr class="terms-divider">
            <h3>第16条（試験提供機能）</h3>
            <p>ベータ版その他試験的に提供される機能について、当社は品質、継続提供または正確性を保証しません。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第4章 アカウント管理</h2>
            <h3>第17条（IDおよびパスワードの管理）</h3>
            <p>利用者は、本サービスに関するID、パスワードその他認証情報を自己の責任において管理するものとします。</p>
            <p>利用者は、IDまたはパスワードを第三者に貸与、譲渡、共有または利用させてはなりません。</p>
            <p>利用者の管理不十分、使用上の過誤または第三者による不正利用により発生した損害について、当社は責任を負いません。</p>
            <p>利用者は認証情報の漏えいまたは不正利用を認識した場合、直ちに当社へ通知しなければなりません。</p>
            <hr class="terms-divider">
            <h3>第18条（SMS認証等）</h3>
            <p>当社は本人確認のため、SMS認証、メール認証その他当社が必要と認める認証手段を利用する場合があります。</p>
            <p>利用者は認証手続に必要な情報を正確に提供するものとします。</p>
            <p>認証が完了しない場合、当社はサービスの全部または一部の利用を制限できるものとします。</p>
            <hr class="terms-divider">
            <h3>第19条（アカウント利用責任）</h3>
            <p>利用者アカウントを用いて行われた行為は、当該利用者自身による行為とみなします。</p>
            <p>利用者は自己の責任において従業員その他利用権限を有する者を管理するものとします。</p>
            <p>従業員等による不正利用についても利用者が責任を負うものとします。</p>
            <hr class="terms-divider">
            <h3>第20条（利用権限の管理）</h3>
            <p>利用者は退職者その他利用資格を失った者のアカウントを速やかに停止しなければなりません。</p>
            <p>利用権限管理の不備により発生した損害について当社は責任を負いません。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第5章 利用者の義務</h2>
            <h3>第21条（法令遵守）</h3>
            <p>利用者は、本サービスの利用にあたり、宅地建物取引業法、個人情報保護法、不正アクセス禁止法、著作権法、景品表示法その他関係法令を遵守するものとします。</p>
            <hr class="terms-divider">
            <h3>第22条（顧客情報の管理）</h3>
            <p>利用者は本サービスを通じて取得した顧客情報を自己の責任において管理するものとします。</p>
            <p>利用者は顧客情報の漏えい、紛失または不正利用を防止するため必要な措置を講じるものとします。</p>
            <p>利用者による顧客情報管理に起因する損害について当社は責任を負いません。</p>
            <hr class="terms-divider">
            <h3>第23条（禁止事項）</h3>
            <p>利用者は以下の行為を行ってはなりません。</p>
            <p class="terms-subitem">①法令または公序良俗に違反する行為</p>
            <p class="terms-subitem">②第三者の権利を侵害する行為</p>
            <p class="terms-subitem">③虚偽情報の登録</p>
            <p class="terms-subitem">④サービスの不正利用</p>
            <p class="terms-subitem">⑤不正アクセス</p>
            <p class="terms-subitem">⑥リバースエンジニアリング</p>
            <p class="terms-subitem">⑦スクレイピングその他大量取得行為</p>
            <p class="terms-subitem">⑧サービスの再販売</p>
            <p class="terms-subitem">⑨競合サービス開発目的での利用</p>
            <p class="terms-subitem">⑩ウイルスその他有害プログラムの送信</p>
            <p class="terms-subitem">⑪反社会的勢力への利用</p>
            <p class="terms-subitem">⑫当社が不適切と判断する行為</p>
            <hr class="terms-divider">
            <h3>第24条（利用者の責任）</h3>
            <p>利用者は自己の責任と費用において本サービスを利用するものとします。</p>
            <p>利用者とエンドユーザーとの間で生じた紛争は利用者の責任で解決するものとします。</p>
            <p>当社が第三者から請求等を受けた場合、利用者は当社に生じた損害を補償するものとします。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第6章 AIサービス特則</h2>
            <h3>第25条（AI機能の利用）</h3>
            <p>本サービスには生成AIを利用した機能が含まれます。</p>
            <p>AI機能は自動的に回答または提案を生成するものであり、人による確認を経ていない場合があります。</p>
            <p>AI機能は利用者の判断を支援するためのものであり、利用者の判断を代替するものではありません。</p>
            <hr class="terms-divider">
            <h3>第26条（AI生成コンテンツ）</h3>
            <p>AIチャットその他AI機能により生成された回答、提案、要約、分析結果その他一切の出力内容（以下「AI生成コンテンツ」といいます。）は参考情報です。</p>
            <p>AI生成コンテンツは専門家による助言を構成するものではありません。</p>
            <hr class="terms-divider">
            <h3>第27条（ハルシネーション等）</h3>
            <p>AI機能は事実と異なる情報を生成する場合があります。</p>
            <p>AI機能は以下の内容を含む場合があります。</p>
            <p class="terms-subitem">①誤情報</p>
            <p class="terms-subitem">②古い情報</p>
            <p class="terms-subitem">③不完全な情報</p>
            <p class="terms-subitem">④矛盾する情報</p>
            <p class="terms-subitem">⑤不適切な推測</p>
            <p class="terms-subitem">⑥計算誤差</p>
            <p class="terms-subitem">⑦事実誤認</p>
            <p>利用者は重要事項について必ず自ら確認するものとします。</p>
            <hr class="terms-divider">
            <h3>第28条（専門的助言の否認）</h3>
            <p>本サービスは以下の専門的助言を提供するものではありません。</p>
            <p class="terms-subitem">①法律相談</p>
            <p class="terms-subitem">②税務相談</p>
            <p class="terms-subitem">③会計相談</p>
            <p class="terms-subitem">④建築相談</p>
            <p class="terms-subitem">⑤不動産鑑定</p>
            <p class="terms-subitem">⑥投資助言</p>
            <p class="terms-subitem">⑦住宅ローン審査</p>
            <hr class="terms-divider">
            <h3>第29条（AI回答に関する免責）</h3>
            <p>当社はAI生成コンテンツの正確性、完全性、最新性、有用性を保証しません。</p>
            <p>利用者またはエンドユーザーがAI生成コンテンツを利用して行った判断について当社は責任を負いません。</p>
            <p>AI生成コンテンツに起因する損害について当社の故意または重過失による場合を除き、責任を負わないものとします。</p>
            <hr class="terms-divider">
            <h3>第30条（学習モデルおよび技術仕様）</h3>
            <p>当社はAI機能に使用する生成AIモデル、アルゴリズム、プロンプト設計、学習データその他技術仕様について開示義務を負いません。</p>
            <hr class="terms-divider">
            <h3>第31条（AIサービスの変更）</h3>
            <p>当社はAI機能の全部または一部を変更、中断または終了できるものとします。</p>
            <p>第三者AIサービスの仕様変更、回答品質変更、API停止またはサービス終了により、本サービスの全部または一部が利用できなくなった場合であっても、当社の故意または重過失による場合を除き、当社は責任を負わないものとします。利用者はこれをあらかじめ承諾するものとします。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第7章 不動産サービス特則</h2>
            <h3>第32条（不動産情報の位置付け）</h3>
            <p>本サービスに掲載または提供される不動産情報、価格情報、取引事例情報、周辺環境情報、市場情報その他一切の情報は参考情報です。</p>
            <p>当社は当該情報の正確性、完全性、最新性または有用性を保証しません。</p>
            <p>利用者は自己の責任で情報を確認し利用するものとします。</p>
            <hr class="terms-divider">
            <h3>第33条（物件提案機能）</h3>
            <p>物件提案機能は利用者またはエンドユーザーが入力した条件に基づき候補物件を表示する機能です。</p>
            <p>提案物件は機械的に抽出された候補であり、利用者またはエンドユーザーの希望条件への適合性を保証するものではありません。</p>
            <p>提案結果に起因する損害について当社は責任を負いません。</p>
            <hr class="terms-divider">
            <h3>第34条（AIマンション査定）</h3>
            <p>AIマンション査定機能により表示される価格は参考価格です。</p>
            <p>当社は査定価格が実際の売買価格、査定価格または成約価格と一致することを保証しません。</p>
            <p>利用者またはエンドユーザーは査定価格のみを根拠として重要な判断を行わないものとします。</p>
            <p>当社の故意または重過失による場合を除き、査定結果に起因して生じた損害について責任を負わないものとします。</p>
            <hr class="terms-divider">
            <h3>第35条（住宅ローンシミュレーター）</h3>
            <p>ローンシミュレーターは利用者が入力した条件に基づき参考値を算出する機能です。</p>
            <p>シミュレーション結果は借り入れを保証するものではありません。</p>
            <p>金融機関の審査結果がシミュレーション結果と異なる場合があります。</p>
            <p>税制改正、金利変動、制度変更等により結果が変動する場合があります。</p>
            <p>当社はシミュレーション結果に起因する損害について責任を負いません。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第8章 システム利用</h2>
            <h3>第36条（外部サービスとの連携）</h3>
            <p>本サービスは、第三者が提供するAIサービス、クラウドサービス、認証サービス、決済サービス、通信サービスその他外部サービスと連携して提供される場合があります。</p>
            <p>当社は、外部サービスの障害、停止、仕様変更またはサービス終了により本サービスの全部または一部が利用できなくなった場合であっても、当社の故意または重過失による場合を除き、責任を負わないものとします。</p>
            <p>当社は、外部サービスの変更に伴い、本サービスの機能、仕様または提供方法を変更する場合があります。</p>
            <hr class="terms-divider">
            <h3>第37条（メンテナンス）</h3>
            <p>当社は保守点検、アップデート、障害対応その他必要な場合、本サービスを停止できるものとします。</p>
            <p>緊急の場合、事前通知なく停止することができます。</p>
            <hr class="terms-divider">
            <h3>第38条（サイバー攻撃等）</h3>
            <p>当社は合理的な範囲でセキュリティ対策を講じます。</p>
            <p>しかしながら、以下を完全に防止することは保証できません。</p>
            <p class="terms-subitem">①コンピュータウイルス</p>
            <p class="terms-subitem">②ランサムウェア</p>
            <p class="terms-subitem">③不正アクセス</p>
            <p class="terms-subitem">④DDoS攻撃</p>
            <p class="terms-subitem">⑤ゼロデイ攻撃</p>
            <p class="terms-subitem">⑥サプライチェーン攻撃</p>
            <p class="terms-subitem">⑦その他第三者による攻撃</p>
            <hr class="terms-divider">
            <h3>第39条（システム障害）</h3>
            <p>当社は以下に起因する障害について責任を負いません。</p>
            <p class="terms-subitem">①通信回線障害</p>
            <p class="terms-subitem">②停電</p>
            <p class="terms-subitem">③地震</p>
            <p class="terms-subitem">④台風</p>
            <p class="terms-subitem">⑤洪水</p>
            <p class="terms-subitem">⑥火災</p>
            <p class="terms-subitem">⑦戦争</p>
            <p class="terms-subitem">⑧テロ</p>
            <p class="terms-subitem">⑨政府規制</p>
            <p class="terms-subitem">⑩不可抗力</p>
            <hr class="terms-divider">
            <h3>第40条（データ保存）</h3>
            <p>当社は利用データの保全に努めますが、データの完全保存を保証しません。当社の故意または重過失による場合を除き、責任を負わないものとします。</p>
            <hr class="terms-divider">
            <h3>第41条（契約終了後等のデータ削除）</h3>
            <p>当社は利用者データを削除し、匿名化し、またはその他適切な方法により処理することができます。</p>
            <p>利用者は、契約終了日またはサービス終了日までに必要な利用データを自己の責任で保存または取得するものとします。</p>
            <p>当社は、前項に基づく利用者データの未取得または削除により生じた損害について、当社の故意または重過失による場合を除き、責任を負わないものとします。</p>
            <hr class="terms-divider">
            <h3>第42条（データ消失）</h3>
            <p>システム障害、サイバー攻撃、外部サービス障害、誤操作その他の事由によるデータ消失について当社の故意または重過失による場合を除き、責任を負わないものとします。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第9章 免責</h2>
            <h3>第43条（一般免責）</h3>
            <p>当社は、本サービス利用に関連して利用者または第三者に生じた損害について、本規約に定める場合を除き責任を負いません。</p>
            <hr class="terms-divider">
            <h3>第44条（営業機会損失等）</h3>
            <p>当社は以下について責任を負いません。</p>
            <p class="terms-subitem">①営業機会損失</p>
            <p class="terms-subitem">②契約機会損失</p>
            <p class="terms-subitem">③顧客獲得機会損失</p>
            <p class="terms-subitem">④信用毀損</p>
            <p class="terms-subitem">⑤逸失利益</p>
            <p class="terms-subitem">⑥間接損害</p>
            <p class="terms-subitem">⑦特別損害</p>
            <p class="terms-subitem">⑧結果的損害</p>
            <hr class="terms-divider">
            <h3>第45条（返金制限）</h3>
            <p>利用者が支払った利用料金は、法令上返金義務がある場合または当社の故意もしくは重過失による場合を除き、返金しないものとします。</p>
            <p>以下の場合も同様とします。</p>
            <p class="terms-subitem">①メンテナンス</p>
            <p class="terms-subitem">②システム障害</p>
            <p class="terms-subitem">③サイバー攻撃</p>
            <p class="terms-subitem">④外部サービス障害</p>
            <p class="terms-subitem">⑤通信障害</p>
            <p class="terms-subitem">⑥不可抗力</p>
            <p>月額利用料金の日割返金は行いません。</p>
            <p>本サービスの終了に伴い利用契約が終了した場合であっても、当社は未経過期間に係る利用料金の返金義務を負わないものとします。</p>
            <hr class="terms-divider">
            <h3>第46条（責任制限）</h3>
            <p>当社が損害賠償責任を負う場合であっても、その責任は直接かつ通常の損害に限られるものとします。</p>
            <hr class="terms-divider">
            <h3>第47条（損害賠償額の上限）</h3>
            <p>当社の損害賠償責任総額は、当該利用者が当社へ過去12か月間に支払った利用料金総額を上限とします。</p>
            <p>ただし当社の故意または重過失による場合を除きます。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter">第10章 その他</h2>
            <h3>第48条（知的財産権）</h3>
            <p>本サービスに関する著作権、商標権、特許権、ノウハウその他一切の知的財産権は当社または正当な権利者に帰属します。</p>
            <hr class="terms-divider">
            <h3>第49条（秘密保持）</h3>
            <p>利用者は本サービスを通じて知り得た当社の非公開情報を第三者へ開示してはなりません。</p>
            <hr class="terms-divider">
            <h3>第50条（反社会的勢力の排除）</h3>
            <p>利用者は自らおよび役員等が反社会的勢力に該当しないことを表明保証します。</p>
            <hr class="terms-divider">
            <h3>第51条（権利義務の譲渡禁止）</h3>
            <p>利用者は当社の事前承諾なく契約上の地位または権利義務を第三者へ譲渡できません。</p>
            <hr class="terms-divider">
            <h3>第52条（分離可能性）</h3>
            <p>本規約の一部が無効と判断された場合であっても、その他の規定は引き続き有効に存続します。</p>
            <hr class="terms-divider">
            <h3>第53条（協議事項）</h3>
            <p>本規約に定めのない事項または解釈に疑義が生じた場合、当社と利用者は誠意をもって協議し解決するものとします。</p>
            <hr class="terms-divider">
            <h3>第54条（準拠法および合意管轄）</h3>
            <p>本規約は日本法に準拠します。</p>
            <p>本サービスまたは本規約に関して生じた紛争については、東京地方裁判所または東京簡易裁判所を第一審の専属的合意管轄裁判所とします。</p>
            <hr class="terms-divider">
            <h2 class="terms-chapter terms-supplement">附則</h2>
            <p class="terms-date">制定日：2026年6月4日</p>
            <p class="terms-date">施行日：2026年6月4日</p>

            <div class="terms-actions" style="margin-top: 2.5rem; text-align: center;">
                <a href="index.php" class="btn-primary" style="display: inline-block; padding: 0.75rem 2rem; border-radius: 999px; text-decoration: none;">
                    トップページへ戻る
                </a>
            </div>
        </div>
    </main>
</body>
</html>
