# Upgrade RC15 to RC16

[README](../../README.md) · [Current scope](NEXT-RELEASE.md) · [RC16 release](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.16)

RC16 is the published source-only administrator, instance-content and Persian Telegram UX release integrated through [PR #93](https://github.com/ach1992/bot-refactor/pull/93). Verify the release assets and final post-merge evidence before an authorized rollout. This guide records a source transition, not an installation or live acceptance result.

## Exact package and runtime delta

| Evidence | Identity |
| --- | --- |
| Reviewed/package source | `660e18e9fb88b37ae8e607ee0ed7607414f628f3` |
| Runtime source tree | `ba4c0483c606b0ec15370a2af55a01fabeb5a468` |
| Ordinary PR93 merge | `ba68652032e3809375b71606d6ad6fdcdc97dec1` (same tree) |
| Official source CI / artifact | [40847705267](https://github.com/ach1992/bot-refactor/actions/runs/40847705267) / `12886012964` |
| Runtime ZIP SHA256 | `2b9435d092bd3d5aa933f395101b8c56faffe973ee5bd1af954663c043ac9a71` |
| Complete runtime | **115 members / 114 PHP files** |

Use the official [runtime ZIP](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.16/achbot-v0.10.0-rc.16-runtime.zip), [full per-file manifest](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.16/achbot-v0.10.0-rc.16-runtime.zip.manifest.json) and [SHA256SUMS](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.16/SHA256SUMS) from that release. Its acceptance report records source checks; it does not attest to live acceptance. No new SQL sidecar is part of RC16; RC15 retains the earlier migration assets. Verify the ZIP hash, manifest candidate/tree and **every runtime member**, including `messages/loader.php`, `messages/fa/user.php` and `messages/fa/admin.php`. GitHub's automatic Source code archive is not the runtime package. The ZIP excludes private `baseInfo.php`, SQL/data, backups and test tools; it does not apply migrations.

Compared with the official RC15 runtime (112 members), RC16 adds `src/Admin/Access.php`, `src/Admin/InstanceContent.php` and `src/Telegram/UserProfile.php`; **no further runtime path is retired**. Replace the complete runtime consistently rather than copying only these helpers or changed PHP files.

The following nine paths were already retired before RC16 and remain absent:

- `pay/index.php`
- `settings/messagewizwiz.php`
- `settings/subLink.php`
- `settings/tronChecker.php`
- `settings/values.php`
- `settings/warnusers.php`
- `src/Finance/CryptoChecker.php`
- `src/Finance/GatewayInitiation.php`
- `src/Finance/Routes/CryptoAndExchange.php`

Compare the actual installation with its trusted previous manifest and the RC16 manifest. Do not blindly delete unknown files or directories. An older/partial installation needs its own reviewed path and schedule reconciliation; preserve private config, generated data, logs, uploads and other instances. Current payment initiation is `pay/checkout.php`; the existing coordinator retains its admitted child modes. Do not reactivate retired automatic TRON workers. See the [runtime audit](RUNTIME-FILE-AUDIT.md) and [installed provenance guide](INSTALL-DIAGNOSTICS.md).

## Compatible RC15: paired runtime and staff settings

1. Inventory the installed runtime/schema and retain a restorable, paired runtime/database/private-state recovery copy. Preserve native identities, payment/attempt/effect/replay journals, wallet history and unresolved evidence. **No new SQL is required for a compatible RC15 installation. Do not rerun completed012/013**, recreate journals or reimport the original V9 schema. Earlier installations must first follow the [shared-storage contract](SHARED-PROVIDER-STORAGE.md); a release ZIP does not establish schema compatibility.
2. During the separately authorized cutover, replace all 115 runtime members together. Preserve the actual private `baseInfo.php`, primary `$admin`, secrets, webhook, Cron configuration and existing lock lease/inode; never unlink or recreate an active lock. Do not run old and new writers concurrently. Verify all files against the release manifest and check the actual serving PHP/OPcache before declaring the new runtime active.
3. From the primary Owner account, inspect the existing administrator list. The primary `$admin` is Owner and remains listed; non-owner membership is the current database staff list. Valid persisted levels remain. Existing `users.isAdmin` staff without an explicit persisted level become **Operator**; Owner assigns **Manager** where required through this same list. Retained private arrays are neither imported nor used to infer a higher level.
4. Pair any actual private-config cleanup with this new runtime. Only the obsolete `$admins_list` and `$special_admins` staff assignments are to be ignored/removed; keep primary `$admin` and all unrelated private settings. **Installed RC15 still uses those arrays: do not remove them from an RC15-only installation.** Do not overwrite the real private file with `baseInfo.example.php` or treat a source-example edit as completed private cleanup.
5. Open fresh menus for administrator levels, notification preferences and Bot Settings, then perform the separately authorized acceptance checks. Source CI, archive verification and publication alone are not installed acceptance.

## Four levels and administrator notifications

| Level | Existing management scope |
| --- | --- |
| Owner | Primary private `$admin`; manages staff and their notification preferences. |
| Manager | Ordinary administrator functions except adding/managing administrators. |
| Operator | Also excludes statistics, server management/settings, plans/categories, Bot Settings and gateway/channel settings. |
| Support | Customer information, ban/unban and the order database editor. |

**All four current administrator levels retain the same existing human-receipt review, approval and rejection workflow**, with the existing pinned quote/evidence, target, lease, replay and effect controls. There is no extra payment grant or new permission step. This common receipt workflow does not grant independent wallet/debt, foreign-ticket or native/server-management authority. Consequential Owner-only reconciliation stays separate. Payment review appears immediately after the administrator-list entry.

Owner can switch new secondary administrator-message copies on/off per staff member, independently of level. Missing preferences default on for valid current staff; off suppresses **new copies only**. Receipt buttons already delivered keep their normal completion/status maintenance for current valid staff, even when notifications are off. Revoked, banned or ambiguous recipients are excluded. Receiving a message never grants an action; the primary Owner delivery and historical financial references remain intact.

## Instance text, destinations and visible changes

Use **Bot Settings** to edit the initial welcome, returning-user/home messages and shared purchase note. Unset bodies show neutral Persian setup guidance. Legitimate existing per-instance overrides remain; these bodies now live in persisted settings rather than fixed brand-bearing catalog entries. Send ordinary text or use Telegram's supported text formatting; literal pasted HTML is text, not a markup editor. `/clear` resets a field to its unset behavior; familiar cancel/back leaves the editor. Static interface text remains in the two audience catalogs.

Configure support, help-channel and cooperation-panel destinations there. Mandatory-channel membership identity and join URL are separate: keep a private channel's numeric ID for membership checks and set its invite URL in the dedicated field. Clearing the join URL restores public-channel derivation or a valid existing private `joinLink` fallback. Optional unset/invalid destinations do not produce a broken URL button. Saved destinations and native keyboard URLs retain their bytes; actual HTML/Markdown/plain displays encode values at their own boundary without fetching submitted hosts.

Receipt captions omit duplicated metadata while retaining the pinned exact bank amount directly after paid amount. User-info statistics retain incoming-payment/service-value meanings; permitted staff can edit agent percentage next to agent status. An authenticated private `/start` refreshes display name and a nonempty username; removing a username does not erase its previous stored value. Configured auxiliary links appear for both providers after purchase and in order details, without changing native subscription/config bytes.

## Recovery and remaining acceptance

Before a separately authorized downgrade, reconcile staff membership, levels and notification delivery: RC15/older code does not understand RC16 tier restrictions and may use retained private arrays again. Preserve protected copies of relevant configuration for a paired recovery plan; do not assume restoring an old source tree preserves the new access policy. A downgrade also loses the new settings editors/presentation behavior. It does not undo money or native effects.

Keep private config, native incarnation/operation evidence, financial journals and unresolved outcomes. Never restore an old dump after new writes, erase replay history, retry uncertain provider/payment effects or refund automatically. The earlier RC15 boundary still applies: **source-only RC14 rollback after012 is unsupported**; use an audited paired recovery or roll-forward plan.

Serving-SAPI/OPcache readiness and actual Telegram, provider, merchant, wallet and Cron acceptance remain separate checks. This document claims no RC16 installation or live acceptance. [Issues #26](https://github.com/ach1992/bot-refactor/issues/26), [#51](https://github.com/ach1992/bot-refactor/issues/51) and [#81](https://github.com/ach1992/bot-refactor/issues/81) remain open until their actual criteria pass.
