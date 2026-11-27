# Telegram presentation boundary

AChBot uses one optional presentation layer immediately before both form-encoded
and multipart Telegram Bot API requests. It is an **opt-in rendering facility**,
not a message/router, financial state, or content migration.

## Default and authority

With both presentation switches disabled, the shared **transport** is
an identity transform: payloads, JSON keyboards, callback data, ReplyKeyboard
press text, outgoing method, recipient, timeout and return contracts remain
unchanged. This guarantee is about the optional transport decoration layer;
it does not change the canonical HTML message format. The optional decoration
requires no additional runtime database fields.

The optional configuration belongs in the instance's private `baseInfo.php`,
not in catalog entries or a shared public file. The documented sample in
`baseInfo.example.php` is disabled. Never publish real bot credentials.

```php
$achbotTelegramPresentation = [
    'premium_emoji_enabled' => false,
    'premium_emoji_ids' => [
        // '✅' => '1234567890123456789', // replace with actual checked ID
    ],
    'button_styles_enabled' => false,
    'button_styles' => [
        'callback_data' => [
            // 'buySubscription' => 'primary',
        ],
        'reply_text' => [
            // '📞 ارسال شماره' => 'primary',
        ],
    ],
];
```

Use a separate policy for each bot. Turning either flag on intentionally
changes **presentation**, so requires owner approval and a safe test bot.
There is no automatic Premium owner-entitlement check: Telegram enforces
whether the bot can use Custom Emoji. Bot API IDs are decimal strings;
use `getCustomEmojiStickers` with an authorized test token to verify
availability and the intended Unicode fallback before enabling.

## Rendering contract

- A configured Unicode grapheme maps to its custom emoji ID. An enabled HTML
  text or caption gets `<tg-emoji emoji-id="...">✅</tg-emoji>`, with the
  **original emoji** left inside the tag for native fallback. HTML tags,
  attributes, entities, existing `<a>` elements, untagged `http(s)://`,
  `tg://`, `t.me/`, `www.` and other URI schemes (including VPN configuration
  links such as `vless://` and `ss://`) with encoded query strings, literal
  `<code>`/`<pre>` and existing `<tg-emoji>` content are not replaced.
- Plain text containing a mapped emoji is escaped and sent with HTML parse
  mode **only when Premium is enabled**. Literal text, identifiers, and
  copyable strings remain visually identical.
- Telegram text and captions use HTML or explicitly literal text. Incoming
  formatting entities are interpreted at their UTF-16 offsets by one shared
  renderer; user data is escaped rather than treated as markup.
- Inline buttons can replace an exact *leading* mapped emoji with
  `icon_custom_emoji_id` and can receive a mapped `style`. Existing
  callback values and actions remain unchanged. ReplyKeyboard labels are
  user inputs; the layer **never removes or rewrites them**, and currently
  does not auto-add Premium icons there. It can add configured colors without
  changing pressed text. Colors are restricted to `primary`, `success`,
  and `danger`; the default Telegram style is unchanged otherwise.
- Service payloads, financial records, Telegram callback alerts, URLs, native
  subscription/config bytes, and unrecognized methods are not transformed.

When a decorated request is **explicitly rejected** with Telegram error
400 for a custom emoji, button-style, or entity-format error, the original
payload is sent **once**. An uncertain delivery (timeout, invalid response,
HTTP 429, 5xx) is **never retried by this layer**: a previous send may have
been accepted, and the existing financial delivery journal remains authoritative.
Fallback does not mutate saved content, nor retroactively edit messages.

## Message ownership

The transport renderer is not authority to delete history. Preserve all
human-authored input and retained evidence, including issued invoices,
receipts, subscription information, support conversations and reports.
Once a customer receipt status is acknowledged, obsolete payment instructions
are transient and can be retired without removing actual financial evidence.
Opening a case from staff evidence creates a separate workbench; navigation
inside that workbench may edit it. Transient bot-owned navigation must be
retired only by verified identity;
a successful send, a successful edit, a definite missing target, and an
unknown delivery are distinct outcomes. First-start welcome and normal
menu share one message when within the Telegram text limit; an oversized
configured welcome is preserved separately, without a duplicate menu.
The approved shared refresh/link-change advice retains both auxiliary-link
and QR instructions unconditionally.

## Support conversations

The support router owns category selection, private typed title/body/reply,
staff categories, scoped queues, history/attachment access, closure and rating.
A ticket and its first message commit together. Subsequent messages serialize
with closure on the ticket row; a customer reply marks it awaiting staff, a
staff reply marks it answered, and closure cannot be undone by a late reply.
Each current inbound text/photo retains its original private chat/message ID;
legacy history remains readable with unknown identity left NULL. Literal text
that resembles photo JSON is not classified as media.

The existing sealed operational followup journal records the private original
audience in the same transaction as each accepted message, closure or rating.
No public report channel receives conversation content. Current recipient
authority and preference are checked independently at dispatch; known
non-application may retry, unknown sends are not repeated automatically.
Acknowledgement means accepted by Telegram, not read by the recipient.
Long text/captions are split without discarding content; history and queue
views share scoped rendering and deterministic bounded pages. Pagination
reflects current queue state, not a frozen historical snapshot.

