# マイページ（edit.php）で使用している画像・アイコン

## 写真（Unsplash License）

商用利用可・許諾不要・クレジット表記は任意です。WebP に変換して保存しています。
差し替える場合は同じファイル名で上書きしてください。
セクションへの割り当ては `assets/css/edit.css` の `.section-hero--*` で定義しています。

| ファイル | 使用箇所 | 内容 | 出典 |
| --- | --- | --- | --- |
| hero-mypage.webp | ページ見出しの帯 | 夕暮れの戸建て | https://unsplash.com/photos/1600585154340-be6161a56a0c |
| section-greeting.webp | 1. ヘッダー・挨拶部 | 名刺交換 | https://unsplash.com/photos/1577415124269-fc1140a69e91 |
| section-company.webp | 2. 会社プロフィール部 | オフィスビル | https://unsplash.com/photos/1486406146926-c627a92ad1ab |
| section-person.webp | 3. 個人情報 | 担当者のデスク | https://unsplash.com/photos/1587560699334-cc4ff634909a |
| section-tech.webp | 4. テックツール選択 | 分析画面 | https://unsplash.com/photos/1460925895917-afdab827c52f |
| section-comm.webp | 5. コミュニケーション機能部 | 打ち合わせ | https://unsplash.com/photos/1785902082866-384f1df89285 |
| section-template.webp | 6. テンプレート選択 | 物件外観 | https://unsplash.com/photos/1564013799919-ab600027ffc6 |
| section-payment.webp | 7. 決済 | カード決済 | https://unsplash.com/photos/1563013544-824ae1b704d3 |
| section-chat.webp | チャット履歴・顧客一覧 | 商談 | https://unsplash.com/photos/1681569685386-b7bda397672e |
| section-org.webp | 組織・配下顧客 | チーム会議 | https://unsplash.com/photos/1731458769726-cef60c792665 |
| section-ai.webp | AI育成 | AIイメージ | https://unsplash.com/photos/1677442136019-21780ecad995 |
| section-band.webp | 自社帯登録 | 図面の作成 | https://unsplash.com/photos/1503387762-592deb58ef4e |

人物が写っている4点（挨拶・コミュニケーション・チャット履歴・組織）は、
アジア系の人物が写っているものを選んでいます。
決済・自社帯登録の2点は手元のみで顔は写っていません。

Unsplash License: https://unsplash.com/license

## アイコン（Lucide / ISC License）

`icons/` 配下のSVGは Lucide v1.33.0 のアイコンです。ISCライセンスで商用利用可。
取得元のままだと `stroke-width="2"` なので、既存デザインに合わせて `1.6` に変更し、
`class` 属性とライセンスコメントを除いた以外は無加工です。
`edit.php` の `editSectionIcon()` がセクションキーとファイル名を対応付けています。

| ファイル | 使用箇所 |
| --- | --- |
| icons/megaphone.svg | 1. ヘッダー・挨拶部 |
| icons/building-2.svg | 2. 会社プロフィール部 |
| icons/contact.svg | 3. 個人情報 |
| icons/layout-grid.svg | 4. テックツール選択 |
| icons/messages-square.svg | 5. コミュニケーション機能部 |
| icons/layout-template.svg | 6. テンプレート選択 |
| icons/credit-card.svg | 7. 決済 |
| icons/message-square-text.svg | チャット履歴・顧客一覧 |
| icons/network.svg | 組織・配下顧客 |
| icons/brain-circuit.svg | AI育成 |
| icons/panel-bottom.svg | 自社帯登録 |

Lucide: https://lucide.dev/ / ISC License: https://github.com/lucide-icons/lucide/blob/main/LICENSE
