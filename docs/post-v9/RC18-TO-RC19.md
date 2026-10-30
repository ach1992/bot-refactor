# RC18 → RC19

RC19 publishes reviewed source `9451ef306be34bc224d57645d7b7394b8bea3a99` (tree `61dabc1cf10c184e35275811eccb0de0870c04ea`), integrated by [PR98](https://github.com/ach1992/bot-refactor/pull/98). It simplifies shared native Marzban/PasarGuard presentation, retires the root purchase-helper path and manages invitation gifts in the existing gateway/channel UI.

## Migration and configuration

**No new SQL migration is introduced.** A compatible RC17/RC18 installation keeps its existing schema, data, financial journals and native identities. Never rerun completed012/013. Earlier or partly migrated installations must follow their exact [shared-storage](SHARED-PROVIDER-STORAGE.md) and upgrade contracts; the [fresh-install SQL sequence](FRESH-INSTALL.md) is only for a genuinely new empty database.

Invitation gift is now `PAYMENT_KEYS.inviteReward`, immediately after Wallet in Gateway/Channel Settings. The existing guarded editor accepts integer Toman 0–999999999; 0 or `/clear` disables future gifts. Unset, malformed or duplicate configuration disables them. RC19 ignores retained private `$inviteReward` and does not import it. Configure the intended amount through the new UI before reopening public invitations. Preserve the private file; any removal of the obsolete assignment belongs to the paired operator-controlled runtime/configuration rollout.

Existing display documents remain readable; reads do not backfill settings. Edit delivery and order-page contexts independently through the selected server. Newly unconfigured policies default to subscription/config/available auxiliary links on, first 3 inline configs and purchase subscription QR. Explicit prior policies remain authoritative until edited.

## Install the complete runtime

1. Verify the official runtime ZIP, its manifest and `SHA256SUMS`, including exact source/tree. The archive has 115 runtime members / 114 PHP files and contains no real private configuration, SQL/data, logs, backups or test tools.
2. Prepare a protected recovery copy and a coordinated cutover. Preserve the real `baseInfo.php`, database, settings, webhook and Cron command/environment/active lock inode. Do not run old and new writers together or delete/recreate an active lock.
3. Replace the complete 115-member runtime consistently. The old root `buy_helpers.php` is **retired**: remove that old installed file as part of the approved cutover; extracting over the old folder alone does not remove it. Its real implementations now live in `src/Subscription/Delivery.php`, `src/Purchase/OrderWrites.php` and canonical shared purchase modules. No alias remains.
4. Confirm the managed invitation amount and each server's intended display policy. Order-page QR has no per-server selector: the two Bot Settings switches govern explicit config/subscription QR actions. Config QR sends every accepted native config individually, independent of inline selection, visibility and the ten-inline limit.
5. Verify the complete installed manifest and actual serving PHP runtime during the operator-controlled acceptance window. Use [installation diagnostics](INSTALL-DIAGNOSTICS.md) and the [read-only readiness preflight](PAYMENT-READINESS-PREFLIGHT.md) with their documented trust and SELECT-only constraints.

Copying the ZIP into a bot folder does not extract, install or activate it. Publication/source CI does not certify real Telegram rendering, provider operations, merchant/wallet settlement or Cron behavior.

## Display and QR behavior

Purchase-delivery and order-page sections each have paired subscription/config/auxiliary switches. Config-on exposes ordered exact-name selection, one name per line, maximum 10 inline; automatic mode selects first 3. The complete final message preserves order facts and subscription/auxiliary links, removing whole trailing configs to fit and splitting protected overflow safely. Only purchase delivery has the subscription/first-selected-config/off QR cycle. Details: [subscription presentation](SUBSCRIPTION-PRESENTATION.md).

## Recovery and acceptance

No new schema rollback boundary is added. Before an approved downgrade, reconcile the new display choices and managed invitation amount: RC18 does not understand the same new controls and resumes its private invitation source. Do not erase current settings or restore an old database after later writes merely to undo a display change. Earlier pre012 source/schema recovery restrictions still apply; never retry an uncertain financial/native effect blindly.

Installed acceptance remains open in Issues [26](https://github.com/ach1992/bot-refactor/issues/26), [37](https://github.com/ach1992/bot-refactor/issues/37), [51](https://github.com/ach1992/bot-refactor/issues/51) and [81](https://github.com/ach1992/bot-refactor/issues/81). Known separate investigations include the pre-existing callback-ack URL observation and a non-reproduced CI lock-witness precondition failure; see the [source checkpoint](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6512297673). Neither is claimed fixed by this release.
