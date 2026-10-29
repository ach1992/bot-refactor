# AChBot payment readiness preflight — read-only operator gate (#51 / #26)

**Purpose.** Before requesting a *separately approved* installation or payment
smoke, inspect the exact installed runtime against a trusted source-only ZIP
manifest and the installed MariaDB schema/configuration **without changing
anything**. This is a repeatable diagnostic report, not a bot feature, data
migration, payment test or provider/Telegram certification.

## Scope and assurance

The independent tool `tools/payment_readiness_preflight.php` runs **CLI-only**
(PHP >=8.1, `mysqli`). It never loads `baseInfo.php`, sends a Telegram call,
contacts a payment provider, changes a wallet/order or runs Cron. It uses an
explicitly supplied DB connection, opens `START TRANSACTION READ ONLY`, reads
`information_schema` and the two settings JSON documents, then rolls back.
Use a DB account granted **SELECT only** on the intended database; the source
cannot compensate for unnecessarily privileged credentials.

It checks:

- All 115 listed runtime files' byte lengths and SHA-256 against the
  **provided** manifest, and an independently pinned 115-member filename
  inventory (canonical sorted-set SHA-256 from `package_post_v9.members()`),
  with no retired automatic TRON/WeSwap PHP files. The locally
  executed policy and wallet validation helpers must also match the supplied runtime
  manifest; a tool checkout from another source version is rejected.
  No other runtime file is deleted or overwritten. `baseInfo.php` is never read.
- Original 23 table names, the three original-schema named owner lookup
  indexes used by current final authorization's `FORCE INDEX`, and expected
  distinguishing columns/named unique indexes for additive `001`, `003`,
  `004`, `005`, `006`, `007`, `008`, `009`.
  Existing tables with partial/mismatched structures are **blocked**; no
  existing object is replaced. `002` is the retired historical RC4 path,
  not an instruction to install it. No sidecar SQL is executed.
- Exactly one valid JSON-object `BOT_STATES` and `PAYMENT_KEYS` document,
  dynamic `gatewayRules` decoded with the **current production PHP parser**,
  legacy method audience modes and each method's switch. A method is
  *potentially offerable* only if its global switch is on, its local
  configuration is usable, and its effective policy is not unconditionally
  denied. An explicit method entry (including `[]` or all-disabled rules)
  overrides `gatewayAudience`; missing entries retain legacy fallback,
  including `off`. It does **not** evaluate a specific customer or payment
  history, so an enabled rule is only a possibility, not guaranteed eligibility.
- Gift seller URLs, bank identity length, and TRX rates follow existing
  source validators. Manual USDT availability calls the exact production
  `achbotManualUsdtConfiguration()` for each network, preserving explicit
  empty-field precedence over legacy addresses, the default legacy BEP20
  network, and explicit numeric/finite/range rate validation. The tool uses an
  **one explicit persisted bot rate only** (`manualUsdtRateToman`) for every
  account; retired agent overrides are ignored. It never loads private
  configuration or queries a price service. The `usdt_rate` finding reports
  shared availability without emitting its value. A valid address without any usable settings rate is not
  offerable. An explicitly invalid rate remains a configuration finding;
  valid TRX and other methods keep their own independent availability.
- Report signals distinguish `potentially_offerable`,
  `configured_but_policy_denied`, `not_offered`, and `unavailable`. Each
  method explicitly states `actual_customer_eligibility=not_tested`. No
  raw merchant secrets, addresses, rates, user rows or DB credentials are
  emitted.

**Important limitations:** A provided manifest is a comparison baseline, **not
proof it came from official GitHub CI**. The operator must independently
verify its origin/commit/ZIP hash. Named tables, columns and indexes do **not**
prove exact migration DDL, foreign-key integrity, real row compatibility or
actual installed migration history; ambiguous/previously deployed sidecars
require a separate schema-and-row audit. A configured merchant field does not
prove the credential is valid, active, funded or authorized. Manual USDT
using configured USDT rates requires operator verification of the chosen rate
and its service applicability; this CLI does not load `baseInfo.php`.
Customer-specific cohorts and real merchant/payment availability are not
certified even when a method is marked `potentially_offerable`. PHP CLI file checks do
not establish which code a webserver/OPcache is actually serving.

## Execute on a separately authorized target

Use trusted source, not a script copied to an internet-served webroot. Keep
any credential and the report in an operator-private directory; do not put
real tenant secrets, reports or database dumps in GitHub. Obtain an official
manifest tied to the exact release/CI package, and independently verify that
package's SHA-256 and Git SHA before using the manifest. Select the installed
bot directory **without modifying it**.

