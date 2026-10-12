# AChBot — scheduled entrypoint and CWD inventory (V5)

Inventory derived from tracked PHP source. **Actual active Cron schedule, runner user, `cwd`, PHP executable, locking/overlap and PHP-FPM/OPcache behavior are not present in this public repository and are NOT verified.** Preserve existing names and registrations; this inventory never authorizes editing host-wide Cron. All paths are relative to the deployment application root.

| Entry path | Boot/include evidence | Work/side effects | V5 treatment / CWD hazard |
|---|---|---|---|
| `settings/messagewizwiz.php` | `__DIR__/../baseInfo.php`, `__DIR__/../config.php`, `__DIR__/jdf.php`; then `src/Jobs/*` via anchored `__DIR__` | Rate cache refresh, `bc_jobs` pin/unpin/edit/delete, `send_list` bulk Telegram, offset/heartbeat/shutdown; error log `settings/error_log.txt` | **Refactored internal processors**, filename unchanged; parent `config.php` still depends on operational CWD for `settings/values.php` |
| `settings/warnusers.php` | `__DIR__/../baseInfo.php`, `__DIR__/../config.php`; V5 notification module via `__DIR__` | Expiry-warning notices, panel reads/deletion, `warnOffset`/`AutoRenewCheck`, conditional wallet/subscription auto-renewal | **Notification-only extraction**; monetary/renewal portion remains byte-identical in original entrypoint. Do not run with real panel/wallet data during testing |
| `settings/gift2all.php` | `__DIR__/../baseInfo.php`, `__DIR__/../config.php` | `gift_list` batches, panel provisioning, stored offset/capacity changes | Inventory only; unmodified |
| `settings/rewardReport.php` | `__DIR__/../baseInfo.php`, `__DIR__/../config.php`; relative `../phpqrcode/qrlib.php`, `temp.txt` | Rewards, reports, payment acceptance, configuration provisioning | Inventory only; **CWD-sensitive relative includes and file writes** remain unmodified |
| `settings/tronChecker.php` | `__DIR__/../config.php`; relative `../settings/temp.txt` | TRON transaction verification, payment/provisioning, remote TRON API | Inventory only; **CWD-sensitive**; does not directly include `baseInfo.php` but `config.php` requires it |
| `settings/subLink.php` | `__DIR__/../baseInfo.php`, `__DIR__/../config.php`; opens its own mysqli | HTTP token-based subscription endpoint, possibly panel mutation | **HTTP entrypoint, not confirmed Cron**; preserved, unmodified |

Supporting runtime surface: `config.php` includes `settings/values.php` and `settings/jdf.php` by relative path, then `baseInfo.php`. `settings/jdf.php` is a date library, not a separately scheduled processor. Webhooks `bot.php`, `pay/`, health and public subscription paths retain their existing route/response semantics. The V5 archive includes all previous 38 runtime paths **plus** 5 new internal paths without renaming existing scheduled files.

## Operator-only evidence to collect before staging approval

- `crontab -l` for **the correct existing service user**, sanitized of secrets; any panel-managed Cron schedule and working directory, without printing live `baseInfo.php`.
- Real path to PHP binary, relevant extensions, timezone, CLI/FPM version, active deployment root and OPcache invalidation mechanism.
- Actual cron overlap/lock policy, work progress at cutover (`state`, cursor/offset values), any running jobs, owner-approved downtime/rollback arrangement.
- Test-scope audit: no live bot, customer data, real payment API, panel, or third-party webhook is ever touched by automated V5 acceptance.

**Do not assume** `cd` into `settings/` is safe for `config.php` relative includes. Preserve the existing observed runtime invocation and CWD until privately tested.
