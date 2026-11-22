# Wallet finality, Native uncertainty, and original-operation recovery

Owner: Finance (Issue [#26](https://github.com/ach1992/bot-refactor/issues/26)). This describes the **current supported source contract**, not proof of installed Production behavior, recovery authorization or deployment instructions.

## Contract and transaction boundaries

1. Validate the original payment, authenticated customer/quote and eligibility under the existing per-payment lock. Insufficient funds or an unsupported service means **no Wallet debit and no Native write**.
2. For Wallet, claim the original `pays.id`/`hash_id` and debit `users.wallet` in one SQL transaction. Commit the resulting `processing_wallet` state **before** dispatching any external service operation. This state means financially *held*, **not** fulfilled or customer-notified.
3. Invoke Native for that claimed operation. An HTTP error, bad acknowledgment, first-readback mismatch, or worker crash **does not establish failure**. Read back the exact Native account before deciding whether another physical operation is necessary. The Owner explicitly authorizes reapplying the **same original absolute PasarGuard RESET/PUT renewal** when incomplete or unknown, using its pinned original account/entitlement and the same held Wallet claim. This exception does not apply to additive operations, CREATE, Marzban, or a different payment.
4. Verify the exact resulting Native entitlement. Finalize `increase_order`, local order expiry (where applicable), and `pays.state=paid_with_wallet` atomically in a separate short SQL transaction. A failed finalization leaves the original claim eligible for later GET-first reconciliation or original PasarGuard absolute Retry; **never charge again**.
5. Handle Telegram delivery separately from payment and Native finality. Never infer a successful delivery from a committed local payment or a repeated callback.

**PasarGuard reset-renewal clarification (Issue #227):** the original,
durably claimed `RENEW_ACCOUNT` is an **absolute** plan replacement
(reset usage, set exact purchased volume and expiry), not an additive
`INCREASE_DAY`/`INCREASE_VOLUME`. If an original Native RESET succeeded
but the absolute PUT/SQL COMMIT failed, a payments-authorized operator may
observe the **same pinned native identity and immutable original operation**
and explicitly confirm finishing that original reset (including a measured
native entitlement-drift warning). The ordinary services Cron may also retry
that **same immutable absolute operation automatically** when its original
`pay:ID` mutation remains `mutating`, the authenticated Native account,
instance and access groups still match the pinned original, and the
already-purchased target has not been reached. Transient GET/RESET/PUT
failures defer the held claim to later Cron passes, not a second Wallet
debit. A staff confirmation rechecks live evidence and executes that
same original continuation rather than requiring a stale Native preview
digest; a confirmation made after Cron already completed the exact
payment reports its terminal status, without a second Native write. A changed
account identity, changed access groups, another conflicting operation or
unavailable provider is not permission for an unverified write. Prefer GET-only local reconciliation when Native already
has the exact originally verified target. Additive DAY/VOLUME remains
non-replayable: only verified-original-Native evidence can authorize a
single local COMMIT. Paid CREATE recovery reuses its original reserved
effect/line and cannot provision a second account. An unknown Telegram
acknowledgment never authorizes an automatic second send.

**Automatic renewal cycles (migration 004):** an automatic `RENEW_ACCOUNT`
also owns a unique `automatic_renewal_attempts` row. Original-wallet
recovery validates this immutable captured cycle (original pay, customer,
order, server, plan, amount, days and volume) before Native or financial
finality. Recovery seals `pays.state=paid_with_wallet`, the original
once-only renewal audit, exact live-verified order expiry, one-shot
customer/admin notice obligations, and `automatic_renewal_attempts.stage=done`
in the **same** local transaction. It clears the cycle's `notif` flag.
A legitimate zero-price/100%-discount cycle is allowed only with matching
original automatic-renewal evidence; other Wallet recoveries still require
a positive original amount. There is no second auto-renewal reservation
or debit. Original payments with no valid, pinned Native mutation/attempt
evidence remain review cases, not invented successes.

**Observation is not admission:** a long-lived, original Native
`provider_state=mutating` fence blocks further edits, plan changes, bulk
gifts, deletion, add-ons and new financial claims, **not** authenticated
GET-only status, quota, expiry or subscription/config-link rendering.
Only the original immutable provider panel/account identity may be
observed; foreign, missing, retired, or unverified Native data remains
unavailable. No stale local quota/expiry is substituted. User/admin
subscription-detail presentation and warning semantics remain unchanged.
The matching CI scenarios exercise reset-only 12-to-10 GiB correction,
additive pending Native work, wrong account identity, concurrent workers
and original first-send obligations.


`RENEW_SCONFIG` (external Marzban plan renewal) uses the original Wallet claim, a plan snapshot pinned by the locked payment transaction, and original `pay_id` passed to the Native adapter. The service call no longer runs inside the Wallet transaction; the renewal audit and paid state commit together. A committed but ambiguous Native result remains `processing_wallet`, and the old button cannot charge or renew again. A Wallet Marzban renewal carries the original **physical endpoint, provider type, logical server, account remark, payment ID and purchased days/volume** through the helper, service router and Native adapter. The adapter compares the original endpoint before its first authenticated GET and rechecks the same pinned target at every existing Native reset/write boundary; a later provider/endpoint substitution leaves the original claim held. The Marzban renewal does **not** have the PasarGuard original absolute-mutation journal: this source package does not grant automatic recovery or re-issuance authority for that class. Operations must establish the original remotely applied renewal independently before deciding a target-specific manual resolution.

The `processWalletPayment()` diagnostic `claim_commit_unknown` means the SQL COMMIT acknowledgement itself was ambiguous. The payment row and wallet balance must be inspected; never treat that log as proof of rollback. The `post_commit` stage means the durable claim was acknowledged but subsequent work is incomplete or uncertain.

## PasarGuard Wallet DAY/VOLUME recovery

Backend functions: `achbotWalletPgResumeAddon(mysqli $db, int $payId, int $actor, int $primary)` (explicit payments-capable staff) and internal `achbotWalletPgResumeAppliedSystem(mysqli $db, int $payId)` in `src/Finance/WalletEditRecovery.php`. Neither is a Telegram/UI callback. The system entrypoint is invoked by the existing **services Cron** via `achbotWalletAddonMaintain()` after an inactivity grace window and current read-only verification of the original PasarGuard effect. A live manual request still needs target-specific Owner authorization. Automatic reconciliation is not administrator override, and it never rewrites a baseline, unproven or independently drifted Native service.

The function refuses anything other than the **original** `INCREASE_DAY_*` or `INCREASE_VOLUME_*` `processing_wallet` claim. It validates original owner, order, paid unit count, price and saved PasarGuard physical identity, original `pay:ID` mutation/absolute target, native instance/incarnation and imported origin. An imported shared `service_effects.order_id=NULL` remains NULL; no old order is manufactured. With `observe_only`, the Native adapter performs an authenticated **GET** and accepts **only** the already-applied original absolute entitlement. A baseline, changed Native entitlement, wrong group, unverified original journal, missing approval, changed customer/account or archive fails closed. It does not PUT, RESET, refund, add days/volume again, or send Telegram.

On successful GET-only readback, the **original account mutation fence stays `mutating`** until a short local SQL transaction locks the original payment/order/account, rechecks original staff permissions (for manual invocation), archive state, physical identity and mutation proof, then changes the original journal to `linked` **atomically with** original order expiry, the one add-on audit, `paid_with_wallet` **and original first-send notification obligations**. No SQL lock spans the Native HTTP GET. An audit, notification-outbox or COMMIT failure rolls back the journal transition and preserves the original fence. If final COMMIT acknowledgment is ambiguous, verify the original payment state before any further action; never issue another debit or Native write.

**First-time wallet checkout now has the same invariant, not merely
the recovery worker.** Before committing the Wallet debit, the PasarGuard
DAY/VOLUME callback verifies that the original durable customer-delivery
**entire original notice-writer dependency** is ready: InnoDB delivery tables and unique intents, `payment_attempts` writer columns from migrations `003` **and `014`** (including correctly typed evidence fulfillment payload/state, unique pay/quote constraints and required precision), transactional `setting` report-marker storage, and the evidence encryption key. Missing or incompatible columns/schema fail before any Wallet debit or Native edit. Source-real tests include the staged `003` + `018` without `014` writer columns and a present-but-incompatible fulfillment payload, for both DAY and VOLUME.
 In the existing `payIncreaseDayWithWallet*` and
`payIncreaseWithWallet*` routes, the original debit and
`processing_wallet` claim COMMIT before Native. On genuine
PasarGuard DAY/VOLUME payment claims, `achbotPgServiceEdit()` preserves
the original `provider_state=mutating` fence after verified Native PUT.
The exact same applied-only internal finalizer (fresh authenticated Native
GET; never an additive re-PUT) then commits the original account journal,
payment/order/audit and customer/admin outbox together. If the PHP worker
dies after the successful PUT but before that local COMMIT, the Cron
self-healer can finish the **same** operation without requiring a manual
staff intervention. If Native ACK itself is missing, the original in-flight
claim stays held for the next read-first check. Other provider types,
manual service actions and old Wallet Marzban renewal contracts are not
silently altered; a failed native proof never becomes a paid invoice.

The **existing** `payment_attempts` and `payment_delivery_intents` schemas hold one new, notification-only Wallet claim and its sealed customer confirmation packet, created as part of that same successful financial COMMIT. `achbotDeliveryMaintainPending()` sends only `pending/attempt_count=0`; a lost Telegram ACK is `unknown`, never automatically resent. The existing Persian DAY/VOLUME confirmation templates remain in use. The **ordinary** administrator/report-channel fanout is sealed and persisted in a per-payment `WALLET_ADDON_REPORT:ID` setting row in the same COMMIT. Its dispatcher first consumes a one-shot claim in a short transaction and **only then** calls the existing `achbotNotifyAdmins`; confirmed/unknown results are journaled without optimistic resend. Crash-before-send after claim may require manual evidence review, never blind duplicate fanout.

The internal Cron scans only old enough `processing_wallet` DAY/VOLUME claims with a matching original `pay:ID` PasarGuard `mutating` fence and uses the very same original identity/Native applied-only reconciliation as the human path. Per-case deferrals rotate `pays.updated_at` as an inbox retry schedule (no paid-state, money, Native or quote changes), so old baseline/drift cases cannot starve newer provable cases. No arbitrary total retry ceiling or extra Cron worker is introduced. This path does not automatically resolve uncertain Wallet Marzban renewals or buy-subs, which do not have the required exact PasarGuard absolute-journal evidence.

**Evidence boundary:** The source cannot independently reconstruct a historical Wallet debit ledger where none was recorded. `processing_wallet` is the guarded original claim under current transaction rules; that does not, alone, prove the entire past balance timeline or exclude out-of-band changes. The Owner must verify target-specific financial history before approving live reconciliation.

## Mandatory production approval and verification

Before any real Hell/Vivan/Chili SQL, authenticated Native read/write, Wallet correction, customer message or recovery action, obtain explicit target-specific Owner approval. Provide the original payment ID, known wallet/quote provenance, current `pays`, order, original `service_effects` and `provider_accounts` state, current Native GET evidence, pending attempts/notifications, expected changes, backup and roll-forward plan. Runtime ZIP and GitHub Release are not installation/OPcache evidence.

For approved once-only settlement, verify: original Wallet balance **unchanged by recovery**, `pays.state=paid_with_wallet`, the order's exact Native-verified absolute entitlement, precisely one local renewal/add-on accounting row from this finalization, original `pay:ID` still consumed, original imported provenance/NULL order unmodified, no duplicate Native operation for an already-applied entitlement, and no fabricated Telegram acknowledgment. For an incomplete or unknown **original absolute PasarGuard renewal only**, a repeated RESET/PUT must use the same persisted absolute target and be followed by independently verified Native readback. If the Native account/instance, access groups, original payment or entitlement evidence is unrelated or cannot be authenticated, defer the operation for review rather than claim success. Report communication gaps separately. After a committed reconciliation, use only explicitly approved compensating operations, not ad hoc rollback SQL.

### Hell original payment #112719

As documented in Issue #26 on **2026-11-17**, a target-specific Owner-authorized operation using **installed v0.10.6** reconciled the original DAY 10 / 100000-toman `processing_wallet` payment `#112719` for Hell order `#33908`, account `plus-5100859387-93217`. Fresh authenticated PasarGuard GET matched original unchanged Groups, 60 GiB quota and original target expiry. The installed original applied-only helper committed **one** local audit, terminal `paid_with_wallet` and original account `linked`, without another Wallet debit or Native PUT/RESET. The regular customer message was subsequently sealed and dispatched once using the existing delivery ledger (Telegram ACK verified), and the normal admin/report fanout received two acknowledgments. The task-specific root-only backup was removed upon verified completion. Original historical Wallet debit timeline was not independently recoverable; no assumption of a second refund or debit is justified. **This production settlement is NOT proof that the new Cron source has been installed.**

## Isolated acceptance

`python3 tools/test_post_v9_wallet_sconfig.py --run-disposable` exercises the real current Checkout and WalletSettlement against disposable MariaDB, including second-session committed-debit proof, insufficient funds, Native unknown/rejection, local audit failure, plan changes, concurrency, crash and ambiguous commit. Only the Native transport is mocked.

`python3 tools/test_post_v9_wallet_edit_recovery.py --run-disposable` exercises the real PasarGuard adapter with a loopback Native server, real PHP 8.5 and disposable MariaDB. Both DAY and VOLUME, imported `order_id=NULL`, original `fileid=0` historical migration references validated without modern import-origin records, permissions, wrong origin, original baseline, independent drift, SQL audit rollback with competing-mutation refusal, concurrent operator recovery, ambiguous final COMMIT and one-time finalization are checked. The enhanced fixture also exercises old-enough internal Cron recovery and exactly-once normal first-send customer/admin notification journals, with no real Telegram messages. These suites also join required `tools/ci_quick.py`; source tests are not a substitute for the exact-HEAD GitHub CI gate, independent read-only review, Owner Merge/Release approval or production acceptance.

`python3 tools/test_post_v9_wallet_marzban_target.py --run-disposable` exercises actual renewal helper, Core dispatcher and Marzban adapter against disposable MariaDB and two independent loopback Marzban HTTP providers with the same account username. It checks endpoint/provider substitution after the initial Wallet Checkout comparison, at the final adapter handoff, during authenticated GET, and normal unchanged-target fulfillment. Only timing/configuration changes are fault-injected in the fixture; Native adapter and HTTP remain current source.

## Original Wallet BUY_SUB and Marzban review (Issue #227, PR #228)

The existing shared **Payments Requiring Review** inbox retains original
`BUY_SUB` and `RENEW_SCONFIG` cases when `pays.state=processing_wallet`
and the ten-minute inactivity window has elapsed. An authorized payments
administrator can open **Review original Wallet effect**; this does **not**
charge the Wallet, create an account, mutate Native, or settle a payment.

For `BUY_SUB`, `src/Finance/WalletOriginalRecovery.php` first reads the
original immutable `pays.id` and per-ordinal `service_effects` ledger,
including the consumed `wallet_hold` and the **already committed** complete
capacity reservation. Each `create` line is checked against the same original
payment, plan, server, group, endpoint, `operation_id`, remote username,
expiry and bytes. A linked line is accepted only if the original local order
belongs to the same purchaser, physical server, account, plan and allocated
price. An authenticated, pinned **Native GET**, with exact operation marker,
original expiry/quota and a second identity read, independently corroborates
each existing account. A matching Native account **without a proven linked
original local order** remains explicitly marked for staff investigation;
GET success alone is not authority to manufacture an order or repeat CREATE.

Only when **all** original lines were already linked and corroborated can
payments-authorized staff receive an actor-bound confirmation token. The
confirmed action acquires the existing per-payment worker lease and repeats
all reads, reconstructs the **original first-send account-details message**
from current verified Native data, then locks the same Wallet payment,
original hold/effects/orders and staff authority in one short SQL transaction.
It inserts **one original notification-only Wallet attempt** and exactly one
sealed delivery intent per account, and changes the **same** payment to
`paid_with_wallet` in that transaction. It does not generate a CREATE,
reserve/release capacity, insert a new order, generate an additional debit,
or claim a prior Telegram acknowledgement. A SQL or Native uncertainty leaves
the original claim reviewable. The historical Wallet debit is **not**
independently audited by this reconstruction; an Owner must verify the
specific tenant's original Wallet balance/quote history before any live use.

For `RENEW_SCONFIG`, the existing original Wallet claim, description,
server/endpoint, account identity and **current** plan values are displayed
in the operator evidence view. An authenticated current Marzban GET is
advisory only. Unlike PasarGuard, the old Marzban renewal has no durable
original absolute mutation journal that proves the original purchased
expiry and quota were applied: a matching current account or today's mutable
plan **does not establish** that the old paid renewal succeeded. This path
never issues another Marzban RESET/PUT, recharges Wallet, or fabricates a
successful local payment. Independently reconciled provider and payment
history is needed before any target-specific Owner-authorized intervention.

### Separate administrator Telegram report obligation

`WALLET_ADDON_REPORT:<pay_id>` is a separate original notification obligation.
Its unacknowledged `pending`, in-flight `dispatching` or
`manual_dispatching`, and `unknown_or_partial` states remain visible in the
**same** inbox even after customer Telegram ACK and financial settlement.
The operator sees the exact payment/marker and real delivery state. The
first `pending` send belongs to the existing first-send Cron only;
`unknown_or_partial` is **never resent automatically**. An authorized
administrator may explicitly consent to **one** separately journaled manual
re-send of the **same encrypted original report** after the UI warns about
possible duplicate recipient messages. This sends no Wallet/Native/account
operation. A new `manual_dispatching` marker is durably claimed *before*
Telegram; a lost ACK or mid-send crash stays in human review and cannot
trigger another automatic or manual send. Only a real returned Telegram
acknowledgement marks that attempt `acknowledged` and removes the case from
attention; it must not retroactively assert that the earlier unknown send
was successful. Actual recipient acknowledgement may still require
independent investigation for multi-recipient partial fanout.

### Source-real CI / owner gates

`tools/test_post_v9_wallet_original_recovery.py` exercises original
WalletSettlement/ServiceEffects CREATE and local order against socket-only
MariaDB, authenticated HTTP Marzban GET, actual original-identity matching,
permissions, forged confirmation, concurrent operator finality, durable
one-shot Telegram intent and conservative Marzban renewal hold. The existing
`test_post_v9_wallet_edit_recovery.py` now checks customer ACK + original
admin report UNKNOWN, denial, concurrent manual report resend, and one-shot
ACK, and `test_post_v9_wallet_renewal_recovery.py` now covers an original
order with three independent `processing_wallet` claims and concurrent
operator/Cron actions. All are in `tools/ci_quick.py`.

These scenarios are **disposable source tests**, not proof that the historical
Hell payments `113037/113331/113346` for order `30271` were resolved. No
live data/provider/Telegram operation is authorized here. The operator's
Persian review copy remains subject to explicit Owner UI/copy approval.
The current `023_scoped_payment_lock_indexes.sql` migration is **not included
in the runtime ZIP**: obtain exact tenant authorization, schema preflight,
backup, rollback plan and index verification before matching production
runtime installation. Preserve independent review/merge/release gates.
