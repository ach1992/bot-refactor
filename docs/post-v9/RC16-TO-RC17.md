# RC16 → RC17

RC17 keeps the familiar server, payment and administrator workflows. It consolidates destination settings and adds independent native config selection for purchase delivery and order details. PasarGuard and native Marzban use the same renderer, editor and existing per-server JSON; no provider-specific table or registry is added.

## Upgrade

1. Keep a protected recovery copy of the current runtime, actual private `baseInfo.php`, generated data and relevant server-display settings. Verify the official runtime ZIP, its complete manifest and `SHA256SUMS`; GitHub's automatic source snapshot is not the runtime package.
2. A compatible RC16 installation needs **no new SQL**. Do not rerun completed012/013, recreate journals or import the original V9 schema. Earlier installations must first reconcile their exact [shared-storage migration boundary](SHARED-PROVIDER-STORAGE.md).
3. During an authorized cutover, replace all **115 runtime members / 114 PHP files** consistently. Preserve private configuration, financial/native evidence, webhook, Cron configuration and the existing lock lease/inode. RC17 adds no runtime file and retires no further path compared with RC16.
4. Verify the complete installed manifest and actual serving PHP/OPcache before declaring activation complete. Source CI, syntax checks and publication alone do not certify real Telegram/provider/merchant/wallet/Cron acceptance.

The source/tag identity and ZIP digest are in the release manifest, checksums and release validation notes. The runtime archive excludes the real private file, SQL/data, backups and test tools.

## One place for settings

- **Bot Settings → متن‌های ربات:** the four start/welcome/home/purchase-note message bodies.
- **Gateway/channel settings:** support, income/report channel, mandatory-membership identity, private invite URL, help channel and cooperation destination. Save, clear and cancel return to this same section.
- **Selected server → نمایش لینک و QR → انتخاب کانفیگ:** choose **کانفیگ‌های بعد از خرید** or **کانفیگ‌های صفحهٔ سفارش**, then use the existing protocol/name selection or all/none controls. The Bot Settings server-display shortcut is removed.

A private channel's numeric membership identity and its invite URL remain separate. Existing destination values and the valid private `joinLink` fallback are retained. Staff roles, receiving preferences and the common receipt-review workflow are unchanged.

## Existing choices and independent native selections

Existing server documents are read without rewriting them. Each context uses its explicit list, otherwise the old shared `selectors` list, otherwise all native configs. An explicit empty list selects none; a missing match never exposes another config. Selecting one context does not change the other's effective choice.

Advanced native JSON may use `delivery_selectors` and `view_selectors`, with the same grammar and limits as `selectors`. Shared visibility, subscription, size limits and delivery-QR mode remain in the same policy. Order config QR follows the order-page selection; a first-config delivery QR follows the purchase selection. These are display choices, not provider access/Group changes.

When explicitly selecting ALL while an old shared filter exists, the editor preserves an inheriting peer by copying that existing list to its context, then removes the shared filter and selected-context override. This occurs only inside that explicit guarded edit, never at startup or during reads.

Managed Marzban compatibility keeps its established separate positional delivery/view lists and their existing semantics. No PasarGuard positional alias or separate management path is introduced. See [presentation details](SUBSCRIPTION-PRESENTATION.md).

## Recovery

RC16 does not understand newly stored context-selector keys. After configuring independent choices, do not downgrade source alone or blindly remove keys: ALL normalization may also have preserved an inherited list in a context key. Use an audited paired runtime/display-configuration recovery or roll forward; preserve both intended selections.

Do not restore an old database dump after new writes, erase replay/effect history or retry uncertain money/native effects. The existing post012 rule still applies: source-only RC14 rollback is unsupported. This release performs no actual tenant migration, cache reset, native operation or financial transaction.
