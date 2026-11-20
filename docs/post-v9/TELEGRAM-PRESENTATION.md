# Telegram presentation boundary

AChBot uses one optional presentation layer immediately before both form-encoded
and multipart Telegram Bot API requests. It is an **opt-in rendering facility**,
not a message/router, financial state, or content migration.

## Default and authority

With both presentation switches disabled, the shared **transport** is
an identity transform: payloads, JSON keyboards, callback data, ReplyKeyboard
press text, outgoing method, recipient, timeout and return contracts remain
unchanged. This guarantee is about the optional transport decoration layer;
it does not negate individually reviewed, source-level Markdown-to-HTML
conversions of static prompts. Those conversions intentionally change the
wire-level parse mode while preserving the rendered text and formatting.
An older installation without the new settings stays in transport identity
mode. No runtime database schema or migration is required.

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
- Markdown/MarkdownV2 content is never silently fed to an HTML parser.
  A few historic call sites and legacy default parameters still use legacy
  Markdown. These require a verified, output-equivalent producer migration
  before premium decoration can cover them. Do not replace a parse-mode
  string without checking escaped dynamic inputs and stored content.
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

## Legacy HTML migration boundary

An unconditional conversion of the old `sendMessage()`/`sendPhoto()`
Markdown defaults to HTML would change the appearance of messages already
constructed with legacy Markdown or mixed literal HTML. It can also alter
customer-authored support text, escaped placeholder values and historical
broadcast jobs, potentially changing copy/paste semantics.

For future source changes prefer **HTML as the template/format source** and
use `achbotMessageHtml()` or `htmlspecialchars(..., ENT_QUOTES |
ENT_SUBSTITUTE, 'UTF-8')` at the correct dynamic-text boundary. Do not
escape trusted HTML tags a second time. Avoid HTML for plain button labels,
callback data, callback alerts and raw machine-consumed configuration bytes.

Before migrating remaining legacy calls, test the exact old rendered text,
entities and URLs against the candidate HTML, including: Persian RTL text,
underscores, backticks, brackets, quotes, ampersands, user content, captions,
zero/long text, optional per-instance templates, scheduled broadcasts and
retries. Preserve existing Golden/Stable test evidence and extend it rather
than rewriting it as if no behavior changed.

## Validation and rollout

`php -n tools/test_post_v9_telegram_presentation.php` exercises default
identity, HTML token boundaries, Unicode graphemes, keyboard semantics,
method coverage and known-rejection fallback without a Telegram API request.
It also runs inside the current `php85-validation` acceptance gate.

For audited static HTML call sites, run
`python3 tools/test_post_v9_telegram_html_callsite_parity.py`.
The check uses PHP's `token_get_all()` to distinguish executable sends from
comments, quoted literals and non-PHP text (the CLI PHP `tokenizer` extension
must be available). It compares exact per-file counts and source-argument/catalog
fingerprints, not source line numbers. An in-memory regression check verifies
that physical removal, `//` or block-comment disabling, and keyboard mutation
invalidate the audit, while line-number/whitespace changes do not.
The audit also pins reviewed explicit `parse_mode = null` call signatures:
these sends intentionally remain unformatted, rather than being converted
only for uniformity. The HTML and explicitly unformatted counts are reported
separately. Composed, parameterized, customized and persisted messages remain
outside this static callsite contract.

The ten reviewed discount-administration catalog compositions have a separate
source+rendering parity test:
`python3 tools/test_post_v9_telegram_html_discount_compositions.py`.
It pins the original catalog fragments and keyboard expressions, exercises
PHP's actual rendering (including an integer-coerced userid), and verifies
that removed, comment-disabled or keyboard-modified sends cannot pass the
source contract. The userid check uses executable PHP tokens within the
owning conditional to require the actual integer-cast assignment and reject
later userid writes, including comment-spoofed casts and post-cast
reassignments. It also requires a top-level, unguarded cast statement and
pins the reviewed branch's executable token path up to the notification,
so newly inserted conditional or loop guards and jumps cannot silently
skip it. Whitespace and comments remain irrelevant; any changed executable
control flow requires deliberate review. Rendering executes the validated
source assignment.
This is intentionally not a generic Markdown converter:
user-authored, customized and persisted content remains out of scope.

On an intentional change, inspect and review the effective diff first, then
run the read-only `--print-audit`
option to obtain proposed new fingerprints; never update the expected values
solely to make CI green. Other dynamic or persisted content is outside this
static-audit contract.

A green PHP unit test or CI build does **not** prove that a live bot owner has
Telegram Premium, that a Custom Emoji ID exists, or that every Telegram client
renders the same style. These are separate installed/test-bot acceptance checks.
Do not enable the flags, deploy to Hell/Vivan or send live payment messages as
part of a source-only presentation refactor.
