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

- Read `docs/v1/README.md`, `docs/v1/TESTING.md` and the frozen V0 testing notes before changing the harness. Run `python3 tools/check_v1.py`; it runs the original `tools/check.py` inside a verified immutable V0 snapshot. For the native gate use `python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v0-schema.json` with existing prerequisites only.
- `tests/fixtures/baseline-lock.json` anchors 19 original runtime/reference files to the imported Git commit. V0 must not edit them. In later increments, preserve an immutable old implementation/reference and compare the candidate; do not re-record golden output merely to bless changed behavior.
- Keep all tests in a development checkout. Native DB tests create only their own private socket/datadir and remove only that instance; never add an existing DB/production endpoint override.
- V0 catalogs, tests and traces remain immutable. Do not run recording/regeneration to accept V1 changes. The V1 envelope in `tools/v1_contract.py` verifies the exact bounded extraction and unchanged reference evidence.
- Snapshot recording is a separate reviewed maintainer action. Normal CI compares committed traces and known SQL failures; it must never update them.
- Run mutation controls, private behavioral traces, native schema/transaction checks and exact-candidate independent review. Preserve operator acceptance before starting the next increment; no production acceptance follows from mocked or CLI checks.

## V1 package boundary

- New modules have no eager runtime I/O. Preserve legacy wrapper signatures and lazy `__DIR__` loading. No schema, callback, payment-policy or configuration changes belong to this increment.
- Use `tools/package_v1.py --output <outside-repository.zip>` only from a clean committed checkout. It builds staging source, not a deployment or accepted release. Tests/tools/private state are not runtime package members.
