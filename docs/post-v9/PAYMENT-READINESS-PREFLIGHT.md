# Wallet-first installation and payment readiness

Use this read-only diagnostic on the exact release-matched private source
checkout and installed runtime, before requesting an independently authorized
operator smoke. It does not certify live payment or deployment.

## One source-bound procedure

The operator entrypoint is tools/payment_readiness_preflight.py. It verifies
the raw manifest against an independently trusted SHA256, matches the expected
release commit and tree to the clean source checkout, and uses the same
tracked runtime membership as package_current.py. It checks both the source
helpers and installed files with verify_installed_runtime.py. The PHP worker
is internal to this procedure; do not run it separately.

The expected database metadata is derived from database/current-schema.sql,
not a pinned historical file count or list of migration numbers. It checks
required tables, engines, columns and nullability, named indexes (uniqueness,
column order, no prefixes), named constraint kinds and foreign-key endpoints.
It also runs the current checkout, Native, wallet, payment evidence, delivery,
report, service mutation, add-on and transfer storage guards. These checks
are read-only, not a full DDL/type/check-expression/row-integrity certificate.
Unlisted PHP entries in package-owned directories or at the runtime root,
extra directory symlinks there, and a root index.html shadow block the source
check without opening their contents. Extra database objects and files are not
deleted; reconcile them separately against the release and actual webroot.
This is not a full web-server routing or arbitrary extension audit.

The tool reads BOT_STATES, PAYMENT_KEYS and the payment-key editor source
marker for configuration checks. Missing settings on a newly initialized
database mean unconfigured, not invalid schema. Duplicate or malformed
payment settings documents and invalid current gateway rules are reported.
Editor source-marker findings are independent of payment-processing readiness:
a missing marker is normal on a fresh installation; well-formed historical
field revisions do not prevent current field edits. Duplicate or malformed
editor history warns that editing is unavailable, not that existing payment
processing is broken. Noncurrent key names are reported for controlled
reconciliation; no settings, history, or credentials are printed or deleted. A method is potentially offerable only with its switch,
required local settings and an effective policy allowing some audience or an explicit valid user exemption.
It uses the current manual destination/rate helpers and current gateway-rule
parser. It does not test a real customer's eligibility, balance or merchant.
API credentials are checked only for local configuration/envelope presence,
never decrypted or authenticated. The private webhook encryption key,
baseInfo.php, real provider/Telegram availability, and active web PHP/OPcache
are not inspected. A valid structure is not sufficient authorization to
enable payments or run Cron.

## Execute

Use Linux, Python 3, Git and the intended PHP CLI with mysqli. Obtain the
official runtime ZIP, source checkout, expected commit and independently
trusted manifest SHA256 from the selected release. Verify the ZIP against
the exact checkout with package_current.py verify before installation.
The DB account should have SELECT only on the intended database; use a
local socket or separately approved encrypted transport.

~~~bash
export ACHBOT_PREFLIGHT_DB_HOST=localhost
export ACHBOT_PREFLIGHT_DB_NAME=YOUR_DATABASE
export ACHBOT_PREFLIGHT_DB_USER=YOUR_SELECT_ONLY_ACCOUNT
export ACHBOT_PREFLIGHT_DB_SOCKET=/run/mysqld/mysqld.sock
read -r -s -p 'Read-only DB password: ' ACHBOT_PREFLIGHT_DB_PASSWORD
printf '\n'
export ACHBOT_PREFLIGHT_DB_PASSWORD
umask 077
python3 /trusted/achbot/tools/payment_readiness_preflight.py \
  --manifest /trusted/runtime.zip.manifest.json \
  --runtime-dir /actual/bot/path \
  --expect-commit EXPECTED_EXACT_SOURCE_COMMIT \
  --trusted-manifest-sha256 INDEPENDENT_TRUSTED_MANIFEST_SHA256 \
  --php /actual/path/to/php > /private/achbot-readiness.json
status=$?
unset ACHBOT_PREFLIGHT_DB_PASSWORD
printf 'Preflight exit: %s\n' "$status"
~~~

The PHP worker starts a READ ONLY transaction and rolls back. The diagnostic
never runs DDL, changes settings, invokes workers, makes external requests
or reads private configuration files. Keep the report private. Do not put
real credentials into arguments, repository fixtures or GitHub comments.

| Exit | Meaning |
| --- | --- |
| 0 | Source and required structure match; local payment configuration has at least one potentially offerable method and no configuration BLOCK. This is not live acceptance. |
| 3 | Source and structure match; payment configuration is incomplete, invalid or entirely unavailable. Expected for an untouched fresh database. |
| 2 | Source/manifest mismatch, unavailable inspection, or missing/incompatible required current structure. Investigate the specific finding; never restore retired tables to clear it. |

Use structure_ready and payment_configuration_ready independently.
An API credential marked present_not_decrypted still needs separate,
authorized verification. Do not change schema or settings from the report
without identifying the actual target and approved operation.

The required CI test runs this exact operator entrypoint on the actual
package and consolidated schema with a SELECT-only disposable account,
including fresh, configured, invalid configuration, missing constraint,
nonunique command index, missing foreign key, and tampered/unsafe manifest
or runtime evidence. No production or external payment tests are performed.
