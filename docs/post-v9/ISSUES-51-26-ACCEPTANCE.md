# Issues #51 / #26 — source acceptance matrix and remaining gates

This is the **execution/evidence matrix**, not a second product specification.
The accepted policy and schema contracts remain in PROJECT-SPEC.md,
FINANCIAL-CONSOLIDATION.md, PAYMENT-UX-LIFECYCLE.md and migration guides.
Only **source/isolated synthetic** evidence is claimed here. A test marked PASS
does **not** certify a live merchant, installed database, real Telegram message,
real Marzban provisioning, refund or real-money correctness.

## Pre-installed payment readiness snapshot (source-only tooling)

The read-only [payment readiness preflight](PAYMENT-READINESS-PREFLIGHT.md)
compares 102 installed runtime files against a supplied verified manifest,
inspects the original schema and additive 001/003-009 table shapes, and checks
unique settings documents, current gateway rules and method configuration
without showing credentials or modifying financial rows. Its disposable
MariaDB test runs in the exact-candidate CI; it is **not** a claim about an
installed tenant or real payment.

## Reproducible test drivers

- Finance callback/provider/HTTP/DB acceptance: ../../tools/test_post_v9_finance_routes.py;
  its report contains each scenario name and actual PASS.
- Manual provider/Telegram/admin effects: ../../tools/test_post_v9_manual_payments.py;
  its scenario list includes direct execution of ManualApproval.php, Checkout.php,
  RenewalCheckout.php, Extensions.php and payment receipt handlers.
- UX lifecycle, stale message and payment status: ../../tools/test_post_v9_payment_ux_lifecycle.py.
- PR #72 review remediation: ../../tools/test_post_v9_wallet_checkout.py
  runs the real `buyType=much` Catalogue draft and locked wallet debit;
  ../../tools/test_post_v9_nonpaid_routes.py uses real `buildRemark`
  for independently authenticated manual-mode admin batches; and
  ../../tools/test_post_v9_referral_route_order.py executes real standard
  and custom receipt handlers with failed post-COMMIT QR, customer message
  and operator markup transports, proving one 008 award and no CREATE replay.
- Full source, package and original/additive-schema acceptance:
  ../../tools/check_post_v9.py. GitHub Compatibility workflow runs the exact
  candidate on PHP 8.1 and 8.4 and executes frozen historical V9 separately.
- Run from repository root with installed PHP/MariaDB prerequisites:
  python3 tools/test_post_v9_finance_routes.py --run-disposable --report /path/finance.json
  python3 tools/test_post_v9_manual_payments.py --run-disposable --report /path/manual.json
  python3 tools/check_post_v9.py --report /path/post-v9.json
  Run with TMPDIR set to an **owned, sufficiently capacious directory** when
  /tmp is constrained. Never clean another test owner's temporary files.

PASS(S) means actual current source with MariaDB + locally simulated
Telegram/provider/Marzban behavior. PASS(static) denotes validated code/
packaging, not a browser or remote integration observation. GATED means
authorization, provider credentials or new accepted persistence is required.
NOT TESTED means no corresponding real execution evidence. No network request
to a real payment processor is authorized by this matrix.

## Current executable scenarios

