# RC20 → RC21

RC21 makes optional bank-SMS and gift-code verification follow the existing receipt-review journey. [PR102](https://github.com/ach1992/bot-refactor/pull/102) and [Issue57](https://github.com/ach1992/bot-refactor/issues/57) own implementation and acceptance. The [RC21 release](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.21), manifest and SHA256SUMS identify the exact source and assets. Publication is separate from target installation, SQL execution, API enablement and live acceptance.

## Behavior and artifact boundary

The table describes newly issued RC21 invoices. Earlier pinned RC20 operations keep their original recovery policy as described below; this release does not rewrite them.

| Area | RC21 behavior |
| --- | --- |
| Bank transfer, API OFF or ON | Offer receipt submission immediately; deliver the real receipt with the original subject-specific administrator caption/actions before any new API request or consumption |
| Gift card, API OFF | Retain the original image submission and human review |
| Gift card, API ON | Accept a code and show it as escaped copyable Telegram code in the same original review caption/actions; check only after primary administrator delivery |
| Automatic API settlement | Use exact matching evidence, the shared financial claim and native journals; update valid staff copies to "تایید خودکار توسط API" after completed API-backed settlement |
| Unsuccessful or uncertain check | Preserve the truthful human-review or uncertain-operation state; do not repeat an external effect or ask for a second payment |
| Separate legacy bank timer | Retain unpinned receipt behavior; never claim an API-pinned bank invoice, including under a stale eligibility scan |

The customer bank journey has no Check payment / 600-second fallback distinction for new issuance. Customer acknowledgment precedes optional verification; delayed Telegram acknowledgment cannot undo delivered evidence or committed settlement. Original quotes, single-card value, manual/API races and late evidence-only consumption remain. Staff projection rechecks the current winner after asynchronous edits. All supported purchase, renewal, add-on and wallet subjects keep their original review routes/captions.

The runtime remains **119 members / 118 PHP files**. Use the whole matching runtime and its manifest; do not replace only payment modules. It excludes private configuration, SQL, tests and setup tools. RC20's display/QR, managed invitation, plan-independent physical gifts and notification routing remain. RC20's historical release assets are unchanged.

## Schema and existing operations

RC21 adds **no schema change relative to RC20**. Source `migrations/post-v9/014_api_payment_evidence.sql` remains byte-identical: SHA256 `2185934b8775989be2533b2ff47499cd1f483929c2b89552179523d7d74e378a`. It is a separate sidecar, not part of the runtime ZIP and never executed by publication or extraction.

- If the target has complete correct014, verify its actual definitions and perform no014 SQL.
- If014 is absent, keep both new-issuance API switches OFF. Its19 nullable attempt columns,5 nullable transaction columns and2 indexes still need a separately authorized, backed-up schema operation before API enablement.
- If014 is partial or incompatible, inspect all existing definitions and obtain an audited completion plan. It contains two independently committing ALTER statements without IF NOT EXISTS; never blindly rerun the whole file, drop evidence or restore stale rows.

Never re-import the fresh-install baseline into an existing bot, replay003, or rerun completed012/013. [RC19 → RC20](RC19-TO-RC20.md) defines014 preparation and partial-state recovery. An older installation retains its matching earlier migration, retired-file and private-configuration obligations; RC21 does not prove which of those are complete. It need not install RC20 as an intermediate runtime. A genuinely new empty database uses [fresh installation](FRESH-INSTALL.md).

API switches govern new issuance. Existing issued profiles, quotes, credentials, codes, claims and in-flight operations retain their original pins and recovery obligations. RC21 does not backfill/rewrite old invoices or silently convert an old profile. Turning a switch OFF does not quiesce existing API work or roll back consumption. Coordinate actual writers and inspect pending operations during the maintenance plan; never clear an uncertain hold or replay request69. Preserve the per-bot webhook secret because it decrypts profiles and codes.

## Separately authorized target cutover

1. Identify the canonical bot directory, actual installed source, serving PHP, per-bot database binding/schema and current writers. A staged ZIP or published release is not installed-source proof; do not guess a default database.
2. Obtain a protected current source/configuration/database backup and credible recovery procedure. Preserve financial/native journals, source/quote/claim pins, recoverable codes, settings, notification evidence and uncertain holds.
3. Quiesce the target's real webhook/payment/scheduled writers through its maintenance mechanism. Preserve the Cron command/environment and active lock path/inode; never delete or recreate the lock or change the canonical installation path.
4. Inspect complete schema definitions. A correct RC20 schema needs no new migration. Any missing/partial014 work follows its separate authorization/preflight/recovery plan; a capability PASS alone is not full DDL/history/profile certification.
5. Replace the complete verified RC21 runtime consistently, preserve private configuration/data, and keep retired files absent using the matching older-version transition guides. Keep setup sources, SQL, backups and diagnostics outside the served directory.
6. Verify every installed member against the RC21 manifest and confirm the actual serving PHP context. Run only the matching read-only [installed-runtime diagnostics](INSTALL-DIAGNOSTICS.md) and [payment readiness preflight](PAYMENT-READINESS-PREFLIGHT.md) within the authorized target scope; do not execute the real bot/config/Cron as a diagnostic.
7. Review both method switches and original in-flight profiles. API setup, authentication checks, verify/redeem, actual payments/provider mutations and Telegram acceptance require their own explicit live-test authorization. Isolated source tests do not supply that result.
8. Resume writers only after the target's authorized checks pass; retain source/schema/secret identities and recovery evidence. Observe ordinary reconciliation without replaying unknown outcomes.

The current Owner authorization covers source merge/publication and completed-resource cleanup only. Chili ZIP transfer, migration, installation, API activation and live tests remain on hold; this guide does not authorize them.

## Validation and recovery

Exact-candidate PHP8.1/PHP8.4 CI separately exercises frozen original history and current runtime. Current tests include76 API-flow scenarios,22 native preflight scenarios and321 manual-payment scenarios, plus evidence/negative controls, all without live calls. The old runtime fails the new receipt-first/timer-ownership regressions. Test-only scheduler time fences keep earlier negative fixtures outside independent timer scenarios without suppressing diagnostics. Independent review, artifact hashes and the final source identity are recorded in PR102 and the release record.

After API issuance/consumption or any later financial/native write, prefer a reviewed roll-forward preserving the current database and secret. A source/database downgrade needs an explicit current-state compatibility and recovery plan; an old snapshot must not erase later payments/evidence or cause duplicate native effects. Notification failure cannot undo settlement. Existing uncertain operations remain operator-reconciled and must not be restarted merely because a message or HTTP acknowledgment was lost.
