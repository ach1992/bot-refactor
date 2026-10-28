# Current runtime file and operation audit — 2026-10-28

This audit covers the current source candidate based on RC13/main `2c0e728`. It does not attest to files removed from any live installation. Frozen V0–V9 sources and published ZIPs remain unchanged.

## settings and pay

| Current file | Decision | Actual consumer / reason |
| --- | --- | --- |
| `settings/cronRunner.php` | KEEP, canonical dispatcher | Owns the full-batch lease, launches four isolated child processes, and contains the actual broadcast/service-maintenance compositions as admitted child modes. |
| `settings/gift2all.php` | KEEP | A coordinator worker processes administrator gift batches through common provider/account effect guards. |
| `settings/messagewizwiz.php` | REMOVE | Former composition runs in the real coordinator's `--job=broadcast` mode. No old-filename or replacement thin worker wrapper remains. |
| `settings/rewardReport.php` | KEEP | A coordinator worker handles reporting, configured backups, bounded NOWPayments reconciliation and automatic receipts. It is not a disposable backup helper. |
| `settings/warnusers.php` | REMOVE | Warnings → automatic renewal → payment maintenance now run in `--job=services`, preserving the existing nested try/finally ordering. |
| `settings/jdf.php` | KEEP | `config.php` and worker/bootstrap consumers use its existing calendar/date formatting functions. |
| `settings/values.php` | REMOVE | Catalog-only compatibility facade removed. `config.php` and `health.php` load the canonical two-file catalog and initialize their required bindings directly. |
| `settings/subLink.php` | REMOVE | This was only the unused X-UI token-conversion endpoint. In RC13 it was already a side-effect-free retirement stub; no supported runtime include or URL builder references it. Marzban and PasarGuard deliver their native subscription URLs. Current packaging excludes it, and current tests retain the archived safe-response proof while asserting the endpoint is absent. |
| `pay/index.php` | REMOVE | Old thin wrapper removed. Current initiation links use `pay/checkout.php`; the actual former `GatewayInitiation.php` implementation moved there and that internal file is also removed. |
| `pay/checkout.php` | KEEP, canonical controller | Contains the actual GET-only public bootstrap, gateway initiation and protected attempt reservation, rather than another forwarding wrapper. |
| `pay/back.php` | KEEP, unchanged | This is the active Zarinpal/NextPay browser return plus NOWPayments return and authenticated IPN entrypoint. Removing it would break already issued callback URLs and settlement evidence. |

A runtime-wide facade check found no additional application file retained solely as a moved-body alias. In particular, `buy_helpers.php` still implements delivery, diagnostic filtering and increase-order persistence; `bot.php`/`config.php` remain real request/bootstrap compositions. The small `phpqrcode/qrlib.php` is the third-party library entrypoint, not a compatibility alias introduced by this refactor.

The already retired automatic TRON/WeSwap files stay absent. Four business jobs retain their original order: gifts, broadcasts, reports/payment reconciliation, service maintenance. No installed Cron registration changes in this source batch.

The two remaining actual standalone workers (`gift2all.php`, `rewardReport.php`) reject HTTP before configuration or private bootstrap; their business implementations remain.

Canonical child modes require inherited fd 3 to match the trusted private lock inode, owner and permissions, plus a kernel-reported full-file exclusive `FLOCK ADVISORY WRITE` lease in Linux `/proc/self/fdinfo/3`. Missing or malformed evidence fails closed. An independent open of the same file, a shared/read lock, an unrelated locked inode, an unknown mode or an invocation without that lease cannot run a child. The child never acquires, converts or unlocks its parent's open-file-description lock. Native tests verify the lease survives parent death during either an external worker or canonical broadcast child.

Only two exact dispatch modes are accepted. Both run as separate PHP processes with the original working directory/environment and failure-continuation semantics. Their bodies were folded into the real coordinator, without introducing another alias wrapper.

For a later approved rollout, reconcile only the explicit retired-member list against the exact prior installed manifest. Do not delete private `baseInfo.php`, runtime state/logs, uploaded archives, or other bot instances.

## Shared business operations