| ID / current code path | Preconditions, input and identity | Expected DB / financial effect | Telegram / browser / provider | Marzban / replay / failure | Evidence and result |
|---|---|---|---|---|---|
| H01 GatewayInitiation.php NOWPayments | Customer; eligible pending order; 003 ready | One provider-scoped attempt and immutable quote; no duplicated invoice | HTTPS checkout link only; browser success is not settlement | Reused link, provider timeout or lost response cannot mint a second invoice | finance: nowpayments_inflight_reservation_no_double_invoice, nowpayments_unknown_creation_prevents_orphan_reissue — PASS(S) |
| H02 GatewayReturn.php / pay/back.php NOWPayments IPN | Valid HMAC; exact invoice, provider, amount and order | Persist primary/child evidence; no credit for unauthenticated status | Minimal real local HTTP IPN response, no forced Telegram fulfillment | Duplicate/forged/cross-invoice IPN cannot duplicate effect | finance: nowpayments_real_loopback_http_ipn_minimal_bootstrap, nowpayments_ipn_cross_invoice_child_not_acknowledged — PASS(S) |
| H03 NOWPayments reconciliation worker | Finished and sufficiently paid; browser never returned | One final payment claim, guarded wallet/service request | Status Telegram may be retried independently of financial state | Pending/partial/underpaid/late/unknown require appropriate hold or review | finance: nowpayments_signed_ipn_durable_without_browser, nowpayments_terminal_partial_review_no_credit — PASS(S) |
| H04 NOWPayments owner finance inbox | Owner in private chat; documented state and many children | Read-only operator evidence, bounded 12 per page, no extra-child paid assumption | Persian details, safe provider inquiry and keyset cursor | Same-second 12/13 boundary, no repeated or skipped cases | finance: nowpayments_owner_inbox_case_cursor_stable_ties, nowpayments_13th_funded_child_cursor_inquiry_safe — PASS(S) |
| H05 NextPay verification | Current supported merchant, exact provider-scoped reference | Verified wallet credit once or guarded service attempt | Provider HTTP response and Persian return page never by themselves prove delivery | 503, code-zero, missing 003, collision and replay stay bounded | finance: nextpay_http_200_code_zero_actual_wallet_fulfillment, nextpay_callback_provider_scoped_colliding_external_reference — PASS(S) |
| H06 Zarinpal verification | Current supported merchant, matching authority | Atomic guarded local claim; mismatches no effect | Verified return not equivalent to service success | Repeated/foreign authority rejected; service failure held for review | finance: zarinpal_mismatched_authority_refused, hosted_zarinpal_wrong_provider_paid_claim_rolls_back — PASS(S) |
| H07 Hosted multi-account (NextPay/Zarinpal) | Two Marzban accounts, one verified order | No false completed state from partial external effect | Operator sees unresolved outcome, no second-payment invitation | Remote partial/full result and callback replay never re-provision automatically | finance: hosted_nextpay_two_account_partial_effects_operator_recovery_no_replay and Zarinpal equivalent — PASS(S) |
| M01 Card-to-card receipt | User, pinned destination/quote, original receipt photo, stage=review | No wallet credit before review; operator or bank-Cron claim only once | Persian pinned bank instructions and receipt button | Scheduler rejects competing method/duplicate callback | manual: bank_random_exact_amount_persisted_and_rendered, scheduled_before_admin_bank_approval_exactly_one_credit — PASS(S) |
| M02 Gift-card receipt | Valid seller URL, manual review | Preserve pinned original order/method, no automatic bank credit | Separate Persian gift-card instructions/photo lifecycle | Failed delivery retries same image, never second credit | manual: gift_distinct_quote_link_and_photo_action, uncertain_telegram_delivery_never_double_credits_gift_card — PASS(S) |
| M03 Manual USDT BEP20/TRC20/ERC20 | Configured checksum-valid destination/network/rate | Pinned immutable quote, manual provider ledger only | Explicit asset and network, proof uploaded to operator | Cross-network or method-switch replay rejected; no automatic blockchain settlement | manual: manual_crypto_b_immutable_network_quote_and_replay, manual_crypto_c_immutable_network_quote_and_replay, manual_crypto_e_immutable_network_quote_and_replay — PASS(S) |
| M04 Manual TRX on TRON | Explicit network/rate/valid Tron Base58Check | Separate TRX quote and original photo; no bank auto settle | Native TRX payment message, no retired checker | Forged network/invalid destination denied | manual: manual_trx_unique_quote_receipt_and_no_auto_settlement, tron_checksum_invalid_destination_denied_before_claim_t — PASS(S) |
| M05 Manual wallet top-up approval | Operator independently verified deposited funds; type INCREASE_WALLET | One locked wallet credit and paid-state transition, preserving signed debt | Operator can approve/reject; user sees status separately | Concurrent buttons, same image retry, and late Telegram response cannot double-credit | manual: manual_wallet_two_admin_concurrent_single_credit, late_telegram_response_cannot_revert_terminal_payment — PASS(S) |
| M06 Standard BUY_SUB accept{hash} | Admin, exact have_sent, correct subject, delivered original receipt in reviewed manual attempt | Atomically claim processing before remote work; approved only after checked local commit | Unreviewed/foreign-provider/stale buttons show refusal | One remote create max across concurrent approvals; failed Marzban/SQL stays processing for review | manual: real_standard_operator_unreviewed_receipt_denied, real_standard_concurrent_admins_one_remote_create, real_standard_verified_receipt_one_delivery_no_replay — PASS(S) |
| M07 Standard RENEW_SCONFIG accept{hash} | Admin, original renewal order, manual reviewed photo and message | Same exclusive processing fence and guarded completion | Do not announce delivery on remote ambiguity | Remote PUT/response loss and local insert failure cannot re-trigger renewal | manual: real_standard_renewal_receipt_one_remote_change_no_replay, real_standard_renewal_uncertain_remote_change_no_replay, real_standard_renewal_mysqlireportoff_unconfirmed_order_never_approved — PASS(S) |
| M08 Standard multi-account partial | Authorized multi-account standard purchase, account N errors | Keep processing with no automatic re-approval | Operator gets reconciliation notice | Partial remote effects may survive local rollback; no replay | manual: real_standard_partial_second_account_stays_reconcile_no_replay — PASS(S) |
| M09 Standard post-remote local failure | Remote completed, order INSERT/capacity fails, MYSQLI_REPORT_OFF variant | Never re-arm have_sent; local transaction rolls back where possible | Operator message without raw private SQL text | No second remote call on callback replay | manual: real_standard_remote_success_local_write_failed_no_automatic_replay, real_standard_mysqlireportoff_unconfirmed_order_never_approved, real_standard_mysqlireportoff_capacity_reservation_failure_prevents_create — PASS(S) |
| M12 Standard post-COMMIT Telegram outage | Verified manual standard purchase/renewal; remote and exact SQL COMMIT already succeeded | Remain **approved**; payment/service record cannot be undone by messaging | Distinct Persian operator follow-up; no misleading remote/financial uncertainty even if operator alert also fails | No second remote action on repeated callback; stage and pay ID logged | manual: real_standard_postcommit_telegram_failure_approved_no_replay_truthful_review, real_standard_renewal_postcommit_notification_outage_preserves_approved_no_replay, real_standard_renewal_both_telegram_messages_fail_without_fatal_or_replay — PASS(S) |
| M13 Admin message edit and failed remote outcome | Approved standard order with failing Telegram keyboard edit, or explicit failed remote response/warning channel outage | Preserve approved when button edit fails; preserve processing for remote ambiguity | No raw exception to admin; bounded pay ID, stage and exception class/code in protected logs | No false local order/credit on rejected remote result, no repeated provisioning | manual: real_standard_operator_buttons_failure_does_not_leak_raw_exception, real_standard_explicit_remote_failure_kept_for_review_without_local_order, real_standard_ambiguous_remote_and_failed_admin_notice_no_fatal_no_replay, real_standard_renewal_explicit_remote_failure_no_local_order_no_replay — PASS(S) |
| M10 Custom BUY_SUB accCustom | Admin, chosen custom day/volume, manually reviewed photo | One service claim and approved state only after effect | Standard plan or non-admin callback rejected | Remote uncertainty/replays stay reviewable | manual: real_custom_authorized_review_state_approves_only_once, uncertain_custom_external_effect_operator_reconciliation_no_retry — PASS(S) |
| M11 Renewal/day/volume extensions | Exact order/subject, operator, manual reviewed evidence | One guarded extension record after remote verified completion | Late/foreign provider callback rejected | Bank scheduler/admin race cannot double apply | manual: real_renewal_operator_before_bank_cron_one_remote_effect and day/volume equivalents — PASS(S) |
| M14 Provider-neutral service edits | Paid renewal, days/volume via gateway, wallet, reviewed receipt or bank Cron | Authenticated account readback with absolute applied expiry and quota before confirmed local accounting/terminal payment state | Future panel denied until an adapter proves its effect; bounded one absolute correction after strict original-state readback | No duplicate relative edit/reset, no false success on reply loss, no-op 2xx, changed target, competing edits, incomplete local SQL or stale Cron | Native actual HTTP/SQL service edits (12+ scenarios) and native actual bank scheduler + finisher (7+ scenarios) — PASS(S); installed post-crash unattended reconciliation still GATED |
| W01 Customer wallet spending | Unique customer, positive amount, no active external attempt | Guarded debit, never create or worsen customer debt | Only post-claim status confirms payment | Nonpositive/insufficient/cached action denied | manual: wallet_spend_invalid_walletnegative_no_financial_effect, cached_wallet_button_blocked_after_bank_transfer — PASS(S) |
| W02 Customer-to-customer transfer | Two distinct unique user identities and genuine one-use transfer step | Both wallet effects and step consumption commit atomically; signed debt protected | Persian transfer result or safe deferred notice | SQL/lost COMMIT/no recipient/overflow: no blind retry | post-V9 wallet_transfer_scenarios — PASS(S) |
| W03 Administrator debit/debt | Authorized administrator, reason, separately gated 005 audit | Durable actor/reason adjustment, negative debt allowed; customer cannot deepen | Correct admin audit and status without source secrets | Repeated action, lost response and rollback must not duplicate debt | post-V9 admin_wallet_adjustment_scenarios — PASS(S); installed 005 activation GATED |
| U01 Draft expiry/old buttons | True unissued pending quote, same-owner replacement or timeout | Only eligible unclaimed draft can expire, never provider-issued evidence | Status/check button; no invitation to pay again after claim | Replaced or stale Telegram button cannot issue new provider attempt | post-V9 payment_ux_cases and checkout_serialization_cases — PASS(S) |
| U02 Manual photo delivery | Saved photo ID, permitted original actor/chat and exact stage | Pending/receiving/review state, original evidence durable | Retry same photo after Telegram timeout/no message ID | No extra wallet/service effect, no second image accepted | manual: same_photo_recovery_after_uncertain_delivery_gift_card and bank/USDT equivalents — PASS(S) |
| U03 Browser status/RTL | Redirect-only state, no authenticated settlement implied | Rendering never changes money | Escaped Persian, dir=rtl, responsive CSS, return-to-bot button | Stale/invalid links held for safe inquiry | source static and UX lifecycle — PASS(static); real mobile visual inspection NOT TESTED |
| D01 Original 23-table legacy | Frozen schema with no optional 003, no unknown attempt evidence | Compat pre-ledger cases only where explicit allow rules exist | No false promise of hosted settlement | Partial/missing 003 must never masquerade as legacy absence | finance: current vs 23-table cases; manual: partially_missing_003_ledger_blocks_cached_wallet_debit — PASS(S) |
| D02 Additive 003/004/005/006 | Separately staged SQL, expected constraints/FK and absence gates | Persist only authorized ledgers, no migration to real tenant | No customer-visible change without source/deploy approval | Partial schema and SQL permission errors fail closed | post-V9 full suite and finance: shared_ledger_composite_provider_uniqueness_and_fk — PASS(S); installed migration GATED |
| D03 MySQL statement and transaction failures | Owned real MariaDB, MYSQLI_REPORT_OFF, races/lock waits | Atomic local effects where possible; uncertain commit not assumed undone | Review/error rather than fake fulfillment | Do not replay remote effect or double-credit | finance: local_insert_order_mysqlireportoff_requires_confirmed_sql; manual: real_standard_remote_success_local_write_failed_no_automatic_replay — PASS(S) |
| D04 Frozen historical V0–V9 | Pinned original commit, untouched historical expectations | No changes to frozen original schema or golden behavior | Legacy verified separately from current source | Candidate tests must not weaken historical acceptance | Compatibility CI historical worktree; source-full post-V9 current runtime — historical execution must be verified on the exact candidate CI separately |
| O01 Live merchant/payment | Real merchant sandbox/account, authorized transaction | Actual provider account/payment correlation | Operator/customer expected production-grade messaging | Real refund/funds confirmation and settlement | NOT TESTED — real money or merchant actions not authorized |
| O02 Installed Telegram/Marzban | Approved bot installation/tenant and approved isolated accounts | Validate actual DB, sidecars, Cron and effect identity | Real private chat on mobile, live callbacks, page redirects | Full remote reconciliation, permissions and cleanup | NOT TESTED — installation and customer actions not authorized |
| O03 Durable per-account effect recovery | Accepted schema and operator reconciliation contract for uncertain account N | Per-account external effect identity, durable checkpoint and safe recovery | Correlated operator decision, no false service success | Process death / unknown external response never causes blind provisioning | NOT COMPLETE — schema/architecture/owner decision required under #26 |
| O04 Release and operator acceptance | Independently reviewed exact candidate and separate Owner grants | No tenant migration or money changes before authorization | Source merge != installed UX acceptance | Verify installed rollback/recovery before closing Issues | GATED — independent review, Owner Merge/release/deploy grant, real operator acceptance |

