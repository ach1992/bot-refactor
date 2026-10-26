# Unified payment-message lifecycle (Issue #51)

**Scope:** source-only checkout UI and Telegram message/reference management. This does not authorize payment, financial mutation, rollout, or SQL migration.

## State and payment identity

Every order keeps its original pays ID, hash, owner, amount and type. The existing payment_attempts table (migration 003) is the authoritative claim/evidence for hosted invoices and manual transfer receipts. A Telegram button is presentation only: removing it can never settle, refund, delete or cancel an external provider claim.
For new bank-transfer BUY_SUB invoices, proposed and separately deployed 009
additionally records the exact original purchase and 003 bank-attempt identity
atomically at method selection, before displaying transfer instructions.
Any existing 003-backed bank purchase without authentic issued 009 evidence
requires operator reconciliation, not retroactive reconstruction by Cron.


States are awaiting_payment, receipt_review, needs_review, payment_recorded, expired and declined. In particular, payment_recorded DOES NOT prove Marzban service delivery or an actual wallet credit. Keep separate financial evidence and conservative Persian customer copy. Existing Persian/RTL responsive PaymentPage.php handles browser returns.

## Buttons

New checkout/receipt messages are recorded by exact Telegram chat_id and message_id when optional migration 006 is installed. An existing one-minute warning worker then edits each registered message after payment transitions or entering review, replacing pay buttons with read-only status/menu actions; text edits are preferred and markup-only deletion is fallback. Successful markup-only fallback is retried for truthful text. Every edit failure is retriable and cannot modify original transaction money or status. A microsecond-precision last-attempt timestamp rotates unsuccessful edits behind untouched messages, so a permanently uneditable message cannot block the other messages for the same payment. The bounded Cron sweep prioritizes automatic financial renewal before optional Telegram edits.

Historical Telegram messages without known message ID cannot be discovered by the Bot API. Old raw third-party URLs cannot be revoked from Telegram; never offer raw provider URLs in NEW messages. New payment URLs point only to existing bot-owned gateway endpoints which re-check the exact original pays state and the authoritative newer committed draft BEFORE forwarding to a provider. Unsafe, stale and owner-mismatched callbacks fail closed. Clicking an obsolete button cannot create a replacement invoice.

## Safe expiration and newer drafts

A genuinely untouched unclaimed draft expires after **30 minutes by default**, or when a newer same-user/payment-family/order draft has actually committed. **Commit order for each owner is represented by increasing pays.id while the shared owner lock is held; request_date is NOT a secondary sort condition.** A PHP request may compute its timestamp before taking the owner lock, so a larger committed ID may legitimately have an earlier request_date. The timestamp is used solely for the idle deadline. A newer committed draft permanently supersedes an older unclaimed link, even if that newer draft later becomes expired, declined or mismatched: an obsolete payment link must never reactivate. An already-issued provider claim is preserved for reconciliation and is never locally cancelled by a newer draft. Its duration is configurable with bounded administrative BOT_STATES setting paymentDraftMinutes (5..180, default 30).

**Issued MANUAL invoice:** the pinned bank-account / gift seller HTTPS link / selected USDT- or TRX-network destination in 003 payment_attempts is retained. If the customer has NOT clicked the exact “send receipt / gift card” Telegram button within **6 hours of attempt creation** (setting paymentManualHours, 1..72 hours), a serializable pays-then-attempt InnoDB lock verifies the absence of provider_status, receipt lease, photo evidence and child transactions, then updates the pays record and attempt.stage to expired **without deleting evidence**. The original quote/identifier remain for audit/support. A sender may have transferred funds out of band: expiry never certifies no funds or authorizes refund, and the visible customer text asks to contact support with proof. If the customer clicks that button before expiry, a durable provider_status=receipt_started marker is recorded. This is an UPLOAD INTENT ONLY (not transfer/receipt/credit/provision evidence); the existing photo receiving/review process remains separately protected. Legacy step-only/stale clickable message cannot bypass source-real age/owner/method guards. Prior photo evidence, receiving or review is never expired by the idle worker.

