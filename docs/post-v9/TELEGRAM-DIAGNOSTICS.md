# Telegram and payment delivery diagnostics — operator runbook

## Current source behavior

New Telegram transport source replaces the legacy application-root
`error_log.txt` writer with the configured **PHP error_log sink**.
No file in the bot webroot is opened or truncated for Telegram diagnostics.
`src/Jobs/BroadcastSend.php` stops writing `settings/error_log.txt` for
rejections as well; it logs a safe contextual event to PHP instead.
Financial/customer delivery events keep using their original InnoDB
attempt journal, independently of logging availability.

Transport failures now produce a single line starting with `[AChBot]`
and JSON with `event=achbot.telegram.transport`, UTC timestamp with
microseconds, bot instance (basename), Telegram method, a per-request
`request_id`, elapsed milliseconds, numeric `curl_errno`, HTTP status,
Telegram `ok` / numeric `error_code`, classified `reason`, optional
`telegram_reason`, `retry_after`, useful curl DNS/connect/TLS timings and
Telegram `message_id` **only** after a positive ACK. Financial customer
dispatch adds `pay_id`, `delivery_ordinal`, `delivery_attempt`,
`delivery_actor`, so the logs can be joined to the **original** stored
`payment_delivery_intents`/`payment_delivery_attempts` rows.

`cURL 35` classifies as `tls_handshake`, `cURL 28` as `timeout`, `6` as
`dns_resolution`, `7` as `connection_failed`; codes alone are not a proven
root cause. HTTP 429 and Telegram 400/403 are recorded even when cURL itself
succeeded. Entity parsing errors and blocked-customer rejections have explicit
safe classifications; the raw provider description is never persisted.
Source-only transport regression exercises separate configured PHP sinks in
synthetic Webhook/Cron-like paths, payment-attempt correlation, response
contract preservation, and sensitive-value redaction. Actual deployed PHP-FPM
and Cron sinks must still be validated separately. Return-value and timeout
policies are unchanged.

**Data handling:** never log the token-bearing API URL, outgoing message
body, original sealed/encrypted packet, raw Telegram error description,
customer's private message, bearer credential or arbitrary provider payload.
Explicit numeric payment/actor/ordinal IDs are retained in the authorized
diagnostic channel. The original attempt journal is the durable forensic
authority: a missing positive Telegram ACK is `UNKNOWN`, **not evidence
that no message was delivered**.

Routine successful Telegram keyboard edits do not fill the log.
Original financial customer delivery calls are logged for successful ACK
as well as failures. If all ordinary Telegram successes need temporary
diagnostics, set `ACHBOT_TELEGRAM_LOG_SUCCESS=1` in the supported PHP runtime
environment, then disable it after investigation; it does not alter sends.

## Where to look on aaPanel/production

The actual active PHP-FPM / Nginx configuration decides the physical
`error_log()` destination. For the known host, the observed Nginx/PHP
error sink was `/www/wwwlogs/host.hellservice.top.error.log`, while
PHP-FPM's master log was `/www/server/php/85/var/log/php-fpm.log`.
**Validate live configuration before relying on these historical paths.**
Cron CLI error logs normally go to stderr, which must be captured by
that installation's existing scheduler service/log destination; never
assume the webserver log contains its Cron messages.

For a specific customer notification, filter an authorized log using
`pay_id` and `delivery_ordinal`, then correlate transport `request_id`,
`duration_ms`, `curl_errno`, `http_status`, `telegram_error_code` and
`telegram_message_id` with the audited original delivery attempt.
Use UTC timestamps in structured logs and the DB instance's timezone
when reconciling older SQL records; be explicit about conversions.

The webroot's historical `error_log.txt` is **not** a general PHP log.
Vivan/Hell/Chili historical root-owned copies remain useful evidence.
Do not delete them automatically during upgrade. First verify:
(1) the new source is installed on that bot, (2) both webhook and
Cron workers emit to observable private sinks, (3) failures are
correlated, (4) historical evidence is retained/exported according to
the operator's agreed retention policy. Only then separately authorize
removal of the legacy files.

## Rotation and noise control

Use the existing aaPanel / OS log rotation for the **actual** Nginx/PHP/CLI
sinks, and check for conflicts before adding any additional rule.
Rotation must preserve existing entries, not truncate a busy log just
because it exceeded a fixed 1 MiB threshold. Choose retention, max
size and compression from the installation's operational policy and
capacity, rather than hardcoding them in the source. Test rotation
and reopening on an isolated/safe target before enabling production
log rules, and verify readable history after rotation.

The source fixes diagnostic loss and the transport's `json_decode(false)`
warning path. Unrelated PHP Warning/Deprecated problems in `phpqrcode`,
`UpdateBindings.php` and other modules remain separate root-cause work;
do not conceal them by disabling useful PHP error reporting. Avoid
logging every successful low-value operation in a busy bot.

## Security and deployment boundary

Log files must remain **outside** the public Webroot and accessible only
to authorized operator/service identities. The owner already blocked
public HTTP access to the historical Chili `error_log.txt`; re-verify
before deleting the old file. Moving logs in source is **not** a deploy:
production may continue writing legacy files until the installed runtime
is explicitly updated. No Nginx/aaPanel changes, log deletion,
database migration, Cron/webhook edits, financial/Native calls or live
Telegram sends are performed by this source work.
