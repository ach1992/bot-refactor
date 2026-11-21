# AChBot message editing

Only **two files contain editable bot/PHP payment-page copy**:

- `fa/user.php`: customer and agent messages, customer buttons and harmless shared labels/date words.
- `fa/admin.php`: administrator controls, operational reports and administrator-only diagnostics.

Both files have named topic sections. Search for the current sentence, then edit its value in place. Keep the stable key, parameter names and placeholders unchanged (for example: {amount}, USER-ID, PRICE-TOMAN or CONNECT-LINK). The former global $mainValues and $buttonValues runtime arrays are removed. PHP consumers resolve source copy through `messages/loader.php` and its lazy `achbotCurrentMessage()` entrypoint, which delegates per-installation copy to `src/Admin/InstanceContent.php`. Neither is an additional editable text source. The obsolete `settings/values.php` facade remains removed.

Classify editing placement from the actual recipient, including forwarded support reports and shared controls; a route filename alone is not an audience. Choose status/action emoji from the complete outcome and action, not an isolated word such as success, unsuccessful, delete or duplicate.

File placement does not authorize a recipient. Existing actor/owner checks and explicit destinations decide whether a message may reach a user or administrator. A harmless shared label lives once in `user.php`, even when administrators also see it. Never move an operational diagnostic into a customer message to help troubleshooting.

## Presentation conventions

- Use ASCII digits (`0`–`9`) in bot-owned visible numbers and examples. Keep dates, amounts, IDs and units meaningful; never rewrite customer-supplied names/messages or protocol payloads merely for appearance.
- A complete message or control may start with one relevant emoji. Reuse the same cue for the same task: `💰` wallet, `💳` payment, `📋` subscriptions/plans, `📦` volume, `📅` time, `💬` support, `🔎` search, `🔙` back, `♻️` renewal, `🔄` refresh, `✅` confirmed success, `⚠️` uncertainty or a problem, `❌` cancel and `⛔️` access denial.
- Do not stack emoji, decorate both ends of a heading, or add emoji to date words, units, continuation fragments, URLs or data labels indiscriminately. A flag or joined emoji is one glyph and must remain intact.
- Preserve the caller's existing parse mode to avoid changing rendered behavior. New user-facing templates should prefer HTML with correctly escaped dynamic values. Buttons and callback alerts remain plain text. Use `<b>` for headings/important amounts and `<code>` for copyable IDs/addresses only in HTML messages. Legacy Markdown and plain messages must not receive untested HTML tags. Many entries are fragments; do not add or close a formatting tag without checking the complete message. See [Telegram presentation](../docs/post-v9/TELEGRAM-PRESENTATION.md) for the optional rendering boundary and the legacy migration gate.
- Keep existing wording unless clarity/privacy requires a reviewed correction. A payment or delivery with an unknown result remains under review; never imply confirmed success, rollback or refund without evidence.

## Runtime API and evidence

Require `messages/loader.php` once. It needs no private config, database or bootstrap. It loads only the two fixed audience files, rejects duplicate cross-file keys and retains the existing logical `topic.stable_key` API:

```php
achbotMessage('topic.stable_key', ['amount' => $displayAmount]);
achbotMessages('legacy_messages');
achbotMessageTopics(); // Logical sections; independent of physical filenames.
```

Substitution is explicit and one-pass. Missing/extra parameters and unknown keys are programming errors. Existing callers retain escaping and dynamic expressions. `achbotMessageHtml` escapes every supplied value and is suitable only when all parameters are untrusted; already escaped display values use `achbotMessage` to avoid double escaping. The loader performs no automatic emoji, digit or customer-content rewrite.

The original relocation inventory remains immutable evidence. Owner revision 4 permits this two-file layout and controlled style/privacy changes. `tools/text_catalog_style_amendments.json` pins each changed value against `7faac9b`, separates the retired implementation-only parser matchers, and explicitly records privacy parameter/diagnostic exceptions. Runtime parser matchers belong in implementation code, not editable copy.