**Issued HOSTED invoice:** opening the bot-owned checkout URL is observable but **not payment evidence**. After **6 hours by default** (setting paymentHostedHours, 1..72 hours) the bot-owned URL and registered Telegram buttons are replaced with read-only status/support, but the real upstream invoice can still be payable. Financial pays/payid, 003 payment_attempts and any payment_transactions stay immutable and pending/review for reconciliation with the provider. There is no automatic upstream cancellation, refund, double-issue or inference of failure. Provider-specific finality and eventual settlement are managed only by authenticated provider evidence and Issue #26.

**Administrator gateway-toggle and audience-source hardening (2026-10-26):** Changing an active payment method and cycling its customer cohort require a freshly authenticated private Telegram callback, current primary/delegated administrator role under an exact owner-PK lock, and a single global monotonic `update_id` gateway-source marker in original `setting`. The marker and updated `BOT_STATES` document commit atomically under the existing named settings writer lock. Both administrators and unrelated supported payment methods participate in the same chronology: an older delayed callback cannot overwrite a newer accepted policy; replay, revoked actor, duplicate user identity, invalid callback or uncertain SQL commit fails closed. Gateway flags toggle using the **fresh persisted state**, not the cached request snapshot. No new table/migration/real payment effects. The historical generic internal settings writer remains available for unrelated trusted code paths, and the separate administrator invoice-window wizard has not been certified by this focused gateway-toggle/cohort change. Native proof: `tools/test_post_v9_gateway_admin_source.py` extracts actual bot/config PHP, reproduces the revoked/stale prior source (RED), validates current source (GREEN) against disposable original-schema MariaDB, and enforces failure/rollback and provider-free behavior. Installed Telegram role/key provisioning is a separate operator gate.

