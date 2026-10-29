# RC17 → RC18

RC18 restores the existing two-button Telegram keyboard pattern for three gateway/channel destinations: cooperation URL, help channel and the private mandatory-membership invite URL. The status/edit button and adjacent localized label follow the neighboring settings. Editing callbacks, permissions, URL validation, existing BOT_STATES data, save/clear/cancel and return navigation are preserved.

## Upgrade

1. Verify the official RC18 runtime ZIP, complete manifest and SHA256SUMS. The manifest identifies the exact independently reviewed source; GitHub automatic source snapshots are not the runtime package.
2. A compatible RC17 installation needs **no new SQL or private-configuration changes**. Preserve data, journals, existing settings, webhook, Cron configuration and the existing lock lease/inode. Never rerun completed012/013. Earlier installations must first reconcile the [RC16 → RC17 guide](RC16-TO-RC17.md) and [shared-storage contract](SHARED-PROVIDER-STORAGE.md).
3. During a separately authorized cutover, keep a protected recovery copy and replace the complete **115 runtime members / 114 PHP files** consistently. Only `config.php` differs from the RC17 runtime; no runtime member is added or removed. The archive excludes actual private configuration, SQL/data, backups, logs and tests.
4. Verify the installed manifest and intended serving runtime during an authorized acceptance window. Publication, CI and isolated fixtures do not certify live Telegram rendering, provider behavior, merchant/wallet operations or Cron acceptance.

## Existing settings

Use the same gateway/channel menu and editor. No values are migrated or reset. The cooperation URL, help-channel URL and private invite URL keep separate status/edit buttons and informational label buttons. A private channel's numeric membership identifier remains independent of its invite URL and existing fallback.

Native Marzban and PasarGuard purchase-delivery/order-page config selections were already delivered in RC17. They remain under selected server → نمایش لینک و QR → انتخاب کانفیگ, using the existing shared renderer, editor and per-server setting. Explicit context list → shared selectors → all fallback and explicit empty selections are unchanged. RC18 adds no duplicate provider-management path.

## Recovery and acceptance

This layout-only patch adds no persistence format or new migration boundary. A separately authorized rollback to RC17 can preserve the current database/settings, subject to the existing recovery obligations for unresolved operations. Older RC16 and pre012 downgrade restrictions remain in force; see [RC17 recovery](RC16-TO-RC17.md#recovery). Never restore an old dump after new writes or retry uncertain financial/native effects blindly.

Actual content/navigation, financial and native-provider acceptance remains open in Issues [#37](https://github.com/ach1992/bot-refactor/issues/37), [#26](https://github.com/ach1992/bot-refactor/issues/26), [#51](https://github.com/ach1992/bot-refactor/issues/51) and [#81](https://github.com/ach1992/bot-refactor/issues/81). This release does not install files, inspect a target or authorize live operations.
