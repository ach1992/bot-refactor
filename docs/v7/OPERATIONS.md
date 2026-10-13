# V7 source-only candidate and rollback — owner/operator-gated

**This document is not an instruction to install on any live bot.** The
owner has authorized isolated code development and synthetic testing only.
A new explicit owner approval is required before PR integration, release,
real staging installation or production changes. The unrelated working bot
must remain untouched. No real `baseInfo.php`, database, provider, Telegram
identity, wallet/payment account, host Cron, or PHP-FPM configuration may be
changed as part of this source development.

## Exact candidate verification (safe offline)

1. Open the candidate PR and check HEAD SHA, tree SHA, two clean exact-HEAD CI
   jobs and independent author-separated review verdict. If SHA changes,
   obtain a fresh exact-HEAD run and review; never use an old green run.
2. Obtain `achbot-v7-runtime.zip` and `.manifest.json` from matching CI run.
   Compare `candidate` to the reviewed commit, `tree` to Git, zip SHA256,
   65 ordered member paths, each size/SHA256/blob SHA1, and expected Git blobs.
   Use `tools/verify_v7_package.py`; reject untracked files or other content.
3. Verify every member is **source only**; the artifact excludes DB dump,
   wallet/customer/Telegram information, real `baseInfo.php`, secrets,
   generated media, runtime backups and logs. No MariaDB migration or table
   modification is part of V7. Confirm V6.1 backup fix is still identical.

## Authorized controlled source swap (after *separate* owner gate)

1. On an **owned isolated staging clone only**, identify the actual active
   root, existing V6.1 source version and MariaDB schema, owner-controlled
   credentials (do not print them), PHP CLI/FPM GD/mysqli/cURL/ZIP extensions,
   OPcache behavior, file ownership and actual entrypoint/Cron paths. Make
   verifiable private **prior source-only** copy and reversible switch point.
2. Freeze new requests and overlapping workers only by the operator-approved
   application-specific method. Do not modify live Cron, run rewardReport.php,
   or execute financial settlement as a smoke test. Keep existing database
   state and schema, `baseInfo.php` and other private files untouched.
3. Stage eleven new `src/Plans/*` and `src/Purchase/*` guarded modules **first**.
   Validate their Git blobs. Then stage remaining 54 existing runtime members
   together and perform the smallest safe/atomic source switch possible for
   the observed operator environment. Do **not** unzip blindly over a live
   directory or overwrite private material. New public paths are not added.
4. In disposable staging test actual sent original callbacks and persisted
   `users.step`, customer single and agent multiple purchases, free/trial
   eligibility, admin provisioning, stock/order rows, plan/category settings,
   Marzban/X-UI adapter behavior, public subscription endpoint and failure
   paths. Verify no unauthorized wallet/price changes and no accidental Cron
   execution. Record separate operator PASS/FAIL/NOT_TESTED and redacted logs.
5. Confirm actual PHP-FPM and OPcache code identity using the observed host
   release policy (no blanket restart), monitor errors, preserve private file
   hashes and database identity. Do not assume GitHub CI alone implies live
   compatibility or restore success.

## Source-only rollback

At an operator-approved safe switch point, restore the **prior V6.1 source
files** from verified original source package, including `bot.php`,
`buy_helpers.php` and unchanged `config.php`/`settings`/`pay` members. The
new guarded V7 modules may remain dormant and unreferenced; do not delete
unknown paths. Do **not** restore an older MariaDB database, alter 23-table
schema, overwrite wallets/payments, edit `baseInfo.php`, or rewrite Cron
registrations as part of rollback. Perform the environment-specific OPcache
refresh policy and validate the old webhook, original paid subscription,
subscription link and scheduled task boundaries using operator-approved tests.

`tools/test_v7_swap.py` demonstrates a real **owned** MariaDB persisted-order
V6.1→V7→V6.1→V7 source overlay without touching private sentinel or restoring
SQL. This is not a substitute for operator acceptance on an actual host.

## Gates currently not satisfied by source development alone

- V5 Issue #14: operator acceptance **NOT TESTED**.
- V6 Issue #16: operator acceptance **NOT TESTED**.
- V6.1 Issue #18: owner-reported successful Telegram ZIP delivery; full
  MariaDB restoration **NOT TESTED** by explicit owner choice.
- V7: independent HIGH_ASSURANCE read-only review and owner merge/release/
  deployment permission must be recorded separately after exact-HEAD CI.
