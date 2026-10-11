# Legacy system inventory (static inspection only)

## Runtime boundaries

| Entrypoint or file | Observed role |
|---|---|
| `bot.php` | Telegram updates, commands, callback handling, admin/client flows |
| `config.php` | Shared functions, persistence connection/helpers, Telegram/VPN clients, business logic |
| `buy_helpers.php` | Purchase, renewals, account provisioning and financial helpers |
| `baseInfo.php` | Runtime-only deployment secrets and settings, NOT tracked |
| `pay/index.php`, `pay/back.php` | Payment start and return/callback |
| `settings/warnusers.php` | Warnings, renewal/maintenance scheduled processing |
| `settings/messagewizwiz.php` | Broadcast jobs; filename may be referenced by Cron |
| `settings/tronChecker.php` | Crypto payment checking |
| `settings/gift2all.php` | Gift services |
| `settings/rewardReport.php` | Reward/report workflows |
| `settings/subLink.php` | Subscription endpoint |
| `settings/jdf.php`, `phpqrcode/` | Date and QR support (preserve bundled third-party attribution) |
| `health.php` | Runtime health endpoint |
| `settings/values.php` | User-facing copy/templates |

## Database

`database/legacy-schema.sql` is a DDL-only export of **23** original MariaDB tables. Sample data in the uploaded SQL contained real-looking integration credentials and user information, so no INSERT data was imported here. The reference schema name and field names are intentionally not renamed. This project does not execute that SQL against production.

## Coupling risks

- Globals and shared includes complicate isolation and unit testing.
- Telegram callback identifiers persist in sent messages; changing them breaks old keyboards.
- Payment/renewal flows have side effects and are business critical; require side-effect-equivalence checks and idempotency understanding.
- External panels and endpoint paths are deployed contracts, not internal detail.
- Cron and webhook registration are out of the archive's scope; verify on isolated staging before claiming complete coverage.

This is an initial map, not a full dependency graph or proof of system behavior.