Only disposable prompts may be removed best effort. Typed input must belong
to the actor's current editor, not a callback's message text. Support
cancel/menu navigation consumes only its matching editor and clears its draft,
without deleting the original conversation or erasing a newer unrelated
editor. The reply-keyboard removal and inline menu are separate Telegram
payloads where required. A successful registration notice describes durable
registration, never unconfirmed delivery.

## Canonical HTML ownership

Use HTML templates and escape untrusted dynamic text exactly once with
`achbotMessageHtml()` or `htmlspecialchars(..., ENT_QUOTES |
ENT_SUBSTITUTE, 'UTF-8')`. Do not escape trusted tags a second time.
Plain button labels, callback data, callback alerts and raw configuration
bytes are not HTML. Intentionally authored instance templates retain their
HTML contract. Stored checkout plan/category titles and descriptions are
literal data; the renderer escapes them exactly once and replaces template
tokens in one pass without interpreting tokens inside dynamic values.
Render the purchase template and the instance purchase note separately; append
the rendered note only after substituting checkout-owned tokens, preserving
literal token-like strings in the note and its HTML attributes.
Broadcast jobs store raw text for history and rendered HTML
for execution; edit jobs do the same. Historical pending jobs are not replayed.
The broadcast worker reserves each recipient in the existing durable cursor
before dispatch. Recipient-specific rejection, malformed or missing response,
transport exception, and a failed local ACK record do not discard later
recipients. An interrupted job resumes at the next unclaimed recipient under
the existing exclusive Cron lease, without a fixed stale-worker wait. Never
replay an uncertain dispatch, including a crash between claiming and sending;
a message may be omitted if its outcome cannot be established. Only a positive
integer message ID from an accepted Telegram response is recorded in
`bc_messages`; this is API acceptance, not proof of reading or receipt by the
person. Record actual unpersisted ACK IDs and failures in the private error log.
A failed database claim cannot authorize sending; failed audience/ACK reads
cannot claim an empty successful completion. The next admitted Cron tick can
recover after the database is available.

A confirmed Telegram 429 defers the broadcast queue until the provider's
valid `retry_after`, using the existing paused `send_list.updated_at` checkpoint
as the earliest eligible time (normally NOW; future only during the pause).
A rejected recipient is not automatically re-sent. Do not sleep for the
provider's wait while holding the shared Cron lease; without a usable wait,
yield to the next tick. Preserve FIFO across broadcasts. Keep the existing
400-row page and normal pacing; stop claiming new recipients after a 45-second
dispatch slice so slower sends cannot indefinitely occupy the other Cron
jobs. An in-flight request may finish beyond the slice. This is not a total
audience cap or a new scheduler. No new schema or state is required.

Dispatch and live progress use the same current audience predicate. The
remaining count is eligible rows after the durable cursor, not current group
size minus ACK count. At completion count unique recorded positive message
IDs for that broadcast only, independent of current audience. Persist
completion before the optional approved admin report; distinguish processing
completion from Telegram acceptance and exclude unconfirmed sends. Cancellation
cannot be reopened and failure of a report cannot restart customer sends.

Management-job admission serializes on the completed broadcast row. A
current staff editor generation, queued edit, and intended raw/HTML text
commit together; a failed admission cannot leave an independently queued
edit or consume the editor. Concurrent submissions of the same active
action reuse the existing in-progress result rather than create a second job.
A stale typed edit cannot authorize a new job.

Pin/unpin/delete/edit jobs use the same lease, claim and pause/finish
checkpoints, independently of the send queue. They also continue past a
recipient-specific failure and resume from the next unclaimed message
after interruption. Each queue gets its own dispatch slice. A worker-level
exception is reported to the coordinator but cannot suppress the other
queue or the remaining Cron jobs. Confirmed pin/unpin/delete outcomes
update the corresponding exact message flags and existing action counter
in one local transaction; an edit ACK must identify the original message
or confirm the identical edit via the shared Telegram no-op classifier.
Unconfirmed actions are not blindly replayed. An unpin always contains
the original message ID (never the latest-pin fallback); delete directly
targets that same message. The existing `bc_jobs.offset` uses the full
unsigned BIGINT domain of `bc_messages.id`; PHP preserves decimal strings
and SQL compares unsigned integers exactly. A claim with SQL conversion
warnings cannot authorize dispatch. Pause and completion change state
without rewriting the persisted cursor. `send_list.offset` continues to
match `users.id` without a schema change. No unrelated message or new
worker is required. Historical raw edit jobs and jobs without an eligible
completed source are not executable and cannot starve valid queued work. Explicit blocked/deactivated user responses may
update the ordinary user step, preserving bans and protected admin
workflows; other 403 responses do not label a user as blocked.
A contradictory HTTP/Bot API acknowledgement or rejection cannot authorize
a formatting retry in ordinary or multipart sends.
Support history stores original literal content, and live sends render
Telegram entities without modifying the incoming text.

## Validation

