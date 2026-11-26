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
