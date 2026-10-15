# AChBot — post-V9 R4 panel-group schema migration

**Status (2026-10-16):** Source-only Marzban runtime was first published as [v0.10.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1), followed by security-only [v0.10.0-rc.2](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.2) (no migration SQL change), with successful [exact-tag PHP 8.1/8.4 CI](https://github.com/ach1992/bot-refactor/actions/runs/38688393237). Owner reports installing the official ZIP and separately applying this migration on one aaPanel/OpenLiteSpeed PHP 8.4 LSAPI installation. After one narrowly approved mtime-only activation, X-UI menu entries disappeared, Marzban groups management appeared, and Owner-reported group creation succeeded. **This is partial UI/write-flow acceptance, not independent SQL row/schema verification or complete account/transfer acceptance**; see [read-only install diagnostics](INSTALL-DIAGNOSTICS.md). [Owner's controlling decision](https://github.com/ach1992/bot-refactor/issues/31#issuecomment-6184330243) allows the additive design, **not** an automatic live SQL write. Production/staging schema operations, bot rollout, backup/restore and release each have separate gates.

## Purpose / scope

The bot can represent one actual Marzban panel as several logical servers with different pricing, plans, API URLs and administrator credentials. The **only** accepted shared-panel identity is an administrator-defined group. Identical API URLs or names never automatically assign a group; two different addresses may intentionally share a group.

The frozen V9 reference `database/legacy-schema.sql` is unchanged. Apply the additive, nullable **single-column** migration [`migrations/post-v9/001_marzban_panel_group_id.sql`](../../migrations/post-v9/001_marzban_panel_group_id.sql) only in a separately approved target environment:

```sql
ALTER TABLE server_info
    ADD COLUMN IF NOT EXISTS panel_group_id int(11) DEFAULT NULL AFTER flag;
```

This keeps the same 23 tables. No previous row is reclassified: every old server has `panel_group_id=NULL`, meaning no logical transfer until an admin assigns a group. Group definitions live in *existing* `setting` records with `type='ACHBOT_MARZBAN_PANEL_GROUP'`, `value` as a human-readable name and `setting.id` as the group ID. The schema intentionally has no FK into the polymorphic `setting` table; PHP validates the group type, membership, permissions and locks at write/switch time. The display `server_info.flag` is unrelated. Group creation uses a named MariaDB advisory lock held through commit/rollback to prevent concurrent same-name group definitions; no additional table, index or column is required.

## Separately authorized production/staging procedure

1. Obtain explicit Owner authorization for the **specified** staging/production MariaDB schema mutation and eventual bot update. Record the environment, exact candidate HEAD/TREE, reviewed migration SHA256, installed source/DB schema versions, backup plan and approved maintenance window. This source-only PR/CI does not supply that authorization.
2. Freeze bot writes and active payment/renewal/Cron webhook consumers according to the actual installation runbook. Establish an access-controlled, restorable **full database backup**, including financial tables, and prove restoration into a **separate isolated** MariaDB instance. Merely producing an archive is not recovery proof.
3. On the isolated restored copy, check MariaDB version/permissions, original `server_info` DDL and row counts, apply **only** the exact migration above, verify 23 tables still exist, every original server ID/field/value remains and `panel_group_id` is nullable/NULL. Reapplying on MariaDB must not change DDL or rows. Exercise the current post-V9 source against this isolated schema, including normal purchases/renewals, then verify no surprising payment/Cron side effects.
4. At the separately authorized maintenance window, first recheck that the live schema does not already differ unexpectedly; recheck the backup and source/DB identity. Apply the reviewed additive migration **before** enabling R4 source handlers. Confirm the column exists and is nullable; server rows, orders, plans, funds and 23 table names are unchanged. If a precondition fails, **stop and investigate** rather than forcing a migration.
5. Deploy/enable the independently approved source artifact only after its migration-aware checks and explicit release/install approvals. In the Telegram admin Server Management menu, define meaningful named panel groups. Select the correct group during server registration or edit existing servers one at a time. Operators must independently know which entries really share the underlying Marzban account store, even with differing URLs/admin accounts. Leave uncertain servers unassigned.
6. Confirm a bounded, **non-financial**, low-risk same-group logical switch using a consenting test account only with explicit operator authorization. Confirm only `orders_list.server_id` changes, provider user/links/UUID/plan/price/traffic remain unchanged, renewals remain safe, and cross-group/unassigned targets are refused. Resume ordinary traffic only after acceptance and health checks.

## Rollback and failure behavior

- **Before applying SQL:** leave database and old source untouched; no cleanup is needed.
- **After adding the nullable column:** rolling back to historical V9 or an earlier source-only build can leave the extra column in place. Do **not** drop it automatically: dropping would discard group assignments and can require a table rebuild. A destructive reverse DDL or database restoration needs its own owner approval and data reconciliation.
- **Partial rollout:** if the DB column is missing, R4 switching/group management must remain disabled; install migration before using those features. Existing ordinary purchase paths should not silently pick an arbitrary group.
- **Unassigned/invalid group:** all cross-server switches are refused until a real administrator corrects group assignment. A reassignment that would strand an active order whose original plan belongs to another group is refused.
- **Actual physical migrations** between distinct panels, rewrites of real account profile/inbounds/proxy and future PasarGuard changes are **not part of this migration**.

## Evidence boundary

`python3 tools/test_post_v9_logical_transfer.py --run-disposable` runs real PHP callbacks, original 23-table disposable MariaDB restored from fixtures, the additive migration twice, two simultaneous administrator group-creation callbacks on independent PHP/MySQL connections with a disposable delay trigger, Unicode name cases and local fake Marzban authentication. It also runs a read-oriented pinned V9 catalogue handler after the additive migration (not a complete installed-source rollback or live recovery drill). `python3 tools/check_post_v9.py` integrates it with other current source/history tests. No connected production tenant/database or real payment/Telegram/Marzban operation has been exercised or approved. The source-only ZIP does **not** include or execute SQL; CI publishes the migration beside the ZIP as a separately identifiable source file.
