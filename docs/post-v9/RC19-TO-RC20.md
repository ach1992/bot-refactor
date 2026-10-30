# RC19 → RC20

RC20 adds optional API-assisted bank-transfer/gift-card verification, plan-independent administrator gifts with shared-customer notices, and event-aware notification routing. It retains RC19 display/QR/invitation behavior and the separate legacy automatic card-transfer implementation. Implementation and validation records belong to [PR101](https://github.com/ach1992/bot-refactor/pull/101) and [Issue57](https://github.com/ach1992/bot-refactor/issues/57); the [RC20 release record](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.20) and manifest identify the exact published source.

This guide defines a **separately authorized target cutover**. Source merge/publication is not installation, SQL execution, API activation or real payment/provider acceptance. RC19 ZIP staging did not establish an installed version.

## Artifact and compatibility boundary

The RC20 runtime ZIP contains **119 members / 118 PHP files**, compared with RC19's 115 / 114. The four added modules are:

- `src/Finance/PaymentEvidence.php`
- `src/Finance/PaymentApiAdmin.php`
- `src/Finance/ApiPayments.php`
- `src/Finance/Routes/ApiPayments.php`

Install the complete matching runtime; do not copy only these files. The runtime ZIP excludes private `baseInfo.php`, SQL, verification tools, logs, backups and customer data. Keep the matching setup-source checkout and verified sidecars outside served paths. Verify the official manifest and checksum list before any target change.

A compatible RC19 installation already has its own verified earlier schema history. **Never rerun completed 012/013**, re-import the fresh-install baseline or replay003 merely to prepare RC20. Earlier or partly migrated targets first need their exact [shared-storage recovery](SHARED-PROVIDER-STORAGE.md) audit. The [fresh-install sequence](FRESH-INSTALL.md) applies only to a new empty database.

## Migration 014 and the default-OFF window

[`migrations/post-v9/014_api_payment_evidence.sql`](../../migrations/post-v9/014_api_payment_evidence.sql) is a separately shipped source sidecar, not an automatic runtime action. It adds 19 nullable fields to `payment_attempts`, 5 to `payment_transactions`, the `idx_payment_evidence_due` index and unique `uq_payment_evidence_operation` index. It introduces no table, provider registry or old-invoice backfill, and does not rewrite issued 009 pins.

The optional API switches `smsApiVerifyState` and `giftApiVerifyState` default OFF. Existing unpinned invoices stay manual. A target can retain its manual journey with the APIs OFF while 014 is not applied; **neither API mode may be enabled before the full matching 014 schema is applied and verified**. Administrator service gifts and notification routing add no separate SQL migration.

Migration 014 has two `ALTER TABLE` statements and no `IF NOT EXISTS` guards. MariaDB DDL can commit independently: failure in the second statement does not imply the first was rolled back. If any object already exists or a statement fails, stop, retain the exact error and inspected applied state, and keep writers quiesced until an authorized schema audit defines completion/roll-forward. Do not rerun the entire file, use `--force`, drop new fields, clear claims, or restore an older database as a shortcut.

## Operator-controlled cutover

1. Identify the actual installed release/source, serving PHP runtime, database migration state, private configuration, webhook and scheduled coordinator. Do not infer installation from a release/ZIP pathname or prior staging.
2. Approve a target-specific maintenance and recovery window. Preserve a verified, protected source/database/private-configuration backup and its restore procedure, including the existing webhook secret, financial/effect/evidence journals, gift holds, settings and replay markers.
3. Quiesce webhook/payment/scheduled writers through the existing maintenance mechanism; let the existing coordinator/children release their lease. Preserve the Cron command/environment and lock path/inode. Do not run old and new writers together, change the installation's canonical path, or delete/recreate the lock.
4. Inspect the exact schema and both 014 statements against that target. If 014 is included in this approved rollout, apply the matching sidecar once and verify all column/index definitions before API enablement. If 014 is deferred, explicitly keep both API switches OFF and record that limitation.
5. Replace all 119 runtime members consistently while preserving the real private file and data. Previously retired source remains absent; an older target must also follow the exact [RC18 → RC19](RC18-TO-RC19.md) root-helper/private-invitation boundary. A ZIP copied into a directory is not an extraction or installation.
6. Verify every installed runtime member against the trusted RC20 manifest and verify the actual serving PHP context. Use the matching [installation diagnostics](INSTALL-DIAGNOSTICS.md) and SELECT-only [readiness preflight](PAYMENT-READINESS-PREFLIGHT.md). Its 014 capability finding is not complete DDL, credential or live-API certification.
7. Review staff levels/receiving preferences, report destination, native gift notices and intended manual payment settings. Configure each API profile through the existing administrator UI, then enable/live-test it only within its separately approved acceptance context.
8. End maintenance only after the target-specific checks succeed; keep protected results and source/schema/secret identity together. Record untested live paths truthfully and preserve unresolved operations for reconciliation.

No command in this guide authorizes a real target change. Do not use a manual Cron run, real gift, payment, plugin verify/redeem or provider mutation as a harmless smoke test.

## API setup and customer behavior

The [API contract](API-PAYMENT-EVIDENCE.md#configuration-before-api-enablement) lists exact setting keys and URL suffixes. Bank SMS needs its actual HTTPS API URL, bearer token and explicit positive monitoring horizon. Gift cards need the actual HTTPS API URL, key ID, secret and explicit `rial`/`toman` interpretation of canonical `redeem_amount`. Source filters are optional. Working OpenSSL AES-256-GCM and the **unchanged existing webhook secret** protect recoverable profiles/codes; no new host secret is required.

A settings-complete indicator proves local validation only. It does not test WordPress reachability, authentication, client scopes, funds or live consumption. The read-only readiness tool never decrypts these private credentials or calls either API.

Bank matching uses the complete original immutable amount, including the three randomized trailing digits. Initially the invoice offers Check payment; after 600 seconds it also offers receipt submission, while checking continues. A later exact ready SMS after manual approval can be consumed for evidence only, without repeating financial/native effects. Used or mismatched SMS do not become new candidates.

Gift API mode accepts one exact-value code using `redeem_amount`, not its display/face-value label. Used/revoked/expired states have distinct responses; unknown codes and unavailable/unprovable outcomes enter genuine manual review. Manual crypto and the unrelated existing automatic bank path retain their established behavior.

## Service gifts and notification recipients

An administrator gift does not depend on the purchase plan; genuine planless imports are eligible subject to native identity/entitlement checks. Apply it once to the physical subscription and attempt one notice for each admitted distinct current customer. Shared references and supported explicit same-panel aliases must not multiply the provider increment.

Displayed expiry/quota come from the panel. Purchase/cache expiry, amount, plan and financial history remain unchanged; verified positive-day gifts re-arm the existing warning marker. Native results, recipients and notice acknowledgements are recorded separately from purchase history. A notice failure does not undo a completed gift or rearm a native increment. [Direct-gift recovery](DIRECT-GIFT-RECOVERY.md) describes holds and manual reconciliation.

The following matrix governs unsolicited copies; ordinary responses to a currently authorized actor retain their workflow.

| Event | Owner | Manager receiving ON | Operator/Support receiving ON | Report destination |
| --- | --- | --- | --- | --- |
| General/automatic result | Yes | Yes | No | Configured report channel where intended |
| Actionable manual receipt/code review | Yes | Yes | Yes | Existing private evidence boundary |
| Private diagnostic or batch progress | Yes | Yes | No | Private only |
| Backup | Existing delivery only | No new copy | No new copy | Existing backup destination |

Valid existing staff receipt copies still converge after manual/API settlement even with new-copy receiving OFF. Revoked, banned or ambiguous identities remain excluded; notification preference grants no extra financial/native permissions.

## Recovery after partial work or new writes

Before admitting new writes, use only the recovery plan approved for the actual source/schema/configuration state. Nullable014 is not permission to drop its evidence or blindly rerun the file.

After an API-pinned invoice, external consumption, new gift-result journal or any other new write, RC19 or an older database can ignore/erase new recovery state. Keep writers quiesced and prefer a reviewed **roll-forward**. Turning a switch OFF affects new issuance; it does not cancel/reinterpret earlier pins or complete their pending work. An older-runtime downgrade or database restore requires its own current-state authorization and evidence-preserving reconciliation, not a blanket source replacement or stale backup restore.

Never rotate the webhook secret during this cutover, discard encrypted profiles/codes, regenerate an operation key/reference, alter original amounts, clear an uncertain hold, reset a gift cursor to retry, rerun 012/013 or replay request69. Its actual native outcome, provider, installed version and root cause remain unknown. A missing message is not proof of a failed payment/gift or a refund.

## Delivery and acceptance evidence

Release publication proves artifact availability and identity, not target delivery. Record actual installed manifest/source, schema results, unchanged private-secret identity without revealing it, PHP web/CLI contexts, webhook and Cron lease configuration, and separately authorized acceptance results. Real API credentials/scopes, network outages/delayed SMS, Telegram rendering/delivery and merchant/native outcomes remain unverified until those actual tests occur.

Operational acceptance is tracked in Issues [26](https://github.com/ach1992/bot-refactor/issues/26), [37](https://github.com/ach1992/bot-refactor/issues/37), [51](https://github.com/ach1992/bot-refactor/issues/51) and [81](https://github.com/ach1992/bot-refactor/issues/81). [Issue57](https://github.com/ach1992/bot-refactor/issues/57) records RC20 source implementation and release delivery; closing that source outcome does not certify installed acceptance. Published RC19 and its historical source/checksum facts are unchanged.