```bash
# These are example operator-local inputs; use the actual approved target.
export ACHBOT_PREFLIGHT_DB_HOST=localhost
export ACHBOT_PREFLIGHT_DB_NAME=YOUR_INSTALLED_DB
export ACHBOT_PREFLIGHT_DB_USER=YOUR_SELECT_ONLY_ACCOUNT
export ACHBOT_PREFLIGHT_DB_SOCKET=/run/mysqld/mysqld.sock
read -r -s -p 'Read-only DB password: ' ACHBOT_PREFLIGHT_DB_PASSWORD
printf '\n'
export ACHBOT_PREFLIGHT_DB_PASSWORD
umask 077
php /trusted/achbot/tools/payment_readiness_preflight.php \
  --manifest /trusted/achbot-runtime.manifest.json \
  --runtime-dir /path/to/installed/bot > /private/achbot-readiness.json
status=$?
unset ACHBOT_PREFLIGHT_DB_PASSWORD
printf 'Preflight exit: %s\n' "$status"
```

If the database has no password only in a disposable synthetic fixture, supply
an explicit empty environment value; never grant anonymous production access.
Avoid writing a password in a command argument or committed shell script.
A nonlocal database requires an operator-approved encrypted transport;
`START TRANSACTION READ ONLY` is **not** a substitute for a SELECT-only account.

Exit `0` means the tested **local preconditions only** were satisfied and the
isolated operator smoke can be *planned*, not that live payment is safe. Exit
`2` means at least one blocking check or failure to inspect; read the
structured JSON `checks` for stable reason codes and stop before any
migration/merchant action. Missing manifest or any expected sidecar shape is
blocking for **full current-source readiness** even if an older installation
continues to run a subset of features. `method_selection=PASS` means at least
one globally enabled, locally configured method is **not provably denied for
all customers**. It does not guarantee any actual customer will qualify.
`configured_but_policy_denied` is an informational `WARN` for that method;
if no other potentially offerable method exists, `method_selection` is `BLOCK`.
A known invalid explicit USDT rate is a blocking configuration error.

## Follow-on gates — NOT performed by preflight

1. Verify installed webhook-secret provisioning, effective CLI vs served
   PHP/OPcache build, private Telegram administrator menus, current delegated
   roles/revocation, and actual category-dependent payment selection.
2. Verify existing/required sidecars against the *exact installed DDL and
   preserved rows*; independently rehearse a tenant-specific backup restore.
   An already-existing `007` requires a forward migration decision; do **not**
   replay `CREATE TABLE` or drop/replace financial evidence.
3. With separately scoped Owner permission and isolated accounts, test
   provider-specific merchant auth, callback, settlement, wallet, purchase,
   renew, extensions, Cron and recovery with genuine operator observations.
   Never infer settlement from a browser return, or auto-refund/reissue an
   ambiguous external effect. Review old Telegram merchant-secret exposure
   and decide on rotation through a separate approval.
4. Persist target/version (without secrets), report digest, migrations
   independently audited, test outcome, remaining gaps and rollback evidence
   in [Issue #51](https://github.com/ach1992/bot-refactor/issues/51);
   cross-provider financial residuals remain [Issue #26](https://github.com/ach1992/bot-refactor/issues/26).

## Safe development regression

```bash
python3 tools/test_post_v9_payment_readiness.py --run-disposable \
  --report /tmp/achbot-payment-readiness-regression.json
```

This regression exercises the **actual PHP CLI** with an owned, socket-only
MariaDB, original schema plus all eight synthetic sidecars, a real SELECT-only
account and a 104-file ZIP-shaped runtime. It deliberately injects missing
settings/migrations, duplicate documents, malformed policies, absent hosted
keys, source drift, retired members, broken unique indexes and failed DB auth.
It also compares USDT address/rate behavior and static policy precedence
against the actual source helpers using synthetic PHP globals/users, testing
explicit clears, rate bounds, legacy defaults, denied/overridden methods and
valid independent alternatives.
It verifies the read-only user cannot issue UPDATE, retains synthetic wallet
and private config sentinels, and makes zero real external calls. It is part
of `tools/check_post_v9.py` for exact candidate CI on PHP 8.1 and 8.4.

The `wallet_transfer_storage` check additionally executes the manifest-bound
current transfer readiness helper: users, journal 005 and the original
`setting` cursor storage must satisfy their transactional schema contracts.
It performs metadata reads only; it neither creates cursors nor certifies
individual cursor contents, historical migration execution or live transfers.
