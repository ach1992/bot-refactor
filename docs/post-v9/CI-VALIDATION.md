# Current AChBot CI contract (PHP 8.5)

The accepted Stable line checks **current bot functionality**, not every archived
source revision. This is source acceptance only: live payment finality, provider
behavior, Telegram delivery and production schema/install acceptance remain
separate operator decisions.

## Required fast check

[.github/workflows/ci.yml](../../.github/workflows/ci.yml) runs on PRs and
main pushes under the single `php85-validation` required status:

- Exactly one PHP 8.5 runner and one SHA256-verified cached MariaDB fixture
  toolchain. No Docker-in-Docker, APT mirror installation or eight duplicate
  MariaDB downloads. Independent cases use isolated, disposable socket-only
  databases and local loopback providers.
- The Wallet-first suites are the current source-real owners of payment
  evidence, Wallet debit/credit, service commands, scheduled recovery, and
  durable delivery. Each uses actual PHP and disposable socket MariaDB;
  hosted/Native peers use only owned loopback HTTP. The old executors are
  removed from runtime, so their driver contracts are historical evidence
  at main `484c80e6ee030e8656da96edc3000311d10327b5`, not executable
  requirements for the replacement. The mapping below is explicit.
- The canonical two-catalog ownership gate rejects unreferenced and
  unbound keys; retained values must equal accepted Stable. Direct
  Telegram calls compare every unchanged path to Stable and individually
  pin each intentionally changed path with a reason, exact before/after
  digest and count. Native/financial tests separately assert the actual
  immutable delivery packet's text, keyboard, recipient, parse mode, QR
  bytes and part/attempt state. Unchanged menu, management, web and
  context parity suites remain required. Negative fixtures reject changed
  parse mode, altered arguments, missing calls, and new unowned calls.
- Nonpaid trial/bulk runs on the exact empty current schema, including
  durable multipart delivery, interrupted-process recovery, the actual
  staff attention inbox and actor-bound confirmation, replay denial and
  stale ownership denial. Fresh-schema proof checks retired Native and
  payment-only delivery columns, mandatory source/part identities, unique
  source/attempt indexes, historical-row rejection by construction-only
  DDL, exact retained table definitions and empty/partial settings menus.
- The actual documented payment-readiness CLI is required CI coverage:
  trusted current manifest and installed members, empty consolidated schema,
  representative current settings and SELECT-only account, missing current
  constraints/indexes and tampered source. Structural readiness is distinct
  from payment configuration. No private key or external call is needed.
- Existing current-provider import, alias, DELETE, gift, account
  management, indexed payment lock, payment audience, permission, browser,
  onboarding, agency, Wallet transfer/admin and diagnostic suites remain
  mandatory. PasarGuard is the only shipped Native adapter; historical
  Marzban origin identifiers are not an execution capability.
- Every relevant build produces a reproducible current-source-only runtime
  ZIP, manifest and checksums at the **current exact Git commit**, separate
  from any real database migration or deployment.
- Pure `README.md` and `docs/**/*.md` changes (proven from two existing
  Git commit objects, not a filename guess) skip PHP/native tests while
  retaining the required status. Unknown/ambiguous changes run the checks.
  Superseded PR runs are cancelled.

The required status is **representative current Stable functional coverage**, not an
exhaustive claim about every fixture and negative test. It fails if any
required suite, source/Cron check or package verification fails; it never
calls the real merchant, Telegram API or production database.

## Explicit replacement of obsolete executor drivers

The accepted prior main remains recoverable from Git; do not run retired
drivers against new source or revive their removed entrypoints. Retained
business contracts are tested through the following current suites.
`REQUIRED_CURRENT_CASES` in the CI runner checks named affirmative scenario
receipts in both MySQLi modes for the critical replacements.

