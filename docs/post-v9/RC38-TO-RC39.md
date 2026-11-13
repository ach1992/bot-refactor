# RC38 → RC39 — indexed bank SMS, gateway editor, public root and health

RC39 is the next **source-only** prerelease after
[v0.10.0-rc.38](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.38).
It includes three independently reviewed, Owner-approved source changes:
[PR #172](https://github.com/ach1992/bot-refactor/pull/172),
[PR #173](https://github.com/ach1992/bot-refactor/pull/173), and
[PR #174](https://github.com/ach1992/bot-refactor/pull/174).
No target installation, SQL migration or automatic payment/provider action is
performed by release publication.

## Changes since RC38

### Bank SMS lookup — PR #173

- Search WordPress Pay Manager SMS with the original per-invoice **full pinned
  amount and unit**, trying the configured Toman amount and equivalent Rial
  amount when representable. Use the source's indexed time filter
  `received_from` equal to **original invoice issuance minus 180 seconds**.
- The lower bound filters **WordPress ingestion time**, not the date embedded
  in the bank SMS. Do not add a `received_before` upper bound: late ingestion
  remains possible. The original SMS **transfer time** must still overlap the
  original invoice window from issuance minus 180 seconds through issuance
  plus the configured monitoring horizon.
- Preserve original bank quote, provider/source filters, `ready` status,
  same-amount collision handling, original administrator evidence/ACK,
  durable SMS consume/proven journal, once-only settlement, and manual
  approval winner/finality. Fall back to the previous bounded unfiltered
  cursor scan only when older Pay Manager explicitly lacks the filter or the
  configured source-time interpretation cannot safely use it. Network failure
  is not evidence of absence and never authorizes new consumption.
- These changes do **not** establish that a running Vivan instance has
  improved latency. Verify installed WordPress capabilities, actual bot
  source and payment-specific timing only in an authorized target acceptance.
  Preserve the investigative evidence in
  [Issue #171](https://github.com/ach1992/bot-refactor/issues/171).

### Administrator gateway-exemption editor — PR #172

- The real cancellation control clears the temporary edit keyboard and
  returns to the **same payment method** rule menu without changing saved
  exemptions. The initial list shows only the **10 most recently inserted**
  numeric Telegram IDs rather than all IDs; the full count and current
  effective policy remain available.
- Exemptions still bypass only payment-method audience rules, not gateway OFF,
  provider authorization, financial settlement, or identity guards.
  See [payment rule contract](GATEWAY-ELIGIBILITY-RULES.md) and
  [Issue #51](https://github.com/ach1992/bot-refactor/issues/51).

### Root page and monitoring — PR #174

- The current 132-member runtime ZIP now contains the Owner-supplied
  `index.php`, unchanged, SHA256
  `b0350c23c91091be0d66695cc388ea185afc5446d974c4d13dd19edbd7a6c36c`,
  and **does not** contain the retired `index.html`. The replacement is
  a presentation-only account page: login/register/forgot-password do not
  implement real authentication, registration persistence or email recovery.
  Its referenced `fonts/Vazirmatn.woff2` is not shipped; browser fallback
  fonts remain available.
- The new `health.php` performs lightweight local PHP/token configuration
  and MariaDB `SELECT 1` readiness with configured two-second connection
  and read limits. It no longer calls Telegram `getMe` or reads/writes
  `health_cache.json`, avoiding false downtime from external Telegram
  latency or cached failure bodies served with HTTP 200.
- On a healthy installation a **GET** to the correct full path returns
  HTTP **200**, JSON containing the literal keyword **`"app":"ok"`**.
  Missing configuration or a failed local DB probe returns HTTP **503**
  with `"app":"fail"`. `HEAD` returns the same HTTP status **without a
  response body**; do not use HEAD with keyword matching. Unsupported
  methods return 405.
- Monitor only the actual live instance path, for example:
  `https://host.hellservice.top/vivan/health.php`,
  `https://host.hellservice.top/hell/health.php`, or
  `https://host.hellservice.top/chili/health.php`.
  `/health` is **not** an alias for `/health.php`, and
  `/user/health.php` monitors a distinct legacy installation.
- Local readiness is **not** proof of Telegram webhook delivery, Cron
  execution, Marzban/PasarGuard operation, or payment settlement. If those
  need monitoring, design separate dedicated checks instead of making
  this inexpensive uptime endpoint depend on the external Telegram API.

## Validation and exact provenance

The accepted PR #174 was reviewed at
`dfdd2e8e3a918c9b16f8ab0b1729e725a8a2ab9e`, tree
`149d41198642c189b779da2670852c487889e95b`, and merged as
`734ec40272b69481a9757391acde426c1d839c2e`, with the **same Git
tree**. [Full PHP 8.5 CI #43443216653](https://github.com/ach1992/bot-refactor/actions/runs/43443216653)
passed Foundation, all eight native families and strict aggregate.
The earlier source changes also passed their exact full PHP 8.5 checks:
[PR #172 CI](https://github.com/ach1992/bot-refactor/actions/runs/43412305554)
and [combined PR #173 CI](https://github.com/ach1992/bot-refactor/actions/runs/43437733434).
Release-only README/guide edits do not change runtime member blobs; the
published tag, 132-member ZIP and Manifest establish the final release SHA
and ZIP identity. Frozen historical V0–V9 artifacts are unchanged.

## Upgrade, verification and rollback boundaries

1. Download the **attached release runtime ZIP** and verify the source-bound
   Manifest and `SHA256SUMS`. GitHub's automatic source ZIP is **not**
   an installable runtime package. This release requires no new SQL schema,
   private PHP settings, webhook, Cron change or upstream plugin upgrade
   beyond the already compatible APIs; do not replay completed migrations.
2. Publishing this release **does not** transfer an archive to Vivan, Hell
   or Chili, extract files, enable APIs, or change any running service.
   A later ZIP staging/installation requires its own authorized scope.
3. Before a separately authorized installation, preserve `baseInfo.php`,
   existing finance/native journals, private settings and verified rollback
   data. Explicitly remove any **retired deployed `index.html`** before
   installed-runtime verification. An old `health_cache.json` may remain;
   the new endpoint ignores it. Never delete financial evidence or run
   migrations merely for this release.
4. After an authorized installation, perform real GET/HEAD checks against
   each configured domain, check monitor method/status/keyword, confirm
   PHP-FPM/Nginx routing and observe DB timing. For indexed SMS, correlate
   original invoice issuance, WordPress ingestion/ready, SMS transfer,
   bot polling, remote consume and financial fulfillment without re-consuming
   historical SMS or manually approving paid orders.
5. A source rollback must be assessed against **current** financial/native
   journals and potential API consumption; do not blindly extract RC38
   over a partially settled newer installation. Follow an approved,
   verified rollback/roll-forward plan.

Installed Vivan financial acceptance [#157](https://github.com/ach1992/bot-refactor/issues/157)
and Hell migration [#161](https://github.com/ach1992/bot-refactor/issues/161)
remain independently approval-gated. This guide documents source release
behavior, **not** completed target deployment.
