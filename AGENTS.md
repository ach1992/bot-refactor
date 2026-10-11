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

## V0 development checks

- Read `docs/v0/README.md` and `docs/v0/TESTING.md` before changing the harness. Run `python3 tools/check.py`; for the native gate use `python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v0-schema.json` with existing prerequisites only.
- `tests/fixtures/baseline-lock.json` anchors 19 original runtime/reference files to the imported Git commit. V0 must not edit them. In later increments, preserve an immutable old implementation/reference and compare the candidate; do not re-record golden output merely to bless changed behavior.
- Keep all tests in a development checkout. Native DB tests create only their own private socket/datadir and remove only that instance; never add an existing DB/production endpoint override.
- Generated catalogs: `python3 tools/inventory.py --write`; review resulting source locations and regenerate only after intentional analyzer changes.
- Snapshot recording is a separate reviewed maintainer action. Normal CI compares committed traces and known SQL failures; it must never update them.
- Run mutation controls, private behavioral traces, native schema/transaction checks and exact-candidate independent review. Preserve operator acceptance before starting the next increment; no production acceptance follows from mocked or CLI checks.
