# Post-V9 Marzban-only — isolated operator acceptance

**Status (2026-10-15): PARTIAL operator acceptance.** The stale X-UI menu symptom on one aaPanel **OpenLiteSpeed / PHP 8.4 LSAPI** installation was resolved after a one-time, explicitly authorized, installation-scoped mtime-only refresh of exact released `bot.php` and `config.php`. The Owner confirmed the new Marzban groups menu, initial empty state and successful group creation after separately applying the additive migration. These are operator-reported UI/write-flow results; no independent DB row/ID check or full subscription, permission, migration recovery, financial or logical-transfer acceptance has passed. This checklist is **not complete** and authorizes no new live operation. See [INSTALL-DIAGNOSTICS.md](INSTALL-DIAGNOSTICS.md) and [Issue #31](https://github.com/ach1992/bot-refactor/issues/31#issuecomment-6199206127).

## Source and migration identity

- Published [source-only v0.10.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1) at exact commit [`6645ac16a1f8a8a99aa8fc2bd203a7937bd01c7c`](https://github.com/ach1992/bot-refactor/commit/6645ac16a1f8a8a99aa8fc2bd203a7937bd01c7c), tree `39263d483e801e3b617af41131feb2b9d9d85654`, 92 runtime files; the only post-R6 difference is documents. Release ZIP SHA256: `1d9f65e1275afa5b19be44f7d8c6d10527e56f40a40a708031cecb8c5d05aa3e`.
- [Exact v0.10.0-rc.1 main CI — PHP 8.1 and PHP 8.4 PASS](https://github.com/ach1992/bot-refactor/actions/runs/38688393237). Its official source-only artifact contains the same verified 92-member runtime ZIP as the [public pre-release](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1), with the migration distributed separately.
- Migration SHA256: `786c0e7b64b44420597ba86e9ff6ec38743af62011fe42433ceb01c6bb53aa30` for [`001_marzban_panel_group_id.sql`](../../migrations/post-v9/001_marzban_panel_group_id.sql). It is supplied **outside** the 92-member runtime ZIP.
- Previous historical source-only release [`v0.9.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1) does **not** require this column; current published [`v0.10.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1) **does**.

## Gate A — prerequisites before any installation or real SQL

- [ ] Receive **separate explicit Owner authorization** naming the isolated test environment, any actual database to be modified, intended source package and rollout/migration scope. Owner's permission to merge PR #32 alone is not this authorization.
- [ ] Confirm this is an independently controlled test bot/database, not the unrelated older working bot or an unapproved production tenant. Inventory the installed source revision, PHP and MariaDB versions, webhook/Cron/payment jobs and current schema without exposing credentials or customer data.
- [ ] Preserve a current **full MariaDB backup** and private installation/config backup under access control. Actually restore the SQL backup into a **separate isolated MariaDB** and verify table/data integrity. A successful backup ZIP or Telegram delivery is not restore proof.
- [ ] Prepare a source-only rollback to the previous pinned, verified installation; plan maintenance/write freeze for webhooks, payment callbacks, renewals and Cron. Do not let two application versions concurrently mutate the same schema during rollout.
- [ ] Verify exact official package manifest/source SHA and the reviewed migration SHA256. Never deploy an unverified generic GitHub source archive as though it were the verified runtime ZIP.

## Gate B — isolated recovery and schema rehearsal

- [ ] On the restored **isolated** database, verify the original 23 tables, `server_info` structure, server IDs/row counts and critical payments, orders, plans and wallet values **without publishing them**.
- [ ] Apply only the separately approved single nullable `server_info.panel_group_id` column; verify that all previously ungrouped servers remain `NULL`, all original rows/columns and table names remain, and a second application does not change DDL/rows.
- [ ] Verify the proposed post-V9 source against the migrated isolated copy, with financial/Cron external effects blocked. Revert to the previous source on the isolated copy as a **bounded compatibility smoke**; do not infer that real production recovery is proven.

## Gate C — staged operator checks after explicit install approval

- [ ] Keep only test Telegram identities, provider accounts and artificial balances; use a truly isolated bot/webhook and Marzban test account. No real payment gateway, TRON transfer, wallet debit, customer migration or active production Cron testing.
- [ ] Confirm `/start`, normal customer/admin authorization, existing subscriptions, catalog/plan selection, account details, safe renewal and callback behavior; check actual web SAPI (OpenLiteSpeed LSAPI or PHP-FPM), webhook and Cron readiness without enabling unintended settlement jobs.
- [ ] Under **Server Management → Panel Groups**, create a named group in Persian, reject a duplicate name, assign two logical server records **only when they are known to be the same actual physical Marzban backend**, including the case of different API URLs/admin accounts.
- [ ] On a consenting **test** subscription, confirm permitted same-group relocation changes **only `orders_list.server_id`**. Verify original plan, price, UUID/link, Marzban remote account, payment/wallet and capacity are unchanged and renewal eligibility remains.
- [ ] Confirm negative controls: unassigned server, different group, deleted/invalid group, inactive or zero-capacity destination, non-admin/other-user request, stale/replayed callback, unsupported old panel. These must fail safely without unintended account, finance or server changes.
- [ ] Capture redacted test timestamps/outcomes, installed source identity, schema/column presence, webhook/PHP logs and failures. **Never** paste customer exports, database credentials, Telegram bot tokens or complete payment callbacks into GitHub.

## Stop / recovery rule

If preconditions, restore, schema, webhook, authorization or state assertions fail: **stop and do not enable normal traffic or real payment/Cron processing**. Disable the new application path as approved, restore the previous source from its verified manifest, inspect the database and report the exact redacted failure in Issue #31. A source rollback may safely **leave the added nullable column**; do **not** drop it or restore an old live database blindly. Any destructive DB rollback is a **new Owner decision**, with data reconciliation.

## Reporting and completion

Record environment identification (without secrets), exact source/package SHA, verified backup/isolated restore, schema identity, staged scenario matrix, failures/recovery outcome and approvals in [Issue #31](https://github.com/ach1992/bot-refactor/issues/31). **Only after those real installation tests pass** should the owner decide on broader rollout, separate finance remediation and issue closure. Read the [detailed migration runbook](PANEL-GROUPS-MIGRATION.md) for ordering and boundaries.