| Journey | Common operation boundary | Native differences kept at the provider boundary |
| --- | --- | --- |
| Paid customer purchase | `achbotCreatePaidAccount` and shared service-effect reservation/linking | Native account creation, identity proof, entitlement payload and subscription delivery |
| Administrator gift / bulk creation | `achbotCreateNonPaymentAccount`, DirectGift and existing source/account effect guards | Same native creation boundary; no payment is invented |
| Customer trial | `achbotCreateNonPaymentAccount` with the existing trial claim | The same account creation adapter |
| Manual/customer/admin renewal and automatic renewal | `achbotApplyServiceEdit` plus the existing protected financial claim/finalization | Verified native expiry/quota read and write, preserving uncertain outcomes |
| Extra days / extra volume | The same shared `increase_day` / `increase_plan` catalogs and `achbotApplyServiceEdit` | Native units/payload translation only; no provider-specific SKU tables |
| Account view, warnings and expiry/quota decisions | `achbotServiceObservation` / `achbotServiceObserve` and the shared lifecycle policy | Adapter returns a validated observation; unavailable/malformed/disabled/on-hold evidence never authorizes deletion/charging |
| Toggle / revoke / delete / link refresh | Existing provider operation seam with ownership and effect guards | Actual supported native action and its verification |

A future provider needs an explicitly registered adapter/capabilities, native identity and entitlement contract, and integration tests. It must not duplicate payment, wallet, warning, add-on catalog or order business rules. Unknown providers remain unsupported.

## Existing-account import is a separate boundary

Adding a **new** subscription already uses the common creation operation for administrators and customers. Importing an account that existed outside this bot is a different ownership/adoption operation.

The legacy Marzban importer is hardened in this candidate:

- A decoded username is only a lookup hint. The submitted subscription token must exactly match the verified native token.
- The submitted origin/path prefix must match the native URL or an explicitly configured panel/subscription alias. A hostname suffix, credentials in a URL or an arbitrary outside origin is not proof.
- Ambiguous matches across logical servers fail safely; remark prefixes, a first server or a hard-coded default server never select the account store.
- Import rechecks the explicit server and current remote observation, copies the real native expiry (including unlimited), and serializes local attachment with the current actor/target and exact temporary state.
- It does not create a payment, debit a wallet, provision a remote user, or fabricate a PasarGuard identity.
- Replay or a second attachment of the same server/account cannot create another local order. Unknown COMMIT results remain under review.
- The legacy imported-service UI classification is retained; it is not new financial evidence. An imported account initially has no paid plan assignment, so customer guidance explains choosing a plan before renewal.

Native PasarGuard adoption of an outside-bot account remains **unsupported** until the owner accepts a stable ownership/adoption contract. The required `provider_accounts` marker/remote creation identity and effect relationship cannot be guessed from a username or Marzban token. This limitation does not affect fresh PasarGuard purchases, trials, gifts, renewals or volume/time extensions.

## Add-on management corrections

The catalogs remain shared for all supported providers. Prices now require canonical whole Toman values from 0 through 2147483647; quantities remain positive whole units, day SKUs are bounded to the existing 36500-day service contract, and unsupported fractions/negative/overflow values are rejected before writes. Every catalog mutation is serialized with a fresh persisted administrator role and the exact actor/editor snapshot. Private cancellation consumes the editor once and returns to the corresponding day/volume catalog.

The audit also removed reads of the nonexistent `increase_day.acount` field, which caused a valid day-price/quantity management screen to fail under strict error handling.

## Verification

`tools/test_unified_runtime_cleanup.py --run-disposable` exercises real PHP and an owned socket-only MariaDB. It checks the canonical file/worker/public-payment inventory, invalid/valid add-on edits, unauthorized/stale editor cases, contextual cancellation, exact native import proofs and negative controls, replay and absence of wallet/payment/provider writes. Provider reads in the import boundary tests are injected synthetic observations; native HTTP adapter behavior is covered by the separate provider/lifecycle suites. No real panel, payment, Telegram, private configuration or production database was used.

The canonical Cron suite additionally tests admitted modes, inherited-lease denial controls, representative kernel parser records, parent-death recovery, and HTTP rejection before bootstrap for both actual workers. Historical values-facade tests use pinned archived source; current fixtures initialize canonical catalog bindings.
