# Upgrade RC12 to RC13

[راهنمای فارسی](RC12-TO-RC13.fa.md) · [README](../../README.md)

[RC13](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.13) removes the separate agent USDT rate. All new manual USDT (BEP20/TRC20/ERC20) and hosted NOWPayments quotes use the existing `PAYMENT_KEYS.manualUsdtRateToman` for every account. TRX keeps its own rate; existing gateway eligibility rules do not change.

## Compatible RC12 installation

1. Download the complete RC13 runtime ZIP, manifest and SHA256SUMS from the release. Verify the hashes and exact package identity. The runtime contains **112 files**, including both `messages/fa/user.php` and `messages/fa/admin.php`; GitHub's automatic Source code archive is not this package.
2. During an authorized cutover, replace the complete runtime consistently. Preserve the actual private `baseInfo.php`, customer data, financial/replay journals, provider identities, existing webhook and schedules. Do not overwrite the private configuration from a synthetic example or run old and new workers together.
3. **No new SQL or migration is required for compatible RC12.** Do not rerun migration005, recreate journals or delete old settings. Included migration/cleanup assets retain their earlier separate upgrade/maintenance contracts; optional cleanup010 is not part of this update. PasarGuard011 remains a prerequisite only for PasarGuard. RC11/RC9 installations must first reconcile their actual state with the existing [RC11 prerequisites](RC11-TO-RC12.md) / [RC9 contract](RC9-TO-RC11.md); no intermediate binary installation is implied.
4. Open a fresh administrator gateway-settings menu. Only the normal USDT rate remains. Keep/set the intended whole-Toman rate there; `/clear` disables new USDT/NOWPayments quoting for everyone. There is no private, external or 1:1 fallback. Retained `usdtAgentRateToman` values are ignored. Old agent-rate buttons and unfinished editors cannot write; reopen the normal-rate editor for further changes.
5. In a separately authorized isolated installation, check normal and agent menus/new quotes on the configured methods, unrelated setting edits and old invoice/status access. Existing issued attempts, including RC12 agent-rate quotes, keep their original asset/network/rate/amount and must not be repriced or reissued. Source CI is not installed acceptance or real merchant settlement proof.

## Recovery

Preserve original payment/effect/replay history and remote accounts. A source downgrade does not undo a committed financial operation. RC12 can reactivate a retained agent override for **new** quotes; any authorized rollback must explicitly reconcile that pricing/configuration difference before resuming quote creation. Do not delete history, blindly restore an older database, retry an unknown provider write or refund automatically.

The [shared-rate contract](USDT-RATE-SETTINGS.md) and [Issue #26](https://github.com/ach1992/bot-refactor/issues/26) retain the detailed source evidence and operational boundaries. Publication itself performs no installation, SQL, private-setting, webhook, Cron or financial action.