## PR #67 hosted-capacity safety acceptance (BLOCKER-PR67-1)

For hosted NextPay, Zarinpal and NOWPayments BUY_SUB, the first paid 007
effect must atomically own ALL purchased server capacity **before remote
CREATE**. Assert insufficient 2-request/1-slot produces zero CREATE and
no local orders/refund claim; exact 2/2 creates only twice with exactly
two held units; separate buyers, hosted/manual shared-InnoDB contention
cannot overbook; standard and custom manual BUY_SUB payment-scoped
ordinal-0 manual_hold are committed in the original reservation/claim
transaction, consumed by first CREATE without a second decrement.
A missing hold fails before POST. Wallet-funded BUY_SUB instead commits
one ordinal-0 wallet_hold inside the wallet debit claim. In both held
methods CREATE rows reserve ZERO extra; SUM(payment reserved_count) equals
exactly the paid quantity rather than double-counting. Custom manual SQL
hold failure rolls back the claim/slots without remote CREATE and permits
safe reapproval ONLY if original state remains have_sent.
Lost second CREATE keeps all units and operation identities for operator
review; failed first hosted effect INSERT or manual hold INSERT rolls capacity
back; callback replay and local order-link failure never create or debit again. Missing or
earlier 007 reserved_count schema fails closed. The unchanged old 007
schema must not be silently upgraded or treated as having a hold. Current
tests are disposable source-real PHP/MariaDB with synthetic providers,
NOT operator-installed migration/merchant acceptance.

