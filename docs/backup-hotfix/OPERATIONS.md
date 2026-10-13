# AChBot V6.1 backup — safe operator guidance and evidence boundaries

**Published source:** [`v0.6.1-rc.2`](https://github.com/ach1992/bot-refactor/releases/tag/v0.6.1-rc.2). Owner reports the affected V6.1 bot received a real Telegram backup after installation. **Restoration was explicitly not tested** at the owner's request; do not call that a recovery PASS. See [Issue #18](https://github.com/ach1992/bot-refactor/issues/18). Separate older working bots are out of scope and must be left unchanged.

## Before any future operator change

1. Establish the **specific application root**, actual Cron wrapper/user/CWD and aaPanel PHP CLI version without exposing source paths, tokens, client configuration or DB credentials in public logs. Never infer one bot's deployment from another's.
2. Preserve the installation's real `baseInfo.php`, 23-table DB/schema/data, `setting.LAST_BACKUP_TIME`, wallets/orders, PHP/webhook URLs and Cron definitions. Before any authorized patch, prepare a **private source-only rollback** and verify the affected module against its official release manifest (not GitHub's broad source archive).
3. Do not run `settings/rewardReport.php` manually as a backup smoke test: the same script performs rewards and payment auto-approval. Do not reinstall system DB packages, create global mysqldump symlinks or change Cron `PATH` to solve a source-only bug; avoid overlapping old/new Cron/FPM workers on cutover.
4. Verify the previous application's old `backup/` directory is **not publicly HTTP-downloadable**. The V6.1 private scratch directory is outside the webroot; previous webroot SQL files are NOT automatically removed. Protect sensitive ZIPs and logs; never paste tokens, SQL, `baseInfo.php`, customer records or full Telegram transport dumps into an issue.
5. Check only the **normal scheduled** backup outcome. A successful bot response means the document was accepted for Telegram delivery; receipt of a ZIP is still distinct from full restorability or long-term backup retention. Sanitize `[AChBot backup]` reason codes if needed; the unchanged Telegram transport may separately log chat IDs, cURL errors and ZIP paths.
6. **Optional recovery assurance, only if newly authorized:** restore the *received* ZIP into a privately owned **isolated** MariaDB database and verify expected tables/counts without publishing data. The owner explicitly declined this experiment for the RC2 incident and authorized moving forward; leave restoration **NOT TESTED**, not PASS. Never restore this file into the production DB merely as a test.
7. If the patch is operationally defective, coordinate with the owner: quiesce the affected work safely, restore **only the previous application source files**, then resume the known original Cron/FPM behavior. Never roll back the live database, `baseInfo.php`, wallet/order balances, a payment settlement or another working bot as part of source rollback.

## Documented interpretation

- `source/CI pass`: exact candidate code and disposable integration checks succeeded, not proof of owner environment.
- `delivery observed`: owner confirmed a ZIP reached the intended Telegram channel for the affected bot after RC2.
- `archive structural pass`: uploaded ZIP was previously reported to contain parseable SQL and a complete dump footer; does not prove restore.
- `restore not tested`: no isolated MariaDB import/verification performed; owner explicitly chose to proceed anyway.

Changes to real bots, financial processing, live schema/cron, or system configuration always require separate owner authority. V7 work is restricted to isolated source development under [Issue #21](https://github.com/ach1992/bot-refactor/issues/21).
