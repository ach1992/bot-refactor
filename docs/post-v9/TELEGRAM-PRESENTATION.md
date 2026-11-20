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

The current admin-wallet reporting path (`src/Finance/Routes/AdminWallet.php`)
uses explicit HTML for debt preview, committed operator/recipient notices,
changed-balance warnings, uncertain-result warnings, and the reason prompt.
These producers concatenate only fixed catalog fragments and PHP integer
operation facts (including formatted signed balances); the arbitrary private
reason is not interpolated into the displayed notices. The existing
per-instance `$mainValues` prompts retain their parse mode. The recipient,
serialized InlineKeyboard callbacks, status paths, delivery order, and
existing operator-menu HTML remain unchanged. The recipient notice previously
had **no** parse mode; it is HTML only where verified literal-safe.

`python3 tools/test_post_v9_telegram_html_wallet_reports.py` executes the
actual current PHP report function with source-owned integer operation facts
and the conditional reason prompt expression, and checks full rendered text,
keyboard/destination parity against a golden captured from pre-migration
Stable main. It covers positive/negative/formatted integers, Persian/Unicode,
malformed markup input rejected at the typed boundary and special characters
in private pending-step data that must never reach a notice. This bounded
proof does **not** authorize converting customizable wallet prompts, support
replies, stored broadcasts or arbitrary dynamic Markdown elsewhere.

Eleven additional fixed-catalog and typed-numeric conditional/composed
notices have source-owned explicit HTML: gateway-policy and panel-group prompts
in `bot.php`, wallet-transfer restart, payment-plan invalidation, a manual
receipt rejection prompt, interval/add-on write warnings, server-display save
or cancellation, wallet top-up amount presentation, and bulk-account delivery
review. These are **only** the reviewed fixed catalog branches or numeric
`number_format($amount)`/`count($deliveryReview)` expressions; customizable
`$mainValues`, arbitrary dynamic source strings, stored broadcasts and plain
callback labels retain their existing modes. The source/keyboard call
expressions and report order are unchanged: only the `sendMessage()` parse-mode
argument was added, with no financial, authorization, persistence or delivery
retry changes.

`python3 tools/test_post_v9_telegram_html_composed_notices.py` uses the PHP
lexer to locate exactly eleven active, reviewed callsites; pins their
complete original text and keyboard expressions independently of parse mode;
and executes the **actual PHP expressions** against three synthetic scenarios
covering both conditional branches, Persian/Unicode, arbitrary string input
that must not be displayed, formatted monetary amounts and a counted review
list. The source and entire rendered text/keyboard golden fingerprints were
captured from the exact pre-change `main` before this conversion. Every
resulting text is literal-safe for Markdown/HTML with no differing formatting
entities or automatic links. A source/keyboard drift, changed catalog,
missing/disabled send or changed parse mode fails validation. This is a
bounded provenance contract, not a general PHP data-flow analyzer or a
license to migrate user-defined messages; on intentional changes, inspect
the effective diff before accepting a new `--print-audit` fingerprint.

The next bounded source-owned migration covers exactly eleven
`sendMessage()` producers in `bot.php` and `src/Admin/ManagementFlow.php`:
spam-unlock date notice, typed payment-policy field/range prompt, predefined
payment-gateway setting prompt, three direct-gift journal-ID status notices,
and five administrative management-wizard status/result notices. Their
original text expressions and keyboard arguments are unchanged, with an
explicit third `HTML` argument added; previously single-argument sends now
explicitly pass the existing default `null` keyboard. The direct-gift ID is
from the integer-returning `achbotDirectGiftWrite()` or explicitly cast
persisted source ID. Gateway names are selected only from catalog-defined
administrative fields, policy field labels and min/max numbers are fixed
catalog/integer values, and management status keys come from fixed internal
branches. No customer names, support replies, custom copy or persisted
captions are included. Text/whitespace, callbacks, finance/authorization,
transaction completion and deferred-delivery semantics must not change.

`python3 tools/test_post_v9_telegram_html_management_gift.py` discovers and
pins the eleven actual executable PHP call arguments, extracts the **real**
management-text and policy-fields functions, and executes every actual text
and keyboard expression in PHP for fourteen source-typed fixtures (154
text/keyboard pairs). Both fingerprints were captured before conversion from
pre-change `main`. Its literal safety audit and negative source mutation
checks fail for changed/missing producer calls, parse-mode drift, and unsafe
replacement of numeric facts with arbitrary text. The golden contract is
bounded to these source-owned statuses; deliberate Markdown code-formatting,
URLs and arbitrary/persisted messages explicitly remain for separately
validated migration rather than an unreviewed global parser switch.

Current plan editing has two source-owned HTML sends in
`src/Plans/Administration.php` and `src/Plans/Intervals.php`: the fixed
per-day/per-GB price prompt and four finite interval-list titles.
`python3 tools/test_post_v9_telegram_html_plan_prompts.py` uses PHP tokens
to extract the actual executable sends and pins their text, source-owned
title derivation, explicit HTML parse mode and keyboard payloads. It runs
the source-real expressions in PHP against pre-migration visible-text
fixtures, including the UTF-16 escaping helper and negative source mutations.

The old `serverNames` JSON example was an HTML code-span migration in PR
#219, but its plan editor was already retired from active Marzban/PasarGuard
menus; issue #220 removes that dead producer and its editable catalog entry.
Its historical fixture remains recoverable from PR #219 and its commits, not
as a required current-runtime producer. Current source parity alone does
not prove live Telegram-client rendering or authorize conversion of
administrator-authored support replies, arbitrary backtick input,
per-instance templates, stored broadcasts or photo captions.

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
