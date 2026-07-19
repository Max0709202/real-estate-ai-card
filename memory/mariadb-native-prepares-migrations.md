---
name: mariadb-native-prepares-migrations
description: Why in-code schema migrations using SHOW COLUMNS/INDEX with bound placeholders silently fail on this MariaDB
metadata:
  type: project
---

The DB connection runs with `PDO::ATTR_EMULATE_PREPARES => false` (native prepares) in [backend/config/database.php](../backend/config/database.php). On the production/staging MariaDB, a **native-prepared `SHOW COLUMNS ... LIKE ?` or `SHOW INDEX ... WHERE Key_name = ?` throws** (`1064 syntax error near '?'`). Several `ensure*Table()` helpers wrap their `ALTER TABLE` migrations in `try/catch` that only `error_log`s, so when the introspection query throws, the migration is **skipped silently** — the column/index is never added, and no error surfaces to the user.

**Why:** this bit staging hard — `chat_session_devices` existed there in an old shape (only `id, session_id, visitor_identifier, first_seen_at, last_seen_at`), so `verified_until` was never added. `chatSessionDeviceAuth()` then threw on `verified_until > NOW()`, was caught, returned null, and every chat `send.php` returned 403 "SMS認証の有効期限が切れています". See [[staging-base-url-and-chat-403]].

**How to apply:** when adding schema-drift migrations, introspect with a **plain** `SHOW COLUMNS FROM t` / `SHOW INDEX FROM t` (no bound params) and compare in PHP, or use `information_schema`. Never bind a placeholder into a `SHOW` statement in this codebase. Fixed in `ensureChatSessionDevicesTable()` ([backend/includes/chat-phone-helper.php](../backend/includes/chat-phone-helper.php)); audit other `ensure*` helpers if new ones appear. When a table pre-exists on a server, verify the migration actually ran (`SHOW COLUMNS`) rather than trusting the silent helper.
