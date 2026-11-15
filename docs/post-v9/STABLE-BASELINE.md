# First stable baseline (proposed v0.10.0)

## Boundary and readiness

- The current supported runtime is one shared AChBot implementation for **native PasarGuard and Marzban**. Hell/Vivan are already migrated to PasarGuard; future Marzban servers remain supported. Chili is a separate installation, never automatically part of the Hell/Vivan cutover.
- Superseded bot releases and obsolete UI callbacks are not a supported ongoing compatibility target. Historical financial rows, real customer subscription URLs and credentials, native identities, replay/uncertainty fences, and live integration contracts **are** preserved. No customer should need to replace a config because a bot version changed.
- PasarGuard plan membership uses `server_plans.provider_group_ids`, not `custom_sni`. PG plan creation stores SQL NULL for the Marzban-only field. Marzban plans retain their native `inbounds/proxies` field. The disabled old external renewal picker no longer searches serialized metadata text and is bound to Marzban with a native plan-access check. Normal PG renewal uses the provider-aware order/plan flow.
- `orders_list.link` is a shared purchase/import record and Marzban fallback cache, **not** the PasarGuard native subscription authority. PG config and current subscription URL are obtained from authenticated PasarGuard GET and never rewritten from historical cached links.
- The finite `RENEW_SCONFIG` financial history remains readable/settleable by existing ledger paths. Do not bulk-delete prior payments or their replay guards merely because the old UI is disabled.
- Frozen V0-V9 contract/test evidence and retired cutover scripts are kept outside shipped runtime. They do not mean older deployed bots remain supported.

## Delivery gates

1. Accept the reviewed source candidate against the exact Git tree and require complete PHP 8.5 native CI (all eight families and aggregate). Independent review and approved merge are required; a previous production cutover review waiver did not extend to this new source release.
2. Build the exact clean merged-HEAD runtime ZIP with `tools/package_post_v9.py`; verify the source-bound manifest, runtime inventory and SHA256SUMS. The first non-prerelease tag may be `v0.10.0` after required gates. A GitHub release is not a deployment.
3. Before any separate production install, preserve real private config/DB and inspect Hell/Vivan current source identity, runtime ownership, scheduled jobs, and reversible rollback. Never execute the old one-time Marzban→PasarGuard cutover again.
4. Read-only production acceptance covers active order identity, live Native subscription retrieval, plan Group assignments, stored display policy matching, and Cron schedule/log observations. Finance effects and real Telegram sends require their own permitted controlled end-to-end evidence. No tests here mutate customer entitlements.
5. Observe aaPanel Cron (one active `Bot Hell` and `Bot Vivan` schedule at one-minute intervals; retired original schedules disabled). Leased overlapping ticks can be safely skipped; monitor runtime completion and Telegram `400/message_missing` edit failures without falsely marking old text as synchronized.

## Operational record

See migration [Issue #183](https://github.com/ach1992/bot-refactor/issues/183), display incident [#187](https://github.com/ach1992/bot-refactor/issues/187), and Draft cutover [PR #184](https://github.com/ach1992/bot-refactor/pull/184). This document states the intended baseline; its presence is not a published-release or production-rollout claim.
