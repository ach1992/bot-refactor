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
to match the bot's actual Telegram username. Existing invite banner text,
photo, button labels, reward amounts and success presentation are unchanged.
An absent/invalid configured username produces the **existing** invite-unavailable
alert and a classified server-side diagnostic instead of silently waiting on
the Telegram network. Fix the private configuration if this condition occurs;
do not expose its token or contents.

## Other active network paths

- Current Marzban account enable/disable/toggle PUT: bound connection (5s)
  and total (20s) time, disallow redirects, retain TLS verification and
  classify missing/invalid/non-200 remote acknowledgement as **uncertain**, not
  a confirmed successful toggle. Do not automatically retry an uncertain PUT;
  read the remote account's actual state before any further action.
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
- Other observed current-runtime remote cURL operations already have explicit
  connection/total timeouts. Reassess new outbound paths if future code adds
  them.

## Source and installation boundary

- No SQL/DDL migration, new table, private configuration format change, bot
  token change, Cron change, Telegram webhook change, payment amount or Native
  account migration accompanies this source correction.
- Run the PHP 8.5 exact-candidate eight Native family CI and protected
  aggregate, plus the offline invitation and Marzban error regressions.
  Independent review is required before merge.
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