| Old CI driver | Current source-real replacement / retirement proof |
| --- | --- |
| api_payments | wallet_first_credit, wallet_first_manual: SMS/gift verified identity, one credit, lost evidence ACK, local rollback, explicit rejection, service invalidation; service execution owns subsequent Native/Telegram effects |
| postcutover_recovery | wallet_first_cutover_openings, wallet_first_service_execution: historical prepaid evidence cannot execute, current uncertain CREATE/readback/commit recovery, migrated alias identity fences; native_import_name_reuse, native_import_alias_duplicate remain |
| nowpayments_finality | wallet_first_hosted: original credential, signed child, exact amount/order/invoice, confirmed not final, uncertain issuance, actual public checkout/return, poll and replay |
| automatic_renewal | wallet_first_service_execution: actual scheduled cycle, alias one-charge, low balance, refresh before first possible dispatch, immutable dispatched target, no historical renewal table, reset lost ACK, fair worker, durable notices |
| bank_purchase | wallet_first_manual, wallet_first_service_execution: original bank receipt and timer, manual service completion, rollback, exact receipt edits, standard/custom/bulk, durable delivery |
| wallet_checkout | wallet_first_funding, wallet_first_service_execution: five real wallet callback families, atomic owner/amount/capacity and exact draft, replay, invalid names, insufficient funds |
| wallet_archive_schema_modes | wallet_first_retirement: old schema rejected, partial 033 and nonunique command index rejected; service execution: archive absent from runtime and schema; explicit receipt rejection, terminal close/refund and partial BUY delivery |
| wallet_sconfig_finality, wallet_marzban_target, marzban_bearer | retired as authorized; negative original callback, unsupported Native provider and absent executors in wallet_first_retirement and wallet_first_service_execution |
| wallet_pg_recovery, wallet_original_renewal | wallet_first_service_execution: absolute add-on/renewal, original account/alias fence, lost reset and PUT, local finality rollback, foreign/drift denial |
| wallet_original_buy_and_marzban, wallet_original_buy_pasarguard | wallet_first_service_execution: current funded BUY, old prepaid claim refusal, unique ordinal, lost response, 409/readback, identity/physical target, no second debit or capacity; Marzban retired |
| service_effects | wallet_first_service_execution, wallet_first_funding: one command, effect/ordinal, original quote and capacity, ownership, commit/recovery, delivery |
| low_wallet_reentry | wallet_first_service_execution: actual low-wallet callback, unchanged draft and alternative receipt, no duplicate credit/debit |
| provider_registry | wallet_first_retirement: exact current registration, incomplete adapter and unsupported provider denial; wallet_first_funding: isolated test-only Native and payment registrations through unchanged common engines |
| scoped_pg_payments | wallet_first_service_execution: actual scoped DAY/VOLUME drafts, approval and wallet callbacks; gateway, permissions and existing-provider tests remain |
| manual_unknown_redrive | wallet_first_service_execution: original immutable packet attempts, private operator confirmation, recipient/role and attempt binding, 429, unknown, stale/replay denial |

An original pending historical claim is never runnable as an old workflow on the new schema. The first-install schema is checked against the retained historical construction definitions; old unused tables are omitted. Owner-authorized manual transfer/reconciliation is a separate operation;
do not manufacture a debit or Native result. The delayed Native write
counterexample remains a required characterization of the upstream API limit,
not an affirmative remote exactly-once guarantee.

## Stable-only coverage boundary

Only **Stable v0.10.0 and later** is supported. Historical V0-V9 execution,
archived RED fixtures, old PHP 8.1/8.4 matrices and byte-for-byte comparisons
against pre-Stable implementations do **not** run in CI, even manually. Their
published results remain recoverable from Git history; they are not current
compatibility requirements. Avoid reintroducing them in new acceptance tests.

Existing customers' live contracts (original issued subscriptions, unpaid
payments, wallets, permission grants and previously imported provider accounts)
remain a current Stable obligation: test these workflows against the **current
Stable source/schema** without running obsolete bot implementations.

Use targeted **current-only** functional integration checks during development
for high-risk changes. Expand the representative CI roster only where evidence
shows an active path is otherwise untested. No separate historical eight-family
workflows or mandatory retroactive migration suite.

## Baseline and measurement

