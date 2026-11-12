# RC30 to RC31: source-only publication and operator upgrade boundary

This guide describes AChBot v0.10.0-rc.31 compared with the prior released v0.10.0-rc.30. A GitHub Release or downloaded ZIP is **not** an installation, schema migration, payment settlement or live Telegram/Native provider test. Protected Chili order #33 and historical service-plan journal #2 remain unchanged unless separately approved.

## Changes in source

- **Service changes:** Marzban applies one protocol-complete Host PUT (explicitly clearing removed protocols); PasarGuard uses its own canonical Group semantics. Strict GET readback and durable journal finality precede local plan updates. Admin-only GET-only recovery and a separately authorized one-shot corrective Native write keep uncertain operations reconcilable.
- **Payment finality:** after a verified real plan/server change, unfinished original renewal/day/volume invoices for that subscription are retired only at the same local commit. Original provider IDs, amounts, receipts, funds and journals remain as evidence. Late verified funds can enter financial review but must never fulfill the old invoice against a changed service.
- **UI and reporting:** a real Bot Settings customer-plan switch defaults ON to preserve the previous behavior; existing admin management is independent. Successful service changes notify permitted staff and the configured reports channel.
- **Bulk accounting:** administrator no-payment and customer/agent paid multi-account paths report original source/invoice, full/partial confirmed count, individual username and local order IDs, and uncertain Telegram deliveries. Marzban-generated `mbz_bulk_` and `mbz_paid_` usernames have at most 32 ASCII bytes and do not rename previously dispatched effects. PasarGuard and explicit names retain their separate contracts.
- **Local deadlock recovery:** after a proven InnoDB 1213 rollback, at most one additional *SQL-only* finalization attempt can occur for manual purchase. The original Native CREATE/GET, capacity hold and payment are not replayed. PR #150 adds cross-journal, dual-provider finality tests.

## Schema and cutover prerequisites

1. **No new DDL or migration was introduced between RC30 and RC31.** Do not rerun already-applied migrations. Preserve existing payment and service evidence, including valid installed 003, 014, 017, 018, 020 and 022 schema/journals. SQL release sidecars are references, not instructions to execute them.
2. For a separately approved cutover, identify the actual installed version from the *complete* file SHA manifest; a few matching files or an absent ZIP is not proof. Verify the new runtime ZIP and manifest against the release SHA256SUMS before using them.
3. Preserve private `baseInfo.php`, bot and staff settings, tenant data, gift-code encryption evidence, payment/Native/Telegram journals, current Cron/webhook schedules, installed configuration and required secrets. No old source or user account record is garbage to delete for release cleanup.
4. Inspect the actual live schema and active payment/service-plan operations before changes, take appropriately scoped file/database/config snapshots and **prove a restore path**. The Git tag cannot reverse external money transfers or Native writes.
5. Only with separate, current target-specific authorization and a verified rollback plan, stage the checked source-only ZIP using the operational host procedure. Preserve the old release artifact. A missing schema prerequisite or a destructive data change needs its own approval; publication never silently runs SQL.
6. Runtime validation of the source was performed under PHP 8.5 in eight isolated native-family suites; it does not verify the installed host PHP, extensions, provider version, merchant APIs or Telegram behavior.

## Installed acceptance checklist — NOT performed by publishing

- Administrator bulk: multiple accounts for one source; distinct Native names; local orders and the exact total reported; account-by-account Telegram packets. Inject ambiguous cases only in disposable test installations.
- Customer and agent purchases: single/multiple account wallet, manual evidence, hosted gateway outcomes; full-count reporting, original-order identities, capacity and no duplicate CREATE/charge; separate delivery acknowledgements.
- Plan and server changes: Marzban obsolete protocol clearing, PasarGuard Group replacement, shared physical references, staff/customer switch permissions, reporting fanout and GET-only recovery. Explicit corrective PUT only on an authorized disposable target.
- Prior old-service invoices: superseded quotes do not settle against the new service, authentic late money remains reviewable, valid new post-change quotes remain payable, and no phantom refund/wallet credit is asserted.
- Confirm real Telegram photo, message and receipt-button updates independently of SQL/Native success. Preserve provider Note marker, original journals and diagnostics.

## Rollback and ownership

RC30 remains the prior immutable recovery artifact. Retain release SHA256SUMS, source manifest and tested restore/roll-forward instructions for each actual installation. Replacing PHP files after a partially completed external payment or Native mutation cannot undo it; reconcile its original evidence instead of repeating the call.

Sources: [PR #149](https://github.com/ach1992/bot-refactor/pull/149), [PR #150](https://github.com/ach1992/bot-refactor/pull/150), [full PHP 8.5 evidence](https://github.com/ach1992/bot-refactor/actions/runs/43119266197), [service Issue #144](https://github.com/ach1992/bot-refactor/issues/144), [bulk Issue #147](https://github.com/ach1992/bot-refactor/issues/147).
