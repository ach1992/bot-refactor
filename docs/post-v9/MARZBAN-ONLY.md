# AChBot — Marzban-only post-V9 source and acceptance

**Current source:** Integrated on `main` by [PR #32](https://github.com/ach1992/bot-refactor/pull/32) (merge `7c22dccc4d1ee3f4ef77032f1933481abe8e4279`, 2026-10-15). The author-separated independent R6 review was **COMPLETE / APPROVE** and the exact merged source passed [PHP 8.1 and PHP 8.4 post-merge CI](https://github.com/ach1992/bot-refactor/actions/runs/38686398872).

**Historical public V9:** [`v0.9.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1), frozen source `7eb077fd8e6d0a303421626d1500f69e295a6664`.

**Delivery boundary (2026-10-15):** The Marzban-only source is published as [v0.10.0-rc.1 source-only pre-release](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1). Owner reports manually replacing an installed ZIP and applying the migration, but **the observed server still shows old X-UI menus**, so active webhook/PHP code identity and installed operator acceptance remain **UNVERIFIED**. See [INSTALL-DIAGNOSTICS.md](INSTALL-DIAGNOSTICS.md), [Issue #31](https://github.com/ach1992/bot-refactor/issues/31) and [roadmap #29](https://github.com/ach1992/bot-refactor/issues/29). No finance/production certification or automated deployment/SQL migration is implied.

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
access, support, broadcast and Cron. Preserve all 23 original MariaDB tables and data; the R4 group identity adds
only a separately gated nullable server_info column to the current runtime. Future PasarGuard, new X-UI and unknown panels are *not*
supported yet; add each later with its own provider-specific API contract,
without reinstating or guessing old X-UI semantics.

Intentionally old **settings/subLink.php** was an X-UI-only token conversion
endpoint; Marzban uses its provider's own subscription URL. Invalid/empty
tokens still say `Wrong token`, but a legacy-shaped valid token returns
HTTP **410** without DB access. Original URL filename remains, preventing
accidental execution of removed X-UI SQL/API.

## Owner-approved Marzban shared-panel groups — R4 (2026-10-15)

**Owner clarified after R3:** two logical "servers" can point to the **same real Marzban backend** even if their API URLs and administrative usernames differ. Matching URLs or user names can also refer to different logical/physical deployments. **Only a valid admin-managed shared panel-group ID identifies the same real panel for switching.** R3's automatic endpoint/username inference is retired.

A group is defined by an administrator in Server Management → Panel Groups; its display name (including Persian) is persisted in an ordinary `setting` row with `type='ACHBOT_MARZBAN_PANEL_GROUP'`. Names accept 1–60 Unicode characters (including emoji), trim surrounding whitespace, and reject line breaks. To prevent indistinguishable distinct IDs under concurrent admin submissions, creation uses a MariaDB advisory lock spanning duplicate-name lookup, INSERT and transaction COMMIT/ROLLBACK; the duplicate request is refused. The stable `setting.id` identifies that group. Each logical Marzban server has a dedicated nullable `server_info.panel_group_id` column. During Marzban onboarding and server configuration the administrator chooses an existing group from an inline keyboard, or deliberately leaves the server unassigned. No free-text per-server arbitrary tag creates a group. `server_info.flag` remains only a display emoji/flag.

**No group is NOT a shared group.** All preexisting servers remain NULL/unassigned after the migration and cannot switch with any other server until explicitly grouped by the admin. Different URLs or credentials in the **same valid group** are allowed; even identical URLs/credentials in different groups are rejected. Unknown/retired panel types and missing/deleted groups fail closed. Admin and user callbacks check current permissions, order status, group membership, destination capacity/active state and plan compatibility. Transactions and row locks protect the DB-only order reassignment; changing a group cannot strand an active order already logically reassigned with an original plan on another group.

**What the switch does:** update **only** `orders_list.server_id`. It does NOT change the real Marzban account, proxies/inbounds, protocol, token/UUID/link, order `fileid` or original plan, price, wallet/transactions or capacity counters. Same-group plan-linked accounts retain their renewal eligibility and original plan pricing. Individual logical servers can continue to offer different configurable product plans/prices. For true changes of physical panel or real user profile based on selected plan, create a separate Owner-gated provider-specific design for Marzban and eventually PasarGuard; **do not claim remote migration here**.

### One explicitly accepted additive MariaDB migration

This is a narrow exception to the initial Issue #31 no-schema-change rule, explicitly authorized by the Owner in [the R4 correction](https://github.com/ach1992/bot-refactor/issues/31#issuecomment-6184330243).
- Original **historical** `database/legacy-schema.sql` remains byte-identical and all 23 table names and data stay. The current post-V9 schema adds only **one nullable `server_info.panel_group_id` column**, from `migrations/post-v9/001_marzban_panel_group_id.sql`.
- The migration is **NOT automatically included/executed** by the source ZIP. GitHub acceptance tests run it *only on owned disposable MariaDB*, verify old values, all other tables, idempotence and fresh NULL memberships. The true production backup/restore, maintenance window, DDL application and admin membership assignment remain separately gated.
- See [PANEL-GROUPS-MIGRATION.md](PANEL-GROUPS-MIGRATION.md) for prerequisites, ordering, fail-closed recovery and roll-forward strategy. Source-only rollback to the frozen V9 source does **not** require dropping the additive nullable column; don't drop it blindly to pretend the migration was reversed.

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

Current CI on the merged source does a detached
`git worktree add --detach "$RUNNER_TEMP/frozen-v9" 7eb077f...`
and executes V9's historical full suites under PHP 8.4 (PHP 8.1
compatibility subset separately) before returning to the new candidate.
[PR #30](https://github.com/ach1992/bot-refactor/pull/30) remains a separate,
open historical **published V9 release ZIP** acceptance effort. Any such
V9-only tests belong to the detached original V9 reference, never to the
new Marzban-only runtime.

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
Admin and customer server reassignment now use the explicit named panel-group
identity; original R3 URL/username inference is gone. **38 native PHP
cases** exercise real server-group creation (including two simultaneous admin
requests on separate connections), catalog selection during Marzban onboarding
and editing, Unicode name limits, different URLs/admin credentials in one
group, same URL in a different group, unassigned servers, stale/forged
callbacks, plan-linked order protection and a separately verified additive
nullable-column migration on 23 original disposable MariaDB tables. A pinned
V9 native catalogue handler also executes successfully on that migrated test
schema, providing a bounded source-rollback compatibility smoke.
There are no remote account moves or real payment/provider mutations.
Real-world financial exactly-once and settlement risks remain
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
92 runtime members at every phase, the frozen historical SQL DDL **source file**
unchanged (not an installed database rollback), and synthetic local
baseInfo/private sentinels untouched. This proves
source overlay byte integrity, **not** installed rollback, full data restore
or safe live payment continuity.

The CI artifact also contains the separately named additive migration SQL
and SHA256 digest beside (not inside) the source ZIP. The SQL requires
its own production owner gate and manual/preapproved execution sequence.

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

Source integration is complete, but a green source-only CI does **not**
authorize release, installation, migration or production operations.
Keep the unperformed operator/recovery/financial evidence and any results
of staged testing in [Issue #31](https://github.com/ach1992/bot-refactor/issues/31).
Use the [operator acceptance checklist](MARZBAN-OPERATOR-ACCEPTANCE.md) for the next gate.
