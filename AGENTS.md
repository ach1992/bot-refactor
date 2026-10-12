# AChBot repository rules

- This is a public repository: never commit customer data, deployment secrets, raw SQL dumps, runtime backups or logs.
- Phase 1 is strictly a behavior-preserving PHP refactor over the unchanged legacy MariaDB schema. DO NOT change the DB, run migrations or alter observable admin/customer behavior.
- Preserve current webhook, payment/subscription, Telegram callback-data and Cron file-path contracts until validated compatibility adapters exist.
- Keep local `baseInfo.php` untouched and excluded from Git; any evaluation of changes to its structure belongs to the LAST refactor increment.
- Build characterization tests and compare old vs new database side effects, external calls and messages before changing a high-risk flow.
- Keep small independent deliverable stages with targeted tests, required exact-candidate review and explicit operator test acceptance before starting the next stage.
- For ambiguous bugs, preserve current behavior; track a fix independently rather than silently changing it.
- The **case-sensitive official product name is AChBot**, not AchBot. Use AChBot for all new project/release-facing text and source comments; lowercase `achbot` may be used as a technical slug. Do not rewrite frozen historical source/tests or change protocol identifiers solely for branding.
- Use AChBot naming for new internal structures. Do not mass-replace legacy `wizwiz` literals used in persisted Telegram callbacks or existing scheduled paths.
- Source of truth is code, issues, CI, project spec and verified environment; do not rely on ChatGPT memory.

## Current V4 contract and gates

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
