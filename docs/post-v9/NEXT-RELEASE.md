# Current source and published release contracts


## UNRELEASED — Issue #144 service-change corrections (not deployed)

This section documents an **unreleased candidate**, not an update to the
published RC30 ZIP or the active Chili installation.

- Marzban Native Host changes use **one** partial-update PUT that includes
  explicit empty arrays for protocols removed by the new plan. Both old and
  target protocol sets are compared using strict normalized native GET
  evidence; no second automatic PUT or credentials/quota/Note rewrite.
- PasarGuard continues to use absolute, canonical `group_ids`, an existing
  durable mutation fence, authenticated identity checks, Native GET before
  and after the bounded PUT, and no-op/replay checks. The two providers do
  not share incorrect transport semantics.
- Successful service/server and plan changes use a common report-channel and
  staff notice titled **تغییر سرویس**, with escaped subscription name,
  order/customer IDs and numeric actor ID. Administrator order buttons for
  service/server and plan are adjacent.
- BOT_STATES adds an editable **customer plan-change** toggle, default ON to
  preserve established behavior. Both the visible customer button and both
  callback entrypoints enforce OFF; staff plan management remains available.
- **Stacked finance candidate only** (not part of PR #145's independently
  validated exact SHA): a local plan-change intent or staff-authorized
  same-physical-server transfer atomically transitions old, unclaimed
  renewal/extension invoices (including insufficient-wallet
  \`low_wallet\` quotes) for **all** current shared order owners to
  \`service_superseded\`. Their payment IDs, hashes, original amounts, provider
  invoices, API/receipt evidence and rows remain unchanged. The invoice's
  service fulfillment is closed; no external refund or provider cancellation
  is claimed. Late signed IPN evidence is preserved, but old product
  fulfillment and customer/staff payment callbacks are denied by original
  state-CAS checks. Customer status explains the financial-review path.
  A new service-bound invoice also checks the CURRENT uncommitted plan
  journal for the selected owner and every old_rows shared owner, so it cannot
  start a fresh old-plan claim during the Native GET/PUT/finalize gap.
  Selecting the SAME already-local plan never retires a still-valid current
  invoice merely to verify/reconcile Native access; a new correctly quoted
  plan-specific invoice AFTER a verified change remains payable.
  Retired old-service invoice evidence is terminal for Native cleanup only
  while its attempt remains in an unclaimed pending/review stage; unexpected
  progress to fulfillment remains a real reconciliation hold.
- **Strict safety exceptions:** a checking/fulfilling claim, an unreconciled
  Native effect, an active API evidence lease, a reserved/redeemed gift/SMS
  operation, an unknown 003/014 schema or a bound foreign owner cannot be
  silently retired; it continues to hold the service transition for
  reconciliation. Retiring an open invoice happens with the service-change
  intent commit, before a potential Native PUT. A later uncertain Native
  response does not resurrect prior invoices or falsely claim service success.
- **Not included:** user-initiated server relocation, automatic refunds,
  general late-payment financial resolution and operator-facing safe recovery
  of an already-dispatched ambiguous Native plan journal. Owner-approved
  manual reconciliation remains required for these exceptional cases.
  The existing server transfer remains staff-authorized. Customer toggles
  must not imply they authorize physical changes to another customer's
  shared Native subscription.
- Previously dispatched ambiguous operations must **not** be silently retried
  or have their journal marked committed. The Owner described the current
  Chili record as disposable test data and explicitly did not authorize
  modifying it; new code only fixes **future** operations.

No new migration, scheduler, provider dependency, production mutation or
change to the Native account Note marker is included. Future installation
still needs an exact candidate review, Owner rollout approval and separate
operator acceptance. Follow [Issue #144](https://github.com/ach1992/bot-refactor/issues/144).


## Published RC30 — source-only Cron, Telegram, Marzban and gift-card reliability

[v0.10.0-rc.30](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.30) publishes independently approved [PR #142](https://github.com/ach1992/bot-refactor/pull/142), merged as `main@ebca4bee143ccb03ea45c2ea2767646b60ac6f9c`, TREE `f5103e9ffcdcc88994f30ec4e5550dc7b9546a13`. The **131-member source-only runtime ZIP** has SHA256 `5ac98c346867e1a8d3aeb7c38482090cde5288d293f05375f6e7f74e13e79150`, five modified runtime paths since RC29, and **no new DDL**. See the [RC29 -> RC30 operator guide](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.30/RC29-TO-RC30.md), [test acceptance matrix](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.30/TEST-CHILI-ACCEPTANCE.md) and full Release Notes.

The PR corrects CLI Cron actor bootstrap without altering scheduled frequency, retains failed customer Telegram message references with classified bounded diagnostics/retries, rejects malformed Marzban Bearer tokens at authentication and supplied-token consumer boundaries, and protects previously proven consumed gift cards from cross-invoice re-verification and settlement, even if WordPress manually unredeems the card. It preserves the strict `achbot:v1:` plus 32 lowercase-hex Native account Note identity and pinned financial-effect recovery. The approved administrator action reads “🔑 تایید خودکار توسط API”.

[Candidate CI #42938284660](https://github.com/ach1992/bot-refactor/actions/runs/42938284660) passed foundation, all eight PHP 8.5 native families and aggregate. [Merged-main CI #42966042120](https://github.com/ach1992/bot-refactor/actions/runs/42966042120) succeeded using a validated **same-TREE reuse** receipt and a freshly rebuilt main-SHA package; native families were not rerun on main. The Owner-relayed independent review was COMPLETE / APPROVE; previous invalid-Bearer REQUIRED and customer-message documentation OPTIONAL were resolved.

**RC30 publication is not an installed Chili upgrade.** The source adds no migrations, no implicit SQL/Telegram/WordPress/Marzban/PasarGuard operations, no change to actual Cron frequency and no new host-only log-rotation behavior. Installed manifest/provenance and controlled rollback/roll-forward remain to be verified before a separately authorized rollout. Issue [#117](https://github.com/ach1992/bot-refactor/issues/117) retains 48-hour unattended archive and installed operations acceptance, and Issue [#51](https://github.com/ach1992/bot-refactor/issues/51) retains its remaining installed UX acceptance.

## Published RC29 — source-only Chili text and payment reliability

[v0.10.0-rc.29](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.29) publishes independently approved [PR #140](https://github.com/ach1992/bot-refactor/pull/140), merged as `main@c5e97d1f76e3b58bd182533002f00f1bf6a4f362`, TREE `a0f2a423d4b148f96d03cba4606345c95ede7d64`. Exactly 131 runtime files (8 changed since RC28), no new SQL schema or signing keys. Owner-approved Chili Persian catalog copy, compact order-hash footer labels, AG2 format fencing at the final provider/staff boundaries, historic encrypted gift-code recovery, bounded Telegram edit retries and bank SMS due hint are included. The due hint is **20s** but Cron still runs **once per minute**, without a guaranteed confirmation SLA. Read the published [RC28 -> RC29 guide](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.29/RC28-TO-RC29.md) and [release notes](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.29).

The reviewed candidate passed [8/8 native PHP 8.5 families](https://github.com/ach1992/bot-refactor/actions/runs/42920692168), and [merged-main CI](https://github.com/ach1992/bot-refactor/actions/runs/42922661449) reused the identical accepted tree with fresh verified packaging. **Source release is not installed Chili/provider/Telegram/financial acceptance.** Issue [#117](https://github.com/ach1992/bot-refactor/issues/117) retains the requested hosted-gateway 48-hour archive and installed/operator acceptance; #51 remains open where its independent outcomes are unmet. No target SQL/installer/Cron/provider action is authorized by this publication.

## Published RC28 — source-only manual receipt reasons and gift preflight

[v0.10.0-rc.28](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.28) published independently approved [PR #138](https://github.com/ach1992/bot-refactor/pull/138) and [PR #139](https://github.com/ach1992/bot-refactor/pull/139): protected typed manual-rejection reasons and non-consuming authenticated gift-card screening before staff disclosure. Unavailable/uncertain API remains subject to legitimate staff review. No new SQL migration. [RC27 -> RC28 guide](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.28/RC27-TO-RC28.md). RC30 is the latest published source; neither RC29 nor RC30 publication proves an installed rollout.

## Published RC27 — source-only simplified automatic-gateway exception inbox

[v0.10.0-rc.27](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.27) publishes independently approved [PR #136](https://github.com/ach1992/bot-refactor/pull/136) and [Issue #117](https://github.com/ach1992/bot-refactor/issues/117), merged into 27e7d03a2f1adafbec24f4921e253c10325b78d3, TREE fcc1d5b8928d60f81b695947e76dde01b028dba0. The 131-member runtime ZIP SHA256 is 7853912ea273410dfe6a2c4e50bba0961d826e74f8bcc6c16a4747693ba9130f. [Full eight-family PHP 8.5 CI](https://github.com/ach1992/bot-refactor/actions/runs/42628557514) passed on the reviewed HEAD; [merged-main same-TREE CI](https://github.com/ach1992/bot-refactor/actions/runs/42634642173) passed via documented reuse and a fresh exact-source ZIP. The external independent review returned COMPLETE / APPROVE, no BLOCKER or REQUIRED.

RC27 replaces RC26's experimental recovery workbenches with exactly one private on-demand unfinished ORIGINAL NOWPayments/NextPay/Zarinpal inbox. Staff with the existing payments capability have original-payment Approve, Reject, terminal local Archive and Back. Historical provider, Native, delivery and financial evidence remain; Archive prevents later original effects but is not an external refund or cancellation. Browser callback concurrency now differentiates live worker contention from actual SQL failure. No new workbench or replacement-flow replay was introduced.

**Version boundary:** Fresh empty databases use existing prerequisites plus **018 -> 020 -> 022**; experimental 019/021 are not new-install steps. Existing RC26 installations must retain all historical 018/019/020/021 objects and sealed/fingerprint evidence, and may only apply the genuinely missing 022 after an explicitly authorized schema audit/cutover. RC27 distributes checksum-bound migrations separately from the runtime ZIP, not as automated DDL. Follow [RC26 to RC27](RC26-TO-RC27.md) and [gateway-review contract](AUTOMATIC-GATEWAY-REVIEW.md).

**Still unverified:** installed Chili source/schema, real merchant verification, Marzban/PasarGuard, Telegram and webhook/Cron acceptance. GitHub publication authorizes none of those target mutations. Issue #117 remains OPEN. Older RC26 assets stay immutable.

## Published RC26 — source-reviewed recovery; installed acceptance open

[RC26](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.26)
publishes the independently reviewed and Owner-approved Issue #117 source
through [PR #133](https://github.com/ach1992/bot-refactor/pull/133) and
[PR #134](https://github.com/ach1992/bot-refactor/pull/134).
Release tag and verified source manifest bind exact merged HEAD
`390f7ac5cb99285864d019e3ee0ee0b5fad05bec`, TREE
`a9d677eae76794e47ad4ae88b3ae60520a1a1ef0`, identical to the
independently approved PR #134 R2 candidate TREE. The
[exact-candidate full PHP 8.5 CI](https://github.com/ach1992/bot-refactor/actions/runs/42518405396)
passed all eight families, foundation and protected aggregate. The
[post-merge CI](https://github.com/ach1992/bot-refactor/actions/runs/42519667946)
passed with a verified **same-TREE source-acceptance reuse** receipt, not a
second full eight-family execution. The main ZIP was freshly built and its
SHA256-bound manifest separately verified. RC26 has **132 runtime members**
without private configuration or SQL. Published source **does not** certify
installed payment, Native, MariaDB, Telegram or database migration behavior.
See [RC25 -> RC26](RC25-TO-RC26.md) and [Issue #117](https://github.com/ach1992/bot-refactor/issues/117).

The reviewed source now includes the original paid Hosted BUY_SUB
NextPay/Zarinpal linked-account/delivery continuation, first-ever
unreserved trailing 007 ordinal support, and an explicit **distinct**
paid replacement for damaged/foreign/orphaned originals. No unknown original
Native CREATE is re-POSTed. Current persisted `payments` permission applies
to Owner/Manager/Operator/Support only where that administrator actually has
it, including fresh locked checks before replacement and finality; Owner-only
specialist tools remain separate. Migration
`021_paid_reconciliation_provenance.sql` is committed and distributed as a
checksum-verified SQL **sidecar**, never embedded in the runtime ZIP.
For new standard/custom invoices, the original issued entitlement days/GB are
frozen in 021's nullable `pays` fields; historical accounts lacking both
those terms and independent original-007 proof remain fail-closed, never
filled from today's mutable plan. A surviving later 007 Native account must
be strictly authenticated before claiming an original first-ordinal
replacement without an additional original capacity reservation.

RC26 also includes the reviewed delivery-maintenance change, which adds a
bounded, first-attempt-only sweep inside the existing four-worker Cron
services worker: it sends only proven terminal-payment, fully sealed 018
`pending` packets, with at least two minutes of quiet time,
`attempt_count=0`, the existing locked claim/immutable validation and one
durable delivery-attempt record. Invalid packets defer without blocking other
rows; `unknown` / `dispatching` messages are never automatically resent.
This neither settles money nor creates/mutates a Native account and does
**not** make all stalled `processing` cases automatically recoverable.

### Installed upgrade / remaining acceptance boundary

A separately authorized RC25-to-RC26 install requires actual installed
source/DB/config and hotfix drift inventory; a restore-tested full backup;
the original 001–017 history audit; and audited, one-time **018 → 019 →
020 → 021** migration sequencing with exact existing object/index/FK shapes.
Verify 021's unique replacement claim, restrictive self-FK and two nullable
`pays` frozen-term columns before enabling the reviewed runtime. Keep
private `telegramWebhookSecret`, original 018/020 ciphertext, finance/
provider journals and active Cron locks. Stop on partial or incompatible
schema. Reversing only PHP after new journal or Native writes is not a safe
rollback: plan a tested pre-write whole-state restore or evidence-preserving
roll-forward. Read [RC25-to-RC26 staging steps](RC25-TO-RC26.md) and
[payment recovery constraints](PAYMENT-DELIVERY-RECOVERY.md).

Source acceptance remains limited for Native PUT outcomes lacking the
original authenticated positive operation proof (including uncertain
Marzban renewal/day/volume/standard `RENEW_SCONFIG` and PasarGuard
`mutating` operations). Do not retry an ambiguous additive PUT or treat
HTTP 404/timeouts as proof of absence. Confirm provider-specific payment
review and fulfillment parity, schema readiness, exact installed migrations,
real Telegram ACK and both providers using authorized disposable/installed
acceptance evidence. Issue #117 stays OPEN until its remaining outcome is
proven; RC26's GitHub Release is not installed deployment, migration or production approval.

## Published RC25 — independent administrative Panel Groups

[RC25](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.25) publishes the independently approved [Issue #114](https://github.com/ach1992/bot-refactor/issues/114) and [PR #115](https://github.com/ach1992/bot-refactor/pull/115). Marzban/PasarGuard logical Panel Groups are administrator-declared physical backend membership: the admin can change/clear a valid Group even with active orders, payment effects, unfinished journals or different credentials/URLs. The operation changes only local Panel Group metadata, not Native access, plan references, orders or money. Subsequent plan/import/transfer operations independently verify Native identity and persistent journal holds, including surviving orphaned-order journals. See the [product contract](SHARED-SERVICE-TRANSFER.md) and [RC24 -> RC25 upgrade/rollback](RC24-TO-RC25.md).

The 124-member source-only runtime has no new SQL requirement or installed-host certification. It reuses RC24's 016/017 schema when present and does not replay 015/016/017. Full exact-source PHP 8.5 [CI](https://github.com/ach1992/bot-refactor/actions/runs/42192558708) and [post-merge same-tree check](https://github.com/ach1992/bot-refactor/actions/runs/42196043404) succeeded. Release publication is not authorization to deploy Chili or perform Native/payment effects.

## Published RC24 — shared service access and plan journal

[RC24](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.24) introduced guarded shared-physical subscription transfer/plan management and one-time schema sidecars 016 (retire obsolete server-level PasarGuard Native Groups, with preconditions) and 017 (durable plan-change journal). It retained optional RC22 migration 015. Read the [RC23 -> RC24 upgrade guide](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.24/RC23-TO-RC24.md) before older upgrades. The RC25 administrator Group-assignment rule supersedes only RC24's old order-dependent group reassignment gate; the Native consumption safeguards remain.

## Published RC22 — scoped offers and safe Cron initialization

[RC22](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.22) ships the reviewed, merged source of PRs [#103](https://github.com/ach1992/bot-refactor/pull/103) and [#109](https://github.com/ach1992/bot-refactor/pull/109), plus the current PHP 8.5 validation system. The 121-file source-only runtime introduces two plan modules and preserves existing Marzban/PasarGuard, financial and administrator behavior. This published source is **not** evidence of installed-host acceptance.

Optional [migration 015](../../migrations/post-v9/015_scoped_addons.sql) enables global/server/native PasarGuard Group-set day/volume offers; it changes only existing add-on and payment tables (seven columns, two unique indexes). The source still handles pre-015 legacy global offers when 015 is absent. Partially applied 015 is incompatible; installed migration, restore rehearsal, rollout and live payments need independent target-specific authorization. Read [RC21 → RC22](RC21-TO-RC22.md) before preparing an upgrade and [scoped add-ons](ADDON-SCOPES.md) for runtime safeguards. RC22 also enables trusted first-run Cron lock-directory creation when the scheduler OS user is permitted to do so.

## RC22 Cron lock-directory creation (RC21 did not include it)

[Issue14](https://github.com/ach1992/bot-refactor/issues/14) tracks the Owner-accepted first-run behavior. The top-level CLI coordinator creates missing external lock-directory components with mode `0700` after validating their existing parents. Valid existing directory/file permissions and inodes are preserved; unsafe paths fail closed without repair. Concurrent first starts remain single-flight, and distinct canonical bot paths retain independent hashed leases. Child modes, HTTP and includes cannot bootstrap directories.

The default remains `/var/lib/achbot/cron-locks`; creation uses the current Cron OS user's permissions. A non-root user without permission there needs `ACHBOT_CRON_LOCK_DIR` beneath a trusted writable external parent, or administrator provisioning. No SQL, runtime-member or four-worker contract change is required. This behavior is part of RC22 source; it does not modify immutable RC21 assets or establish installation. See [Cron setup](FRESH-INSTALL.md#6-configure-cron-and-its-private-lock).

## RC21 evidence-first payment review

[RC21](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.21) carries the [Issue57](https://github.com/ach1992/bot-refactor/issues/57) correction through [PR102](https://github.com/ach1992/bot-refactor/pull/102). The release record and manifest establish publication/source identity; they do not establish installation. Bank transfers always offer immediate receipt submission. API verification/consumption follows delivery of the original actionable administrator evidence. Gift API OFF retains images; API ON receives a code with the same subject caption/actions and escaped Telegram code formatting. Successful API approval converges staff copies with "تایید خودکار توسط API"; errors preserve human review.

The separate legacy timer cannot settle API-pinned bank invoices; eligibility and the locked claim enforce that ownership. Unpinned legacy receipts retain their existing timer behavior. Original full amounts, native/financial journals, human/API races and late evidence-only handling remain protected. The customer has no Check payment / ten-minute fallback distinction for new issuance.

RC21 keeps **119 runtime members / 118 PHP files** and adds no SQL relative to RC20. Migration014 is unchanged and must already be correct before API enablement; never replay completed012/013 or blindly rerun014. Follow [RC20 → RC21](RC20-TO-RC21.md) for older-source, schema and in-flight-operation boundaries and [the current payment contract](API-PAYMENT-EVIDENCE.md) for behavior/recovery. The Owner authorizes source merge/publication and completed-resource cleanup; Chili transfer/migration/installation/API/live acceptance remain on hold.

The RC20 section below is historical publication behavior. Its ten-minute customer journey is superseded by RC21; RC20's published assets remain unchanged.

## RC20 API payments, native gifts and notification routing

This section describes [v0.10.0-rc.20](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.20). The release record and its manifest establish publication and exact source identity; they do not establish installation. Implementation, exact-candidate checks and independent review are tracked in [PR101](https://github.com/ach1992/bot-refactor/pull/101) and [Issue57](https://github.com/ach1992/bot-refactor/issues/57). PR100 retains superseded component history.

The runtime contains **119 members / 118 PHP files**, four modules more than RC19: PaymentEvidence, PaymentApiAdmin, ApiPayments and its API route module. RC20 retains RC19 display, QR, managed invitation and root-helper retirement behavior below.

| Area | RC20 behavior |
| --- | --- |
| Manual bank transfer | Optional authenticated SMS evidence; match the complete original amount including all three randomized trailing digits; initially offer Check payment, then add receipt submission after 600 seconds while checking continues |
| Manual gift card | Optional code verification and consumption; canonical redeem_amount in an explicitly configured unit, one exact-value card; unknown/outage/uncertain results use genuine manual review |
| Existing automatic bank path | Separate legacy automatic card-transfer behavior is unchanged |
| Administrator gift | Independent of purchase plan, including genuine planless imports; one physical native increment, one notice per admitted current customer; native panel supplies displayed expiry/quota |
| Notifications | Owner receives general/report events except backup; receiving-on Manager gets the same general events; receiving-on Operator/Support get actionable manual receipt/code reviews only; existing valid receipt copies still converge even with new-copy receiving OFF |

Both API switches default OFF. The full [API contract](API-PAYMENT-EVIDENCE.md) covers pinned profiles, exact matching, human/API races, delayed evidence-only consumption, typed code review, role routing and recovery. A settings-complete indicator is not a live authentication or payment test. Existing plugin APIs are used without dependency changes; optional filters are not activation prerequisites.

RC20 supplies source-only [014_api_payment_evidence.sql](../../migrations/post-v9/014_api_payment_evidence.sql): 19 nullable fields on payment_attempts, 5 on payment_transactions, a due-work index and an operation-uniqueness index. No new table or historical invoice backfill is introduced. Missing/partial schema fails the API path closed. Apply014 only during a separately authorized, backed-up target cutover, before enabling either API mode; never replay completed 012/013. [RC19 → RC20](RC19-TO-RC20.md) defines ordering, partial-state stops and the recovery boundary after new writes.

Customer and financial/native history, original quote pins, source/batch holds and the Cron lease/inode remain protected. Never replay request69 or erase its evidence. Its actual installed version, provider attribution, root cause and native outcome remain unverified. Neither RC19 ZIP staging nor RC20 publication proves installation or real Telegram/provider/payment acceptance.

## Published RC19 simple display, QR and invitation settings

[RC19](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.19) publishes reviewed source `9451ef306be34bc224d57645d7b7394b8bea3a99` through merged [PR98](https://github.com/ach1992/bot-refactor/pull/98). It keeps 115 runtime members / 114 PHP files, retires root buy_helpers.php in favor of canonical domain implementations, and adds no SQL migration. Follow [RC18 → RC19](RC18-TO-RC19.md). Publication does not install or certify a live bot.

## RC19 source contract

The Owner accepted one source batch for simple per-server display, complete
buy_helpers.php removal and managed invitation gift. [Issue37](https://github.com/ach1992/bot-refactor/issues/37#issuecomment-6507182633)
holds the scope and later QR/invitation clarifications, delivered in RC19.

Selected server → **نمایش کانفیگ‌ها و لینک اشتراک** now contains independent
purchase and order sections with subscription/config/auxiliary toggles. Config-on
reveals an exact-name editor (one per line, maximum10; /auto restores first3).
Only purchase delivery has the subscription/first-selected-config/off QR cycle.
Order-page QR uses the existing two Bot Settings switches; config QR sends all
native configs individually, independently of inline choices. The complete
final message reserves protected facts/links before reducing inline configs.
Read [presentation semantics](SUBSCRIPTION-PRESENTATION.md).

Gateway/Channel Settings → **پاداش هر دعوت**, immediately after Wallet, edits
PAYMENT_KEYS.inviteReward using the existing authenticated, revision-bound
payment-settings editor. Amounts are integer Toman from0 through999999999;
0 or /clear disables future gifts. Unset/invalid/duplicate configuration uses0,
never a retained private value. Existing invitation messages and gift behavior
otherwise remain. During a separately authorized rollout, configure the desired
gift through the new managed UI before reopening public invitations. Until then,
the new runtime uses0 and ignores the old private $inviteReward assignment;
it does not import it. Remove that private assignment
only as part of the paired new-runtime rollout; this source work touches only
the synthetic example. Downgrading would revive the old private source, so
reconcile gift settings deliberately before any approved recovery.

The root buy_helpers.php is removed, with actual bodies in existing purchase
and subscription domains and public consumers updated. A full manifest-based
source replacement must remove the retired installed file during an authorized
rollout; no alias wrapper, new table, migration or live operation is introduced.
Frozen V0–V9 evidence and published RC18 assets stay unchanged.

Current implementation, exact-candidate CI, review and integration evidence
are recorded in [PR98](https://github.com/ach1992/bot-refactor/pull/98) and
[Issue37](https://github.com/ach1992/bot-refactor/issues/37). Exact-source full
PHP8.1/PHP8.4 CI, author-separated review and action-specific approvals are
required before their corresponding integration/publication actions. Deployment
and actual Telegram/provider acceptance remain separate; source work does not
authorize installed-target inspection.

## Fresh installation and independent Cron locks

Follow [fresh installation](FRESH-INSTALL.md) for a new bot: matching verified
runtime/setup source, empty-database SQL order, private per-bot webhook secret,
Owner configuration, one-minute CLI coordinator and readiness/acceptance. It is
not an upgrade or automatic installer. Existing tenants use their exact release
transition and never replay completed012/013.

The private lock directory remains outside served/install paths. Its default is
/var/lib/achbot/cron-locks; each canonical bot directory has a distinct hashed
lock file. Same-UID Cron installations may share that directory; different UIDs
need separately owned private0700 directories using ACHBOT_CRON_LOCK_DIR. The
current runner's four processes are gift2all.php, admitted broadcast,
rewardReport.php and admitted services; old standalone broadcast/warning paths
are retired. Do not delete or replace an active lock inode.

## Published RC18 gateway destination keyboards

[RC18](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.18) is the previous immutable source-only prerelease. [PR #96](https://github.com/ach1992/bot-refactor/pull/96) restores the established two-button rows for cooperation URL, help channel and private mandatory-membership invite URL. The status/edit button retains its existing callback; the adjacent localized label uses the same informational callback as neighboring settings. Existing validation, BOT_STATES persistence, permissions and save/clear/cancel navigation remain unchanged. No new table, schema, setting or management mechanism is introduced. Read [RC17 → RC18](RC17-TO-RC18.md).

## Inherited RC17 shared settings and native display selection

[RC17](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.17) delivered the following shared settings and native display behavior through [PR #95](https://github.com/ach1992/bot-refactor/pull/95); RC18 preserves it. Its package contains **115 runtime members / 114 PHP files**. Read [RC16 → RC17](RC16-TO-RC17.md) and verify the official assets before rollout. Source merge/publication does not install files or certify real Telegram/provider/merchant/wallet/Cron acceptance.

The release consolidates destination editors in gateway/channel settings and keeps only four message bodies in Bot Settings. Native Marzban and PasarGuard share independent purchase-delivery/order-view config selection through the same renderer, editor and existing per-server JSON in setting. Existing choices/defaults remain until edited; managed Marzban positional compatibility is unchanged. A compatible RC16 installation needs **no new SQL**; do not rerun completed012/013. Inherited RC16 staff, receipt, profile, Persian-copy and private-configuration boundaries below remain.

## Published RC17/RC18 display contexts

The following describes the published RC17/RC18 UI. The RC19 simple controls above supersede this historical journey.

Open the selected server’s display settings and choose purchase delivery or order details before selecting configs by the existing protocol/name controls. Each explicit context list overrides the shared selectors, including an empty list selecting none. Otherwise the shared list and then the existing all-config default apply. Visibility, subscription and limits remain common. The editor preserves the other effective choice when explicitly selecting ALL; reads perform no backfill. The same code/storage/management serves both native providers. See [presentation semantics](SUBSCRIPTION-PRESENTATION.md) and the [RC16 downgrade boundary](RC16-TO-RC17.md#recovery).

## Administrator scope and downgrade boundary

Owner is the administrator configured in private baseInfo and appears in the existing administrator list. That list adds or changes Manager, Operator and Support. Manager has ordinary admin functions except administrator management; Operator additionally excludes statistics, servers, plans/categories, bot settings and gateway/channel settings; Support has customer information, ban/unban, the order database editor, and human payment-receipt review, approval and rejection. This payment permission does not allow independent wallet adjustments/debt, foreign-ticket management or native/server administration. Menu visibility and actual requests agree. Payment review follows administrator-list placement and permits inspection to all four tiers; consequential owner-only reconciliation stays separate.

All genuine Owner, Manager, Operator and Support administrators keep the same existing human receipt review, approval and rejection workflow. Management tiers do not change that process or add a per-administrator payment grant or a new permission step. The independent notification switch controls new message copies only; receipt keyboards already delivered retain their usual completion updates.

Receiving administrator messages is a separate on/off preference beside each non-owner administrator in the existing list. It defaults to on for active staff and applies independently of Manager, Operator or Support level; only Owner can change it. A current valid database staff identity and an enabled preference are both required for new secondary copies. Existing receipt-keyboard status updates require current valid staff authority, regardless of notification preference; stored references alone never authorize delivery. Private staff arrays do not select or authorize recipients. Receiving a message does not grant any action permission: Support may review, approve or reject payment receipts, while foreign-ticket routes and independent wallet/native administration remain restricted. Changing a level preserves the notification preference; explicit off stops new copies while retaining existing receipt-button maintenance and historical references. Revoked, banned, ambiguous or nonstaff recipients receive neither new copies nor receipt-status updates. Missing preferences enable new copies; malformed or duplicate preferences suppress new copies without changing prior receipt-keyboard maintenance. Owner primary receipt/evidence delivery has no disabling toggle. Confirmed new references append atomically; malformed historical metadata is preserved rather than repaired or discarded. Primary Owner delivery and financial evidence/replay remain separate and unchanged.

The existing boolean admin marker remains for compatibility and the assigned level uses existing settings. Existing staff without an explicit persisted level receive Operator scope. Valid persisted Manager, Operator and Support levels remain authoritative; Owner assigns Manager or changes levels through the existing administrator list. Only the primary `$admin` Owner identity belongs in private baseInfo among staff settings. Retained `$admins_list` or `$special_admins` variables are ignored, without importing them into the database or inferring Manager access. An old runtime does not understand new tier restrictions: after assigning tiers, do not roll back source blindly and assume the old code preserves them. Reconcile administrator access before any separately authorized downgrade.

For a separately authorized upgrade to this new runtime, verify the database staff list and assign the required levels from the Owner account, then remove the obsolete `$admins_list` and `$special_admins` assignments as part of that paired runtime/private-config rollout. Keep the primary `$admin` setting. Installed RC15 still uses the old fields: do not remove them from an RC15 installation alone. This source change neither reads nor edits any actual private file and does not authorize a rollout.

On MariaDB snapshot-isolation conflicts, a caller holding an older repeatable-read transaction may have secondary fan-out deferred safely until its next ordinary delivery. Normal delivery remains outside a newly introduced transaction; no caller transaction or isolation mode is changed.

## Bot Settings and copy

Bot Settings → متن‌های ربات contains only the four message bodies. Support, report-channel, forced-membership identity, private invite URL, help and cooperation destinations are managed once in the existing gateway/channel settings; destination save/clear/cancel returns there. Existing stored values are retained; published RC16 assets remain immutable.

Use Bot Settings to edit welcome/home messages and the shared purchase note. Unset content gives neutral setup guidance. Enter normal text or Telegram-formatted text; do not paste escaped HTML. Existing valid per-instance content and custom buttons are retained. Support, help-channel, mandatory-channel join and cooperation-panel destinations are configurable; unset optional destinations do not create fabricated external links. For a private channel, keep its numeric membership identifier and edit the join/invite URL separately. Clearing that URL restores public-channel derivation or the existing valid private-config joinLink fallback.

Destination values are encoded at each actual HTML, legacy Markdown or plain-text consumer. Stored settings and native keyboard URLs keep their original bytes. Current template substitution is a single pass so placeholder-like words inside a destination remain literal data.

Static interface copy remains in exactly two audience files, while instance marketing/message bodies are persisted configuration. Persian prose starts with Persian wording around Latin terms/numbers; raw copyable URLs, configs, code and parser tokens retain their bytes. The display-policy guide uses supported HTML/code presentation.

## Receipts, profiles and links

Administrator receipt captions retain useful customer/service facts, omit duplicated metadata, and place the pinned exact bank amount immediately after paid amount. Original payment/attempt evidence and guarded approval callbacks remain unchanged. User-info counters preserve incoming-payment and service-value meanings; agent percentage is next to agent status for permitted staff. A genuine private /start refreshes the name and a supplied nonempty username, retaining the previous username if absent. Configured auxiliary links are displayed for PasarGuard after purchase and in account details without modifying native subscription/config URIs.

## Previous RC15 shared-order release and migration boundary

[RC15](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.15) is published and PR92 is merged. It consolidated PasarGuard connection/native-operation evidence into existing tables and retired obsolete order columns. The rules below concern that release, not new SQL for this presentation/administration follow-up.

RC15 requires reviewed database preparation: [012_shared_provider_storage.sql](../../migrations/post-v9/012_shared_provider_storage.sql) transfers and verifies populated native evidence before removing the two provider tables; [013_remove_obsolete_order_columns.sql](../../migrations/post-v9/013_remove_obsolete_order_columns.sql) removes the two unused order columns. The runtime ZIP does not execute SQL. Read the [shared-storage migration and recovery contract](SHARED-PROVIDER-STORAGE.md) and [accepted workflow decisions](UNIFIED-SERVICE-MANAGEMENT.md#owner-simplification-follow-up-2026-10-28-revision-2) before rollout. Quiesce matching writers, verify a restorable full backup and use the paired source/schema. After012, reverting only runtime to RC14 is unsupported; recovery must restore the paired database/runtime or use an audited roll-forward. No tenant migration, release, or installation is implied by source acceptance.

The RC13/RC12 scope below is historical release context. Its no-new-SQL and011-only descriptions do not apply to RC15.

## Previous RC13 release scope and delivery contract

[RC13](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.13) removes the separate agent USDT rate. Every new manual USDT and hosted NOWPayments quote uses only `manualUsdtRateToman`, including agent accounts; old issued quotes retain their pinned rate and amount. The [Owner decision](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6453867569), [PR89](https://github.com/ach1992/bot-refactor/pull/89), exact PHP8.1/8.4 CI and independent APPROVE cover runtime source `a9175a2`. Publication documentation introduces no runtime changes. Read [RC12 → RC13](RC12-TO-RC13.md) or the [Persian guide](RC12-TO-RC13.fa.md). A compatible RC12 installation needs no new SQL; earlier migration and operator gates remain separate.

The inherited RC12 scope below remains available, except its optional agent-rate description is historical and superseded by RC13's [shared-rate contract](USDT-RATE-SETTINGS.md).

## Inherited RC12 scope

[RC12](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.12) is published as a source-only prerelease. Its runtime and manifest identify the reviewed source `ecd30ba9192ed374703118a6c131be8809cc7b06`, integrated through [PR #87](https://github.com/ach1992/bot-refactor/pull/87). This document describes that release scope; publication does not establish installation or operational acceptance. Published RC10/RC11 and frozen V0–V9 evidence remain immutable.
Owner scope is recorded in #26 comments
[6433839924](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6433839924),
[6434034586](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6434034586)
and [6434233761](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6434233761).
See the [RC11 preparation guide](RC11-TO-RC12.md) for package, settings and recovery
steps. No actual private configuration, tenant, database, webhook, Cron or money
is changed by this source work.

## Customer and administrator behavior

- The two original **Increase/Decrease wallet controls** remain. A Decrease that
  would create or deepen debt requires a locked before/after preview and separate
  explicit confirmation; preview moves no money. Customer notices report the
  committed operation, amount and resulting balance. Private administrator
  notices also report numeric target ID, before/after balances and tracking ID.
  Failed Telegram delivery does not reverse a committed movement.
- Home sends a new menu while preserving invoices, status results and payment
  evidence. References display the persisted customer's numeric Telegram ID.
  The attention inventory distinguishes unresolved claims/effects from historical
  successful `paid` orders. It shows subject-specific evidence gaps and never
  automatically approves, retries, refunds, reissues or repairs money.
- New volume/day add-on invoices pin **whole GiB/days** in existing `pays.volume`
  or `pays.day`, with the other axis zero and the original `pays.price` retained.
  Fulfillment uses these immutable issued units even after a catalogue change
  or deletion. Legacy zero/NULL/missing/inconsistent quantities do not prove the
  original entitlement: refuse new claim/debit/provider effects and retain
  paid/processing evidence for operator reconciliation. Do not autofill from
  today's SKU, rewrite old quotes, refund or replay automatically. See the
  [financial boundary](FINANCIAL-CONSOLIDATION.md#immutable-add-on-quantity-and-rc11-pending-invoice-boundary).

## Providers, display, rates and copy

RC12 uses genuine Marzban and native **PasarGuard 5.5.1** paths.
PasarGuard persists concrete panel incarnation and remote numeric account
identity, pins native Group IDs and uses native subscription/config responses.
Its Groups are distinct from customer payment cohorts and Marzban physical-panel
groups. Existing Marzban grouping remains unchanged. Retired X-UI variants stay
unsupported; User Templates, `on_hold` and native next-plan/reset scheduling are
not managed. Configure native Groups/Hosts and an absolute subscription URL
prefix before PasarGuard use; mutable upstream Group contents are not frozen by
a pinned ID. Details: [PasarGuard contract](PASARGUARD-CONTRACT.md).

In each logical server's **نمایش لینک و QR** settings, administrators use guided
config/subscription toggles, QR choices, limits and protocol/name selectors.
Marzban positional compatibility is an explicit managed mode; PasarGuard never
reuses those indices. Logical servers have independent policies even when they
share a backend. Configure the intended policy before rollout; an absent setting
uses documented native defaults, while malformed/duplicate/provider-mismatched
settings fail closed. No display setting changes actual subscription access.
See [presentation settings](SUBSCRIPTION-PRESENTATION.md).

Administrator gateway settings own normal/shared `manualUsdtRateToman` and
optional `usdtAgentRateToman` for both manual USDT and hosted NOWPayments.
Missing/cleared agent override uses the explicit normal rate; invalid explicit
override disables only that audience's quoting. TRX keeps its independent rate,
and older pinned attempts retain their original quote. Configure rates before
new use; no private/external/1:1 fallback exists. See [USDT settings](USDT-RATE-SETTINGS.md).

All bot/PHP payment-page copy lives in exactly **two editable files**:
`messages/fa/user.php` for customer/agent journeys and shared safe labels, and
`messages/fa/admin.php` for administrator management and diagnostics. Logical
topic sections and stable keys remain; `messages/loader.php` is code-only and
legacy settings keys remain a compatibility facade. Bot-owned prose/numeric
presentation uses ASCII digits, coordinated meaningful emoji and the format
supported by its actual Telegram or browser consumer. Keep established wording
where possible; never decorate parser/date/unit fragments as whole messages.
Use the [editing map](RC11-TO-RC12.md#message-editing-map) and
[catalog contract](../../messages/README.md), preserving keys, declared parameters,
escaping and legacy placeholders. Callback/state/SQL/provider identifiers and
diagnostic codes remain implementation contracts.

The actual private `baseInfo.php` remains untouched. The six obsolete globals
`hideConnectLinkForMarzban`, `showIndexes`, `hideConfigIfMany`, `linkIndices`,
`USDTPrice` and `USDTPriceagent` are ignored as runtime configuration sources,
not imported. Those six assignments were removed from the synthetic example in
the earlier release. The current staff follow-up additionally removes only
`admins_list` and `special_admins` from that example; primary Owner and per-installation
secrets remain. Actual private cleanup follows the paired-runtime boundary above.

## Schema and package boundaries

A compatible RC11 installation already has its reviewed financial journals.
Do not rerun **005**, rename journals or import RC10; preserve wallet journal
identity and both transfer legs. Audit actual shape/state rather than assuming
a previous upgrade ran.

[011_provider_identity.sql](../../migrations/post-v9/011_provider_identity.sql)
is additive and separately applied **only when PasarGuard is used**.
Existing Marzban-only operation does not require it. Missing, partial or
incompatible 011 blocks PasarGuard; it does not authorize repair or fallback.
Persistence separates the purchased entitlement from later mutation observations;
uncertain provider writes require reconciliation, never blind second dispatch.

[010 cleanup](CURRENT-RUNTIME-CLEANUP.md) is optional operator maintenance, not
an automatic DROP migration or readiness prerequisite. Its strict manifest and
guarded CLI allow only reviewed empty original table shapes with dependency,
retention and tenant identity checks plus explicit maintenance authorization.
Unknown/custom/nonempty state must be retained. Retired TRON/WeSwap source audits
also reject unknown files and active schedules; source search is not permission
to delete installed data.

The RC12 runtime contains **112 files**, including the loader and all
25 message topics. Verify every file against the trusted exact-release manifest;
a partial overlay without `messages/` is unusable. Source ZIPs contain no private
configuration and perform no SQL installation or maintenance.

## Validation, delivery and rollback

The Master binds exact stabilized source, PHP 8.1/8.4 current tests, detached
original-V9 gates, package/manifest and fresh author-separated review. Intermediate
worker checks and adapter probes are not aggregate or installed acceptance.
[Issue #81](https://github.com/ach1992/bot-refactor/issues/81) retains
separate provider/operator gates. The Owner-authorized RC12 source merge and
publication are complete; no production approval is implied here.

Bounded adapter evidence: client `30f8eab14b01421b0cab221a301d392673f696f8`
completed 11 native probe phases against unmodified upstream PasarGuard 5.5.1
commit `eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43` on a task-owned loopback panel.
Native links and links_base64 each contained one configuration; DELETE204 was
followed by by-ID404. This does not certify the aggregate bot or customer
data-plane traffic. See #81 milestones
[6434115683](https://github.com/ach1992/bot-refactor/issues/81#issuecomment-6434115683)
and [6434178553](https://github.com/ach1992/bot-refactor/issues/81#issuecomment-6434178553).

Rollback must preserve committed money, payment/effect/replay journals, provider
identities and remote accounts. Older source may not understand PasarGuard or the
new message/settings dependencies; a source downgrade is not a financial undo.
Never erase journals or restore an old database blindly to make old code run.
Reconcile any uncertain operation against protected local/provider evidence and
use a separately reviewed recovery plan. Lower Telegram update IDs after a long
idle epoch and retained transfer cursors also require explicit reconciliation,
not erasing replay history.
