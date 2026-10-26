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
   matching actor and fresh MariaDB administrator privilege. CANCEL is also
   a guarded transition: its update ID must exceed the accepted step update,
   and Day/Volume cancellation additionally requires a newer private-chat
   message ID. Revoked delegated administrators and stale/out-of-order
   callbacks or cancellations fail closed. The final global legacy CANCEL
   fallback is also guarded: it locks the exact user primary key, compares
   the fresh row to the originally cached non-direct step, and performs an
   exact-step CAS. If a newer direct-gift nonce has appeared, it skips both
   the old state reset and unrelated legacy cleanup/notifications. The
   original config.php setUser() additionally refuses any legacy step write
   while an active direct-gift step owns the row, protecting other stale
   legacy handlers. The legacy ManualAccounts numeric transition now
   uses exact users.id, actor, current administrator role, expected step
   and original temp as one transactional CAS; stale requests cannot alter
   either step or temp or emit a false success. Other older ban/unban and
   broadcast step writers are conditionally fenced so stale/automatic
   transitions do not erase a newer gift. Ordinary fresh non-direct CANCEL
   remains supported.
2. Remark resolution requires exactly one active orders_list primary-key
   identity for the selected logical server. It pins order id, userid,
   server_id, inbound_id, fileid, remark, uuid, status and old expire_date.
   At least one of days and MB must be positive. Days must be whole units;
   nonzero MB must be a multiple of 1024. Zero plus_* properties are omitted.
3. Before potentially non-idempotent Marzban effects, one committed InnoDB
   transaction creates a permanent source journal and physical-account hold
   in setting and consumes the exact admin wizard step. The original
   setting.type and users.userid are NOT unique. Unindexed userid-to-PK
   resolution happens **before** the transaction; the first transactional
   read locks the real primary-admin users.id PK, then rechecks ambiguous
   identities on a snapshot established **after** that lock. This order
   prevents stale InnoDB REPEATABLE READ views from missing the previous
   claimant's marker. No unindexed setting.type FOR UPDATE table scan is
   needed. Account identity uses the explicit panel group when available,
   otherwise the pinned physical endpoint plus remark. The target credential hash is
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

**The bot.php webhook entrypoint now fails closed without Telegram
webhook secret authentication, BEFORE config.php can run. This applies
to all Telegram update routes, not only Direct Gift.** The PHP process must be able to read the
ACHBOT_TELEGRAM_WEBHOOK_SECRET environment variable. Configure a distinct
high-entropy 16–256 character secret with only ASCII letters, digits, hyphen
and underscore as Telegram setWebhook secret_token, under separate operator
approval, and expose the same value to PHP-FPM/LSAPI. Telegram sends the
X-Telegram-Bot-Api-Secret-Token request header. The route constant-time
compares the header to its configured secret before trusting JSON input.

Missing/mismatched secrets or non-POST requests return HTTP 403 before
database initialization, legacy callbacks, messages or provider calls.
A shell export does not establish that PHP-FPM/LSAPI sees the secret.
**An installation without this provisioned secret will not receive any
webhook updates after a separately authorized source deployment.** Validate
the exact PHP-FPM/LSAPI process environment, webhook secret_token and
operational rollout/rollback separately; never setWebhook in source CI.
Do not put the secret in public code, test data, logs or screenshots.

The formerly pre-check config.php toggleBan_*/toggleAgent_* callbacks now
execute only after authenticated webhook ingress and the existing IP guard.
They require an exact private callback envelope, freshly authorized
administrator row, unique actor/target PKs, current state locks, and a
durable per-actor update/message watermark in existing setting records.
Repeated/out-of-order or ambiguous callbacks fail closed; unknown COMMIT
acknowledgments do not authorize replay or a success notification.
**Moderation precedence:** only a verified *newer* explicit administrator
ban callback may supersede an active Direct Gift wizard. Older moderator
callbacks and inherited legacy text/receipt bans cannot erase active gift
state. Agent status toggling never changes the wizard's step.


### Related administrator-state ownership (B1-R1/B1-R2)

