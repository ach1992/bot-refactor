# RC31 → RC32: source-only naming correction and release preflight

This guide covers `v0.10.0-rc.32` after `v0.10.0-rc.31`, not a live installation instruction. Publishing a GitHub prerelease never installs Chili, changes real payments, runs SQL, invokes Native providers, sends Telegram, or authorizes a rollout. The original protected Chili order #33 and service-plan journal #2 must not be replayed or modified to manufacture acceptance.

## Source changes since RC31

- Independently approved [PR #152](https://github.com/ach1992/bot-refactor/pull/152) replaces RC31's fixed `mbz_bulk_` / `mbz_paid_` name presentation with the actual configured single-account naming convention (`server-userId-number` or digits mode `server-number`), bound to the original source and ordinal for administrator no-payment and customer/agent paid bulk operations. Preserve recognizable configured/manual names.
- New generated Marzban names fit its **32 ASCII-byte** Native constraint; independent PasarGuard names fit its **100 ASCII-byte** constraint. Explicit customer single-account input, including literal `"0"` for NextPay/Zarinpal hosted checkout, is preserved.
- Source/ordinal identity, original 007 effects, per-account reports, full-count capacity, payment/provider ownership, partial/unknown status, and no replay of an uncertain dispatched Native CREATE remain intact.
- Independently approved [PR #153](https://github.com/ach1992/bot-refactor/pull/153) changes only CI/test execution: SHA256-verified MariaDB 10.11.19 archive caching and a bounded three-worker pool for 19 separate service fixtures. No application runtime code change is introduced by this optimization.

## Validation

- [PR #152 exact PHP 8.5 CI](https://github.com/ach1992/bot-refactor/actions/runs/43190781457): foundation + all eight native families + protected aggregate SUCCESS, independently reviewed APPROVE before merge.
- [PR #153 exact new-target PHP 8.5 CI](https://github.com/ach1992/bot-refactor/actions/runs/43196616962): foundation + all eight native families + protected aggregate SUCCESS, independently reviewed APPROVE before merge.
- The final release tag, manifest, SHA256SUMS and released artifact must be cross-checked against the actual merged release commit and its runtime-file manifest; no premerge SHA is silently treated as the release identity.

## Database and migration

**No migrations or DDL differ from RC31** and no new Cron/Telegram/provider configuration dependency is introduced by RC32. This does not establish that the installed target has all older required schema/indices or matching private settings. Inspect the real schema, original open payment/service effects, private configuration and active schedules before any authorized upgrade. Preserve original 003/014/017/018/020/022 and other previously applied evidence; **do not rerun existing migrations** or infer schema from the GitHub ZIP.

## Separate installed rollout (not performed by publication)

1. Verify the full currently installed runtime manifest, not a version comment or a few file hashes. Earlier read-only inspection showed RC31 matches in Chili; it does not prove RC32 is installed.
2. Obtain a fresh target-specific rollout authorization, inventory protected original journals and in-flight financial/Native operations, preserve private `baseInfo.php` / settings and existing Cron/webhook context, and demonstrate a valid file + database + configuration restore path.
3. Verify the RC32 ZIP, bound manifest and every referenced checksum against the official Release, and retain the immutable prior recovery package. Do not overwrite unknown private files or clear original effects.
4. Only after those separate operational gates, coordinate the complete runtime swap, verify actual installed source + schema and test real Marzban/PasarGuard/admin bulk and wallet/manual/hosted customer/agent flows with original source/effect/order identity.
5. Confirm full/partial per-account reports, primary-admin/channel delivery and original customer Telegram acknowledgments independently. Preserve pending/uncertain Native/financial/Telegram operations for explicit read-only inspection instead of replaying them.
6. Prove restore/roll-forward readiness and record real operator acceptance in [Issue #147](https://github.com/ach1992/bot-refactor/issues/147), with service-plan and payment acceptance in [Issue #144](https://github.com/ach1992/bot-refactor/issues/144) and [Issue #117](https://github.com/ach1992/bot-refactor/issues/117). Source publication never closes those Issues.

The last published RC31 release remains a historical reference. A Git rollback alone cannot reverse external payment, account or Telegram effects. Reconcile their original durable evidence before any further action.
