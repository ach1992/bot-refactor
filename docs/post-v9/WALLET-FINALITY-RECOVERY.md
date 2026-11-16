# Wallet finality, Native uncertainty, and original-operation recovery

Owner: Finance (Issue [#26](https://github.com/ach1992/bot-refactor/issues/26)). This describes **current Stable source**, not a production recovery authorization or deployment instruction.

## Contract and transaction boundaries

1. Validate the original payment, authenticated customer/quote and eligibility under the existing per-payment lock. Insufficient funds or an unsupported service means **no Wallet debit and no Native write**.
2. For Wallet, claim the original `pays.id`/`hash_id` and debit `users.wallet` in one SQL transaction. Commit the resulting `processing_wallet` state **before** dispatching any external service operation. This state means financially *held*, **not** fulfilled or customer-notified.
3. Invoke Native once for that claimed operation. An HTTP error, bad acknowledgment, first-readback mismatch, or worker crash **does not establish failure**. Do not replay uncertain Native mutations.
4. Verify the exact resulting Native entitlement. Finalize `increase_order`, local order expiry (where applicable), and `pays.state=paid_with_wallet` atomically in a separate short SQL transaction. A failed finalization leaves the original claim held for review; no second charge or automatic remote retry.
5. Handle Telegram delivery separately from payment and Native finality. Never infer a successful delivery from a committed local payment or a repeated callback.

`RENEW_SCONFIG` (external Marzban plan renewal) uses the original Wallet claim, a plan snapshot pinned by the locked payment transaction, and original `pay_id` passed to the Native adapter. The service call no longer runs inside the Wallet transaction; the renewal audit and paid state commit together. A committed but ambiguous Native result remains `processing_wallet`, and the old button cannot charge or renew again. The Marzban renewal does **not** have the PasarGuard original absolute-mutation journal: this source package does not grant automatic recovery or re-issuance authority for that class. Operations must establish the original remotely applied renewal independently before deciding a target-specific manual resolution.

The `processWalletPayment()` diagnostic `claim_commit_unknown` means the SQL COMMIT acknowledgement itself was ambiguous. The payment row and wallet balance must be inspected; never treat that log as proof of rollback. The `post_commit` stage means the durable claim was acknowledged but subsequent work is incomplete or uncertain.

## PasarGuard Wallet DAY/VOLUME recovery

Backend function: `achbotWalletPgResumeAddon(mysqli $db, int $payId, int $actor, int $primary)` in `src/Finance/WalletEditRecovery.php`. This function has **no newly exposed Telegram/UI entrypoint** and is intentionally not invoked by background workers. A separately authorized operations surface may call it after an authenticated `payments`-capable admin decision; a live manual call needs explicit Owner permission for the exact target and original payment.

The function refuses anything other than the **original** `INCREASE_DAY_*` or `INCREASE_VOLUME_*` `processing_wallet` claim. It validates original owner, order, paid unit count, price and saved PasarGuard physical identity, original `pay:ID` mutation/absolute target, native instance/incarnation and imported origin. An imported shared `service_effects.order_id=NULL` remains NULL; no old order is manufactured. With `observe_only`, the Native adapter performs an authenticated **GET** and accepts **only** the already-applied original absolute entitlement. A baseline, changed Native entitlement, wrong group, unverified original journal, missing approval, changed customer/account or archive fails closed. It does not PUT, RESET, refund, add days/volume again, or send Telegram.

On successful verified readback, the original effect journal can become `linked` (without a Native write), then the same original order/increase audit and `paid_with_wallet` commit together. If that local COMMIT fails, it remains reviewable under the original claim. A later authorized continuation may finish that same effect, but does **not** reapply Native. The result `settled_delivery_unverified` explicitly says no customer Telegram acknowledgment has been proven. Do not fabricate or replay delivery intents.

**Evidence boundary:** The source cannot independently reconstruct a historical Wallet debit ledger where none was recorded. `processing_wallet` is the guarded original claim under current transaction rules; that does not, alone, prove the entire past balance timeline or exclude out-of-band changes. The Owner must verify target-specific financial history before approving live reconciliation.

## Mandatory production approval and verification

Before any real Hell/Vivan/Chili SQL, authenticated Native read/write, Wallet correction, customer message or recovery action, obtain explicit target-specific Owner approval. Provide the original payment ID, known wallet/quote provenance, current `pays`, order, original `service_effects` and `provider_accounts` state, current Native GET evidence, pending attempts/notifications, expected changes, backup and roll-forward plan. Runtime ZIP and GitHub Release are not installation/OPcache evidence.

For approved once-only settlement, verify: original Wallet balance **unchanged by recovery**, `pays.state=paid_with_wallet`, the order's exact Native-verified absolute entitlement, precisely one local add-on accounting row from this finalization, original `pay:ID` still consumed, original imported provenance/NULL order unmodified, no new Native PUT/RESET, no fabricated Telegram acknowledgment. Report any communication gap separately. If the Native entitlement differs from the original absolute target, **stop and review**; never overwrite independent drift. After a committed reconciliation, use only explicitly approved compensating operations, not ad hoc rollback SQL.

### Hell original payment #112719

The checkpoint in Issue #26 records a 10-day, 100000-toman add-on with `processing_wallet` and `pg_edit_readback_requires_review`. Earlier **read-only** PasarGuard evidence observed the exact original target applied; this was a point-in-time observation, **not** fresh present-tense proof. Wallet history suggested but did not conclusively establish the earlier debit timeline. **This source change has not inspected current production Native/SQL, recovered that customer, modified live Wallet, installed a ZIP, or sent a message.** The approved operator needs current original-evidence review before invoking the new backend function. No case closure solely because a PR, CI, or Release succeeds.

## Isolated acceptance

`python3 tools/test_post_v9_wallet_sconfig.py --run-disposable` exercises the real current Checkout and WalletSettlement against disposable MariaDB, including second-session committed-debit proof, insufficient funds, Native unknown/rejection, local audit failure, plan changes, concurrency, crash and ambiguous commit. Only the Native transport is mocked.

`python3 tools/test_post_v9_wallet_edit_recovery.py --run-disposable` exercises the real PasarGuard adapter with a loopback Native server, real PHP 8.5 and disposable MariaDB. Both DAY and VOLUME, imported `order_id=NULL`, permissions, wrong origin, original baseline, independent drift, SQL audit failure and one-time finalization are checked. These suites also join required `tools/ci_quick.py`; source tests are not a substitute for the exact-HEAD GitHub CI gate, independent read-only review, Owner Merge/Release approval or production acceptance.