The legacy `addExemptUser` channel-membership exemption workflow is now
invoked from `bot.php` **after** authenticated Telegram ingress and the
existing Telegram-IP check, never as a `config.php` initialization side
effect. Only a currently authorized primary/delegated administrator can
start an exact private Telegram callback and finish with a newer, genuine
private numeric message. The nonce-bearing `achbotExempt:` step and a
per-actor durable source watermark in existing `setting` are atomically
updated under the primary-administrator PK lock. Completing the wizard
checks the current actor PK and role, exact step, update/message freshness,
unique `EXEMPTED_USERS` policy record, and commits the policy, step and
source marker together. Ambiguous, stale, revoked or unknown-COMMIT
outcomes never produce a success message or authorize an automatic retry.
The historical pre-upgrade `awaitingExemptUserID` step has no recorded
source provenance and must be restarted with a **fresh** authorized admin
callback; it cannot silently grant channel exceptions. No SQL migration.

Legacy `setUser('none')` and the `mainMenu` handler are **not** authorized
unban paths. Generic legacy step updates cannot consume the protected
`banned`, `achbotDirectGift*`, or `achbotExempt:*` states; a cached old
menu request also rechecks current status before showing its menu. An
explicit, authorized unban still uses its dedicated PK/current-step CAS.
Older text/receipt-ban and automated broadcast writers cannot erase either
protected wizard generation; a *newer*, authenticated, authorized moderator
callback can deliberately ban and supersede one. Other legacy non-banned
navigation remains supported.

`config.php` is not directly executable as a public HTTP handler. Its
Telegram update binding is enabled only for the authenticated `bot.php`
entrypoint (payment/cron includes cannot reinterpret arbitrary input bodies
as bot callbacks). The IP allowlist is checked before initialization-time
Telegram branches when included from `bot.php`; the route guard is retained
as defense-in-depth.

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
  MariaDB and synthetic Telegram/Marzban. The concurrency regression waits
  for a real InnoDB owner-PK lock wait before committing the first claim;
  it covers REPEATABLE READ and READ COMMITTED, and a disposable optional
  panel-group alias check. The F2-R1 regression also stages the actual final
  bot.php global CANCEL fallback and original config.php setUser(), holds
  a request after its cached userInfo read, commits a newer Remark/Day/Volume
  gift on another PHP worker, and confirms the obsolete CANCEL cannot erase
  it or perform legacy cleanup. Fresh legacy CANCEL, delegated revocation,
  lost COMMIT acknowledgment and the central legacy step-write guard are
  covered. Additional B1/F2-R2 regressions exercise the actual ingress,
  moderation callbacks and ManualAccounts.php with explicit two-worker
  cached-step synchronization, valid/invalid provenance, role revocation,
  stale/fresh moderator precedence, replay and ambiguous COMMIT outcomes.
  The manual numeric route also rejects old callback bot-text/mixed frames;
  actual legacy ban, receipt-ban and broadcast blocked-status writes are
  tested on the original schema, including preserving ordinary broadcast
  behavior. Source-real acceptance additionally executes the actual
  config.php channel-exemption handler, the actual bot.php mainMenu route,
  their lost-commit, revoked-admin, stale-frame, synchronized stale-worker,
  explicit-unban and nonce-preservation regressions. It requires at least
  132 cases, including global-policy/actor-step atomic rollback when
  an isolated SQL trigger rejects the state transition, preservation
  of already-existing exempt users, and signed newer callbacks from older
  bot-authored menu messages. Telegram update IDs order callback events;
  the bot menu message ID is not incorrectly treated as monotonic.
  No installed schema is changed. PHP 8.1/8.4
  full source/history CI, artifact checks, independent review and operator
  acceptance remain gates.
- CI does NOT certify installed tenant schema, real Telegram webhook
  authentication, real provider account incarnation, PHP-FPM/LSAPI, actual
  customer data, payment state, real service effects, or production rollout.

See [Issue #78](https://github.com/ach1992/bot-refactor/issues/78),
[Issue #26](https://github.com/ach1992/bot-refactor/issues/26) and
[PR #66](https://github.com/ach1992/bot-refactor/pull/66).
