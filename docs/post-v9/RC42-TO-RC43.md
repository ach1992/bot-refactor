# RC42 to RC43 — automatic renewal reporting and CI

## Scope

RC43 publishes approved source changes in PR #181 (renewal notices) and PR #180 (CI planner). The official runtime ZIP has exactly 132 files. Compared with RC42, only:
- `src/Finance/AutomaticRenewal.php`
- `messages/fa/user.php`
- `messages/fa/admin.php`

change within the runtime package. No new DDL, migration, config key, Cron entry, provider API, gateway flow, financial settlement order or subscription deletion behavior is introduced by these changes.

## Messages and boundaries

After a verified automatic renewal is committed, the customer receives escaped-HTML subscription, amount, before/after wallet, days, volume and payment-reference details. The existing general-event router delivers separate reports to the primary Owner, eligible enabled Managers and report channel. Support/Operator do not receive new general reports.

For insufficient funds, the bot commits the per-subscription `autoRenew=off` change, does not spend or edit the Native service, and presents a customer message with wallet/shortfall details and buttons. A failed Telegram delivery does not cause a second financial/native attempt; delivery is best-effort and not a durable outbox. Existing actual 10-day deletion grace and displayed 3-day warning remain unchanged.

## Artifact acceptance

Verify `SHA256SUMS` against the **release asset** ZIP and manifest, then confirm the manifest commits to `d1a1761dfde0dd6be7dffc2fa3e0aa2ff814ccf4`, Git tree `3eb95f6e6d88e38a911d1ca48e90a367cc1d02e4`, and 132 runtime paths. Do not substitute GitHub's automatic repository source ZIP.

The source changes were approved in independently reviewed PR #181 and passed exact-candidate eight-family PHP 8.5 CI [#43548969109](https://github.com/ach1992/bot-refactor/actions/runs/43548969109). The merged commit retains the reviewed tree; main-push CI [#43575764805](https://github.com/ach1992/bot-refactor/actions/runs/43575764805) passed via exact-source proof reuse.

## No automatic installation

Publication **does not** stage, extract, replace files, restart workers, set Cron/webhooks, apply SQL, edit private baseInfo.php, or contact live Telegram/provider/payment targets. Real runtime installation requires separate explicit authorization and verification of the intended bot root, current version, private settings, prior required migrations, package hashes, available rollback artifact, and post-install smoke/financial acceptance. Do not reinterpret archive presence as activation.

Never replay an uncertain payment, provider edit or financial journal to repair a missing Telegram notification. Preserve original finance/service evidence. The preceding RC42 release remains the reference runtime artifact for recovery planning.

Open follow-up: [Issue #26](https://github.com/ach1992/bot-refactor/issues/26) for installed financial/notification acceptance; [Issue #104](https://github.com/ach1992/bot-refactor/issues/104) for remaining proportional CI measurements and PHP dependency classification.
