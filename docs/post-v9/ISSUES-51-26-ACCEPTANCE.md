# Issues #51 / #26 — source acceptance matrix and remaining gates

This is the **execution/evidence matrix**, not a second product specification.
The accepted policy and schema contracts remain in PROJECT-SPEC.md,
FINANCIAL-CONSOLIDATION.md, PAYMENT-UX-LIFECYCLE.md and migration guides.
Only **source/isolated synthetic** evidence is claimed here. A test marked PASS
does **not** certify a live merchant, installed database, real Telegram message,
real Marzban provisioning, refund or real-money correctness.

## Reproducible test drivers

- Finance callback/provider/HTTP/DB acceptance: ../../tools/test_post_v9_finance_routes.py;
  its report contains each scenario name and actual PASS.
- Manual provider/Telegram/admin effects: ../../tools/test_post_v9_manual_payments.py;
  its scenario list includes direct execution of ManualApproval.php, Checkout.php,
  RenewalCheckout.php, Extensions.php and payment receipt handlers.
- UX lifecycle, stale message and payment status: ../../tools/test_post_v9_payment_ux_lifecycle.py.
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
| M09 Standard post-remote local failure | Remote completed, order INSERT/capacity fails, MYSQLI_REPORT_OFF variant | Never re-arm have_sent; local transaction rolls back where possible | Operator message without raw private SQL text | No second remote call on callback replay | manual: real_standard_remote_success_local_write_failed_no_automatic_replay, real_standard_mysqlireportoff_unconfirmed_order_never_approved, real_standard_mysqlireportoff_unconfirmed_capacity_never_approved — PASS(S) |
| M12 Standard post-COMMIT Telegram outage | Verified manual standard purchase/renewal; remote and exact SQL COMMIT already succeeded | Remain **approved**; payment/service record cannot be undone by messaging | Distinct Persian operator follow-up; no misleading remote/financial uncertainty even if operator alert also fails | No second remote action on repeated callback; stage and pay ID logged | manual: real_standard_postcommit_telegram_failure_approved_no_replay_truthful_review, real_standard_renewal_postcommit_notification_outage_preserves_approved_no_replay, real_standard_renewal_both_telegram_messages_fail_without_fatal_or_replay — PASS(S) |
| M13 Admin message edit and failed remote outcome | Approved standard order with failing Telegram keyboard edit, or explicit failed remote response/warning channel outage | Preserve approved when button edit fails; preserve processing for remote ambiguity | No raw exception to admin; bounded pay ID, stage and exception class/code in protected logs | No false local order/credit on rejected remote result, no repeated provisioning | manual: real_standard_operator_buttons_failure_does_not_leak_raw_exception, real_standard_explicit_remote_failure_kept_for_review_without_local_order, real_standard_ambiguous_remote_and_failed_admin_notice_no_fatal_no_replay, real_standard_renewal_explicit_remote_failure_no_local_order_no_replay — PASS(S) |
| M10 Custom BUY_SUB accCustom | Admin, chosen custom day/volume, manually reviewed photo | One service claim and approved state only after effect | Standard plan or non-admin callback rejected | Remote uncertainty/replays stay reviewable | manual: real_custom_authorized_review_state_approves_only_once, uncertain_custom_external_effect_operator_reconciliation_no_retry — PASS(S) |
| M11 Renewal/day/volume extensions | Exact order/subject, operator, manual reviewed evidence | One guarded extension record after remote verified completion | Late/foreign provider callback rejected | Bank scheduler/admin race cannot double apply | manual: real_renewal_operator_before_bank_cron_one_remote_effect and day/volume equivalents — PASS(S) |
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

## Issue #26 per-account identity: staged source-only acceptance

The new additive `007_service_effects.sql` and
`src/Finance/ServiceEffects.php` provide the first **BUY_SUB-only**
postpayment remote-effect journal. They are not a generic service/wallet
transaction framework and are not a reconciliation autopilot. No account
count, deltas in counts, or absence in a remote users listing is admissible
as delivery proof. A current 404 after a possible prior creation/deletion
also cannot authorize reissue.

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

Real standard manual approval and hosted payment suites also assert their
actual per-line ledger states (`verified` without order for partial
manuals; `local_recorded` only for checked order persistence). Source
success does not certify installed Marzban behavior, live provider
settlement or Telegram delivery. See FINANCIAL-CONSOLIDATION.md for the
precise unprotected legacy/non-BUY_SUB routes and Owner test gate. Keep
Issues #26 and #51 open through separate installed acceptance.
