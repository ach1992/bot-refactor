# Shared payment ledger — design, migration and safety gates

**Owner decision (2026-10-17):** Do not create separate tables for every gateway; use unprefixed, clear domain names. This candidate belongs in AChBot under Issue #26. It supersedes the NOWPayments-only SQL shipped in the **immutable v0.10.0-rc.4**, but has not yet been merged, released, migrated, installed or production-certified.

## Shared storage

- **payment_attempts:** One currently active provider claim per existing pays.id. Records provider, order, provider reference, quote amount/currency, original Toman obligation, settlement asset/minimum, checkout URL and progress/review. UNIQUE (provider,provider_reference) and UNIQUE (provider,payment_id) protect per-gateway identity, not unscoped global IDs. A reservation with no upstream reference must not trigger blind reissuance.
- **payment_transactions:** Multiple provider-reported child payments per attempt, unique (provider,payment_id). A composite foreign key rejects child attribution to the wrong provider. Observing a payment is not proof of merchant settlement.

NOWPayments signed-IPN/finished-only safeguards remain. NextPay and Zarinpal now create and bind records through the SAME shared table before/after external checkout issuance. Provider verification remains provider-specific. The original pays table remains the business-state authority. The schema is **not** a durable general exactly-once business-effect/outbox ledger, nor are manual/card/TRON, privileged debt or remote Marzban reconciliation completed by this change. Legacy pays.payid callback ambiguities remain fail-closed.

The original 23 tables, existing panel-group 001 migration, and historical V0–V9 contract remain unchanged. Fresh additive SQL is migrations/post-v9/003_payment_attempts.sql; no SQL is included in or executed by a runtime ZIP.

## Preflight: read-only schema checks

    SHOW COLUMNS FROM server_info LIKE 'panel_group_id';
    SHOW TABLES LIKE 'achbot_nowpayments_attempts';
    SHOW TABLES LIKE 'achbot_nowpayments_child_payments';
    SHOW TABLES LIKE 'payment_attempts';
    SHOW TABLES LIKE 'payment_transactions';

Read exact SHOW CREATE TABLE and privately inspect row counts, original order/payment identity, incomplete provider attempts and running IPN/Cron processes. Presence is NOT schema-version equivalence: CREATE TABLE IF NOT EXISTS does not upgrade older column layouts. Do not share real customer/finance records or credentials.

### Fresh database with no RC4 NOWPayments-specific tables

Only after separate Owner authorization for the exact target/database mutation, independently restorable backup and isolated rehearsal, apply the **new shared 003 only**, then verify original data, 23 historic tables, panel-group state, new provider-scoped constraints and synthetic payment acceptance. Do NOT run the historical RC4 002 first. The new revision number 003 is intentional because RC4 already published a different SQL migration numbered 002.

### Historical RC4-specific tables already present

The public RC4 release, its prior SQL and any payment data are immutable history. Do not rename, drop or silently abandon those rows.

1. Explicitly authorize a quiesced payment/IPN/Cron/database cutover, record exact installed version and ensure a verified isolated restore.
2. Audit old tables against **exact RC4 released DDL**, including notification claim columns, and check row/identity uniqueness, pending invoices, provider payout evidence and ambiguous external effects. Earlier staging draft DDL is NOT automatically compatible.
3. On the isolated restored copy, create the new common tables **empty**, then rehearse the optional ONE-TIME non-destructive migrations/post-v9/legacy_rc4_nowpayments_import.sql. Run using a client that ABORTS ON FIRST ERROR, not a tool that commits after partial failure. It preserves the old primary keys, child IDs, quoted/settled amounts, review leases and timestamps and does not delete old records.
4. Compare all old/new records, children, states and financial amounts; verify current-source behavior in staging. If new tables already contain unrelated attempts, the fixed-ID copy may collide and MUST NOT be run. Design and independently rehearse a safe ID mapping instead.
5. Only after a separate Owner decision, validated backup/restore, staging acceptance and planned rollback/forward may production migration/install be considered. Keep old tables for evidence; their future deletion requires its own explicit decision.

Source rollback to RC4 after creating new common payment attempts is NOT a complete rollback; old code cannot read new ledger rows. Quiesce and reconcile those attempts or use reviewed roll-forward recovery.

## Evidence and remaining operations gates

Synthetic/isolated MariaDB tests cover all three checkout providers, composite provider-scope uniqueness and FK, signed multi-child NOWPayments IPN, finished-only settlement, replay/failure controls and exact-historical-RC4 non-destructive import. No real financial/Marzban/Telegram/Cron/database tenant operation was carried out.

Private NOWPayments IPN key and payout policy, operator Cron reachability, full financial outbox, exact-once remote fulfillment, live restore proof and release/install acceptance remain separate gated work in Issue #26. See NOWPAYMENTS-MIGRATION.md and FINANCIAL-CONSOLIDATION.md.
