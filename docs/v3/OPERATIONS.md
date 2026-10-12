# V3 staging source and code-only rollback

This is a 32-file runtime source package, not a production deployment, DB installation or migration. Build from the clean committed candidate only:

```sh
python3 tools/package_v3.py --output /tmp/achbot-v3-runtime.zip
```

The adjacent manifest records commit, tree, ZIP SHA-256 and every member's SHA-256. The builder rejects dirty state, an existing output, symlinked members or output inside the repository. Tests/tools, SQL, real `baseInfo.php`, backups, cache/logs and private state are excluded. The eight earlier modules and six new support modules are all included. `baseInfo.example.php` is not a replacement for the real configuration.

## Stage and verify

1. Use a non-production bot/environment and synthetic data with the same supported PHP/SAPI, extensions and DB contracts. Record candidate/tree/hash, actual versions, scheduler working directories, permissions, web routing and process/OPcache handling. Keep secrets and customer information out of public reports.
2. Quiesce staging requests/workers or use the deployment's established atomic source switch. Preserve local configuration, current DB, writable state, missing-from-upload assets and ownership/permissions.
3. Copy and verify **all `src/` dependencies before activating the matching `bot.php`**. Keep `bot.php`, `config.php`, `buy_helpers.php` and the modules from one package. Six support handlers are intentionally scoped includes; do not wrap them in functions, move the include positions or change them to `require_once`.
4. Apply the established OPcache/process handling and syntax/hash checks, then resume staging. No webhook re-registration, Cron change, schema import, data conversion or configuration change is required by V3.
5. Exercise an existing title/body or reply wizard; create text/photo tickets; user open/all lists; reply; close/rate; administrator categories, queues and pagination; administrator text/photo reply; history and authorized attachment retrieval; cancellation and old keyboards. Compare text, callback payloads, recipients, SQL state and ordering, not just HTTP success. Include unrelated menu/purchase/renewal/worker smoke checks. See `LEGACY-BEHAVIOR.md` before interpreting a pre-existing defect as a new regression.
6. Verify direct GET/POST execution of the new support files produces empty 404 responses in the actual deployment topology. The code includes a direct-script guard; source inspection and the owned PHP-server test do not replace web-server configuration checks.
7. Record actual tested scenarios/outcomes and any sanitized mismatch against this exact tree in Issue #8. Leave untested cases unverified. Earlier #4/#6 deployment evidence is not automatically completed by these new tests.

## Rollback

Quiesce requests/workers and restore the previous integrated V2 runtime from `893032ef217fe89fa1d46b06a5c3368b8f4fb63e`. Restore **code only**, not an old SQL dump. Preserve the current database and `baseInfo.php`/private assets/state. Unused V3 support files may remain; do not recursively delete a potentially unrelated `src/` directory.

Apply the normal process/OPcache procedure, then repeat the same checks, particularly a ticket/reply wizard started on the new source. The automated native test proves selected support-wizard continuity across code replacement on synthetic MariaDB data; it does not rehearse production FPM or provider effects. Code rollback cannot retract notifications or undo previous completed transactions. A production rollout requires its own explicit authorization.