## Acceptance and interpretation

The PHP tests run actual current source with **owned, disposable** MariaDB,
local HTTP loopback where applicable and synthetic Telegram/payment/Marzban
responses. They do not use live merchant API keys, live Telegram messages or
customer records. A simulated remote success is not proof of a real Marzban
effect; a test's expected local state is not proof that the customer has
received service. Real negative wallet debt is an *authorized administrator*
capability, never a customer-created shortfall.

The standard accept route keeps a processing no-replay fence after uncertain
effects. That is a **safe stop and escalation**, not an automated durable
per-account recovery system; #26 remains open until the latter has a
source-backed owner-approved contract and acceptance. The optional same-second
pagination test specifically asserts identical timestamp values for cases
12 and 13; an unmeasured finance inbox index or new live schema is prohibited.

Update each issue with candidate HEAD/TREE, CI SHA, exact outcome and
remaining authorization gates; keep #51 and #26 open until required installed
and financial/operator acceptance is genuinely obtained or Owner explicitly
resolves the gate.


### PR #66 independent R1–R6 remediation — source-only acceptance (2026-10-22)

The author-separated reviewer of PR #66 exact HEAD `2fc4f57` returned
**COMPLETE / CHANGES_REQUIRED** with six REQUIRED findings. Until a fresh
author-separated verdict on the revised SHA and a separate Owner Merge grant,
this candidate remains **not approved to integrate**.

- **R1 capacity:** a standard BUY_SUB reserves its FULL verified account count
  with `pays.processing` before remote CREATE in the same short InnoDB
  transaction. A conditional `server_info.ucount>=count` UPDATE and actual
  affected-row check prevent oversubscription for concurrent different
  buyers. Existing `decreaseServerCapacity` strict writes also require
  remaining capacity. An unknown/partial external CREATE leaves original
  processing and capacity reservation in place **for human reconciliation**;
  capacity is not automatically released or charged a second time.
- **R2 renewal target:** `RENEW_SCONFIG` carries original persisted payment
  `server_id` across the committed claim, requires exact plan/server/inbound/
  payment identity just before outbound edit, and uses the original server
  for the external call. A reassigned plan with the same account remark
  never causes an edit on the new server.
- **R3 Telegram:** downstream `sendQrFromLink`, `sendMessage` and
  `editKeys` returns must positively confirm success; failure, `ok=false`
  or NULL are operator-notified post-COMMIT uncertainty, never financial
  rollback or service re-CREATE. `editKeys` propagates actual transport
  result; QR account ordinal and failed follow-up stage are visible privately.
- **R4 inviter reward:** require one exact existing inviter, bounded
  positive reward and confirmed conditional wallet UPDATE/affected row
  before claiming a credit. Missing/overflow/SQL failure do not generate
  false credited notices. After a confirmed credit, Telegram failure
  cannot cause re-credit; original payment and inviter identity are
  provided for independent human reconciliation, not replay.
- **R5 legacy receipt:** only when both 003 attempt/transaction tables
  are positively proven absent may the already-authenticated original
  operator-delivered 23-table receipt proceed. Partial 003, unknown
  schema, broken SQL, missing operator evidence remain denied. No
  backfill or migration occurs.
- **R6 real-helper assurance:** `tools/test_post_v9_standard_integrity.py`
  executes real Purchase/Core, Purchase/OrderWrites, ReceiptHelpers,
  RenewalHelper and ReferralReward PHP against private socket-only
  MariaDB. Network Marzban/Telegram are isolated spies only. The separate
  28-case gate tests total/multi-account/concurrent capacity, provider
  uncertainty, nonthrowing SQL, target reassignment, nonthrowing Telegram,
  reward failure and full original/partial/complete ledger conditions.
  The pre-existing 239 synthetic-route cases remain required separately.

These are **source-only** assertions. No real merchant receipt was settled,
no genuine Telegram/Marzban account was modified, no 003/007 was installed,
and true end-to-end crash recovery/installer/operator acceptance is NOT
established. Issues #26/#51 remain open.

### PR #66 second independent review / follow-up work — 2026-10-22

The fresh reviewer independently verified commit `b06ca4e` / tree
`6a91d5c` and reported **COMPLETE / CHANGES_REQUIRED**:
**BLOCKER 0; REQUIRED 5 (R1–R5); OPTIONAL 0; R6 RESOLVED**.
The original successful PHP 8.1/8.4 CI and 28 native tests did **not**
justify an APPROVE.

Follow-up work started on **the same existing PR #66 branch**, with NO
merge, release or schema installation. The source changes provide:
- **R2 partial improvement:** current-plan `active=1` semantics now hold
  for post-claim readback as well as locked preflight; inactive/deleted/
  moved plan stays no-replay `processing` with no outbound call.
  Logical `server_id` is NOT a pin to immutable physical endpoint config.
- **R3 improvement:** real `sendQrFromLink` inspects both QR photo and
  account text results, attempts the text even after a photo failure,
  cleans temporary PNGs, and reports an aggregate unconfirmed result.
  Post-COMMIT recipient loops attempt later accounts/administrators
  independently, collect bounded per-target issues, and never reprovision
  remotely on a failed notification.
