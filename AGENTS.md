# AChBot repository rules

- **SHARED USDT RATE — Owner decision 2026-10-28:** new manual USDT and hosted NOWPayments quotes use only `PAYMENT_KEYS.manualUsdtRateToman` for every account. No separate agent rate/editor/menu exists; retained `usdtAgentRateToman` values are ignored. Preserve validated RC12 editor revision history without enabling edits of the retired field. Missing/invalid normal rate disables new USDT quotes for everyone; TRX is independent. Existing attempts retain their original pinned quote. This supersedes optional-agent-rate descriptions for current development; published RC12 assets remain unchanged. See [Issue #26 decision](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6453867569).

- This is a public repository: never commit customer data, deployment secrets, raw SQL dumps, runtime backups or logs.
- **USER-VISIBLE ERROR PRIVACY (Owner decision 2026-10-16):** Customer-facing Telegram/error/checkout/status messages must describe the affected action and safe next step in plain Persian; do NOT expose infrastructure/provider implementations (Marzban, panel type, database/SQL, internal API, server stack), stack traces, raw exception texts or internal state codes. Preserve specialized diagnostic reason codes and payment/operation correlation for **authorized administrator-only notices or protected application logs**, with sensitive fields and credentials redacted. Admin management/configuration screens may display genuine technical provider names when operationally necessary. Missing/ambiguous financial outcomes must say *under review*, never falsely claim a refund, failure or a completed operation. Test both audience boundaries for modified error paths. The shared Telegram transport's failure/429 logs must NEVER serialize raw outgoing payload values, provider response snippets, cURL error text, bot token, customer messages or private administrative diagnostics; retain only non-secret operation/error-code metadata. Previously installed legacy error logs may already contain private data and require a separately authorized operational privacy review/remediation.
- **CURRENT PROVIDER OWNER DECISION (2026-10-27, Issue #26 revision3):** source development now includes native PasarGuard5.5.1 alongside Marzban. PasarGuard requires011 stable identity/snapshot persistence and native Group semantics; no Marzban alias, User Template,on_hold or native scheduling. Preserve Marzban and fail closed for unknown/retired providers. Display policy is per-server administrator-managed; USDT rates are bot-managed; all UI copy belongs in exactly two editable audience catalogs, messages/fa/user.php and messages/fa/admin.php (Owner revision4, Issue26 comment6447224257). Keep ASCII digits in bot-owned prose/numeric presentation, coordinated meaningful emoji and supported formatting, preserve established wording, and never put admin-only details in customer messages. Leading emoji decorate whole messages/buttons where suitable, not date/unit/continuation fragments; parser tokens stay in implementation source and must not be edited as copy. The current synthetic baseInfo.example.php may remove only the six obsolete display/rate assignments; original V0–V9 checkout remains immutable. Real private baseInfo.php is untouched. See docs/post-v9/NEXT-RELEASE.md and #26 comment6434233761. Earlier Marzban-only decisions below are historical where superseded.
- **R4 OWNER DECISION — EXPLICIT PANEL GROUPS:** [Issue #31](https://github.com/ach1992/bot-refactor/issues/31#issuecomment-6184330243) supersedes prior R3 auto-identification: multiple logical Marzban server entries may share one actual panel even when their URLs **and** admin usernames differ. The only authority for a logical `orders_list.server_id` switch is shared, valid, administrator-assigned `server_info.panel_group_id` pointing to a named catalog row in existing `setting`; no group => no switching. Server flag/emoji, URL equality, credential equality and callback data MUST NOT infer physical-panel identity. Preserve remote accounts, original plan/fileid, token, pricing, inbounds, payments, wallet and capacity. Cross-group/retired/unknown panels fail closed. A future increment may support actual cross-panel migration and automatic provider profile/plan reconciliation.
- **ADDITIVE CURRENT SCHEMA EXCEPTION:** Owner explicitly accepted one new nullable `server_info.panel_group_id` column for post-V9 current runtime. The **original 23-table V9 schema file remains byte-frozen**, while the original post-V9 schema adds that column; the Owner-authorized Chili RC5 installation additionally applied shared payment-ledger migration 003, leaving 25 tables (23 original plus 2 shared), without changing the frozen SQL. See `migrations/post-v9/001_marzban_panel_group_id.sql` and `docs/post-v9/PANEL-GROUPS-MIGRATION.md`. Never apply the migration to an actual tenant without separate Owner production authorization, tested backup/restore and staged verification; never imply source-only ZIP deploys its migration.
- **TEST GENERATION BOUNDARY:** pinned V0–V9 tests and exact V8/V9 byte-parity artifacts are **frozen historical evidence**. They belong on a detached **original V9 checkout**, NOT directly against the intentional post-V9 runtime, and MUST NOT be rewritten or relaxed to forge historical PASS. New post-V9 tests must examine the actual new runtime, desired Marzban behavior and legacy negative controls. This also applies to CI, packaging and rollback.
- **CURRENT MANUAL CRYPTO DECISION (2026-10-18; supersedes old TRON gateway and single-USDT-network descriptions):** The only new chain-payment experience is manual photo + human admin review for independently enabled USDT BEP20, TRC20, ERC20 and native TRX TRON within the shared manual crypto selector. Each independently configured network destination requires a valid compatible address; USDT uses the one explicit shared `manualUsdtRateToman` rate, TRX requires its own explicit administrator-specified TRX/Toman rate. No chain watcher, legacy TRON TXID success handler, automatic TRON settlement, automatic screenshot confirmation, or blockchain transfer-triggered fulfillment/credit is permitted. Quoted asset/network/amount/recipient/rate are pinned in original shared `payment_attempts`, and no unconfigured option may be offered. The old automatic TRON checker, source-only handlers and Cron shim are now ABSENT from CURRENT runtime packages; the four-worker coordinator is unchanged. No independently scheduled legacy TRON/WeSwap worker is supported. Preserve existing historical financial evidence, review/approval idempotency, and future separately authorized chain-verification boundary; do not imply live publication/deployment or schema authorization from source code. See `docs/PROJECT-SPEC.md` and `docs/post-v9/FINANCIAL-CONSOLIDATION.md`.
- **UNUSED PAYMENT HISTORY — OWNER DECISION 2026-10-20 (Issue #29):** Legacy automatic TRON/TXID and WeSwap were never used and their runtime code and legacy compatibility are removed. NextPay also has no prior-install payment history, but remains an OPTIONAL gateway for NEW transactions on the shared ledger, without NextPay-specific historical imports. Manual TRX on TRON, USDT on TRC20 and NOWPayments are separate supported flows. Preserve immutable V0–V9 history and released packages. Deployments must explicitly reconcile deleted old source files without touching installed data, private config or other instances; no real deployment, migration, release, or Cron edit is authorized here.
- **CURRENT FINANCIAL CONSOLIDATION DECISION (2026-10-16):** Owner approved a product-local, staged common verified-payment/settlement/effect pipeline under Issue #26, including minimal additive schema *only if justified by proven persistence gaps*. NOWPayments hosted-checkout/browser success is NOT settlement proof; provider API `finished` is the only eligible final success state, never `confirmed` or `sending`. Preserve provider-specific evidence, idempotency/recovery/partial-failure semantics and existing public routes. One Owner-authorized Chili test installation runs RC5 and its own chilibot schema has migrated through 003; no other target deployment or new financial, Marzban or Cron operation is authorized by that past event. See `docs/post-v9/FINANCIAL-CONSOLIDATION.md`.
- **NOWPAYMENTS OWNER CLARIFICATION 2026-10-16, REFINED 2026-10-17:** Browser Return-to-Merchant must NEVER be required for verified service provision. `confirmed` is blockchain arrival, not safe merchant settlement by itself; use HMAC-checked IPN, durable invoice/payment order and quote, and existing bounded scheduled status verification at `finished` for fixed-priced services. RC4 published historical dedicated NOWPayments `002`; do NOT apply it merely to prepare the later provider-shared `003` migration. Original frozen V0-V9 SQL is unchanged. The private `$nowpaymentsIpnSecret` remains required for authenticated IPN; actual Cron/IPN/SQL activation is separately gated. **2026-10-17 Owner-approved merchant-fee refinement:** normal NOWPayments conversion/network fee deduction as on the original hosted checkout is accepted without an extra `$nowpaymentsSettlementPolicy`; when absent, no fixed NET floor is required, but full provider-requested deposit, positive actual payout, original USD/Toman quote, `finished`, exact payment identity and non-replay are mandatory. An explicitly configured stricter payout floor remains pinned to each existing invoice, and invalid explicit config fails closed. No unsafe 1:1 default or untrusted status may be used. The source-only candidate also refuses old amount-unprovable browser settlement and cross-gateway invoice overwrite. PR #42 is integrated and its descendants are installed on the Chili RC5 test bot, with an Owner-reported basic operation smoke; NO live merchant payout/IPN settlement was certified. It cannot discover payment ID when every provider IPN is lost; record rather than conceal this gap. See `docs/post-v9/NOWPAYMENTS-MIGRATION.md`.
- **SHARED PAYMENT LEDGER OWNER DECISION (2026-10-17):** No gateway-specific proliferation of tables; use plain provider-neutral payment_attempts and payment_transactions with provider-scoped unique identities, keeping old pays and historical V0-V9 DDL intact. Both NOWPayments and NextPay/Zarinpal checkout issuance must use shared attempt persistence; provider verification remains specialized, and source-only PR work does not certify final fulfillment. The released RC4 includes historical NOWPayments-specific 002 SQL; the shared 003 was independently reviewed, merged in PR #44 and shipped with RC5. On 2026-10-17 only the identified Chili test database chilibot had 003 explicitly applied: 25 tables total, two new empty shared tables; 002 was NOT run. Other databases may be unmigrated or different. If any old RC4 SQL was applied elsewhere, it requires separate real-data audited migration, never automatic rename/drop/backfill. Consult docs/post-v9/PAYMENT-LEDGER-MIGRATION.md and Issue #26 for exact evidence. Neither installed finance acceptance nor further DB, Cron, rollout or release authorization is inherited from prior work.
- **CURRENT WALLET DEBT POLICY:** privileged administrators may deliberately set negative wallet balances as debt; no ordinary user-initiated purchase/transfer may create/deepen debt. Do not blanket-clamp wallet >= 0. Details and separate finance remediation under #26; no real money movements authorized.
- **Historical** Phase 1 V0–V9 was strictly a behavior-preserving PHP refactor over unchanged MariaDB; the prior byte-frozen contract below applies only to its pinned immutable history. Its original all-panel behavior is *not* a requirement of new post-V9 Marzban-only source.
- Preserve active webhook, payment/subscription, Telegram callback-data and Cron contracts; explicitly retired unused TRON/WeSwap entrypoints have no compatibility obligation.
- Keep local `baseInfo.php` untouched and excluded from Git; any evaluation of changes to its structure belongs to the LAST refactor increment.
- Build characterization tests and compare old vs new database side effects, external calls and messages before changing a high-risk flow.
- Keep small independent deliverable stages with targeted tests, required exact-candidate review and explicit operator test acceptance before starting the next stage **unless a scoped owner development waiver is recorded**. A waived operator test remains NOT TESTED, not PASS; waivers do not authorize integration, publication or deployment.
- For ambiguous bugs, preserve current behavior; track a fix independently rather than silently changing it.
- The **case-sensitive official product name is AChBot**, not AchBot. Use AChBot for all new project/release-facing text and source comments; lowercase `achbot` may be used as a technical slug. Do not rewrite frozen historical source/tests or change protocol identifiers solely for branding.
- Use AChBot naming for new internal structures. Do not mass-replace legacy `wizwiz` literals used in persisted Telegram callbacks or existing scheduled paths.
- Source of truth is code, issues, CI, project spec and verified environment; do not rely on ChatGPT memory.

## Frozen integrated V4 contract (historical reference)

- Integrated reviewed V3 reference:
  `283165f19777ad6ab62cfcb03de329c5bb0f8b74` / tree
  `b172f48120a6febf3ee654de2b50acb45ace6a1c`.
  Read `docs/v4/README.md`, TESTING.md,
  OPERATIONS.md, LEGACY-BEHAVIOR.md and Issue #10.
- Only six specified admin/agent regions in bot.php may
  be moved to src/Admin/* and src/Agent/*; the expected
  transformation is in `tools/v4_contract.py`.
  Preserve earlier V1/V2/V3 modules, the other 31 original
  runtime members, all 62 frozen historical evidence files,
  23-table original DDL and the real baseInfo.php.
- Preserve original top-level route positions, the exact
  original PHP bodies and SQL/bindings/callbacks, shared
  caller variables, mutable aliases, partial state, require
  reentry and existing legacy bugs. Never silently normalize
  authorization behavior or convert to a first-match router.
- Run full `python3 tools/check_v4.py` without
  --focused/--case; also run original-schema disposable
  MariaDB, real local cURL, direct HTTP and source-swap checks
  in `docs/v4/TESTING.md`. Never record new expected
  outputs merely to bless a changed runtime.
- Native DB and HTTP fixture processes must be strictly
  owned, socket/loopback only and cleaned. No host service
  change, existing database override, real credentials,
  real payment/VPN/Telegram call or production access.
- Build with `tools/package_v4.py --output <outside-repo.zip>`
  only from clean committed source. 38 runtime members only;
  no SQL, test tools, private state or live config.
- Independent exact-candidate author-separated review is a
  gate before V4 integration. Operator/FPM/provider staging
  and production rollout are separate gates. Previous CI or
  technical approvals do not automatically transfer.

## Current V5 broadcast and scheduled worker contract

- Controlling issue: #14; exact pinned V4 integration reference `ff81d1b8d8832ec17fea7b07dabebc37614d29f2` (tree `80a84c8dff6edd0754acd2d4f17d1211c2112267`).
- Move only original broadcast administration, both messagewizwiz scheduled processors and notification-warning segment; leave financial/wallet/auto-renewal behavior unmodified. Preserve callback bytes, 23-table DDL, old cron filenames, original `baseInfo.php`, 429 offset/retry behavior and checkpoints.
- Source/body exactness and old-file preservation: `tools/v5_contract.py`. Full frozen V0–V4 plus V5 native, SQL/Telegram differential, HTTP direct-guard, negative-control and file-swap checks: `python3 tools/check_v5.py`. `--focused` is not a release gate.
- Source-only runtime **43 tracked members** packaged from clean committed HEAD by `tools/package_v5.py --output <outside-repository-path.zip>` with full member SHA256/Git blob SHA1/HEAD comparison; no real `baseInfo.php`, DDL, tests, secrets or private state.
- Read `docs/v5/{README,TESTING,OPERATIONS,CRON-INVENTORY,LEGACY-BEHAVIOR}.md`. Exact-HEAD CI, separate author-independent HIGH_ASSURANCE review, owner merge and operator deployment approval are hard gates. No change to unrelated Issue #12 or inherited 429 loss semantics.

## V6 panel, account and subscription candidate (Issue #16)

- Owner authorized **isolated V6 development** despite pending V5 real operator acceptance in Issue #14. This is NOT V5 OPERATOR PASS and NOT merge/release/deploy authority.
- New V6 module extraction is pinned by `tools/v6_contract.py` to V5 merged main `38dcdfc0703911133d3bc4b5df145f46b0b7a070`; ten guarded internal PHP modules plus original legacy facades/route location. The original 23-table DDL, all V0–V5 frozen historical evidence, public `settings/subLink.php` and other sensitive runtime consumers stay byte-unchanged.
- Required stable full source gate: `python3 tools/check_v6.py --report <outside-repo-json> --evidence-dir <outside-repo-dir>`; development `--focused` is NOT acceptable as final CI. Full suite includes native original-schema socket MariaDB, localhost provider/sublink HTTP, old/new SQL/Telegram, negatives, and persisted source swap. ZIP must be 53 source members, built from exact clean committed Git HEAD by `tools/package_v6.py` and verified with `tools/verify_v6_package.py`.
- One author-separated exact-candidate HIGH_ASSURANCE independent review after full stable CI; explicit new owner integration/release/deployment gates. Real `baseInfo.php`, wallets, schemas, Cron and real panels/Telegram are outside development authorization. No inherited behavior/security fixes disguised as parity.

## V6.1 incident-driven backup maintenance (Issue #18)

- V6.1 is a narrow source-only backup reliability patch, **not** the proposed V7
  product-plan increment. Preserve all existing public Cron, webhook,
  payment/panel/subscription entrypoints, DB schema and user-visible behavior.
- The only authorized production-runtime code changes are the backup facade in
  `config.php`, backup scheduling in `settings/rewardReport.php`, and
  `src/Backup/Database.php`. No host-wide configuration, production DB/Cron,
  Telegram or real customer data changes are authorized.
- Read `docs/backup-hotfix/` and run the new exact-candidate source/history,
  synthetic, real isolated MariaDB/ZIP, packaging and CI gates. The old bot
  remains untouched; independent author-separated review and separate explicit
  integration/release/deployment approvals remain mandatory.

## External tool/output compatibility and operator evidence (V6.1 lesson)

- For parsers of external program output (MariaDB/MySQL dumps, HTTP/provider
  responses, dates/time formatting), obtain representative **actual** client
  output and add deterministic boundary fixtures for whitespace, padding,
  timezone/hour ranges, EOF and invalid/incomplete responses. Demonstrate a
  RED regression against the previous source before accepting a GREEN fix;
  never bypass fail-closed structural validation simply to silence logs.
- Preserve V6.1's **26** synthetic backup cases and PHP 8.1 native MariaDB/ZIP
  acceptance (plus PHP 8.4 full historical suites) when touching the backup
  or its consumers. Specifically cover MariaDB space-padded hours 0/4/9,
  malformed hours/minutes, truncated SQL, Telegram failure, concurrency and
  success-only LAST_BACKUP_TIME updates.
- Distinguish code/CI PASS, owner-reported real Telegram ZIP receipt, archive
  structural checks and actual isolated SQL **restore**. The owner chose to
  skip the latter for RC2: mark restore NOT TESTED, never silently PASS.
- Never directly execute `settings/rewardReport.php` as a backup-only smoke
  test; it also contains financial/reward side effects. The existing Telegram
  transport has its own potentially sensitive logs despite the new module's
  sanitized reason codes. Protect all owner credentials, SQL and runtime logs.
- V7 development is controlled by Issue #21 and separate owner integration/
  release/production gates. Preserve all real bots, live database schema,
  Cron registrations and `baseInfo.php` without explicit authorizations.

## V7 plans, checkout and provisioning (Issue #21)

- Development authority is isolated source-only. Do not touch production bot,
  PHP/MariaDB/aaPanel/Cron settings, actual panels, Telegram, wallets,
  transactions or `baseInfo.php`. No owner approval for V7 merge, publication
  or deployment is inherited from V6.1.
- `tools/v7_contract.py` pins the **exact** original V6.1 source to
  `ca0405442e98481ec2107772f5d2df94adb2b134`, extracts eight original
  scoped routes and three original helper groups mechanically, verifies
  original callback bytes/function parameters/persistence semantics and all
  54 existing runtime members. New internal modules have direct HTTP guards.
- Existing public `bot.php` and `buy_helpers.php` stay as compatibility
  loaders. The original wallet consumer, settlements, discounts, external
  payments, `pay/back.php`, reward/Tron/Cron, public `settings/subLink.php`,
  `config.php` backup footer fix, 23-table DDL and all previous goldens remain
  unchanged. Do not rewrite historical references or prices to gain parity.
- Development checks: `python3 tools/check_v7.py --focused` only for feedback.
  Exact PHP 8.4 gate: `python3 tools/check_v7.py --report /tmp/v7.json`
  including pinned V0–V6.1. PHP 8.1 gate:
  `python3 tools/check_v7.py --php81`. Both require owned disposable MariaDB,
  loopback HTTP, synthetic Telegram/wallet inputs and GD. Independent
  author-separated high-assurance review is strictly read-only and requires
  stable exact-HEAD GitHub Actions first.
- Exact source-only 65-member artifact is built only from clean, committed
  candidate HEAD by `tools/package_v7.py`, with manifest and
  `tools/verify_v7_package.py`. Operator V5/V6 remain NOT TESTED and V6.1
  actual MariaDB restore NOT TESTED; do not conflate those with source tests.
- Documentation, test matrix, source-only swap/rollback and operational gates:
  `docs/v7/`. Record new evidence and remaining gates in Issue #21 and PR.


## V9 final Phase 1 integration acceptance (Issue #27)

- Exact accepted previous source: merged V8 9ca6cabe43121a528db8d32c9b4aa1b5d10168ea (TREE 434f2d7aac6f23e0afb16b320782641aab81a6e0), public source-only RC1 v0.8.0-rc.1 with 92 runtime members. V9 is global integration acceptance, not redesign.
- The whole V8 runtime, tracked synthetic private example and original 23-table DDL stay byte-identical: tools/v9_contract.py and tools/v8_contract.py enforce this. Any genuinely required change must be root-cause-justified and independently reviewed, not silently waive the pinned baseline.
- Full acceptance: python3 tools/check_v9.py --report /tmp/v9.json --evidence-dir /tmp/v9-evidence under PHP 8.4, or python3 tools/check_v9.py --php81 under PHP 8.1. --focused is NOT full acceptance. Full untouched V8->V7->V0-V6.1 history mandatory.
- V9 synthetic native bootstrap tests 40 global facade signatures, loopback provider requests in two CWD contexts with complete 23-table row parity; physical full-source swap tests the 92-file source inventory, synthetic private files and unchanged schema. Do not confuse source proof with installed acceptance.
- Package from clean exact committed V9 HEAD with tools/package_v9.py and tools/verify_v9_package.py. Never package real baseInfo.php, production/customer DB data, secrets, credentials, backups, logs or test harness.
- No real wallet/payment/chain/provider/Cron/Telegram calls, DB migration or monetary policy change. Issue #26 inherited finance risks and all unperformed operator acceptance remain open. New fresh author-separated HIGH_ASSURANCE independent review, Owner merge, Owner publication and operator deployment gates remain separate.
