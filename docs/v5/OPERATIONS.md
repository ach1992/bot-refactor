# AChBot V5 — staging, code replacement and rollback

**Gates:** This is a proposed operator staging process, **not an instruction to deploy now**. V5 merge, production release/deployment, live DB edits or Cron changes remain unapproved until independent exact-candidate review and explicit owner approval. The V4 operator's positive source-only trial is not V5 acceptance.

## Before any cutover

1. Retrieve exact-head CI ZIP and `<zip>.manifest.json`. Check `sha256sum` against manifest `zip_sha256`, member list/count (43), SHA256 per member and Git blob mapping. Reject changed/unknown files, missing assets, any `baseInfo.php`, SQL/dump, log, token or private state in ZIP.
2. Privately verify the **existing application root** and all 38 prior runtime paths; preserve the existing `baseInfo.php` exactly and retain original MariaDB data/schema, installed Cron names/timing/user/CWD, webhooks, payment URLs and OPcache/FPM settings.
3. Back up **only relevant existing application source** under an operator-controlled private location with a rollback manifest and the currently running version/working tree identity. Never publish backups, config, logs, customer data or secrets. Do not blindly delete the application directory.
4. Inspect currently running `send_list` and `bc_jobs` offsets/states and scheduled processes using *authorized read-only* operations. Plan quiescence/atomic switch so old/new long-running workers do not overlap; record the actual production scheduler semantics before changing files. Never edit Cron globally as part of source swap.
5. Install new `src/` internal modules **before** replacing any entrypoint. Then atomically switch tracked runtime source files using the deployment's verified mechanism; do not replace `baseInfo.php`, operational config or private assets. Keep 35 unchanged older runtime files and all original scheduler file names. A source-only ZIP is a **file overlay**, not a destructive fresh installer.
6. Validate original webhook callbacks including already-sent keyboards, broadcast draft/history/admin roles, native cron startup, queue heartbeat/partial checkpoint behavior, and synthetic or staging Telegram responses in an isolated, consented environment. Verify `settings/error_log.txt` file behavior/path; check no unexpected source-only initialization or schema alteration.
7. For rollback, quiesce processes by the same verified method, restore the previously recorded **source files** (including original `bot.php`, `settings/messagewizwiz.php`, `settings/warnusers.php` and previous `src/` tree) while preserving `baseInfo.php`, MariaDB rows, queue checkpoints and Cron registration. Confirm old source can continue with the current schema, as tested in the V4↔V5 owned native DB rehearsal. Handle OPcache/FPM using the **observed** existing deployment policy; never assume CLI opcache instructions apply.

## Operator smoke and evidence record

Record exact release/ZIP manifest SHA, candidate HEAD/tree, source checkpoint at start, version before/after swap, known current Cron/PHP CWD paths, synthetic test fixtures, job/broadcast status, error log summary with no private contents, and rollback result. A successful automated test does not substitute for a human operator's live deployment decision.

Do **not** run `tests/`, `tools/`, synthetic API scripts or bundled DDL on the real application host. V5 ZIP intentionally excludes them. Keep any old `baseInfo.php` exactly as deployed, even though it is not inside the ZIP.
