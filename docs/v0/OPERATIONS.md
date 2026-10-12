# Runtime boundary and source-swap runbook

V0 is a development/reference increment. It does not require deployment, webhook re-registration, importing SQL, changing any Cron task, or replacing `baseInfo.php`. Existing deployment evidence is not included in the archive; the table below identifies code entrypoints, **not verified current production registrations or schedules**.

## Entrypoints to retain

| File | Inferred invocation/input | Effects and preservation requirement |
|---|---|---|
| `bot.php` | Telegram JSON webhook update | Existing callback bytes, source/IP check assumptions, step machine and message behavior; do not register a new production webhook for tests |
| `pay/index.php` | Payment initiation URL/query | Existing method/hash mapping, redirect and provider request behavior |
| `pay/back.php` | Provider return/callback | Existing verification/credit/provisioning sequence; never replay real transactions |
| `settings/subLink.php` | Subscription token query | Valid GET can mutate local and panel data; safe testing requires an isolated panel and fixture token |
| `health.php` | GET/HEAD | Local config, DB and Telegram checks, 30-second cache; cached failure status discrepancy is characterized |
| `settings/messagewizwiz.php` | Scheduled broadcast/job worker | Preserve deployed path, queue/cursor/claim/shutdown behavior; cadence is unknown |
| `settings/warnusers.php` | Scheduled expiry/renewal processing | Preserve user notification and renewal semantics; actual cadence/overlap policy unknown |
| `settings/rewardReport.php` | Scheduled rewards/report/payment work | Non-idle paths can provision/renew/credit; not a harmless report command |
| `settings/tronChecker.php` | Scheduled crypto payment checking | May credit/provision after verification; do not invoke against live keys |
| `settings/gift2all.php` | Scheduled gift batches | Panel and DB effects, gift offsets/capacity; do not replay a live queue |
| `index.html` | Static root page | Preserve alongside the PHP public entrypoints until actual routing is verified |

Source boundary: [generated include/effect atlas](SOURCE-ATLAS.md), [`pay/index.php`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/index.php), [`pay/back.php`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/back.php), [`settings/subLink.php`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/subLink.php), [`health.php`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/health.php).

## Local files and environment

The real `baseInfo.php` is outside Git. Keep its exact deployed contents/permissions/path during all early increments; the tracked example is not a replacement. Capture variable **names and types** privately without printing values. Record PHP CLI/FPM versions and required extensions (mysqli/mysqlnd, curl, JSON, tokenizer for development; GD/mbstring and relevant date/QR dependencies by actual use). Record PHP error mode, timezone, MariaDB version/SQL mode/collation, web-server routing, document root, reverse-proxy/IP handling and Cron execution user/CWD. Do not infer these from phpMyAdmin dump headers.

Inventory runtime backup/log/cache/temp files and any shared port-counter files before packaging. `getNextPortAndIncrease` receives its file path from the caller and uses a hyphen-separated port/counter value; it does not prove a single universal path. References to QR images exist but those assets are not in the public source set. Preserve required originals and validate CWD-dependent includes/image lookups. [`buy_helpers.php:108`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L108-L133).

Never sync a development checkout with `--delete` over an active deployment. Tests, fixtures, docs, `.git`, raw dumps and helper reports must not become web-accessible. A later release packager must allowlist runtime files while preserving local configuration/assets/state, rather than package everything tracked.

## Evidence needed from an isolated operational rehearsal

Record the candidate SHA and the prior accepted SHA; use a dedicated staging bot, separate synthetic database imported only into that fresh environment, and test panel/gateway endpoints. Reproduce existing public paths and relevant scheduler working directories, not real customer endpoints. Record actual feature flags and roles without publishing secrets. Run the operator matrix, old-message callback and in-flight-step checks, then verify DB/schema/state and outgoing call parity.

A safe result should show: deployed source identity, local configuration unchanged, all required assets present, schema unchanged, no unexpected data repair/migration, correct credit/order state, no duplicate remote provisioning, expected notifications, and acceptable logs without sensitive values. Environment-specific failures remain unverified until resolved; PHP lint or a mock passing cannot clear them.

## Later source-swap and rollback procedure (requires authorization)

1. Identify exact prior/candidate runtime file manifests and verify the target environment. Prepare the previous code package and tested restoration method. Preserve `baseInfo.php`, runtime files and necessary assets; do not include the raw SQL dump in a release.
2. Prevent mixed-version execution using the deployment's verified atomic-switch or controlled maintenance process. Coordinate active web requests and Cron tasks using the actual service setup; no generic unverified service commands are supplied here.
3. Swap only authorized runtime source, keep original URL/Cron entrypoints and configuration, and account for OPcache using the known runtime process. Do not import `legacy-schema.sql` into the existing DB.
4. Validate a bounded non-destructive smoke suite and monitor safe diagnostics. Avoid replaying payments or live subscription URLs under the assumption that GET is read-only.
5. If rollback is needed, restore the previous runtime source under the same execution control, keeping **current** valid persisted data and local configuration. Confirm the previous version understands any conversation/payment state written by the candidate. No migration should have been required.
6. Do not restore an old database snapshot merely to roll back code: that can erase legitimate payments/orders after the snapshot. Unexpected data damage is a separate incident requiring explicit recovery authority and reconciliation. Restoring code also cannot undo already-sent Telegram messages or remote panel changes.

## Operator sign-off record

The operator's result should state the candidate SHA, environment versions, scenarios actually executed, expected versus actual behavior, any sanitized failure, and explicit approval/rejection. Do not request or publish production credentials. V1 starts only after V0's required independent review and operator acceptance; later increments use the same gated pattern.
