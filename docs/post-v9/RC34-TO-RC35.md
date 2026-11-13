# RC34 → RC35 — bounded active outbound requests and Vivan invitation menu

This is the **source-only candidate upgrade guide**. Version RC35 must not be
described as published until the independently reviewed candidate is merged
and an immutable GitHub Release with a verified runtime ZIP, manifest and
checksums exists. Reference: [Issue #162](https://github.com/ach1992/bot-refactor/issues/162).

## User-visible contract and production incident

The live Vivan inviteFriends handler stalled during an HTTP
api.telegram.org/getMe call without a short request timeout, observed twice
in PHP-FPM slow traces on 2026-11-13. The menu now constructs its original
t.me/<botUsername>?start=<numeric-userid> string offline from the existing
private $botUsername. The NL Vivan configuration was independently confirmed
to match the bot's actual Telegram username. Existing invite banners, photos,
reward amounts, callbacks and success presentation are unchanged. An
absent/invalid configured username produces the **existing** invite-unavailable
alert and a classified server-side diagnostic instead of silently waiting on
the Telegram network. Fix the private configuration if this condition occurs;
do not expose its token or contents.

The Owner explicitly approved this revised **ordinary user** main-menu layout
in the same PR (original button emojis and callback destinations preserved):

1. Existing cooperation request: unchanged.
2. Existing wallet top-up | new-subscription purchase: unchanged.
3. 🔐 حساب کاربری | 🔄 مشاهده اشتراک‌ها
4. 🧲 کسب درآمد | 📚 آموزش و قوانین
5. Existing support/help-channel button, last.

The half-space in **اشتراک‌ها** is U+200C. Ordinary users have the referral
button; administrator visibility and agent-specific subscriptions callbacks
are unchanged. When enabled and eligible, the unchanged free-trial button
appears **after** the fixed account and earning rows, never before them.
Its original label, callback and eligibility gate are unchanged. Other
optional search/custom buttons and configured cooperation links retain their
existing availability; help remains last. Two wallet
top-up completion messages, expiration/import instructions, and other
identified references now use the same menu label. There is no text-based
legacy menu action needing an alias: all existing inline button callback IDs
remain unchanged. Frozen historical copy fixtures are untouched. The new approved catalog
delta is source-pinned separately in tools/text_catalog_menu_20261113.json:
exactly nine user entries and one admin entry. Acceptance verifies full-file
hashes, unchanged message keys/placeholders, and only these approved values,
then projects a checked copy backward for pre-existing catalog history tests.
The immutable earlier #158 catalog manifest and frozen V0–V9 evidence are
unchanged.

## Other active network paths

- Marzban ordinary and strict account GET: both have unconditional 5s
  connection / 20s total deadlines, TLS verification and no redirects;
  the strict mode still separately governs invalid-response classification.
  Account presentation and the customer toggle cannot bypass these limits.
- Marzban account enable/disable/toggle: the initial **strict, physical-target-
  matched GET** must return the requested account identity, an active/disabled
  status and the fields to be sent. A malformed/error/timeout/foreign account
  causes **no PUT**. The intended status is computed once. The PUT retains
  its 5s/20s deadlines, TLS and redirect protection, and can report success
  only on HTTP 200 JSON acknowledging that same username and intended status.
  Missing/invalid/contradictory replies are **uncertain**. Never automatically
  repeat an uncertain PUT; observe remote state before taking another action.
  The immutable V9 differential fixture returns a generic HTTP 200
  `{"status":"ok"}` for toggle; this is now explicitly verified as an
  **uncertain** result with exactly the historical outbound wire requests,
  not misreported as a confirmed state change. A separate positive test
  exercises matching username and intended status.
- Marzban getMarzbanUserInfo() link probing formerly repeated the full
  account GET up to 11 times. It now performs one bounded authenticated GET,
  then probes the same subscription URL within an additional 12-second
  budget (maximum 11 probes of at most 3 seconds each), preserving the
  existing last-attempt link fallback rather than blocking on repeated GETs.
- Zarinpal SOAP checkout: the canonical issuance body is historically pinned
  byte-for-byte. Its public checkout entrypoint sets a per-request 15s
  default_socket_timeout before that body; PHP resets the setting at request
  termination. No issuance logic or payment claim was rewritten.
  Zarinpal SOAP verification: 5s connection and 15s stream-read/socket
  timeouts, with the original PHP INI setting restored in finally. On failure,
  preserve existing checkout/verification uncertainty and manual reconciliation
  paths. A timeout does not prove the provider rejected the financial request.
- The legacy curl_get_file_contents helper, legacy
  GatewayClient::NOWPayments() helper and legacy
  renewMarzbanUUID() helper currently have no runtime callers (source
  search). They are **not evidence of active webhook hangs**; do not add
  speculative dependencies, untested shims or unrelated cleanup to this fix.
- Other surveyed current-runtime Telegram, hosted payment, bank evidence,
  PasarGuard and strict Marzban transports have explicit deadlines; the
  previously missed ordinary Marzban GET is now covered as above. This is
  an audit of identified **active paths**, not a guarantee that no future
  unbounded network call can ever be introduced.

## Source and installation boundary

- No SQL/DDL migration, new table, private configuration format change, bot
  token change, Cron change, Telegram webhook change, payment amount or Native
  account migration accompanies this source correction.
- Run the PHP 8.5 exact-candidate eight Native family CI and protected
  aggregate, plus the source-real offline invitation (8 scenarios), Marzban
  GET/toggle (including preflight failures without PUT and mismatched PUT
  acknowledgements), and actual menu/copy regressions covering free-trial
  disabled, enabled/eligible, enabled/already used, agent, admin and custom
  buttons while support remains last. Existing message
  templates and financial effects remain under their original contracts.
  The previous independent review reported two REQUIRED Marzban findings;
  a fresh independent **delta re-review** of this amended candidate is required
  before merge.
- Published ZIP and manifest are **not a live installation**. On any Vivan,
  Chili or Hell target, use a fresh target-specific approval, immutable artifact
  hash verification, restorable source/config backup and a rollback plan.
  Never touch historical Vivan migration data, settled payment journals,
  remote accounts, or existing Hell/Cron/Webhook routing merely to publish RC35.
- Validate the installed Telegram invite text and photo paths with an actual
  authorized user after a separately approved installation; monitor PHP-FPM
  slowlog and webhook responses. Do not run user-facing production tests
  or send Telegram messages under source-only approval.

The Hell migration is a separate, later approval-gated operation in
[Issue #161](https://github.com/ach1992/bot-refactor/issues/161).
