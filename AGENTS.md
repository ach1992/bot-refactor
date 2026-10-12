# AchBot repository rules

- This is a public repository: never commit customer data, deployment secrets, raw SQL dumps, runtime backups or logs.
- Phase 1 is strictly a behavior-preserving PHP refactor over the unchanged legacy MariaDB schema. DO NOT change the DB, run migrations or alter observable admin/customer behavior.
- Preserve current webhook, payment/subscription, Telegram callback-data and Cron file-path contracts until validated compatibility adapters exist.
- Keep local `baseInfo.php` untouched and excluded from Git; any evaluation of changes to its structure belongs to the LAST refactor increment.
- Build characterization tests and compare old vs new database side effects, external calls and messages before changing a high-risk flow.
- Keep small independent deliverable stages with targeted tests, required exact-candidate review and explicit operator test acceptance before starting the next stage.
- For ambiguous bugs, preserve current behavior; track a fix independently rather than silently changing it.
- Use AchBot naming for new internal structures. Do not mass-replace legacy `wizwiz` literals used in persisted Telegram callbacks or existing scheduled paths.
- Source of truth is code, issues, CI, project spec and verified environment; do not rely on ChatGPT memory.

## Current checks and references

- Read `docs/v3/README.md`, `TESTING.md`, `OPERATIONS.md` and `LEGACY-BEHAVIOR.md`. Run `python3 tools/check_v3.py` and all additional native/HTTP/swap checks listed there on the final candidate. Focused options are for authoring only.
- Keep V0/V1/V2 tools, tests, goldens, provenance and docs immutable. The current driver executes frozen references via existing Git objects; never re-record expectations to bless a refactor. `tools/v3_contract.py` defines the current source envelope.
- Only the six specified support regions in bot.php may be extracted in V3. Keep all prior runtime files/modules, schema, callback bytes and real configuration fixed. Scoped handlers require caller scope, original include positions, `require` reentry and existing exit/fall-through behavior.
- Keep tests out of deployed web roots. New support modules have direct-script guards; normal inclusion must preserve caller state. Do not introduce arbitrary input extraction or a first-match dispatcher.
- Native tests own their private temporary DB/socket/datadir or loopback listeners and must remove only those resources. Never add an existing production endpoint override, use real credentials or install host packages as an incidental test fix.
- `tools/test_support_state.py` proves selected support wizard continuity with real synthetic MariaDB rows, not whole-webhook/FPM/provider/manual acceptance. `tools/rehearse_v3.py` is file-copy/CLI smoke with private sentinels, not a database test.
- Build with `tools/package_v3.py --output <outside-repository.zip>` from a clean committed checkout only. The fixed 32-file staging package excludes tests/tools/SQL/private configuration/state. Verify the actual artifact against its manifest and Git objects.
- Preserve owner progression decisions and unresolved operator evidence in their issues. Previous technical approvals and passing CI never approve the next candidate or authorize deployment.
