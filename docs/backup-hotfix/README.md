# AChBot V6.1 — automatic database backup reliability maintenance

**Controlling Issue:** [#18](https://github.com/ach1992/bot-refactor/issues/18). Source base: merged V6 `b43b12132537e0e383729cbef26745b420d0553d`. **Candidate only — NOT merged, released, installed or operator-approved.**

## Evidence and root cause

The owner reported automatic Telegram backup failing since approximately V5 deployment. Read-only live aaPanel checks: four registered `rewardReport.php` wrappers explicitly use PHP 8.1 with `zip/mysqli/curl`, override `PATH` without `/www/server/mysql/bin` and do not inherit the parent PATH. MariaDB 10.11's executable is `/www/server/mysql/bin/mysqldump` but no `/usr/bin/mysqldump` exists. A zero-byte `backup/bot_backup.sql` and absent ZIP matched a failed dump. `LAST_BACKUP_TIME` advanced despite failure. The entire legacy backup function and scheduler had been unchanged from the earliest imported baseline through V6; this is a proven source dependency and deployment compatibility defect, not a demonstrated V5 code regression.

## Intentional changes, only inside backup boundary

- Public original Cron remains `settings/rewardReport.php`, still shares reward reports and payment/auto-approval logic. `bot.php`, `pay/`, subscriptions, settings filenames, Telegram transport and 23-table MariaDB schema remain unchanged.
- `sendDatabaseBackup()` is the same global entrypoint, now delegating to `src/Backup/Database.php` only when called. No new bootstrap work on bot requests.
- Discover installed dump executables at known aaPanel and system absolute locations, with optional vetted absolute `ACHBOT_MYSQLDUMP_BIN` override; no system symlink, PATH, package install, or Cron edit needed. Optional `ACHBOT_MYSQLDUMP_SOCKET` is for unusual installations and isolated native tests; default retains localhost connection policy.
- Match the actual MariaDB/MySQL completion-footer clock notation, including MariaDB's space-padded hours 0–9. The existing strict footer-at-EOF, valid-time, schema and dump-status gates remain active (operator issue: repeated `dump_invalid_or_incomplete` with stock aaPanel MariaDB 10.11, after source V6.1 RC1 deployment).
- Use a restrictive one-use client option file instead of passing credentials on command line; use shell-escaped arguments and status checking. Dump and ZIP exist only in a fresh mode-0700 private directory in a system temp location **outside the application/webroot**. Remove SQL/ZIP/credentials and stderr after each success or failure. The old public `backup/` directory and existing files are **not touched**.
- Preserve existing ZIP member `bot_backup.sql`, Telegram `sendDocument` request/caption/recipient and count queries. A bounded-memory structural check requires the MySQL/MariaDB dump header, host/database identity line, table DDL and final completion marker before accepting a status-zero SQL file; this is **not** a SQL parser or restore verification. Only the new `[AChBot backup]` error-log entries contain sanitized reason codes (without credentials, SQL, recipient/channel IDs, raw command errors or local file paths). **The unchanged legacy Telegram transport emits its own diagnostics on failures, potentially including `chat_id`, a CURLFile temporary ZIP path and cURL error messages.** Operators must protect PHP and transport logs as sensitive data.
- Preserve `setting.LAST_BACKUP_TIME` as the **next-due timestamp**: check it under one per-installation advisory file lock; advance only after confirmed Telegram success, and apply 60-second private retry cooldown after failure. No database schema migration or new DB setting keys.

## Guardrails / limitations

The operational host has **not** received any script or configuration changes and has not been connected to for testing. Passing CI/native synthetic acceptance does not verify its actual DB credentials, `mysqldump` permissions, SMTP/Telegram channel, backup archive size, operator paths, runtime `open_basedir` or production Cron behavior. Live backup success and restoration have **not** been claimed.

Keep the existing bot/source live until independent review, exact-candidate CI, owner authorization and a controlled operator trial. Do **not** casually run `settings/rewardReport.php` to test backup; that file may process automatic financial approval. Never place real backup ZIPs, `baseInfo.php` or SQL dumps in GitHub/test transcripts.

V7 product-plan development in `docs/REFACTOR-STAGES.md` remains a separate future scope; V6.1 is an incident-driven maintenance patch.