- **R4 partial improvement:** referral credit is attempted independently
  before unrelated customer/operator Telegram notifications; other
  source callers of `giveReferralReward` now surface false outcomes to
  an operator with a payment/buyer/inviter correlation, never blindly
  credit again. **A durable, independently auditable per-payment referral
  journal/reconciliation policy still needs an accepted Owner/schema
  decision; general logs are insufficient for exactly-once evidence.**
- **R5 improvement:** both additive 003 tables must be jointly present
  with required columns/unique indexes, or jointly absent for the
  explicitly allowed original 23-table legacy receipt. Valid
  `payment_attempts`-only installation is no longer treated as
  usable. The original human-reviewed receipt and original delivery
  evidence remain mandatory.

**CRITICAL STILL OPEN:** R1 affects `decreaseServerCapacity` default
non-strict call sites (Checkout wallet and custom, bulk and trial) and
mixed-method concurrent capacity accounting. A post-remote throw alone
can roll back a wallet transaction and rearm a payment despite uncertain
remote CREATE. A coherent persistent no-replay reservation/effect design
must be reviewed and implemented; **do not make all callers strict
without proving the remote-uncertainty state and competing-method behavior**.
R4 durable referral evidence and unknown-result decisions likewise
remain open. Reviewer verdict is NOT superseded by partial changes.

The native real-helper suite now has **39** cases including the 11 new
R2/R3/R4/R5 cases, while retaining all 28 earlier actual helper
scenarios and the separate 239 manual method tests. A future Master
must verify **current actual SHA + CI** before relying on these numbers.
No installed or merchant/provider/operator acceptance has occurred.

The final review gate is: coherent R1/R4 decisions and implementation,
all named actual-helper regression cases and broad PHP 8.1/8.4 source
acceptance, one new author-separated independent reviewer on the exact
candidate, followed only then by an explicit Owner Merge grant.
Issues #26 and #51 remain OPEN.

## Issue #26 per-account identity: staged source-only acceptance

The new additive `007_service_effects.sql` and
`src/Finance/ServiceEffects.php` provide the first **BUY_SUB CREATE** journal. The unmerged Option A
R1/R4 source candidate extends that same 007 ledger to wallet-funded
BUY_SUB and authenticated unpaid free-trial/admin bulk CREATE, and
stages a separate 008 referral award table. It does **not** provide
generic service-edit transactions, distributed exactly-once execution,
automatic compensation, or unattended recovery. No account
count, deltas in counts, or absence in a remote users listing is admissible
as delivery proof. A current 404 after a possible prior creation/deletion
also cannot authorize reissue.

For the separate bank-Cron BUY_SUB path, run
python3 tools/test_post_v9_bank_buy.py --run-disposable and the full
tools/check_post_v9.py. The real Cron PHP route on disposable MariaDB must
prove nonterminal receipt claim, exact per-line remote readback, atomic local
order/effect/capacity/payment finality, no pre-COMMIT Telegram, no blind
retries or speculative wallet refunds. Test cases include competing workers,
partial multi-account, lost POST, 409, invalid readback, local SQL failure,
nonbank receipts, the admin-only payment/user/amount/count review context
for unknown effects or SQL failure, a separate MariaDB plan-template edit
**after verified CREATE but before local finalization** (standard and custom
quoted days/GB remain exactly delivered and the order still commits), and a
physical-target edit in the same interval (still fails closed with no order
or second CREATE). The first completed 007 line pins the original service
limits; the finalizer may not reauthorize them from mutable plan days/volume.
The original payment plan/server/provider/inbound identity remains enforced
under lock. Tests also cover post-COMMIT Telegram failure or
uncertain delivery with operator notice but no account recreation. The earlier bank-Cron-only candidate used 95 source members; the earlier recovery-enhanced package used 97; the current PR #66 R1-A1/A2
runtime uses 98 including GiftBatch.php, while historical V9 92-member
source stays frozen.
Independent review and separately approved installed acceptance remain GATED.

The independent **owner-private read-only recovery workbench** is verified
by python3 tools/test_post_v9_recovery_inspection.py --run-disposable
and is mandatory in the full post-V9 gate. Inspect the actual
src/Finance/Routes/ManualReceipts.php callback while connected to an
owned MariaDB fixture, including owner **and** chat authentication,
12-row payment and ordinal paging, exact immutable ledger/target/note
identity before one strictly authenticated Marzban GET, and invalid or
changed target/post-GET data. No other Telegram user may see the report;
failed queries expose no private payment or customer data. No code may
convert a 404, absent ledger, missing unique index, order deletion,
partial multiple accounts or matching readback into an automatic service
CREATE, paid/refunded transition or customer success. Remote 404 is NOT
proof of remote non-execution. The later R1-A1/A2 source package has 98 current members including
RecoveryInspection.php, RecoveryTransport.php and GiftBatch.php, but no schema is installed
automatically. The strict read adapter pins physical URL, credentials and
observed authentication IP across login and GET, rejects redirects, and
fails closed for non-loopback HTTP. It is additionally covered by the
loopback-only real-curl acceptance tool
tools/test_post_v9_recovery_transport.py. The workbench shows precise
per-ordinal operation/effect/order IDs, missing journal entries and
current-only account-count assurance; each callback receives at most one
ACK and an owner-private uncertainty outcome after any post-GET failure. **Actual installed owner-operator review remains NOT TESTED.**