**Dynamic per-method/per-category eligibility (Issue #51, PR #82):**
In addition to the legacy `gatewayAudience` fallback, the optional versioned
`BOT_STATES.gatewayRules` document supplies independent user-category rules,
registration age, and 5 historical success counters, each with administrator
editable count/days/direction. All values are free integers; no hardcoded
90-day/5-payment policy applies. The Telegram editor persists the intended
field with current privilege/source checks, and final protected payment
authorization re-reads current policy from the database. Missing policies
keep legacy semantics; malformed/explicit deny policies never grant access.
Historical `pays.request_date` is REQUEST time, not a settlement timestamp.
**An all-history success count and buyers/non_buyers category must not discard
a successful record for zero/future request_date; only positive-day windows
apply timestamp validity.** Stale Telegram Clear, Toggle, Direction and numeric
entry controls are bound to the displayed rule snapshot, with the latest
persisted policy re-shown on conflict. Actual wallet/hosted checkout source
is exercised across a policy change before final financial authorization.
Full schemas, operator workflow and isolated acceptance matrix:
[GATEWAY-ELIGIBILITY-RULES.md](GATEWAY-ELIGIBILITY-RULES.md).

**Audience and anti-duplicate settings:** the administrator's gateway settings include paymentDraftMinutes, paymentManualHours, paymentHostedHours, receiptApprovedMinutes (10), receiptApprovedOtherMinutes (60), receiptReviewMinutes (360) and receiptDeclinedMinutes (3), with bounded validation. Missing/invalid keys retain legacy-safe defaults. An independent gatewayAudience map per method can target all, agents, non-agents, previous BUY_SUB buyers, non-buyers, active or inactive service owners, or off; unknown policy is denied. It is checked at the first-party gateway URL, Telegram callback, method selection, wallet debit and manual-claim boundary, not only hidden from keyboards. Existing issued provider claims and async settlement evidence are preserved when access changes. No setting disables monetary once-only, admin rights or provider reconciliation.
 Expiry is a conditional InnoDB transaction: lock exact pays row, confirm pending, no payid, and no payment_attempts row, then update pays.state to expired without deleting it. Do not expire or refund pending provider invoices, manual receipts, processing/checking/review, paid or uncertain claims. A newer draft does NOT cancel a live upstream invoice or its financial evidence. This prevents deleting an old pay record while a new purchase or wallet-top-up INSERT can fail, especially when already inside a transaction.

## SQL and rollout boundary

Source-only additive migration: migrations/post-v9/006_payment_message_refs.sql. Columns: pay_row_id with RESTRICT FK to pays; chat_id/message_id unique; message kind, synchronized digest and timestamps. Do NOT reuse pays.message_id or pays.chat_id; they are manual receipt evidence. Migration 006 requires prior 003; absent 006 yields graceful behavior but no proactive editing of untracked history.

Before installing, require a separate Owner decision, exact versioned/checksummed SQL asset, 003/004/005/006 schema and compatibility preflight, backup + restore rehearsal, installed Marzban/Telegram checks and historical pending-invoice inventory. Do not autoapply migrations or put SQL files in the runtime ZIP. New src/Finance/PaymentLifecycle.php must be included in the runtime ZIP. The four-worker Cron remains unchanged. Runtime packaging removes old automatic TRON/WeSwap modules; generic unknown callbacks are denied before financial reads. NextPay remains optional for new payments with no legacy import.

## Merchant credential and destination editor

The separate administrator `PAYMENT_KEYS` editor is a source-only payment
configuration path, **not** a new merchant gateway or settlement engine.
From `درگاه‌ها`, select the desired credential, bank-account/holder, gift
seller HTTPS URL, or independent USDT BEP20/TRC20/ERC20 and TRX TRON
destination or exchange rate. An authenticated **private Telegram callback**
opens a field-specific wizard; a new signed private text message saves the
input, and **Cancel** leaves the stored value unchanged. Invalid URLs,
network-incompatible addresses and rates show Persian correction hints
without overwriting the active setting. `/clear` is supported for the
manual crypto destination and rate fields already permitting it.

The editor checks current primary/delegated administrator identity, role,
ban status, exact owned wizard step, source identity and monotonic Telegram
`update_id` on every action. It never trusts cached `userInfo` or
`paymentKeys` to authorize a write. The historical `setting.type` has
no unique constraint: the writer takes a named lock, verifies exactly one
`PAYMENT_KEYS` document (or creates one), locks the current administrator
PK **before** setting PKs, and commits the field-scoped settings JSON,
`users.step` and the separate
`ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE` watermark together. A source marker
stores per-field revision numbers, and a webhook-secret-keyed HMAC
of the previewed value and revision is stored in the wizard; a stale editor
cannot overwrite a concurrent modification, even if another administrator
restores the original value (ABA). Different-field edits can both succeed.
Malformed, duplicate, revoked, unsigned, replayed or unknown-commit
operations return **no success confirmation**, with reason-code-only
administrative logging. No private field value or submitted credential is
echoed into logs, Telegram management menus or success feedback.

Only `configured` / `missing` indicators are shown for
**NOWPayments, Zarinpal and NextPay hosted merchant credentials**.
The public bank account, holder and valid manual-chain destinations/rates
remain displayed for the operator where needed. The settings menu itself
requires a current signed private admin role check; prior forwarded or
cached-role callbacks cannot expose its public destinations to a revoked
administrator. The chat message containing a newly *typed* secret is
still held by Telegram according to its own service behavior: the source
fix cannot erase previous historic Telegram messages containing exposed
merchant credentials. Any installed historical exposure assessment or
credential rotation is a separate Owner/operator action; no rotation
or production webhook was performed in source acceptance.

**Required source-real regression:** `python3
tools/test_post_v9_payment_key_admin.py --run-disposable` extracts the
actual `bot.php` route and `config.php` menu and uses an isolated
original 23-table MariaDB, independent PHP/MySQLi sessions, synthetic
Telegram updates and no provider/network effects. This suite is mandatory
in the exact-candidate `tools/check_post_v9.py` CI matrix alongside existing
gateway/admin/financial tests. Source-only CI never proves installed
configuration, merchant verification, payment settlement or production
authorization. No SQL migration is needed for these editor changes.

## Acceptance

tools/test_post_v9_payment_ux_lifecycle.py --run-disposable exercises actual PHP on owned original 23-table MariaDB plus only the optional disposable 003 and 006 migrations, with no external provider or Telegram network requests. It checks expiry, supersession, provenance, several messages edited, Telegram retry, manual receipt evidence and competing PHP/MySQLi clients. tools/check_post_v9.py must require all named cases. Frozen V0-V9 evidence remains unchanged. Installed E2E payment/provider and any real SQL activation require separate approval.

Unresolved finance: https://github.com/ach1992/bot-refactor/issues/26
Unified payment UX: https://github.com/ach1992/bot-refactor/issues/51

### Provider issuance vs replacement-draft serialization (PR #60 independent review)

Every customer-initiated new pays draft INSERT (standard/custom purchase, subscription renewal and extensions) now requires a **single MariaDB transaction holding users.userid FOR UPDATE through INSERT and COMMIT**. The independent wallet-topup draft path already uses a corresponding users-row transaction. Historical users.userid is non-unique, so missing or duplicated owners fail closed. The shared lock order is users -> pays -> payment_attempts. These creation paths preserve old financial records rather than DELETEing any issued provider claim.

All three hosted providers (NOWPayments, Zarinpal, NextPay) acquire that same owner row lock, then the intended pays ID FOR UPDATE, then **revalidate the original hash/type/amount/user/order target/request timestamp, draft age, and committed same-family/same-target successor using a CURRENT SELECT ... FOR UPDATE**. Only then can an exactly-one payment_attempts creating row and the local pays.payid marker COMMIT. No external provider operation occurs within the database transaction or before the reservation commits. Even if the initial HTTP GET observed no successor, one committed before the reservation lock blocks that old invoice. A ledger INSERT returning false with MYSQLI_REPORT_OFF is a mandatory rollback, not a payable provider reservation. Ambiguous upstream issuance remains durably claimed for operator reconciliation, not automatically reissued, refunded or discarded.

The historical prePayCheck_Renew_ManualUsdt callback is a **shared crypto-network selector**, not authorization for USDT specifically. A user with TRX-only access can enter this menu, while the actual selected network and final manual quote still enforce the method-specific permission. The ps_<hash> callback reads full pays identity (including order_id and request_date), matching the scheduled successor-detection projection. In gatewayAudience, **only absent** policy keys retain the legacy all default; explicit NULL or any invalid map/method mode fails closed. Administrators see invalid entries as blocked, rather than silently reopening them.

The mandatory Post-V9 regression tools/test_post_v9_checkout_serialization.py runs real concurrent PHP/MySQLi/MariaDB with an explicit owner-row wait/commit barrier for all three hosted providers. The prior exact candidate reaches mock provider issuance after a newer draft commits (RED), while the corrected source issues none (GREEN). It additionally tests a killed locking successor SELECT, nonthrowing 003 INSERT errors and rollback, source-real renewal crypto/network picker for TRX-only/USDT-only/both/neither, forged callbacks, status identity across same/unrelated subscription targets, and preservation of already-issued provider evidence. Reviewer R1 coverage uses two independent real PHP/MySQLi draft creators invoking the production achbotPayUxInsertDraft() under the owner-row lock; creator A computes the earlier timestamp first but commits AFTER creator B, obtaining the larger pays.id. For NOWPayments, Zarinpal and NextPay, only A may reserve an invoice. The old reviewed HEAD reproduces an outbound mock provider issuance on B (RED). Current HEAD refuses B, permits one A reservation, expires B on its bot-owned URL, and preserves all existing provider-issued claims (GREEN). Independent direct-expiry, Cron sweep, and later terminal-state transitions also reject obsolete links. The test has no production database, real provider call or Telegram effect. Explicit NULL, incorrect types, off and restrictive cohorts are also exercised through the actual first-party checkout HTTP entrypoint for all three hosted gateways. No live provider, deployed database, money or Telegram operation is part of this proof.

### Manual and wallet method selection serialized with replacement drafts (R2)

First-time **manual bank, gift, USDT or TRX claims** and **direct customer wallet spending** must use the **same owner-row transaction lock** as customer draft creation and hosted gateway reservations. The common lock order is **users.userid FOR UPDATE → original pays FOR UPDATE → provider attempt FOR UPDATE**. The original pays row, owner, type, stage and current **pays.id-ordered successor** are checked under this lock; a stale Telegram menu preflight or an earlier nonlocking SELECT is never the final payment authorization.

For NEW manual claims, the transaction cannot insert the required 003 attempt until the original pays is confirmed eligible under that owner lock; the 003 INSERT must affect exactly one row and COMMIT before the bot shows pinned payment instructions. Existing **already-issued** manual claims follow their original pinned/evidence-recovery path without being retroactively cancelled by a newer draft. An out-of-band bank transfer or historic receipt still needs operator reconciliation, not an inferred refund or disappearance.

For direct wallet spending, a *reject-only* read-only preflight first preserves prompt rejection of unsupported/retired panels without waiting on a financial owner lock; passing that preflight never authorizes money movement. The actual owner lock precedes the pays lock and the CURRENT-read successor and ledger checks, including a fresh supported-subject check. The existing ownership, available wallet balance, manual/admin debt, method exclusivity, no-repeat, and fulfillment authorization remain intact. Exact-one local processing/debit/completion writes must succeed before reporting customer payment success. SQL exceptions, nonthrowing mysqli failures, owner-lock timeout or deadlock victims fail closed and send operator review where necessary. Remote fulfillment or an ambiguous COMMIT is **never automatically retried or refunded**; source-only financial evidence is not proof of installed-provider settlement.

The mandatory disposable-source-native `tools/test_post_v9_method_selection_race.py` instruments temporary source snapshots only at the actual post-preflight/pre-ledger or pre-debit boundary and uses independent PHP/MySQLi MariaDB sessions. It reproduces the **prior reviewed ee029 source RED**: a newer draft commits while an older manual choice still issues a payment destination or a wallet action still debits and invokes the synthetic handler. Corrected source **GREEN** proves both commit orderings across all four manual methods and wallet, old known Telegram bank/gift callback cannot reveal a stale destination, and existing pinned issued claims remain recoverable. Owner lock timeout, KILL QUERY, deliberately inverted-lock deadlock victim selection and actual MYSQLI_REPORT_OFF insert/debit trigger errors must cause no false success or double side effect. A synthetic lost acknowledgment after a real disposable-DB COMMIT must remain ambiguous: reopening an already-issued manual claim reuses the same immutable attempt; repeating a paid wallet callback never debits or fulfills again. No new schema, real provider, production database or customer funds are involved.

### Definitive wallet authorization under InnoDB REPEATABLE READ (R3)

The wallet's early unsupported/retired-panel check is strictly **reject-only** and runs as a completed standalone **autocommit** read BEFORE starting its financial transaction. A pre-lock nonlocking SELECT inside the same InnoDB `REPEATABLE READ` transaction would pin a consistent snapshot. Later `FOR UPDATE` on the owner/pays does **not** refresh that snapshot for later ordinary SELECTs; audience `is_agent` or supported `server_config/server_plans/orders_list` could therefore be stale. A positive preview is never proof of permission: begin a new transaction, lock **users.userid -> original pays -> payment_attempts**, then revalidate owner, state, supported subject, current pays.id successor and configured audience before debit and fulfillment. If preview rejects an unsupported plan, no transaction begins, no wallet changes and the existing early unsupported-panel feedback remains. R2 serialization and 003 claims are unchanged.

The mandatory `tools/test_post_v9_wallet_authorization_freshness.py` stages the **actual** wallet function and true `achbotSupportsPaymentSubject()` (not a stub), interrupts it after preview/before the owner lock using **temporary snapshot-only instrumentation**, then makes a second independent PHP/MySQLi/MariaDB connection commit a user cohort, buyer eligibility or supported `server_config` change. Twelve checks cover default `REPEATABLE READ` and `READ COMMITTED`, agents/non-agents, buyer/non-buyer, supported-plan invalidation, unchanged eligible wallets, exact-one debit and idempotent callback. The previously reviewed `569802ca` source is demonstrably **RED** for a stale BUY_SUB panel change: it commits a synthetic 100000-toman wallet debit and mock fulfillment after the stored panel changes to unsupported; current source is **GREEN** with no debit or fulfillment. A changed `is_agent` also remains safely unauthorized under the current source. These are owned disposable local tests; no real provider/customer/payment or DB migration.

### Agency approval CAS under concurrent administrators (V1)

The `users.is_agent` value controls payment gateway cohorts. The original Agency approval uses an atomic conditional `UPDATE users ... WHERE id=? AND is_agent=2`; the approval code and original `setUser()` were unchanged by the initial PR #60 R2 patch. Nevertheless the exact SHA PHP 8.4 CI reported a real InnoDB 1213 deadlock victim during simultaneous admin approval, while 100 simultaneous local approval rounds on the source-identical baseline passed. The exact SQL statement of the CI victim was not captured, so we do not misattribute the cause to R2.

The approval-only compare-and-set now handles an explicit InnoDB 1213 victim with at most ONE bounded retry of exactly that conditional UPDATE. Error 1213 rolls back the statement. No successful role grant, notification or admin wizard action happens before this CAS; when another administrator has already approved, the `is_agent=2` predicate returns zero changed rows. At most one role grant and customer success notification is permitted. A second 1213 or lock timeout 1205 fails closed. Other SQL faults remain visible. Decline, identity, role and discount rules are unchanged.

The mandatory `tools/test_post_v9_agency.py` retains the original 23-table schema, actual application route and extracted original `setUser()`. It aligns both concurrent admins with a barrier and checks exact-one notification. Synthetic disposable DB-only MariaDB SQLSTATE 40001 / errno 1213 triggers exercise one-victim retry to a single successful approval, and repeated victim errors to zero approvals/notices. A separate emitted errno 1205 timeout also checks zero privilege change and no success notification. The test drops its temporary trigger/table and checks the original schema remains 23 tables. The injected error is a real MariaDB response, but is not misrepresented as a naturally occurring InnoDB lock cycle; the organic deadlock is evidenced separately in the prior exact-HEAD CI. No deployment or operator role changes were performed.

### Exact-candidate independent review follow-up: shared wallet, settings and mutable-subject locks (F1-F4)

The latest independent PR #60 review found that the original R2 owner-first repair did not cover all three legacy wallet callbacks. Every NEW customer-wallet transaction (direct subscription, renewal, day extension and volume extension) must acquire the **unique** `users.userid FOR UPDATE` owner row before the exact `pays FOR UPDATE` row, successor and existing-attempt checks. The owner lock is shared with new-draft INSERTs and manual/hosted claims and remains held until durable COMMIT. An old quote never gains authority merely because it was visible on Telegram. An already-issued claim retains its evidence and review path. Route-specific fulfillment is deliberately preserved; this patch is not a general settlement or compensation redesign.

The request-global `$botState` remains a **presentation/early-rejection snapshot**, never definitive issuance authority. New claims and debits call `achbotPayUxAudienceAllowed(..., true)` only while holding the owner-first financial transaction. This mode acquires a **current locking read** of the persisted `BOT_STATES` row and reads dynamic cohort membership from current locked database rows. Missing, duplicated, malformed or unreadable persisted policy fails closed at the new-claim boundary; a present well-formed document with an **absent** `gatewayAudience` or per-method entry retains the documented legacy `all` interpretation. Explicit malformed method entries never reopen availability. ALL three hosted gateways repeat the final check after acquiring the owner/pays lock, before the attempt reservation COMMIT and before any remote provider request; a menu/GET precheck does not suffice. Manual first claims and all wallet debit paths follow the same requirement. Issued upstream or manual claims remain recoverable independently of a later policy change.

Administrative `BOT_STATES` updates no longer re-publish the request's original whole-JSON snapshot. `setSettings` serializes writes with a connection-scoped advisory lock, reads the current row under `FOR UPDATE`, applies only the requested field change and commits. Audience cycling similarly reads the current persisted per-method map, preserving concurrent unrelated settings and refusing malformed maps. The payment-settings editor reports database write failure rather than falsely acknowledging success. The existing `settings/rewardReport.php` Cron previously rewrote the same `BOT_STATES` document from an old global snapshot; it now uses the same field-scoped `setSettings('lastRewardMessage', ...)` transaction, and skips the reward notice if that write fails. The original legacy schema has no UNIQUE constraint on `setting.type`: ambiguous `BOT_STATES` rows are **rejected**, not silently selected; no schema/index alteration is implied.

Supported financial subjects are confirmed with **current locking reads** of the relevant `server_plans`, `server_config`, `orders_list` and (where relevant) explicit Marzban group/add-on rows, while the wallet/hosted/manual financial transaction remains open. New plan authorization also checks `server_plans.active=1`. A panel/plan change that races after final validation is either serialized to occur **after** the committed authorized wallet operation, or the operation fails closed before any effect. Do not add arbitrary new dependency locks in reverse order; user owner -> pays -> attempt remains the customer-financial lock discipline. External provider operations are never part of the database transaction and uncertain completion is never automatically retried.

These are source-only guarantees on synthetic original-schema disposable MariaDB, **not** installed system/provider certification. The mandatory Post-V9 acceptance contains independent **prior-source RED/current-source GREEN** cases in:
- `tools/test_post_v9_legacy_wallet_races.py` — all three legacy routes, replacement-first and wallet-first, READ COMMITTED and REPEATABLE READ;
- `tools/test_post_v9_hosted_audience_races.py` — all three hosted gateways, committed policy and agent-cohort revocation before reservation;
- `tools/test_post_v9_manual_audience_races.py` — all four new manual-claim methods after committed policy revocation;
- `tools/test_post_v9_policy_panel_races.py` — wallet policy revocation, plus server type/plan-active revocation between final supported-subject decision and fulfillment;
- `tools/test_post_v9_settings_concurrency.py` — concurrent admin timeout/audience requests and the Cron reward timestamp writer from stale snapshots, preserving the revoked wallet policy and unrelated fields.

The shared mutable dependency lock order is **unique customer owner -> pays -> BOT_STATES audience policy -> plan/panel/order dependency locks -> durable customer-wallet/provider effect**. In particular, the central wallet route must not lock the plan *before* the shared BOT_STATES row while hosted/manual routes use the reverse order. The mandatory \`tools/test_post_v9_cross_method_lock_order.py\` stages an intermediate reverse-order mutant and proves the conflict, then validates that current wallet and hosted transactions for different customers serialize without an inverted-lock deadlock. This is a test-stage mutant, not the previously independently reviewed SHA.

The frozen historical V0-V9 contract/schema and installed migrations remain untouched. Issue #26 owns historical financial replay/debt/reconciliation policy. Independent author-separated review of the **new exact SHA** and a separate Owner merge approval remain mandatory.

### Definitive primary gateway flags and narrow read locks (N1/N2 independent review)

Every **NEW** financial claim validates two independent persisted authorizations under the owner/pays transaction: (1) the current primary gateway on/off flag, and (2) the current audience eligibility. A stale Telegram callback, bot-owned hosted checkout GET, or old manual quote cannot rely on the request-global `$botState` flag to issue a NEW wallet debit, shared 003 manual attempt, or upstream invoice. Final method flags are `walletState`, `cartToCartState`, `giftCardState`, `manualUsdtState` (shared USDT/TRX), `zarinpal`, `nextpay`, and NOWPayments' type-specific `nowPaymentWallet` (INCREASE_WALLET) / `nowPaymentOther` (all other supported subjects). Missing, malformed or non-`on` primary flags fail closed at the new-claim boundary; missing audience *rules* on an otherwise valid persisted policy document retain legacy `all`. Existing issued attempts, payment proofs and upstream reconciliation are preserved; disabling a method does not imply refund or failure.

The historical `setting.type` is **not indexed or UNIQUE**. A `WHERE type='BOT_STATES' ... FOR UPDATE` locking scan could lock *unrelated* Marzban group records, invert the admin's `server_config → group setting` lock order and serialize unrelated customers through a policy X-lock held during financial effects. No installed schema change is authorized to resolve this. The supported source-only solution locates exactly one `BOT_STATES` id using a **nonlocking** bounded scan, then uses a current locking read by the **PRIMARY KEY id**: `LOCK IN SHARE MODE` for new-claim authorization and `FOR UPDATE` for field-scoped administrators' settings mutations. Readers may hold compatible shared locks concurrently; a writer must wait for active financial readers before revocation COMMIT and all subsequent claims observe the changed value. The existing bounded named writer mutex serializes in-application settings inserts and prevents field-update loss. Missing/duplicated rows or a primary-key identity/type mismatch fail closed; out-of-band changes that bypass the application mutex are not silently assumed to be safely serialized. The row-locked read prevents an earlier InnoDB repeatable-read snapshot from becoming the final payment-policy authority. MariaDB 10.11 may choose an `ALL` scan for small `pays` fixtures in the successor `FOR UPDATE` lookup, leaving another customer's exact `pays.id FOR UPDATE` unexpectedly blocked **before** the method-policy/plan boundary. This is separate from `BOT_STATES` locking: the exact CI native wait probe showed the second session stopped at `SELECT * FROM pays WHERE id=? FOR UPDATE` while the first customer's wallet transaction held locks. The final successor query now uses the **existing** `idx_pays_user_v1` when requesting the current locking read, bounding the scan to the owner's index range without changing historical DDL. A MariaDB EXPLAIN assertion plus the real cross-customer wallet/hosted regression protects this access path on both PHP compatibility jobs.

The mandatory real PHP/MySQLi synthetic regressions verify the exact **e3a189b** primary-flag omission (RED) versus corrected source (GREEN) for wallet, all four manual methods, NOWPayments wallet/other, Zarinpal and NextPay, including older callbacks, pre-reservation disable races, positive enabled controls and pinned manual claims surviving later disablement. The original F1–F4 suite remains required. `tools/test_post_v9_setting_lock_scope.py` additionally exercises the original unindexed schema and the exact source `achbotSetMarzbanGroup()` administrator transaction against wallet/hosted/manual authorization under both RC and RR; validates unrelated setting rows do not share the payment lock; compares earlier exclusive-policy reader wait against concurrent shared readers with shared/unrelated plans; and proves that the real `setSettings()` writer waits until current authorized financial transactions finish before later requests are denied. All tests are socket-only disposable fixtures; neither the remote Marzban network nor live provider/Telegram/payment effects are exercised.

No additive policy index or schema change is bundled with PR #60. If installed-setting table scale or concurrency demands an index, that is a separately reviewed and Owner-approved migration decision, never a silent modification to the frozen 23-table historical SQL. The inherited external-fulfillment and finance replay/uncertain-settlement limitations remain with Issue #26. Independent exact-HEAD approval and a separate Owner Merge decision are still mandatory.

### Indexed final cohort current reads (R1 follow-up after N2)

The original historical schema already includes customer-key indexes
`idx_users_userid_v1`, `idx_pays_user_v1` and `idx_orders_userid_v1`. A plain
`FOR UPDATE` with `WHERE userid=?` / `WHERE user_id=?` is **not** an adequate
cross-customer lock-scope guarantee: the MariaDB optimizer may scan unrelated
rows on small tables or choose a competing state-oriented index, causing
unnecessary waiting or an inverse lock dependency during another customer's
transaction. This is separate from the now-corrected successor query and
`BOT_STATES` primary-key locking.

**Final, transactional** audience membership reads for agents/non-agents,
buyers/non-buyers and active/no-active orders therefore use `FORCE INDEX` on
the corresponding existing *customer-key* index. The shared original
`achbotPayUxLockDraftOwner()` owner-row `FOR UPDATE` also uses the indexed
`users.userid` access path. Preview reads remain nonlocking and unchanged;
customer eligibility still relies on CURRENT locking reads, the owner-first
lock order, missing/duplicate-identity fail-closed handling, verified
persisted gateway flags/cohorts, and exact money/attempt guards. There is
**no new migration or historical DDL modification**. Existing N2 application
writer coordination and external-writer/mixed-version limitations still apply.

`tools/test_post_v9_cohort_lock_scope.py` is mandatory in the full
Post-V9 gate. It checks all four affected `EXPLAIN` plans for the intended
index and non-ALL access under small/populated distributions, then runs
48 source-real independent customer pairs: all six cohorts, eligible and
ineligible/absent matches, `READ COMMITTED` and `REPEATABLE READ`. Customer A
holds its own user/pays/active-order records X-locked while B must obtain its
own owner/pays locks and reach final policy authorization without waiting for
A's unrelated records. The existing cross-method successor/plan dependency
suite separately checks that *genuinely shared* business rows still serialize.
This is synthetic evidence rather than installed throughput or live-payment
certification. Exact-candidate GitHub CI on MariaDB 10.11, fresh independent
review and explicit Owner Merge permission are still separate gates.
