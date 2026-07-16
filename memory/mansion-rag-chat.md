---
name: mansion-rag-chat
description: How the 全国マンションDB (mansion name) RAG lookup works in AI chat, and a fixed candidate-selection bug
metadata:
  type: project
---

The AI chat "マンション名検索" flow lives in `backend/includes/chat-public-data-helper.php` (search/normalize/generate) and is invoked from `backend/api/chat/send.php`. Frontend renders `field:'mansion_lookup'` candidates as clickable bubble buttons in `assets/js/chat-widget.js` (`appendBubbleCandidateReplies`). DB-disambiguation buttons carry `value:'mansion_id:<id>'` → resolved by `chatMansionDbDirectAnswerById`. 表記ブレ (Ⅱ⇔2, ヶ⇔ケ, 全半角) is handled by `chatMansionNormalizeText`, which MUST stay in sync with `mansionNormalizeText` in `backend/scripts/import_mansion_buildings.php` (populates name_norm/search_norm).

**Why:** Customer repeatedly reported マンション名 lookups failing. Requirements 1-6 (normalize → RAG → LLM-format DB-only facts → disambiguate with clickable candidates → ID lookup → safe "not found" reply) are all already implemented in current code.

**How to apply:** The real defect (fixed 2026-07-16) was in `chatMansionDbDirectAnswer`: when the user selected/typed a candidate label like `パレステージ江北２（東京都足立区）`, the location qualifier made `$exactRows` empty (name+location never equals bare building_name) yet the old `else` branch ran `$rows = $exactRows`, wiping recalled rows → "該当物件が見つかりませんでした". Fixed to only override `$rows` when `$exactRows` is non-empty, otherwise keep recalled rows and let confidence+location token filtering pick the row. Several customer-reported cases (Ⅱ→2, カーサ新宿) appear to already work in current code and are likely stale-deployment artifacts — verify the deployed branch matches `develop` before assuming a code bug.
