# V1 staging package, file swap and rollback

**Not a production deployment authorization.** Use the non-production bot/environment first. The artifact is a full runtime source package, not a database installation or a verified end-to-end bot release.

## Package construction

From a clean committed development checkout after the full checks:

```sh
python3 tools/package_v1.py --output /tmp/achbot-v1-runtime.zip
```

The ZIP has a fixed 23-file allowlist, deterministic member ordering/timestamps, and includes the five new `src/` modules. `baseInfo.example.php` is only an example. The real `baseInfo.php`, original SQL/row data, schema reference, tests, tools, logs, caches, backups, local files and untracked content are excluded. The adjacent manifest records candidate commit, Git tree, ZIP SHA-256 and every member's SHA-256. The builder refuses a dirty checkout, an output inside the repository or an existing output. Outputs do not install or start anything.

Existing QR/background assets and private configuration/state absent from the original upload are still prerequisites. The package deliberately does not invent, remove or replace them. Do not mistake their exclusion for permission to delete existing files.

## Staging test procedure

1. Record the exact candidate and manifest hash. Use a synthetic/non-production database with the same schema and a separate test bot/panel/payment setup. Preserve the current private configuration and scheduler inventory outside the public repository; never post tokens or real customer data.
2. Quiesce the staging webhook and scheduled workers, or use the deployment's existing atomic source-switch method. Preserve the local `baseInfo.php`, runtime assets, permissions and writable files. This package requires no database migration, configuration edit, URL re-registration or Cron rename.
3. Stage and verify all five `src/` files **before** activating the two wrapper-containing files. A partial copy with missing modules will fail. Keep `config.php`, `buy_helpers.php` and `src/` from the same candidate. Restore the established service/OPcache handling for that staging deployment; the project does not assume a particular web-server/FPM topology.
4. Resume staging only after file hashes and syntax checks pass. Compare the following with the old version using the same fixtures: normal/admin/agent menus; old sent buttons; support link and nested text; receipt and gift-card/cancel keyboards; primary/backup subscription links; displayed traffic just below, at and above one GiB. Also run normal/duplicate purchase and renewal, insufficient balance and provider failure smoke checks. No intended business behavior changes are permitted.
5. Record candidate SHA, PHP/MariaDB/SAPI, tested scenarios/outcomes and sanitized mismatches in Issue #4. Mark untested paths explicitly. Independent review and operator acceptance are required before treating V1 as accepted; the package manifest does not claim either.

## Rollback test

Quiesce staging writes/workers and restore the previous source files from accepted V0 `e44451714384a4946009c9c8636e36f011aa2094`. Restore **code only**, keeping the current database and private configuration/state. The new modules can remain unused; do not delete an unrelated existing `src` directory. Restore the deployment's ordinary OPcache/process handling and replay the same smoke checks, including a conversation started on the candidate.

There is no schema/data reversal: ordinary transactions that occurred while testing are not automatically undone by a code rollback. Live messages or external payment/panel side effects likewise need their own safe test setup. A successful CLI suite is not proof that a production rollback has been rehearsed.

## Report back

Provide the exact candidate SHA, environment versions, a pass/fail result for the listed scenarios, rollback result, and sanitized error text for failures. Do not include real `baseInfo.php`, customer records, payment credentials or private subscription URLs.
