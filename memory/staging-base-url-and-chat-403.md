---
name: staging-base-url-and-chat-403
description: Staging setup (host/docroot, BASE_URL env-awareness) and the two root causes of the staging chat 403
metadata:
  type: project
---

Staging runs at `https://staging.ai-fcard.com`, served from a subfolder of the production web root: `/home/xs013436/ai-fcard.com/public_html/staging.ai-fcard.com/` (Xserver, host `sv16576`, SSH user `xs013436`). Staging vs production is decided at runtime by `APP_ENV=staging` or request host — the DB config split is in [backend/config/database.php](../backend/config/database.php) (`config.staging.php` vs `config.production.php`), and `BASE_URL` is now environment-aware in [backend/config/config.php](../backend/config/config.php) (env var → staging host/APP_ENV → production default). The chat widget's API host derives from `BASE_URL` in [card.php](../card.php) (~line 1298), so a wrong `BASE_URL` made staging call production APIs cross-origin.

**Why:** the "SMS認証の有効期限が切れています" 403 on staging `send.php` had TWO stacked causes: (1) pre-fix `BASE_URL` was hardcoded to production, so SMS verify wrote the device row into the production DB while staging `send.php` read the staging DB; (2) the staging `chat_session_devices` table was missing `verified_until` because the in-code migration silently failed — see [[mariadb-native-prepares-migrations]].

**How to apply:** to verify `BASE_URL` on the server, CLI has no `HTTP_HOST`, so run `php -r '$_SERVER["HTTP_HOST"]="staging.ai-fcard.com"; require "backend/config/config.php"; echo BASE_URL;'` (or `APP_ENV=staging php -r ...`). After host/schema fixes, the browser must clear chat localStorage keys `ai_fcard_chat_visitor_id` and `ai_fcard_chat_session_id:*` and redo SMS auth, else the stale session_id points at a device row in the wrong DB. Firebase Authorized Domains must include `staging.ai-fcard.com` for SMS to complete there.