`php tools/test_post_v9_telegram_presentation.php` exercises the
transport identity, formatting, keyboard and known-rejection fallback
without a real Telegram request. Current catalog and wire/HTML parity
fixtures are part of [CI validation](CI-VALIDATION.md). HTML conversion and acceptance are tracked in [#209](https://github.com/ach1992/bot-refactor/issues/209).
Any visible change or real transport activation needs separate approval.

### Local conversation ownership and original user events

Use `AchBot\\Telegram\\Conversation` for current private input identity, the
actor-owned editor transition and transient screen replacement. A callback's
message text is not new human input. Home/Cancel and migrated editors compare
the current actor row, step and temp before consuming a draft; protected
specialized flows retain their own mutation boundaries. A verified edit ACK
must identify the target, or prove an identical numeric-message no-op.
A definitely missing/uneditable transient target may be replaced once;
old disposable prompt cleanup is best effort. Do not remove evidence or
introduce a global history registry. Telegram reply-keyboard removal and
inline markup are mutually exclusive; keep the necessary removal separate
where used. Callback acknowledgements use the normal Bot API transport.

For ordinary typed editors, successful completion consumes the current
actor's editor and removes its reply keyboard in the result message.
Validation failures keep the real pending editor and its Cancel keyboard.
When no editor is active, Cancel clears the obsolete keyboard and returns
Home without claiming an operation was canceled. Local settings/plan
writes and checkout draft admission consume the editor inside the owned
database transaction. Best-effort Telegram cleanup does not roll back
committed work. The generic step setter also compares current ownership;
dedicated money, evidence and Native boundaries remain authoritative.
User-role and order agency buttons have distinct callback namespaces;
order flags express a desired value instead of a replayable blind toggle.
Former ambiguous toggle callbacks are not current actions.

Current catalogue, trial, subscription-list and support navigation uses
`navHome` only on disposable screens. The shared callback verifier checks
the actual bot, private actor/chat, message ID and button before replacement;
an unproven source receives a new Home without touching the source.
Retained `mainMenu`, `evidenceHome` and payment navigation never overwrite
the original record. A fresh typed exemption cancel consumes its own
nonce and source marker without changing membership policy. A fresh typed
Home can abandon an unsubmitted direct gift through the existing gift
cancellation owner. No claimed financial/Native operation is canceled by
navigation. Manual rejection commits its reason editor with the decision;
reply keyboard cleanup is independent of the durable financial reports.
Discount creation and typed edits use the same local editor transaction
and one detail presenter. Concurrent creation of the same valid code is
serialized by code; no new schema or durable queue is required.

Closing a ticket or deciding an agency application consumes only the
actor's matching current editor in the same transaction as the decision.
If another actor already completed the target, the next matching typed
input retires the obsolete editor without repeating the effect. A failed
or unavailable lookup is not evidence of closure. Unrelated or newer
editors and retained conversation/report records are not cleared.
ReplyKeyboardRemove is sent separately from inline presentation and
is best effort after commit.

Purchase referral amount and invitation banner administration share the
source-bound editor transaction and the existing singleton setting
writer-lock pattern. The financial reader and editor use the same canonical
integer amount domain, including zero to disable rewards; no new cap is
introduced. The current actor and editor generation, exact setting row and
write result are checked before commit. The authenticated input must also
follow the editor-opening update, even when a delayed webhook reads a newly
opened editor for the same source message. Telegram's documented idle update-ID
reset does not expire a still-valid editor. The same opening-generation
decision is enforced on the locked current actor row when Cancel, Home,
admin-panel navigation or another editor actually abandons or replaces
the referral editor. Fresh navigation does not depend on deleting the old
prompt or holding settings-write permission; malformed state can be
abandoned but cannot authorize a setting save. An editor start or advance
must confirm its state transition before emitting the corresponding input
prompt; retirement of an obsolete disposable message is best effort.
Admin-panel navigation removes the persistent reply keyboard only on an
actual confirmed editor exit, independently of the inline panel edit.
Independent retained business effects keep their own ownership boundaries
and do not reset an unrelated editor. Concurrent creation is serialized
within the same database and setting type; ambiguous existing rows are not
silently overwritten or deduplicated.


Subscription search prefers a unique exact name and otherwise permits a
unique literal substring within the current authorized active order scope.
Ambiguous matches ask for refinement, not an arbitrary first result.
A refresh is a verified Native observation, never a new purchase or
rotation. Same-source rotation recovery must match both the verified
Native proxy identity and subscription URL digest. Render the proven result before claiming the exact approved
refresh/rotation success and retain both approved advice lines.

First-start admission and original customer/staff/channel packets commit
together in the existing followup journal. Ordinary Home is still available
if staging is unavailable or another request has already claimed onboarding.
The first-start flag means admission, not Telegram acceptance. Combined
welcome and menu stay together unless the actual platform text limit
requires multiple pages. Agency application controls are bound to the
original application generation; decisions revalidate that generation and
current authority and persist the original result notice in the same
transaction. The existing report audience and worker are reused. Current
recipient eligibility, classified retry and unknown-delivery handling are
the same as for other original events. No new table or timer is needed.
