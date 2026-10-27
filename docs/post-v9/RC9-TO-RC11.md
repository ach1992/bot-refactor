# RC9 → RC11 upgrade guide

This package targets an installation running **v0.10.0-rc.9**. The owner
will skip RC10. No RC10 rename/import migration is included; published
RC10 artifacts remain unchanged. This guide is preparation, not evidence
that a tenant has been upgraded.

## Published package

Use [v0.10.0-rc.11](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.11)
and its verified `achbot-v0.10.0-rc.11-runtime.zip`, accompanying manifest,
checksums and separate SQL assets. The [Persian guide](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.11/achbot-v0.10.0-rc.11-UPGRADE-fa.md)
is attached to the release. GitHub's automatic Source code archive is not
the verified runtime package.

The tag and manifest pin independently approved source
`2b3719a298b50448d66944ff644d6f466c44396b`; [CI 40431797398](https://github.com/ach1992/bot-refactor/actions/runs/40431797398)
passed on PHP 8.1 and 8.4. Protected-main integration `a034950028093ebe9c2c198004caf4aff1317139`
has the identical reviewed tree. The release remains a source-only
prerelease for owner-run installed acceptance; publication performs no
tenant installation or SQL migration.

## Source and schema

The runtime has **104 files**. SQL sidecars remain outside the source ZIP
and are never applied by extraction. Verify the release checksums and exact
manifest before staging the package. Keep the installed private
`baseInfo.php`, database, access controls and unrelated bots intact.

Inventory the actual database immediately before upgrade. An earlier
read-only Chili inspection found RC9 source and migrations 001/003, with
004–009 absent; another separately authorized operator may have changed
that state since. Never use that old observation as permission to rerun SQL.

| Sidecar | Condition |
| --- | --- |
| 001_marzban_panel_group_id.sql | Skip if its exact column is already present. |
| 003_payment_attempts.sql | Skip if its exact two shared tables are already present. |
| 004_automatic_renewal_attempts.sql | Apply only if absent and automatic renewal is being enabled under the matching contract. |
| **005_wallet_adjustments.sql** | New generic journal required for admin wallet actions **and user transfers**. Apply to RC9 only after confirming both `wallet_adjustments` and `admin_wallet_adjustments` are absent. |
| 006_payment_message_refs.sql | Required for matching durable payment-message behavior; check exact shape first. |
| 007_service_effects.sql | Required for guarded provisioning/edit/recovery paths; check exact current shape, not just table existence. |
| 008_referral_awards.sql | Required for journal-backed referral rewards. |
| 009_bank_buy_invoice_claims.sql | Required for guarded manual bank purchase creation. |

Do not run historical NOWPayments-specific 002 or RC4 import merely to
prepare an RC9 upgrade. Do not recreate any existing table, drop old
financial evidence, bulk rename a table, manufacture historical journal
rows or modify the frozen 23-table schema file. If a 005 table already
exists, stop this migration path and audit the actual source/schema
separately. This is deliberately not a compatibility procedure for RC10.

Before separately authorized installation, take access-controlled file/SQL
backups and rehearse restore. Apply only missing, reviewed sidecars and
verify their full schema contracts using the matching readiness tooling.
Reconcile the source inventory and retired files for this exact release;
ZIP extraction alone is not proof of a complete upgrade. Keep wallet and
financial entrypoints quiescent during coordinated source/schema replacement.

## Private per-bot webhook secret

Add this field to **each bot's own existing private `baseInfo.php`**,
alongside its other variables, with a unique random value:

```php
$telegramWebhookSecret = '<unique-random-secret-for-this-bot>';
```

Replace the placeholder. Generate at least 32 random bytes and encode as
hex or unpadded base64url; the accepted field is a string of 16–256 ASCII
letters, digits, hyphens and underscores. The example above is not a usable
secret. Keep the file private and excluded from Git/backups shared publicly.
The historical `baseInfo.example.php` remains byte-frozen; this additive
field is documented here for existing installations.

During the separately authorized Telegram rollout, use the **same value**
as `setWebhook.secret_token` for that exact bot. The private field alone
does not update Telegram. The code reads no shared environment fallback
and requires no OpenLiteSpeed, LSAPI or PHP-FPM setting. Hundreds of bots
may share one PHP process while retaining different secrets.

The trusted private file is loaded in global scope before ingress auth;
`config.php` subsequently reuses its original configuration variables.
Missing/invalid secret, wrong/missing header or a non-POST request returns
403 before database/route initialization. Direct Gift authentication and
payment-key editor HMAC use this same file-local field. Rotating the
secret invalidates pending payment-key editor baselines; restart those
editor flows. Do not log the secret or place it in command output.

## Wallet behavior and operator acceptance

`wallet_adjustments` retains admin credit, routine debit and deliberate
privileged debt, plus paired user transfer rows. Each transfer's two legs,
balances and consumed step commit together. Missing/incompatible journal
or an INSERT failure blocks/rolls back the transfer. A lost COMMIT
acknowledgement remains under review; never retry or refund automatically.

Existing pending transfers with unversioned or older markers must be
restarted using the displayed Persian action. New starts, recipient
selection and amounts are bound to one actor-locked versioned generation.
Both update/message freshness checks apply at each message stage. A
durable per-actor cursor in the original InnoDB `setting` table survives
completion/cancellation, preventing old starts or selections from taking
over another flow. No extra SQL sidecar is needed for that cursor.
Do not remove `ACHBOT_WALLET_TRANSFER_*` records during cleanup/rollback.

Migration 005 also requires the independent unique
`(telegram_update_id,entry_no)` key; the same update cannot be reused across
admin adjustments and transfers. A table missing this key is incompatible,
even if its generic source index is present.

If Telegram assigns a lower update ID after a long idle period, or an actor
account is deleted/recreated, retained cursor ownership fails closed.
Do not clear it automatically: stop affected transfers and reconcile the
specific bot's cursor, incoming identities and journal under separate
operator authorization. See the full cursor/storage/replay contract in
[ADMIN-WALLET-ADJUSTMENTS.md](ADMIN-WALLET-ADJUSTMENTS.md).

The journal begins recording these routes after installation. It is not
a reconstructed statement of every historical wallet movement. Existing
pays/payment/attempt/effect/referral evidence remains authoritative.

The owner will perform actual bot acceptance. Check ingress for each bot,
admin credit/debit/debt, user transfer and redelivery, then the relevant
payment/provisioning paths with the approved staging scope. Source CI is
separate from live Telegram/provider or real-money acceptance. PasarGuard
is deferred and is not enabled by this package.

## Rollback

Preserve every new financial row and journal. Stop affected entrypoints,
reconcile unknown outcomes by source ID, and choose source that enforces
the same journal/auth contracts. Do not restore RC9's unjournaled wallet
writes while new audited operations are active, or drop tables to make
an older source appear compatible. Restoring a coordinated pre-upgrade
snapshot requires an explicit assessment of any post-backup effects.
