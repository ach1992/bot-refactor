# V4 non-production source-only swap and code rollback

Build a complete **38-file** runtime ZIP from a clean committed V4
candidate (not from a dirty working tree):

```
python3 tools/package_v4.py --output /tmp/achbot-v4-runtime.zip
```

The adjacent manifest includes commit/tree, ZIP SHA-256 and all per-file
SHA-256 values. It excludes real `baseInfo.php`, SQL, tests,
tools, secrets, caches, backups, private state and logs.
`baseInfo.example.php` is only an example.

## Staging gate

1. Use a separately authorized non-production bot and synthetic/authorized
   fixture rows. Record the precise candidate, tree and ZIP hash, SAPI,
   PHP/FPM/OPcache behavior, MariaDB schema identity, web-root routing,
   file permissions, scheduler working directories and live-asset paths.
   Never post credentials or customer details to GitHub.
2. Quiesce webhook/workers or use established atomic-source switching.
   Preserve **current database, real baseInfo.php, assets and writable
   state** without modification.
3. Copy/check **all src modules first**, including V1/V2/V3 modules and
   the six new V4 modules. Only then activate matching `bot.php`
   and other root runtime files from the same archive.
   Preserve original `require` positions and reentry behavior.
   No incomplete mixed source activation.
4. Perform hash/syntax validation and deployment-specific process/
   OPcache handling; resume staging. No schema import, data migration,
   webhook re-registration, Cron rename or config change.
5. Test as primary and delegated administrators, ordinary user and agent:
   admin grants/revokes, numeric-string owner edge, ban/unban, agent
   requests/approvals/declines/rejection release, discount editing,
   plan/server pages and old sent callbacks. Continue a wizard begun before
   code activation and another begun on V4 then rolled back.
   Observe exact state writes, destinations, text and keyboard data.
   Include unrelated support, purchase, renewal, payment and worker smoke.
6. GET/POST directly on six new modules in the actual web-server topology
   must yield empty HTTP 404. PHP dev-server evidence is not FPM proof.
7. Record actual sanitized results and missing cases in Issue #10 tied
   to candidate/tree. Do not silently count skipped operator cases as pass.

## Rollback

Quiesce workers, restore **code only** from the integrated V3 commit:
`283165f19777ad6ab62cfcb03de329c5bb0f8b74`.
Retain the **current** MariaDB rows/schema, real `baseInfo.php`,
private assets and runtime state. Do not import an old SQL dump.
New modules may be left unused; do not recursively delete an unrelated
`src/`. Repeat hashes, process/OPcache handling and the same
manual checks including pending agent/admin wizard states.

The native synthetic DB test demonstrates selected V4->V3 continuity,
not a guarantee for all production conversations; code rollback cannot
undo Telegram/provider messages already sent. Production deployment
requires a distinct explicit authorization and rollout decision.
