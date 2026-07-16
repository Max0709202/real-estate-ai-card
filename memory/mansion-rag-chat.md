---
name: mansion-rag-chat
description: How the 全国マンションDB (mansion name) RAG lookup works in AI chat, and a fixed candidate-selection bug
metadata:
  type: project
---

The AI chat "マンション名検索" flow lives in `backend/includes/chat-public-data-helper.php` (search/normalize/generate) and is invoked from `backend/api/chat/send.php`. Frontend renders `field:'mansion_lookup'` candidates as clickable bubble buttons in `assets/js/chat-widget.js` (`appendBubbleCandidateReplies`). DB-disambiguation buttons carry `value:'mansion_id:<id>'` → resolved by `chatMansionDbDirectAnswerById`. 表記ブレ (Ⅱ⇔2, ヶ⇔ケ, 全半角) is handled by `chatMansionNormalizeText`, which MUST stay in sync with `mansionNormalizeText` in `backend/scripts/import_mansion_buildings.php` (populates name_norm/search_norm).

**Why:** Customer repeatedly reported マンション名 lookups failing. Requirements 1-6 (normalize → RAG → LLM-format DB-only facts → disambiguate with clickable candidates → ID lookup → safe "not found" reply) are all already implemented in current code.

**How to apply:** Fixes applied 2026-07-16:
1. `chatMansionDbDirectAnswer` location-qualifier bug — selecting/typing `パレステージ江北２（東京都足立区）` made `$exactRows` empty yet the old `else` ran `$rows = $exactRows`, wiping recalled rows → "見つかりませんでした". Now only overrides `$rows` when `$exactRows` is non-empty; otherwise keeps recalled rows for confidence+location filtering.
2. Sibling candidate buttons — `chatMansionSiblingCandidates($db,$row)` (prefix match on `name_norm` with trailing 番号/館 stripped) returns the same-name family; `chatMansionBuildAnswerFromRow` now appends them as clickable `field:'mansion_lookup'` buttons (value `mansion_id:<id>`) on every direct answer, so the user can switch buildings. Chosen via AskUserQuestion "Answer + sibling buttons".

**Debugging lesson:** The カーサ新宿 "not found" was NOT a code bug — the live chat was posting to a **different backend** than the migrated staging one (widget `apiBase = data-api-base || location.origin + '/backend/api/chat'`). Diagnostic tool `backend/scripts/diagnose_mansion_lookup.php` (read-only; loads the same includes as send.php) reproduces the full pipeline on any server and prints where it breaks. Also note: opcache on the web tier can serve stale code even after deploy (`validate_timestamps` may be off) — CLI always runs fresh, so CLI-works/web-fails ⇒ suspect backend origin or opcache. Delete the two temp scripts (`diagnose_mansion_lookup.php`, `opcache_reset_web.php`) once done. Verify the deployed branch matches `develop` before assuming a code bug.
