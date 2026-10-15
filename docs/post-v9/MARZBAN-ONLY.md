# AChBot — Marzban-only post-V9 source candidate

**Controlling:** [Issue #31](https://github.com/ach1992/bot-refactor/issues/31);
[roadmap #29](https://github.com/ach1992/bot-refactor/issues/29).
**Historical public V9:** [v0.9.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1),
`main` merged commit `7eb077fd8e6d0a303421626d1500f69e295a6664`.
**Current development branch:** `feature/post-v9-marzban-only`. It is NOT
the installed/released version, and all proposed behavioral changes still
require independent review, Owner merge, release, staging and rollout gates.

## Owner decisions / desired outcomes

Legacy X-UI, Sanaei, Alireza and original normal inbound panels **have never
been used or operated**. Their implementation, buttons, login/configuration
wizard, inbound-based plan creation, protocol toggles, QR/link, subscription
refresh, gifts and Cron/payment fallback paths may be **retired**. Their
nonexistent production deployments do **not** need backups/migration. This
decision is not a waiver for the MariaDB database, Marzban subscriptions,
orders, wallet/payments, future schema deletion or actual server backups.

Keep actual Marzban subscription, purchase/provision, renewal, extensions,
traffic, status, UUID, deletion, plan and server onboarding, user/agent/admin
access, support, broadcast and Cron. Keep all original **23 MariaDB tables**
intact for now. Future PasarGuard, new X-UI and unknown panels are *not*
supported yet; add each later with its own provider-specific API contract,
without reinstating or guessing old X-UI semantics.

Intentionally old **settings/subLink.php** was an X-UI-only token conversion
endpoint; Marzban uses its provider's own subscription URL. Invalid/empty
tokens still say `Wrong token`, but a legacy-shaped valid token returns
HTTP **410** without DB access. Original URL filename remains, preventing
accidental execution of removed X-UI SQL/API.

## Owner-approved logical server switching on one real Marzban panel

The Owner's revised Issue #31 decision (2026-10-15) explicitly confirms that
multiple bot "servers" normally represent **different logical services,
plans and prices on the same Marzban panel**, rather than distinct account
stores. In that case, the supported switch intentionally changes **only**
`orders_list.server_id`, as the inherited bot did. The actual Marzban
account, proxies/inbounds, tokens, UUID, link, traffic, order plan/fileid,
wallet, price paid and capacity counters **are not rewritten** by switching.
Renewal using an old plan remains available if its original plan server and
new logical server are verified to share the same panel; its original
plan price stays in force until separately changed.

The admin can set an ASCII **shared panel group** during Marzban server
onboarding or edit it later in the server settings. No new SQL schema,
columns or migrations are required: optional per-server group values live in
the existing `setting` table. `server_info.flag` remains only the display
flag. Existing untagged entries may switch automatically if their normalized
configured panel URL and admin username match. Explicit conflicting group
labels isolate entries, while matching labels alone **never** override
different panel URLs, usernames or retired provider types. A legacy callback
cannot authorize a switch: both user and admin handlers recheck current
owner/admin privileges, order status, same-panel identity and target
availability, with a guarded SQL transaction and no remote panel API call.
Changing an explicit group may affect eligibility of previously switched
orders; use matching tags for entries representing the same backend.

**Cross-panel relocation remains unsupported** and deliberately fails
closed. A future separately approved provider-specific increment may migrate
accounts between actual panels and reconcile selected protocols/inbounds,
plans and subscription settings on Marzban or a future PasarGuard backend.
Do not interpret today's logical switch as an actual service migration.
No actual tenant, customer credentials or live server may be accessed in
source-only tests.

Current financial/TRON issues are **not fixed by this refactor**; see
[Issue #26](https://github.com/ach1992/bot-refactor/issues/26). Signed negative
wallet as deliberate **administrator debt** remains a required future feature;
user-triggered overdrafts must be prevented by separate reviewed work.

## Two independent and truthful test contracts

### 1. Original V9 source/history — immutable

The frozen `tools/check_v9.py`, `v9_contract.py`, V0–V8 golden files,
original PHP and original all-panel behavior **must run ONLY from a real,
detached V9 source worktree**, not current Marzban-only code. Their
source/evidence is historical; **do not relax them or re-record golden
outputs** to call removed X-UI behavior 'backwards-compatible'.

This candidate's CI does a detached
`git worktree add --detach "$RUNNER_TEMP/frozen-v9" 7eb077f...`
and executes V9's historical full suites under PHP 8.4 (PHP 8.1
compatibility subset separately) before returning to the new candidate.
PR #30, if independently approved and merged first, adds a further
historical **published V9 release ZIP** acceptance test. That work should
remain in the detached V9 reference, not be executed against new runtime.

### 2. CURRENT Marzban-only runtime — independent new proof

On a dedicated development/test host **with no real provider credentials**:

```bash
python3 tools/check_post_v9.py --report /tmp/achbot-post-v9.json
```

The wrapper validates:
- original 92-source-member set; all 91 PHP members pass PHP lint;
- pinned original `database/legacy-schema.sql` and
  `baseInfo.example.php` bytes, without any real `baseInfo.php`;
- native original-schema payment-subject validation for wallet, purchase,
  renewal and extension cases, including retirement/inbound negatives;
- mixed old/new purchase-catalogue, forged Telegram purchase callbacks and
  QR payloads tested on original-schema MariaDB with synthetic fixtures;
- no old X-UI adapter function definitions/references or network paths
  in the live Marzban module; safe server-type selection and onboarding;
- 15 positive **Marzban provider** native cases executed on both a genuine
  original 23-table socket-only MariaDB and local loopback fake provider.
  The pinned original V9 and new code must yield the same ordered HTTP
  requests, PHP output/exceptions and synthetic SQL state on Marzban cases;
- retired provider IDs (Sanaei/Alireza fixture records) rejected with
  **zero HTTP** and no MariaDB changes; unsupported provisioning types
  rejected before calling any provider;
- actual PHP HTTP 410 for an obsolete valid X-UI token, 200 for malformed
  input, 405 for POST; this script is unable to load real DB credentials.
- **17 Marzban purchase/admin/agent/discount/free-trial scenarios, 46 actual
  PHP steps**, using the original source V9 and current code staged in separate
  owned-original-23-table socket MariaDBs. Ordered synthetic Telegram outputs,
  real local Marzban HTTP calls, SQL row transitions, paid-wallet outcomes,
  provider failures and synthetic private-file hashes must match **exactly**.
  The native suite caught an accidental CRLF-to-LF user-message change.
  The original V9 bytes were subsequently restored in otherwise untouched
  spans of the preexisting PHP files, including multiline Telegram messages
  and SQL literals. Path-specific .gitattributes recognizes original CR-at-EOL
  in those files; newly authored lines may use LF. This keeps the effective
  V9-to-current code diff reviewable without modifying historical goldens.

A shared read-only supported-plan check now gates both **wallet purchases**
and **manual receipt approvals**, before the first wallet debit or manual
receipt state-claim. The synthetic tools/test_post_v9_wallet_guard.php fixture
executes **seven wallet negatives plus seven manual-approval negatives** with
in-memory SQL doubles only: unknown/retired provider IDs, old inbounds,
obsolete renewal inbound metadata and missing plans. None may issue SQL
mutations, call a provider or change payment states. The 17 positive purchase
scenarios use real disposable socket-only MariaDB and real local mock-HTTP
Marzban; this does **not** certify a full manual receipt approval, real payment
reconciliation, live Telegram, installed operations or provider financial safety.

An additional original-schema financial-route regression exercises
**19 real PHP route-level scenarios**, rather than only the read-only resolver:
retired/unknown callback and invoice-initiation rejection before any
simulated external provider call; forged inbound/cross-server admin plan
callbacks; supported same-server admin plan updates; and positive/negative
external Marzban-account renewal identity matching. The standalone subject
resolver now covers **23** native scenarios including provider mismatch and
external renewal record identity. Disabled-cURL spies replace actual payment
transport, so no real provider, money or tenant is touched. NowPayments
callback preflight is deliberately before the remote verification request.
Admin and customer server reassignment now use one guarded shared-panel
contract; cross-panel and stale/forged callbacks still fail closed. **32
additional original-schema logical relocation/onboarding cases** validate
the former callback shapes, owner/admin privileges, explicit/automatic tags,
different physical endpoints, plan retention and actual admin group wizard
against only a local fake Marzban login. No payment or remote account mutation
is exercised. Real-world financial exactly-once and settlement risks remain
under Issue #26.

CI executes current suite on PHP 8.1 and PHP 8.4 separately, then creates
a NEW source-only candidate ZIP and manifest from the exact committed
`HEAD^{tree}` using:

```bash
python3 tools/package_post_v9.py create --output /tmp/achbot-marzban-only.zip
python3 tools/package_post_v9.py verify \
  --zip /tmp/achbot-marzban-only.zip \
  --manifest /tmp/achbot-marzban-only.zip.manifest.json
```

A separate actual-disposable-filesystem rollback verification is run via
tools/test_post_v9_swap.py. It uses the genuine pinned V9 file bytes and
current source to perform **V9 -> candidate -> V9 -> candidate**, checking all
92 runtime members at every phase, original SQL DDL unchanged, and that
synthetic local baseInfo/private sentinels remain untouched. This proves
source overlay byte integrity, **not** installed rollback, full data restore
or safe live payment continuity.

The package requires a **clean committed** source worktree, verifies each
actual member SHA256 and SHA-1 Git Blob, pins exact source SHA/TREE, omits
all secrets and data/DDL, and sets rollout/financial/operator flags false.
**Do not use V9's old package tooling to generate a fictitious unchanged
V8-compatible post-V9 release**. Any deployment rollback must use two
version-specific manifests and the original database (no data rollback).

## What is NOT yet certified

- Installed Marzban panel, actual Telegram webhook, live admin onboarding
  and purchase/plan wizard, cron/broadcast actions and PHP-FPM/OPcache
  (the new group/onboarding wizard has only been exercised on a fake local
  panel and disposable original-schema SQL, not a real installed bot);
- real payment/TRON/NextPay/NowPayments, concurrent provisioning or partial
  settlement safety. Legacy financial issues remain separate;
- actual full DB restore or any table/column removal;
- server migrations/backups on nonexistent old X-UI panels (not needed).

**Important review focus:** even though obsolete panel types have never been
deployed per the Owner, source payment/receipt routes may already mark a
synthetic payment before a downstream panel error. A branch that rejects
unknown types without external panel traffic is **not enough to prove
transactional pre-mutation fail-closed guarantees**. Reviewers must trace
purchase→payment→fulfillment ordering and report any partial-settlement
risk clearly; do not green-light real finance on these tests alone.

No merge, release, installation or production operation is implied by a
green source-only CI. Record exact candidate SHA/TREE, changed PHP source,
executed evidence and remaining gaps in Issue #31 and the linked new Pull Request.