Execute `python3 tools/test_post_v9_service_effects.py --run-disposable`:
native PHP 8.1/8.4 and disposable MariaDB must prove unique
`(pays,kind,ordinal)`, pre-POST durable dispatch, real concurrent racing
calls producing at most one POST, exact remote `username/note/expiry/bytes`
readback, unknown POST/GET and wrong-identity freezes, changing panel
group/endpoint, cross-payment same-panel username collisions, two-account
partial, hosted short-transaction order/link rollback, exact live
orders_list identity checking at link time, permanent audit after
permitted local order deletion, and unrelated
create+delete despite an unchanged remote count. Required 007 absence **or
wrong unique index** must block new POST without silently reverting to an
unjournaled fallback. Loopback HTTP covers strict readback and authentication
failures without contacting any installed panel.
The aggregate `tools/check_post_v9.py` includes these cases as mandatory.

The R1/R4 Option A **source-only** acceptance additionally requires:
- Full-count guarded capacity reservation before any paid or unpaid CREATE;
  two competing payments with only one slot, absent/insufficient capacity,
  unknown POST/GET, second-line failures and SQL rollback must never
  cause oversubscription, double-decrement or a blind retry.
- The real Checkout.php custom and multi-account Wallet BUY_SUB routes,
  each original order linked exactly once, with post-COMMIT QR and
  owner-visible uncertain notification recovery; no silent reissue.
- The **actual WalletSettlement** handler with a single committed
  wallet-debit + `processing_wallet` + `wallet_hold` +
  capacity barrier, including concurrent callbacks, stale authorization,
  remote uncertainty, local failure, missing 007 and negative controls
  before any debit; not a synthetic money callback.
- Actual private Telegram free-trial and admin bulk handlers: immutable
  source update, actor/role checking, one normal-user trial, unique batch
  ordinal and local linked orders, last-slot concurrency, partial failure
  and no duplicate customer delivery after uncertainty.
- R4 exactly one referral credit per terminal **non-wallet** fully linked
  BUY_SUB, signed debt-offset arithmetic, disabled-policy snapshot,
  SQL failure/unknown confirmation, identity and provider-readback
  checks and no re-credit after a lost Telegram notification.
- Owner-private recovery of pending wallet debit/slot holds even before
  CREATE ordinal 1, without GET on an unissued remote username and
  without writing payment, account or financial state.

**Mandatory operational gates remain open:** neither 007 nor 008 was
installed on a real tenant by this work; a previous installed 007 is
**not** eligible for silent replacement by the revised 007 CREATE SQL.
Review exact DDL and data before designing any forward-only migration.
Perform independently authorized backup+restore, synthetic operator
acceptance and disposable remote/provider validation. Neither CI nor
source tests certify actual merchant funds, customer delivery or
distributed exactly-once processing. See FINANCIAL-CONSOLIDATION.md
for the contract, retained unknown states and rollback limitations.
Independent review of PR #67 exact older candidate `97bcdbd` found two REQUIRED timing holes:
original `pays.server_id` was not compared during payment-row-locked reservation,
and final order linkage did not revalidate the pinned physical Marzban endpoint/group.
The remedied helper now validates both within their own short transactions,
including a guarded exact-target link UPDATE. Native actual MariaDB regression
covers plan reassignment after checkout preflight, POST-free rejection,
multiple already-verified accounts with subsequent endpoint/group/type drift,
atomic rollback of each newly inserted local order on stale target, and
successful unchanged-target linking. Its malformed-DDL guard also inspects
all four required unique constraints. Review verdict for `97bcdbd`
remains `CHANGES_REQUIRED`; **fresh independent re-review of the new exact HEAD is required**.

The combined PR #66/#67 R1-R5 real-helper regression uses an installed
**disposable** 001+007 journal and synthetic exact-operation Marzban
note/readback for all positive current-runtime BUY_SUB cases, while
preserving legacy original receipt checks when both 003 tables are absent.
A separate restored original 23-table fixture with **no** 007 must leave
an eligible manual receipt at have_sent, its capacity unchanged, and
zero CREATE or order rows. The actual manual accept route now validates
the required 007 constraints before its financial/capacity claim. Missing
007 is a safe operator stop, never authority to send an unjournaled POST.
The standard-integrity native suite and aggregate gate require both cases;
CI is source-only, not permission to install 007 on a tenant.

Real standard manual approval and hosted payment suites also assert their
actual per-line ledger states (`verified` without order for partial
manuals; `local_recorded` only for checked order persistence). Source
success does not certify installed Marzban behavior, live provider
settlement or Telegram delivery. See FINANCIAL-CONSOLIDATION.md for non-CREATE effect limitations,
installed operation boundaries, and the separate Owner test gate. Keep
Issues #26 and #51 open through separate installed acceptance.


## PR #66 whole-main remediation — gift holds and physical CREATE target (2026-10-24)

**Source-level only; no operator installation, tenant SQL changes or real Marzban/
Telegram activity authorized.** The full 69-file main-effective independent review
of PR #66 at HEAD `87844469` reported two REQUIRED interactions. This section
describes the proposed revised behavior; only exact-HEAD CI and subsequent
independent re-review establish its acceptance.

**Bulk gifts / R1:** `settings/gift2all.php` now requires
`success=true`, `verified=true`, `uncertain!=true` and exact absolute
account quota/expiry readback before recording local entitlement or reporting
success. Only integral GiB increments (`volume MB % 1024 == 0`) and positive
day increments are permitted; zero means *omit that delta*, not send an invalid
zero `plus_*` field. An all-zero or fractional-MB request has no provider
request and enters operator review.

The original `gift_list` schema is unchanged. Immediately **before** any
potential external edit, the worker atomically inserts
`setting.type=ACHBOT_GIFT_HOLD_<gift_list.id>` with exact job ID, server,
recipient index/username and requested units, and changes the signed
`gift_list.offset` to `-recipient_index-1`. Either a negative offset or
an existing hold record **stops all unattended Cron continuation** for that
job. Deterministic no-effect refusal can be completed without success notice;
an uncertain remote outcome, SQL failure after verified remote effect, lost
response, or validation failure leaves a durable hold. A verified edit and
exact local expiry update are committed **together** with cursor advancement
and journal deletion, before any customer success notice. Customer Telegram
notification failure after COMMIT is an alert concern, **not** authority to
repeat the remote edit.

