# AChBot repository rules

- This is a public repository: never commit customer data, deployment secrets, raw SQL dumps, runtime backups or logs.
- Phase 1 is strictly a behavior-preserving PHP refactor over the unchanged legacy MariaDB schema. DO NOT change the DB, run migrations or alter observable admin/customer behavior.
- Preserve current webhook, payment/subscription, Telegram callback-data and Cron file-path contracts until validated compatibility adapters exist.
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
