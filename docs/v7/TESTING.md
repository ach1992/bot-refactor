# V7 isolated test/acceptance contract

**Never run against a deployed bot, real `baseInfo.php`, production MariaDB,
actual panel/Telegram, payment account, wallet or live Cron.** Dedicated
unprivileged runner with PHP 8.1 / PHP 8.4 (mysqli, mysqlnd, curl, gd, zip),
MariaDB CLI/daemon utilities and Python 3.12 is required.

## Main gates

```bash
# Fast development feedback only; NOT release-ready acceptance
python3 tools/check_v7.py --focused --report /tmp/v7-focused.json

# PHP 8.4 complete V7 + pinned full V0-V6.1 source/history/native regressions
python3 tools/check_v7.py --report /tmp/v7-full.json --evidence-dir /tmp/v7-evidence

# PHP 8.1 runtime-specific native E2E/backup ZIP compatibility
python3 tools/check_v7.py --php81 --report /tmp/v7-php81.json

# After committing the complete candidate with a clean exact HEAD:
python3 tools/package_v7.py --output /tmp/achbot-v7-runtime.zip
python3 tools/verify_v7_package.py --zip /tmp/achbot-v7-runtime.zip --manifest /tmp/achbot-v7-runtime.zip.manifest.json
```

- `v7_contract.verify` mechanically compares all moved source bytes with the
  exact V6.1 Git baseline; all other 54 existing runtime bytes are unchanged
  except the two approved loader files (`bot.php`, `buy_helpers.php`). It
  byte-checks the immutable schema, frozen historical tests, original payment,
  Tron, reward/Cron, subscription endpoint and V6.1 backup implementation.
  It prohibits extra/absent runtime PHP members and unexpected symlinks.
- `test_v7_facades`: 24 legacy global helper signatures, parameter names,
  defaults and return types compared old vs new with real PHP reflection.
- `test_v7_native --run-disposable`: real original-schema **socket-only 23-table
  MariaDB** (two independent DBs), real PHP mysqli and cURL, owned 127.0.0.1
  Marzban HTTP server, synthetic Telegram/update/role state. Exactly **47
  steps / 18 scenarios**: customer paid-wallet purchase and account creation,
  agent one and two-account purchases, custom plan wizard, free/trial and replay
  denial, admin two-account batch, admin category create/edit and plan price
  edit/draft, daily/volume management visibility, unauthorized admin access,
  invalid category, invalid upstream credentials, upstream 503. At every step
  compare old/new ordered Telegram events and upstream HTTP method/path/headers/
  body; after every step compare persisted users, pays, orders, plans,
  categories and capacity; assert expected real terminal outcomes. Real
  payment settlement policy is original unchanged source, tested only against
  disposable seeded fake wallet balances.
- `test_v7_mutation --run-disposable`: **five** deliberate changes applied to
  disposable copies only; confirm tests detect wrong callback, reversed stock
  SQL, rejected stock, wrong free-trial callback, wrong provider endpoint.
- `test_v7_swap --run-disposable`: create an original V6.1 paid subscription
  against a local provider and persistent original-schema MariaDB, overlay all
  source members in **V6.1 → V7 → V6.1 → V7** order, verify original Telegram
  response and paid order unchanged, all 23 `SHOW CREATE TABLE` identities
  (only MariaDB dynamic auto-increment counters ignored), private sentinel
  files preserved, no SQL rollback/snapshot restore, dormant modules safe.
- `test_v7_http`: every one of eleven new internal modules returns an empty
  **404** for both GET/POST (22 localhost requests). Public endpoints are
  unchanged bytes and retain historical HTTP checks.
- `check_v7`: real `php -l` of all **64** current PHP runtime members; for
  full 8.4 acceptance it creates a temporary *exact Git archive* of the
  pinned V6.1 HEAD with read-only Git object alternates, runs the unchanged
  V6.1 `check_backup_hotfix.py` including all historical V0–V6 modules and
  V6.1 MariaDB/ZIP/footer/negative checks. PHP 8.1 gate separately runs
  pinned V6.1 native backup compatibility and native-ZIP fixture.
- `package_v7/verify_v7_package`: clean exact committed HEAD only, source-only
  65-member deterministic ZIP, pinned file allowlist, per-member SHA256 and Git
  blob SHA1, commit/tree match, archived byte identity, no `baseInfo.php`,
  SQL/private data/logs/backups, no artifact path traversal, no false approvals.

CI uses the existing 2-job `Compatibility` workflow, exact PR HEAD checkout,
PHP 8.1+8.4 with native GD/ZipArchive/mysqli, owned temporary MariaDB and
loopback-only provider, uploaded ZIP, manifest and JSON evidence. Both jobs
must finish successfully **on the final unmodified PR HEAD**. Any new commit
invalidates prior review/CI acceptance and requires exact-candidate rerun.

## Disclosures and manual gates

Synthetic provider/local HTTP does **not** certify real VPN panel versions,
Telegram delivery/rate-limits, production Cron/CWD, PHP-FPM/OPcache, payment
provider settlement, concurrency at scale, live credentials, real backups,
actual MariaDB restore, or source installation. No running of
`settings/rewardReport.php` on production. V5/V6 operator acceptance remains
open; this test pipeline is not authorization to merge, tag, publish or deploy.
Only one fresh author-separated, strictly read-only HIGH_ASSURANCE review of
stable exact candidate after final CI; then obtain new owner authority for
consequential integration and later production actions.
