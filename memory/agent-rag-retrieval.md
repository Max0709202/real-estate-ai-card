---
name: agent-rag-retrieval
description: How agent-registered RAG (edit.php 登録済みRAG) is retrieved in chat, and the two bugs that made it unused
metadata:
  type: project
---

Agents register their own RAG in `edit.php` (`#agent-rag-list`, 一括登録) → API `backend/api/mypage/agent-training.php` → table `agent_custom_rag_items` (business_card_id, title, content, source_note, enabled). Chat retrieval is `getAgentCustomContextForChat()` in `backend/includes/chat-rag-helper.php`, called ONLY from `getBotReplyWithOpenAI()` (openai-chat-helper.php:541) and injected as 【担当者追加RAG】. Business card id: saved via `WHERE user_id = ? LIMIT 1`; read via `chat_sessions.business_card_id`.

**Why:** Customer reported 2026-07-17 that RAG registered in edit.php was not used in chat ("数日前は反映されていました"). Two independent causes, both real.

**How to apply:** Fixed 2026-07-17 in `chat-rag-helper.php`:
1. **Unmatchable tokens** — term extraction used `[一-龥ぁ-んァ-ンA-Za-z0-9０-９]{2,}`, one class mixing 漢字/かな/英数. Japanese isn't space-delimited, so a whole sentence became ONE token → `content LIKE '%夏季休業はいつからですか%'` never matched. (Also `ァ-ン` excludes 長音符 ー → 住宅ローン → 住宅ロ.) Replaced with `chatRagTokenizeMessage()`: per-script runs (漢字 / カタカナ incl. ー+半角 / 英数), ≥2 chars, ひらがな-only tokens dropped as particle noise. Verified 0/7 → 5/7 sample queries match.
2. **Dead fallback** — rescue was `if (empty($rows) && empty($terms))`, but cause #1 made `$terms` always non-empty, so it never fired. The global RAG uses `if (empty($rows) && !empty($terms))`. Changed to `if (empty($rows))` so a non-matching question still loads the agent's recent RAG and lets `chatScoreKnowledgeChunk` judge relevance.

Bug #1/#2 date from `e6220e1` (2026-06-17) and were latent — agent RAG only ever worked for short literal-keyword queries. The 「数日前」 regression is a SEPARATE cause: the マンション gate added 2026-07-14 (`a344865`) skips `getBotReplyWithOpenAI()` entirely, so RAG was never queried — see [[mansion-rag-chat]]. BOTH must be fixed for agent RAG to work.

3. **No precedence instruction (retrieval OK but ignored)** — `openai-chat-helper.php:575` injected `$agentCustomContext` at the tail of the prompt (line ~774, after ragContext/publicDataContext) with NO instruction about authority. The only RAG instruction (`$freshnessInstruction`) refers to the GLOBAL 「ローカルRAG参照情報」, not 【担当者追加RAG】. So the model fell back on its strong prior "I'm an AI → available 24/7" and answered 「24時間対応」 for 営業時間/定休日 even though both were registered — while 夏季休業 answered correctly because no competing prior existed. Fixed by prefixing 「# 担当者登録情報（最優先・一次情報）」 declaring the registered data authoritative over general knowledge, and stating explicitly that the AI's 24/7 intake ≠ the company's 営業時間/定休日. Symptom signature: *some* RAG entries work and others are overridden ⇒ suspect precedence, not retrieval.

**Unverified risk:** `user_id` has no UNIQUE constraint on `business_cards`, and `agent-training.php:16` uses `LIMIT 1` with NO ORDER BY (chat/sessions.php:76 uses `ORDER BY id ASC LIMIT 1`). If an agent owns >1 card, RAG saves to one card while the visitor chats with another → RAG silently unused. Check: `SELECT user_id, COUNT(*) FROM business_cards GROUP BY user_id HAVING COUNT(*) > 1;`