**Operator procedure:** inspect the held record in `setting`, the matching
`gift_list.id` and negative offset; use an explicitly approved authenticated
read-only Marzban GET and local order inspection to reconcile the **exact
recipient** and applied units. Preserve the original account evidence and
never blindly set offset back, delete the hold, retry the edit, or reconstruct
entitlements from guessed count differences. A controlled reconciliation and
restart require a separate operator authorization/procedure, not this code.
Missing/mutated recipient identity also stops, it does not continue to a
different user.

**R1-A durable membership and progress:** Batch *membership* is frozen on
the first successfully verified Marzban users fetch during Cron activation,
rather than the earlier admin enqueue. Users added later are OUT of that
batch, regardless of sort position. The exact original usernames are frozen
and each pending recipient is resolved by name, never the fresh list index.
Eligibility (active, positive quota/expiry) is evaluated at attempt time;
disabled original recipients are skipped without edit. Deleted/unavailable
original recipients cause a durable negative operator hold, never silent
substitution or omission. Every supported Marzban list response MUST contain an explicit
nonnegative integer total exactly equal to the full returned users array
length; missing/fractional/string/negative/mismatched totals and transport
errors fail closed. Never construct or complete a batch from an unverified
partial provider page.

The original setting.value TEXT capacity is respected through bounded
ACHBOT_GIFT_ROSTER_PART_<gift_id> chunk rows and a verified manifest row
ACHBOT_GIFT_ROSTER_<gift_id> containing original units/server, chunk
identifiers and hashes, a frozen member count and independent monotonic
cursor. Creation is atomic under the unique gift_list.id row lock; no
setting.type uniqueness/index is assumed. The independent roster cursor,
legacy gift_list.offset, optional guarded local order update and removal
of the pre-effect hold advance in the SAME transaction. An offset reset
cannot authorize editing a previously completed username. Final job,
manifest and chunk deletion is atomic on the next Cron invocation. The
three affected tables must remain InnoDB, or no provider edit is allowed.

**R1-A1 new-format provenance:** Only the current bot admin enqueue path
may create gift jobs for unattended processing. It calls
src/Subscription/GiftBatch.php to insert the original gift_list row and a
versioned ACHBOT_GIFT_ENQUEUE_<id> setting receipt in the same InnoDB
transaction, including original units and physical destination identity.
A zero-offset job WITHOUT this durable provenance MUST NOT start: an old
Cron could have applied the first non-idempotent Marzban gift, then died
before advancing offset. Unmarked jobs, whether offset=0 or offset>0,
are placed on negative operator hold WITHOUT a provider request.
The new-format receipt cannot be inferred or retroactively forged from
the latest list or signed cursor. An interrupted old worker was tested
directly against the original main source and disposable MariaDB.
Do not auto-enqueue replacements after an ambiguous SQL commit.

**Fresh independent F1 — Telegram source idempotency (source-only):**
The final bulk-gift text step must be a real, original, authenticated
administrator **private-chat message**. `UpdateBindings.php` can bind the
older BOT message text to a callback; that is not a new operator message.
`achbotGiftTelegramSource` therefore accepts only a single message/update
envelope with positive integer `update_id` and `message_id`, matching
message/from/chat/actor IDs and exact original text, no bot sender,
unexpected callback, edited message, group, or mixed update. The database
helper revalidates the primary owner and delegated-admin status under lock
and verifies the operator's current final gift step for a NEW source.
Cached admin privileges and old Telegram steps are not enqueue authority.
This source contract validates the delivered Telegram JSON envelope and
current database administrator authorization; it is NOT an independent
network-origin attestation of the existing webhook endpoint. Webhook
transport origin/secret configuration remains part of installed operator
security acceptance, and no real incoming Telegram delivery was tested.

`achbotGiftNewBatch` now commits **four related state transitions atomically**
on the original InnoDB schema: a gift_list row, its versioned
`ACHBOT_GIFT_ENQUEUE_<id>` provenance, one persistent
`ACHBOT_GIFT_SOURCE_<sha256>` source claim and the administrator's
consumed final wizard step (`users.step='none'`). The source identity is keyed by
the Telegram update ID and the bulk-gift operation type; the claim record
binds the exact original actor, private chat, message ID, text SHA256,
target server, units, original administrator step and new gift ID.
The global serialization owner is the primary administrator's original
**users.id PRIMARY KEY record**, selected via the admin userid with
`LIMIT 2 FOR UPDATE` and a strict exactly-one-row check. Delegated
administrators' own records are also validated under lock. This covers
concurrent PHP/MySQLi sessions and different actors, **without pretending
users.userid or setting.type is UNIQUE**. The primary admin's unique
user record must exist; a missing/ambiguous record fails closed. A
duplicate source returns its same original gift ID without inserting any
new batch; a conflicting or malformed source, unverifiable SQL or
uncertain COMMIT cannot authorize a replacement. The source claim remains
after Cron deletes a completed gift; removing a claim to replay an update
is prohibited. No new tenant tables or SQL migrations are required.

**F2 atomic wizard-step consumption:** A new source must validate the
fresh final step on the already locked actor row, insert all three durable
gift/source records, then consume the step using a single compare-and-swap
`UPDATE users SET step='none' WHERE id=? AND userid=? AND step=?`
guarded by the actor's **unique users.id** primary key. Exactly one changed
row is required. Any SQL error, unchanged step or ambiguous transition
rolls back the entire new enqueue. All four changes commit together.
A replay of an already claimed source returns its original gift ID
**without modifying any administrator step**. In particular, a late
duplicate does not erase a genuinely new wizard generation with the
same literal giftServerVolume value, and two distinct concurrent Telegram
updates cannot consume one original wizard step twice.

