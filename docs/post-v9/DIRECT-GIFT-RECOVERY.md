# Direct administrator gift — source safety and recovery (Issue #78)

**Scope:** new post-V9 single-account administrator gift. Source-only, pending
fresh independent high-assurance review, separate Owner merge authorization,
operator installation acceptance, and separate activation authorization. This
does not change the merged bulk-gift Cron worker in PR #66. Frozen V0–V9
history and the original 23-table database remain unchanged.

## Supported flow

1. A newly delivered private Telegram giftToUser server callback starts a
   random-nonced wizard for the currently authorized operator. Each step
   needs a fresh private text update whose update/message IDs advance,
   matching actor and fresh MariaDB administrator privilege. Revoked
   delegated administrators and stale/out-of-order callbacks fail closed.
2. Remark resolution requires exactly one active orders_list primary-key
   identity for the selected logical server. It pins order id, userid,
   server_id, inbound_id, fileid, remark, uuid, status and old expire_date.
   At least one of days and MB must be positive. Days must be whole units;
   nonzero MB must be a multiple of 1024. Zero plus_* properties are omitted.
3. Before potentially non-idempotent Marzban effects, one committed InnoDB
   transaction creates a permanent source journal and physical-account hold
   in setting and consumes the exact admin wizard step. The original
   setting.type and users.userid are NOT unique, so the source serializes
   through a genuine users.id primary-admin row and checks ambiguity.
   Account identity uses the explicit panel group when available, otherwise
   the pinned physical endpoint plus remark. The target credential hash is
   captured, but no cleartext credential is stored in the journal.
4. The verified service-edit seam must return true success, true verified,
   no uncertainty, and exact absolute integer expire and quota readback.
   Provider HTTP success alone is insufficient. A guarded order identity
   CAS and local expiry persistence commit with source and hold completion
   before success notification.
5. An uncertain remote response, lost acknowledgment, local SQL failure,
   ambiguous order, target drift, crash, and notice failure never rearm a
   remote relative increment. A pending/review hold remains durable until
   an authorized manual investigation. No automatic retry or refund occurs.

## Critical webhook authentication prerequisite

**The direct gift feature fails closed unless configured with Telegram
webhook secret authentication.** The PHP process must be able to read the
ACHBOT_TELEGRAM_WEBHOOK_SECRET environment variable. Configure a distinct
high-entropy 16–256 character secret with only ASCII letters, digits, hyphen
and underscore as Telegram setWebhook secret_token, under separate operator
approval, and expose the same value to PHP-FPM/LSAPI. Telegram sends the
X-Telegram-Bot-Api-Secret-Token request header. The route constant-time
compares the header to its configured secret before trusting JSON input.

Missing or mismatched secrets deny direct gifting without provider calls.
A shell export does not establish that PHP-FPM/LSAPI sees the secret.
Do not put it in this public repository, test data, logs or screenshots;
do not call setWebhook as part of source CI. This change authenticates only
the new direct gift route, not every legacy webhook entrypoint.

## Manual reconciliation by an authorized operator

On the message indicating an uncertain outcome or an already-held account,
record the displayed immutable numeric setting.id. Authorized operators can
inspect the source record, its state, original order snapshot, target URL
and credential fingerprint, operator/source identity and requested units.
The account hold links back to the source id.

An example for a separately authorized **read-only** SQL session:

    SELECT id, type,
           JSON_UNQUOTE(JSON_EXTRACT(value, '$.state')) AS state,
           JSON_EXTRACT(value, '$.order.id') AS order_id,
           JSON_UNQUOTE(JSON_EXTRACT(value, '$.review_reason')) AS review_reason
    FROM setting
    WHERE type LIKE 'ACHBOT_DIRECT_GIFT_%'
    ORDER BY id DESC LIMIT 50;

Compare the exact physical provider account, the local order identity and
expiry, actual remote absolute expiry/quota, and intervening edits. A reused
username may represent a deleted/recreated provider account and is not by
itself immutable identity evidence. A pending source may have applied
remotely even if its acknowledgment was lost.

There is deliberately no generic unlock or retry command. Preserve the
journal and obtain a separate authorization for any manual provider/data
correction, with explicit current-state evidence and conditional writes.
Never automatically repeat a held relative increase. The unindexed
setting.type uses non-locking scans and PK-scoped locking, but source claims
can eventually become costly at scale;
a new unique ledger/index requires a demonstrated need and a separately
accepted additive schema decision, not an unreviewed migration.

## Compatibility and rollback

- Current post-V9 runtime ZIP adds one DirectGift PHP file and has 99
  source-only members, not the previous 98. Frozen V0–V9 evidence is untouched.
- Old non-nonced giftToUserRemark, giftUserDay and giftUserVolume steps are
  inert in this runtime. Restart them through the new menu after reconciliation.
- Rolling back to an older bot may expose the unsafe old direct gift route
  which ignores new journal holds. Operators must disable direct gifting or
  establish safe compensating controls before an authorized rollback.
- Native test: python3 tools/test_post_v9_direct_gift.py --run-disposable
  runs actual bot.php handler, PHP/mysqli, original-schema disposable socket
  MariaDB and synthetic Telegram/Marzban. PHP 8.1/8.4 full source/history CI,
  artifact checks, independent review and operator acceptance remain gates.
- CI does NOT certify installed tenant schema, real Telegram webhook
  authentication, real provider account incarnation, PHP-FPM/LSAPI, actual
  customer data, payment state, real service effects, or production rollout.

See [Issue #78](https://github.com/ach1992/bot-refactor/issues/78),
[Issue #26](https://github.com/ach1992/bot-refactor/issues/26) and
[PR #66](https://github.com/ach1992/bot-refactor/pull/66).
