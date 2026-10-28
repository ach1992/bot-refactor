# Unified management upgrade from RC13

This is source-candidate guidance, not evidence of a production rollout. Use the exact reviewed commit, runtime ZIP and its trusted all-file manifest. No new SQL migration is required for a correctly migrated RC13 installation; migration 011 and existing payment/effect/identity records stay intact.

## Runtime changes

The current runtime has 111 files. Five files are new: the common management draft controller, server wizard, plan wizard, shared lifecycle policy and the full public `pay/checkout.php` implementation.

Six RC13 files are removed. Their exact RC13 byte counts and SHA256 hashes are recorded in [RETIRED-COMPATIBILITY-PATHS.json](RETIRED-COMPATIBILITY-PATHS.json):

- `settings/subLink.php`: unused old-panel response stub.
- `settings/values.php`: bindings now initialize directly from the existing two message catalogs in config/health.
- `settings/messagewizwiz.php` and `settings/warnusers.php`: actual task composition is in admitted, isolated child modes of the existing Cron coordinator.
- `pay/index.php` and `src/Finance/GatewayInitiation.php`: the full implementation is consolidated in `pay/checkout.php`.

The already retired automatic TRON/WeSwap files remain absent. Remove verified retired source members during the approved source replacement; extracting over old PHP leaves callable old routes behind. Refuse unknown modifications instead of deleting unrelated files.

## Public routes and scheduler

All new hosted-payment initiation links use `pay/checkout.php?...`. Previously sent `pay/?...` initiation links are deliberately retired under the owner's path-cleanup instruction; users need a fresh bot payment/status message. No redirect or compatibility alias is installed.

`pay/back.php` remains byte-identical to RC13. Already issued provider browser-return and signed NOWPayments IPN callback URLs retain their genuine callback boundary. The new checkout admits GET only and rejects other methods before private bootstrap or invoice work.

Keep the existing `settings/cronRunner.php` Cron command. It still runs four isolated jobs in the same order under the same whole-batch lease. Broadcast and service maintenance run as admitted child modes of this actual coordinator; direct invocation without its held, trusted inherited lease fails closed. Full gift/report implementations remain active files. Neither a manual worker invocation nor an HTTP request is a safe deployment smoke test.

If an installation independently scheduled one of the removed old worker files, reconcile that schedule explicitly before rollout. Do not leave a duplicate independent worker active alongside the coordinator.

## Private and persisted state

Preserve the real `baseInfo.php`, all credentials, local runtime state, customer/payment/effect/identity data, wallet balances and existing display/rate settings. No new provider/server/order table is introduced. The six obsolete private display/rate assignments were already retired before RC13; this upgrade performs no additional private-settings migration.

The new warning and renewal keyset cursors use ordinary `setting` rows; obsolete cursor rows may remain inert. Existing switches and 36-hour/0.5-GiB warnings, 2-hour/0.2-GiB renewals and 10-day deletion grace retain their policy. Unverified, disabled or on-hold observations cannot authorize charging/deletion. Pending financial work, changed targets or an ambiguous destructive result require reconciliation rather than a blind retry.

Old in-progress management drafts are canceled or safely restarted without deleting another administrator's plan rows. New drafts are per actor and are saved only after final confirmation. Existing native provider identities and per-server configuration display choices are retained.

Outside-bot PasarGuard account adoption is a separate unresolved ownership/identity contract. Fresh purchases, trials, administrator gifts, renewals and volume/time extensions use the common native-provider pipeline.

## Rollout and verification

Complete the required exact-candidate PHP 8.1/8.4 CI and independent review before the separately approved rollout. Quiesce authenticated requests and scheduled effects using the installation's existing maintenance/lease mechanism, verify the prior source inventory, reconcile exact retired members, and replace the source in the already approved bot directory. Do not create another deployment directory or overwrite private state.

Verify every current member against the trusted manifest, absence of all retired paths, PHP syntax, scoped OPcache refresh and the read-only readiness report before ending maintenance. The readiness tool blocks retired compatibility files even when all new members match.

Operator acceptance should cover both native server/plan wizards, back/cancel from old and new messages, existing service views, native entitlements/display, order actions, add-on options and payment/status pages. Any real purchase, provider mutation or scheduled-effect test needs its own authorized test context; a successful unauthorized-webhook rejection is not an end-to-end purchase.

A code rollback restores the exact RC13 source inventory and removes candidate-only public files while preserving current database/private state. It does not undo completed payments or remote actions, release uncertain holds, or justify replaying an effect. Reconcile those outcomes from the existing journals.
