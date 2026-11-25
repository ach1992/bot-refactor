# AChBot — verify installed runtime before any change

Find the [current Stable release](https://github.com/ach1992/bot-refactor/releases/latest); [v0.10.0](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0) remains the first Stable baseline. Use the **actual selected release's** Manifest/SHA256SUMS and source-bound installed-runtime verifier. This is a **read-only disk source identity check**, not a database migration, live webhook test or deployment.

## Verify exact installed source

Obtain the release's expected source **commit SHA** and the raw manifest's independently trusted SHA256 from the signed/controlled release evidence, not from an arbitrary downloaded JSON alone. Use the matching private checkout's verifier; replace paths and the full two SHA literals:

```sh
python3 tools/verify_installed_runtime.py \
  --install-dir /actual/webhook/serving/directory \
  --manifest /private/setup/runtime.zip.manifest.json \
  --expect-commit EXPECTED_EXACT_SOURCE_COMMIT \
  --trusted-manifest-sha256 INDEPENDENT_TRUSTED_MANIFEST_SHA256 \
  --json
```

- Exit **0**: all manifest-listed source files match in size and SHA256.
- Exit **1**: mismatched/missing/unsafe installed members; investigate the report.
- Exit **2**: invalid input, untrusted manifest or unavailable root.
- Matching on-disk files **does not** prove which compiled bytes PHP-FPM/LSAPI OPcache serves, webhook routing, real provider access, financial settlement or Cron execution. The verifier deliberately does not read `baseInfo.php`, contact Telegram, access SQL or traverse unexpected extra files.

Verify the exact host, application document root, PHP handler, OPcache state, public `index.php`, scoped `health.php`, and Telegram webhook through the authorized private operational route. Old unexpected scripts can be publicly callable even if all members in the selected release manifest match: reconcile obsolete paths against the exact current ZIP and documented reverse-proxy/webserver behavior; do not delete unidentified private or custom files.

## Checkout failure diagnostics

The current Checkout paths emit private structured `[AChBot]` events for
draft creation, unexpected draft-view failures, Wallet confirmation, manual
receipt admission/approval, hosted renewal navigation and progress errors.
Use `event`, `trace_id`, `time_utc`, `reason`, `class` and the available
actor/payment/service identifiers together when diagnosing a refusal. SQL
exceptions retain the numeric `sql_errno`; a local application reason is
kept where it is a recognized diagnostic code. Unknown exception messages,
SQL text, invoice hashes, customer payloads and credentials are not logged.
A draft-creation identifier can describe an attempted insert that rolled
back; the event is not evidence that a payable invoice committed. A warning
does not establish provider failure, payment finality or successful service
delivery. Reconcile actual original journals before retrying.

## Production installation and safe rollback

Before any authorized installation, capture the real currently served source identity, version/manifest and pending application activity. Preserve actual private `baseInfo.php`, customer DB/schema, Native identities/URLs, original invoice/payment/effect journals, pending reconciliation, webhook secret and Cron definitions. Capture a verified private rollback source set and appropriate data backup; a backup file existing does not prove restorable recovery.

Use a target-specific maintenance window for a source replacement when required. Deploy **only** the exact verified release runtime members; do not extract the whole Git checkout or replay historical migrations. Manage retired paths by exact inventory, never wildcard deletion. Coordinate active PHP-FPM/OPcache and the existing service/financial Cron lease. After changing the installed source, repeat the manifest disk check, confirm actual web-SAPI source resolution, read-only DB readiness, subscription-link views and the normal scheduled job logs. Do not create a second active Cron task. Real customer/financial/Native operation tests require separately authorized controlled test subjects and effects.

A failed release installation may justify restoring **source only** while preserving all successful payments, newly issued invoices and Native writes. Never restore stale financial SQL or retry uncertain external effects merely to roll back PHP code. Examine the actual journal and route-specific original evidence first.

For a *new* empty database use [Fresh installation](FRESH-INSTALL.md); the [current Wallet-first payment-readiness procedure](PAYMENT-READINESS-PREFLIGHT.md) binds the exact source and schema and separates structural readiness from local payment configuration. Use the actual selected release documentation for an earlier installation. Historical RC-specific diagnosis and upgrade examples remain in [Git history](https://github.com/ach1992/bot-refactor/commits/main/) and [GitHub Releases](https://github.com/ach1992/bot-refactor/releases), not in this current operator guide.