For current Stable source run `python3 tools/test_post_v9_current_copy_ownership.py`, `python3 tools/test_post_v9_copy_relocation_parity.py` and `python3 tools/test_post_v9_web_copy_parity.py`, followed by the current `php85-validation` gate. The older `test_text_catalog_style.py` / `test_next_text_catalog.py` scripts are historical baseline proofs tied to earlier package contracts and are not current-Stable CI acceptance. Source tests cannot certify live Telegram rendering or installed operator behavior.

The retained relocation/source proof is pinned to released RC12 `ecd30ba`; current source still receives live unknown-key/orphan, UI-literal, audience, digits and parameter checks. The shared-USDT-rate Owner amendment retires exactly three obsolete agent-rate help/label/fallback entries, recorded separately in `retired_feature_keys`; the 23 historical parser retirements and original relocation inventory remain unchanged.

The unified-management amendment pins the additional admin/customer templates against RC13 in `tools/text_catalog_management_amendment.json`; prior catalog values remain unchanged. Current binding checks parse and execute only the two declarative catalog assignments in `config.php` and `health.php`, without application bootstrap, private configuration, database or network access. The deleted RC13 facade and relocated runtime source paths are exercised only from their immutable Git versions.

## Current Stable source ownership audit

`python3 tools/test_post_v9_current_copy_ownership.py` checks the current
137-file runtime membership from `tools/package_current.py`, validates
source-literal catalog references, duplicate declared keys, both legacy
array bindings and dynamic-key prefixes, and rejects uncatalogued Persian
PHP literals in the bot and finance runtime (except two explicitly classified
non-copy locale/protocol constants). It also rejects retired plan editor
callbacks and current catalog entries while leaving Native Marzban/PasarGuard
settings untouched. Negative fixtures prove that new literals and retired
routes cannot silently pass. It runs in current `php85-validation` CI.

The standalone `index.php` web-login page uses the `web_login` topic in
the same `user.php` file. Its previous five PHP response strings, HTML
text/placeholder labels and JavaScript page copy are now source-owned by the
same catalog; runtime HTML/JSON/JavaScript rendering remains unchanged.
`python3 tools/test_post_v9_web_copy_parity.py` compares six GET/POST
request outcomes against the accepted current-Stable source, including
exact HTML bytes, JSON response bytes and interpreted JavaScript strings.
`tools/test_post_v9_current_copy_ownership.py` also asserts no noncatalog
Persian text remains in the root web-login PHP/HTML/JS source.
The current-only relocation proof now pins **413** retired no-consumer
keys and 50 new exact-value, source-owned entries (46 browser labels and four
finance/admin display labels); all **2,525** retained values remain byte-exact.
Two more dead keys are retired: the unused subscription-status fallback `legacy_messages.config_doesnt_exist` and the unreachable QR-link button fallback `subscription.buy_helpers.build_links_and_send_qr.start.e4bb9f1ece`. The active subscription status/Start labels remain unchanged.

The global "$mainValues" and "$buttonValues" mutable runtime bindings are
removed. Static source lookups use achbotMessage() with the original
legacy_messages/legacy_buttons keys directly. Ten context-sensitive messages
resolve through the loader entrypoint and existing instance content module;
saved BOT_STATES.instanceMessages retains its HTML format and per-bot precedence.
Finite payment/receipt/wallet/admin key producers resolve through the same
catalog API without legacy array copies. A pure local-array formatter in
src/Presentation/Text.php remains for template interpolation, not as a global
message store.

Both live subscription status labels legacy_buttons.active/deactive remain
byte-identical and sourced directly from the catalog in the status renderer.
The other three obsolete R1 keys remain retired. Token-based source audits
reject undeclared copy, new orphaned keys, unrelated quoted-name impostors,
missing direct bindings and unbounded new dynamic producers. Source-real
context, subscription, finance and static HTML contracts verify behavior.
Staff/customer-authored broadcasts, tickets and invites are stored data, not
editable fixed copy; their original parse modes are preserved.
