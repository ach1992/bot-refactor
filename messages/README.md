# AChBot message editing

Only **two files contain editable bot/PHP payment-page copy**:

- `fa/user.php`: customer and agent messages, customer buttons and harmless shared labels/date words.
- `fa/admin.php`: administrator controls, operational reports and administrator-only diagnostics.

Both files have named topic sections. Search for the current sentence, then edit its value in place. Keep the stable key, parameter names and placeholders unchanged. For example, keep `{amount}`, `USER-ID`, `PRICE-TOMAN` or `CONNECT-LINK` exactly as written. `settings/values.php` is a compatibility facade; it contains no separate copy to maintain. `loader.php` is loading/substitution code, not a third text file.

File placement does not authorize a recipient. Existing actor/owner checks and explicit destinations decide whether a message may reach a user or administrator. A harmless shared label lives once in `user.php`, even when administrators also see it. Never move an operational diagnostic into a customer message to help troubleshooting.

## Presentation conventions

- Use ASCII digits (`0`–`9`) in bot-owned visible numbers and examples. Keep dates, amounts, IDs and units meaningful; never rewrite customer-supplied names/messages or protocol payloads merely for appearance.
- A complete message or control may start with one relevant emoji. Reuse the same cue for the same task: `💰` wallet, `💳` payment, `📋` subscriptions/plans, `📦` volume, `📅` time, `💬` support, `🔎` search, `🔙` back, `♻️` renewal, `🔄` refresh, `✅` confirmed success, `⚠️` uncertainty or a problem, `❌` cancel and `⛔️` access denial.
- Do not stack emoji, decorate both ends of a heading, or add emoji to date words, units, continuation fragments, URLs or data labels indiscriminately. A flag or joined emoji is one glyph and must remain intact.
- Preserve the caller's parse mode. Buttons and callback alerts are plain text. Use `<b>` for headings/important amounts and `<code>` for copyable IDs/addresses only in messages whose caller explicitly uses HTML. Markdown and plain messages must not receive HTML tags. Many entries are fragments; do not add or close a formatting tag without checking the complete message.
- Keep existing wording unless clarity/privacy requires a reviewed correction. A payment or delivery with an unknown result remains under review; never imply confirmed success, rollback or refund without evidence.

## Runtime API and compatibility

Require `messages/loader.php` once. It needs no private config, database or bootstrap. It loads only the two fixed audience files, rejects duplicate cross-file keys and retains the existing logical `topic.stable_key` API:

```php
achbotMessage('topic.stable_key', ['amount' => $displayAmount]);
achbotMessages('legacy_messages');
achbotMessageTopics(); // Logical sections; independent of physical filenames.
```

Substitution is explicit and one-pass. Missing/extra parameters and unknown keys are programming errors. Existing callers retain escaping and dynamic expressions. `achbotMessageHtml` escapes every supplied value and is suitable only when all parameters are untrusted; already escaped display values use `achbotMessage` to avoid double escaping. The loader performs no automatic emoji, digit or customer-content rewrite.

The original relocation inventory remains immutable evidence. Owner revision 4 permits this two-file layout and controlled style/privacy changes. `tools/text_catalog_style_amendments.json` pins each changed value against `7faac9b`, separates the retired implementation-only parser matchers, and explicitly records privacy parameter/diagnostic exceptions. Runtime parser matchers belong in implementation code, not editable copy.

Run `python3 tools/test_text_catalog_style.py` for focused two-file, stable-key, digits, emoji, markup, parameter, audience and native composition checks. The full catalog/source/financial acceptance remains a separate required gate; source checks do not certify a live Telegram rendering or installed operator behavior.