The last exact full PHP 8.5 [Actions run #43747843516](https://github.com/ach1992/bot-refactor/actions/runs/43747843516)
took about **4m47s wall time** (first job to aggregator finish) with eight
native-family jobs, one foundation, a planning job and an aggregator.
Individual family job durations ranged approximately 88–262 seconds; the
longest were `services` and `presentation`. A large portion is source-real
fixture execution, not PHP startup. The old per-run runner-minute cost
includes *all* jobs, not just critical-path time.

Do not claim the new 2–3 minute target is achieved until the same-class
`php85-validation` Actions job has passed and its **actual start/end and
per-check durations** are measured. Review its result before adjusting the
representative test roster, concurrency or MariaDB setup. CI optimizations
must not silently turn an incomplete smoke check into a full acceptance claim.

The full Wallet-first draft run
[#45375429587](https://github.com/ach1992/bot-refactor/actions/runs/45375429587)
recorded 63/63 passing functional checks in 464.15 seconds. The former
8-minute job deadline cancelled the job immediately after the functional
result, before runtime packaging and artifact verification. This is not
a successful required CI status. The 12-minute job ceiling leaves room
for setup, the full unchanged test roster, packaging, and runner variation.
It does not increase individual test deadlines or reduce coverage.

## Local use

From a clean, exact source checkout with PHP 8.5 and MariaDB fixture
executables on PATH:

```bash
python3 tools/ci_quick.py --report /tmp/achbot-ci-quick.json
```

For deeper testing of a high-risk change, run additional **current Stable**
source-real integration tests individually in an isolated development
environment. Do not run archived pre-Stable test drivers. Never execute
test fixtures against live bot configuration, customer/payment databases
or external Native provider endpoints.

The wallet-first funding, hosted, manual, credit and service-execution suites exercise the consolidated empty current schema directly. Recovery cases cover exact and once-only refunds, partial BUY, existing Native account refusal, fresh Native state, terminal lock release, edit readback and service-only automatic renewal in strict and non-strict execution modes. The migration construction comparison is separate from runtime installation.

Final attention hardening: the manual suite verifies actual private recovery of the same persisted receipt after an unknown first Telegram acknowledgement for bank transfer, gift-card photo, USDT and TRX; active lease exclusion, forged actor/group denial, no replacement proof or repeat credit, rejected receipt non-reopening, retired archive callback inertness and independent unknown report visibility. Service execution verifies stalled funded work and unknown delivery parts even when the aggregate is pending. The current-schema proof verifies eight retired payment columns are absent; the indexed locking fixture verifies canonical order ownership and unbound historical add-ons are inert. Exact catalog/wire contracts enumerate the changed attention UI and retain negative mutation tests.

Current receipt tests also verify shared hosted rejection/outbox, late authenticated money after rejection remaining in Wallet, and superseded unfunded receipt rejection without an archive. Registered capabilities drive the common receipt classifications and one receipt outcome projection separates settlement, service and delivery.

Current physical ownership acceptance: scoped current-schema draft locking; plan/transfer/delete use common funded service/Native ownership, current draft invalidation, historical receipt inertness and verified late Wallet credit.

The current draft-admission regression also holds an unresolved Native plan
journal on a separate SQL connection: the affected draft is refused, but
an unrelated physical account can still create a draft without a global
journal lock. The retired pay-bound Native quote entrypoints must be absent
from every runtime package member.


Final receipt continuity acceptance runs actual current-schema source
for all manual photo methods and typed gift codes in both MySQLi
reporting modes. It covers supersession between receiving reservation
and Telegram acknowledgement, lost acknowledgement recovery, atomic
gift-code reservation rollback, timer settlement of superseded receipts,
current manual cooldowns without historical or automatic-payment
interference, isolated malformed evidence with healthy queue progress,
and continued exact-amount SMS search beyond the per-tick page budget
for both Toman and Rial. The shared Native import/alias regression also
uses the canonical installation schema, including checkout draft
invalidation. Tests inject faults and use owned loopback fixtures; no
real customer, merchant or Native account is mutated.

### Wallet-first plan lifecycle and repair acceptance (Issue #204)

The current-schema service suite exercises actual plan PUT, committed pre-dispatch markers, no-effect GET outage and abandoned preparation cleanup, >4096 original mutation keys, scoped journal locking, current administrator credential rotation and GET-only recovery after lost ACK, direct receipt case links, corrective preflight retry without a dispatch, and exact refund after local server deletion. The credit suite uses the current atomic manual-evidence route, including typed gift-card evidence. No real panel, Telegram, customer money or production database is used.


Reporting coverage: the existing strict/non-strict native suites verify manual, API, timer and hosted credit recipient matrices, actual independent approval-actor delivery when general notifications are disabled, superseded API credit content, funded vs retained receipts, original service completion reports, refund/partial retention and referral source evidence. The admin Wallet, transfer and onboarding route suites verify actual standalone source staging and independent Telegram delivery; the empty-schema equivalence test includes 039. No live Telegram or provider calls.


### Additional final-audit gates

The current Wallet-first suites additionally require malformed-channel
financial finality and channel/owner deduplication, case-normalized
channel authorization, rendered manual rejection with the real actor's
private moderation keyboard, delegated hosted rejection, purchase
referral with the original funding actor, and completed-service report
correlation to its original receipt and immutable Wallet debit. Fresh
schema negative checks independently remove Wallet and attempt uniqueness
while retaining the supporting foreign-key indexes. Persian resolution
fixtures explicitly negotiate utf8mb4 with both clients and verify stored
bytes as well as returned text; retain the semantic Persian assertions.


### Shared operational outbox (040)

The operational report suite tests the actual common sender and original
source journal in both strict/non-strict MySQLi modes, including transactional
rollback, committed pending recovery, recipient deduplication, actor-bound
explicit redrive, known 429 timing, revoked/changed recipients, tampered
seals, unattempted batch compaction and scheduled gift enqueue actor
provenance. It verifies that reporting creates no synthetic financial or
Native work. Current-schema construction equivalence checks all 36
migrations and the required operational source uniqueness. Source-real
nonpaid, direct gift, delete/reconfirm, and import/reassignment fixtures use
the same current report schema and actual sender. Nonpaid bulk interruption
exits after committed local effects and before report compaction, then
recovers the exact committed per-account reports without another Native
create. The dedicated scheduled-gift suite now executes the actual Cron
worker and current PasarGuard adapter with owned MariaDB and loopback HTTP:
canonical whole-day input, verified entitlement change, original
customer/owner/actor/channel delivery, duplicate source and worker replay,
and an uncertain write that holds without a second write or success report.
Recipient tests distinguish definitive permission denial (terminal skipped)
from SQL contention (deferred) and preserve original unknown attempts.
The 64-case operational outbox suite also exercises the sealed original
gift customer notice, known/unknown Telegram outcomes, explicit redrive
and changed attachments in both MySQLi modes. Direct gift (34 cases) and
scheduled gift (4 cases) source-real suites use the common sender. Copy
retirement pins exactly three additional source-dead gift warnings; the
wire contract records removal of the old direct customer send. Retained
gift copy is unchanged. Live provider/Telegram and production acceptance
remain separate gates.

The current gateway-rule suite uses the consolidated schema. It distinguishes
confirmed receipt funding from completed original service commands, including
automatic renewals without a synthetic payment row. The actual Native service
execution suite also checks the same renewal-history query before funding and
after verified completion, counting only the original charged owner. The
readiness gate additionally tests valid per-method exemptions and rejects extra
PHP runtime entries and root index shadows without opening their contents.

The required current sales/discount suite exercises the actual administrator
sales menu, custom-date and scheduled report consumers on the consolidated
schema: completed external/Wallet/automatic operations, cancelled and funded
operations, partial refunds, original date windows and retained historical
facts. Incoming-payment totals remain independently asserted. The actual
discount consumer tests buyer/non-buyer parity, unchanged ineligible
price/counters, target restrictions and an owner-lock race. The service
execution suite additionally verifies sales deltas from real checkout,
automatic renewal, full/partial resolution and delivery/report retries.

### Current customer route and content authorization

The required current_customer_routes suite executes the actual current
route handlers against disposable MariaDB with recorded Telegram sends
and network access disabled. It verifies exact public content namespaces,
scoped operations-staff deletion, Native catalogue/availability agreement,
original standard/custom/agent-bulk/renewal discount continuation and
Wallet admission, zero-price and replay behavior, price preservation
after catalog repricing, stale-target rejection, and customer shortcut
entry gates including method audiences, membership and phone requirements.
The renewal case uses a synthetic already-linked Native account;
no live provider is accessed. The separate full service-execution suite
continues to exercise the real service engine against its loopback
provider. Source/transport fixtures are not installed Telegram acceptance.

The customer-route and nonpaid suites also cover purpose-specific
trial-only and mixed catalogues, enabled custom selection, standard
zero-price agency and primary-admin commands (including quantity and
no-debit reservation), forged positive-price trial callbacks, persisted
trial policy and entitlement changes, consumed trials and revoked or
restricted bulk staff. The latter suite uses the real nonpaid provider
loopback. Zero-price commercial funding tests do not by themselves prove
end-to-end provider creation and delivery.

Current CI uses ci_quick.py; current packaging uses package_current.py.
The removed legacy matrix and bank-buy/recovery drivers are historical
source evidence available in Git. Imported historical helpers must be
reconciled before removal; historical migration construction and genuine
provenance are not obsolete runtime execution.

### Current fixture isolation and category storage

Current CI imports dedicated socket-only database, PHP staging, provider and
presentation fixtures rather than executable V1–V9 test drivers. Historical
source is used only for explicit pinned comparisons; the remaining historical
test files are not current acceptance entrypoints. The disposable MariaDB
fixture intentionally retains isolated legacy-schema setup for negative and
partial-schema tests. Runtime packaging still excludes tests and SQL.

Category creation no longer inserts globally shared inactive draft rows.
Current role and actor state are read under lock; the final category insert
and step consumption commit together. Migration 045 is for empty-schema
construction only, and removes the retired category draft fields. The
current-route regression checks concurrent managers, inactive-row retention,
callback/typed-input separation, revoked access, exact rename targets and
storage-width rejection of bulk count/price overflow.


### Current-source fixture boundary

The current Native import and DELETE suites use `native_identity_fixture.py`:
only authenticated Native identity bootstrap and task-owned SQL/HTTP fixtures,
not the retired scoped API payment driver. The direct gift suite uses
`retired_panel_http_fixture.py` solely as a synthetic peer to prove
retired-provider refusal; no old provider implementation is executed.
Original pre-Wallet-first checkout/recovery/Marzban-only test drivers and
their old-only callers are removed from this branch; they remain available
at [the prior exact source](https://github.com/ach1992/bot-refactor/tree/05894a72e8d8c27fcae03d742e0abd0f31a4cb43/tools).
The current roster and retained historical account-provenance construction
are unchanged. Old test commands in historical records refer to their
original source, not executable instructions for the new runtime.