Only Telegram success/duplicate notifications are handled after COMMIT;
there is **no post-COMMIT wizard-step mutation**. An interrupted process or
lost message cannot undo the committed claim and step consumption or
authorize another batch. An operator can intentionally submit a NEW
authenticated update to request another independent bulk gift only
after re-entering the final step. Source claims are durable operation
evidence, not expendable cache rows.

`tools/test_post_v9_gift_enqueue_source.py` executes the current
**real bot.php final text handler**, unmodified Telegram UpdateBindings,
source helper and original disposable MariaDB: duplicate/replayed updates,
parallel PHP processes, post-COMMIT interruption before Telegram notice,
lost COMMIT ACK, SQL receipt/claim/step-update rollback, actual Cron
completion and late replay, different actors and fresh independent
requests, malformed/forged/privilege-revoked sources, nonunique original
actor rows, nonunique setting.type claim corruption, and notice failure.
The F2 regressions independently prove two **different** Telegram updates
cannot consume the same initial step, including a deterministic pause
immediately after A's COMMIT but before A's UI. A late identical-source
replay cannot clear a rearmed **same-literal** step; new update B can
still consume that step. A SQL trigger that fails or silently suppresses
the step transition must roll back gift, claim and provenance. Distinct
actors retain independent steps under global source serialization.
All 47 source-real scenarios are mandatory in
`tools/check_post_v9.py`.

**Deployment compatibility:** This F1 source claim does not retroactively
identify messages that were enqueued by *older* code without an update-ID
claim. An installer must quiesce previous webhook/Cron workers, reconcile
old in-flight gift batches and their administrator steps, and avoid
replaying pending old updates when switching code. Do not synthesize
Telegram source identities for legacy jobs. No installed provider/tenant
migration or live side effect is authorized by source-only tests.

**R1-A2 physical target continuity:** The enqueue receipt also holds the
original canonical Marzban URL, SHA256 credential-identity fingerprint
(never a password), and optional panel-group identity/schema presence.
The roster manifest repeats the immutable physical identity; future Cron
invocations verify both it and the original enqueue receipt.
Transient credentials are reloaded only when they exactly match the
original fingerprint. Login, provider user-list GET, pre-effect single
account GET, PUT and authenticated absolute readback are bound to the
same pinned physical URL and credentials through
GiftBatch -> gift2all -> Core -> Accounts. Redirects and TLS verification
are enforced on the sensitive requests. Endpoint/credential/panel-group
drift before or during any operation fails closed; if a remote edit might
already have happened, its durable hold remains for operator review.
A shared logical panel_group_id is NEVER authorization to switch the
physical destination, even when the new panel has the same username.
The acceptance driver tools/test_post_v9_gift_physical.py executes real
PHP Core/Accounts/cURL through two isolated loopback account stores.

**Upgrade/legacy boundary:** A batch with missing or incompatible
provenance cannot safely reconstruct its previously gifted recipients.
It is placed on negative operator hold without an external request. Never re-create membership from a newer
provider list, blindly reset gift_list.offset, delete the snapshot/hold or
replay uncertain effects. Operator reconciliation requires a separately
authorized, evidence-based procedure. No tenant SQL migration or real
Marzban/Telegram operation is implied by this source-only change.

**Deployment/rollback caution:** Stop old Cron and web workers BEFORE
activating the new gift-enqueue format, or an in-flight legacy process
might apply a job without understanding its new provenance/hold.
Existing unmarked batches must be manually reviewed, including every
zero-offset record. An active new batch requires stable physical target
identity; endpoint/credential/group changes should be coordinated only
after all source-only batch holds are reviewed.
An older offset-only Cron worker does not
understand these roster rows and can repeat a completed gift if the list
changes. Before any separately authorized downgrade, pause/reconcile active
gift batches and operator holds; never replace the worker while an active
snapshot-dependent batch is running or rearm the numeric cursor as a
rollback shortcut. Snapshot rows are operational evidence, not expendable
cache files.

The source-backed disposable PHP/MariaDB suite explicitly checks
reordering, prepend/insertion, late additions, deletion, post-COMMIT
interruption, concurrent Cron, legacy midflight upgrade, cursor mismatch,
manifest tampering, large chunked rosters, incomplete provider lists,
nontransactional schemas and local-order disappearance after volume-only
remote verification. The 46-case PHP Worker/original-schema MariaDB suite
and 15-case real PHP/two-loopback-panel suite are mandatory in Post-V9.
The name-based membership policy binds an original Marzban username on
the original physical account store. Deletion and recreation under the
same username may change account incarnation; there is no proven
provider-native immutable incarnation contract in the supported source.
Operators must reconcile suspected same-name replacements manually,
rather than treating username reuse as proof of original identity.
Installed/provider acceptance remains separately gated.

**Durable CREATE / R2:** paid BUY_SUB and source-authenticated free-trial/
admin-bulk 007 reservations capture the actual Marzban `panel_url`,
`username`, `password` and panel-group identity in their source reservation
transaction. The original SQL journal records the endpoint hash and operation
key **without credentials**. The verified endpoint + matching original
credential identity is carried transiently through
`ServiceEffects -> Core -> config.php facades -> Accounts.php` for login,
CREATE POST, authenticated GET and verification. A later mutable
`server_config` lookup may **reject** a changed identity but must not choose
a different physical panel or token. Credential login, strict CREATE and
strict GET do not follow HTTP redirects; TLS verification remains enabled.
Any unexpected config drift, lost/malformed response or failed readback
leaves the durable effect in `dispatching` for human reconciliation.
A shared logical panel group is **not** authority to switch to another URL.

New isolated acceptance drivers:
`tools/test_post_v9_gift_worker.py` (actual PHP Cron worker, original 23-table
disposable MariaDB, fake notifications/provider) and
`tools/test_post_v9_effect_transport_pin.py` (real PHP facade, 007
journal, two distinct owned loopback HTTP Marzban panels and MariaDB).
Both are mandatory in `tools/check_post_v9.py`. Existing installed/provider
acceptance remains GATED in Issues #26/#51.
