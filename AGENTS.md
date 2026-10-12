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

## Reference and current development checks

- Read `docs/v2/README.md`, `docs/v2/TESTING.md` and the frozen V0 testing notes before changing the harness. Run `python3 tools/check_v2.py`; it runs the frozen V1/V0 gates and compares the current Telegram boundary. The original gates are not rerun against changed runtime files directly. For the native gate use `python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v0-schema.json` with existing prerequisites only.
- `tests/fixtures/baseline-lock.json` anchors 19 original runtime/reference files to the imported Git commit. V0 must not edit them. In later increments, preserve an immutable old implementation/reference and compare the candidate; do not re-record golden output merely to bless changed behavior.
- Keep all tests in a development checkout. Native DB tests create only their own private socket/datadir and remove only that instance; never add an existing DB/production endpoint override.
- V0/V1 catalogs, tests and traces remain immutable. Do not run recording/regeneration to accept V2 changes. The current envelope in `tools/v2_contract.py` verifies the exact bounded extraction and unchanged V1/V0 evidence; previous envelopes run in pinned reference trees.
- Snapshot recording is a separate reviewed maintainer action. Normal CI compares committed traces and known SQL failures; it must never update them.
- Run mutation controls, private behavioral traces, native schema/transaction checks and exact-candidate independent review. Preserve operator acceptance before starting the next increment; no production acceptance follows from mocked or CLI checks.

## Current package boundary

- Transport/message modules have no eager network/database I/O. `UpdateBindings.php` intentionally executes the unchanged assignments in the caller scope at the old bootstrap position. Preserve wrapper signatures, lazy loading, scope and ordering. No schema, callback, payment-policy or configuration changes belong to this increment.
- Use `tools/package_v2.py --output <outside-repository.zip>` only from a clean committed checkout. It builds staging source, not a deployment or accepted release. Tests/tools/private state are not runtime package members.

## V2 checks

- Keep all V0/V1 fixtures, tooling and module bodies immutable; V2 extends the driver rather than re-recording baselines. Read `tools/v2_contract.py` for the exact change boundary.
- Run `python3 tools/test_native_telegram.py --run-loopback --report /tmp/achbot-v2-http.json` only against its parent-owned loopback fixture; no external URL override or real token is permitted.
- Run `python3 tools/rehearse_swap.py --report /tmp/achbot-v2-swap.json`. Its private file sentinels and CLI smoke are not real database continuity, FPM/OPcache or manual operator acceptance.
- Keep explicit owner progression decisions and outstanding acceptance in the controlling issues. A progression instruction is not evidence that a manual test ran, and independent approval of a prior stage never approves the next.
