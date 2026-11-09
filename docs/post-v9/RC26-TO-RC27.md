# AChBot RC26 -> RC27: source-only release / installation boundary

**Version:** v0.10.0-rc.27 (GitHub source-only prerelease).
**Release commit:** 27e7d03a2f1adafbec24f4921e253c10325b78d3.
**Source tree:** fcc1d5b8928d60f81b695947e76dde01b028dba0.
**Independently reviewed implementation:** PR #136, HEAD 8704fbbc8ba75ef3fe4d587a5ac74075c34d0d4b.

Publication is **NOT installation, database migration, host approval, or live money/Native/Telegram acceptance**. Every Chili/host/DDL effect requires its own explicit authorization.

## What changed

RC27 replaces RC26's experimental payment/service/Telegram recovery workbenches with **one private, on-demand administrator inbox** for unfinished ORIGINAL NOWPayments, Zarinpal and NextPay payments. The list shows amount in toman, numeric customer ID and gateway. Detail preserves original invoice, payment, Native and error evidence. Authorized payments administrators see Approve, Reject, terminal Archive and Back.

Archive is a **local terminal decision**: it preserves original merchant/payment/provider and financial/Native/Telegram evidence, and blocks future automated effects for that original. It is not proof of a merchant refund, external Native cancellation, reversal of an already-dispatched effect, or successful delivery. A separately authenticated late NOWPayments child transaction may be recorded without reopening the original.

RC27 closes races between a concurrent verified NextPay/Zarinpal browser return and a running hosted-payment worker. A competing browser gets pending; actual failed/NULL MariaDB lease acquisition produces review/uncertain, never false success. It retains once-only native dispatch and wallet guards, staff authorization, and historical 018 sealed delivery and conditional 021 fingerprint read compatibility. Ordinary bank/gift/API/manual/wallet/referral, Native and Telegram paths remain supported. The experimental paid-replacement administrative workflow is retired.

## Assets and verification

Download the version-matched runtime ZIP, its manifest, SHA256SUMS, and separate SQL sidecars from:
https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.27

Verify SHA256SUMS and then validate the ZIP using a private checkout at the exact release commit with tools/package_post_v9.py verify and the release's ZIP and manifest paths. The ZIP contains 131 runtime members, **no private configuration, no migrations, no installer and no customer data**. Do not deploy GitHub's auto-generated source archive as the runtime.

## Schema boundary: additive migration 022

RC27 requires migrations/post-v9/022_gateway_review_archive.sql. Its only changes are two nullable fields on the EXISTING payment_attempts row:
- review_archived_at DATETIME DEFAULT NULL
- review_archived_by BIGINT UNSIGNED DEFAULT NULL

For an **existing RC26 installation**, retain the installed 018/019/020/021 objects and historical records. **Do not rerun 018/019/020/021, DROP the old 019 table, or DROP any 021 columns**: historical digest/fingerprint verification can still read them. Apply only a verified, genuinely absent 022, after a separately authorized, safe cutover. Prior migrations are already committed and not a replay recipe.

For a **genuinely new empty database**, apply the release's historical baseline and current prerequisite migrations in their documented order, followed by **018 -> 020 -> 022**. Experimental 019/021 are *not* part of the new-install sequence. This is NOT an upgrade procedure for old installations.

Before any separately authorized 022 DDL:
1. Confirm exact installed runtime/config, target database, migration history, and the original payment_attempts schema, unique pay-row index and presence/absence of 022 fields.
2. Inventory old 018/019/020/021 and financial/Native/order/Telegram evidence without deleting it. Preserve all encryption keys and original private settings.
3. Decide recovery/rollback or evidence-preserving roll-forward for the actual target; MariaDB DDL autocommits. The Owner's backup waiver covers only named TEST chilibot, not the shared host or DDL approval.
4. Obtain explicit approval for that exact target and SQL action; use the controlled cutover to apply only the absent 022 and install matching RC27 runtime. Preserve in-flight Cron locks and avoid concurrent webhook/Cron changes without an operations plan.
5. Verify both fields, original payment histories, startup readiness, private staff permission, invoice uniqueness, terminal Archive and no new financial/Native effects from stale callbacks before enabling normal traffic.

A test database is not proof of harmless external effects. A rollback of PHP files alone cannot undo committed financial/Native/remote/Telegram outcomes.

## Acceptance still outstanding

GitHub source review and full PHP 8.5 tests passed. Merged-main CI accepted the same source tree and independently rebuilt the runtime ZIP. This does **not** prove the installed Chili state or real merchant/Telegram/Native operations.

After a separately approved installation, inspect the exact deployed file manifest and schema; test all three gateway inbox flows, terminal Archive races, pending/NULL callback feedback, bank/gift/API/manual and referral continuity, Native uncertainty, Telegram ACK and Cron/webhook health. Preserve original evidence and useful diagnostics. Never auto-retry unknown remote CREATE or extra wallet credit.

Published RC26 release assets remain immutable and must not be mixed with RC27's new-install 018/020/022 chain.
