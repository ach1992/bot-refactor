# AChBot v0.9.0-rc.1 — isolated release-artifact staging acceptance

**Post-V9 roadmap:** [Issue #29](https://github.com/ach1992/bot-refactor/issues/29).
**Published immutable release:** [v0.9.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1).
This document records **what was actually executed** versus what still needs
a separately authorized owner/installed-environment observation. Nothing below
makes this bot/real-wallet/provider production-certified.

## Release-artifact-based acceptance — executed

Command on a dedicated development/test server (never on an installed bot):

```bash
# Fetch the public, source-only RC artifact and matching SHA/TREE manifest.
# Use a new owned temporary directory outside this source checkout.
gh release download v0.9.0-rc.1 --repo ach1992/bot-refactor \
  --pattern 'achbot-v9-rc1-dropin.zip*' --dir "$ARTIFACT_DIR"

python3 tools/test_release_staging.py --run-disposable \
  --release-zip "$ARTIFACT_DIR/achbot-v9-rc1-dropin.zip" \
  --manifest "$ARTIFACT_DIR/achbot-v9-rc1-dropin.zip.manifest.json" \
  --report "$ARTIFACT_DIR/staging-result.json"
```

The test rejects archive members that do not match the published V9 exact
merge SHA `7eb077fd8e6d0a303421626d1500f69e295a6664`, TREE
`7da17725c1e7f0448119b7e41c8b11b21f3e093d`, runtime ZIP SHA256
`5a2fd006048aaba4086f183a07e034ed9716da8277692128fd77006f60c585c0`,
per-file Git blobs and their original manifest.

Both V9 stage directories are newly created from **the original release ZIP**
rather than the current source checkout. The original **23-table MariaDB**
and real PHP 8.4 CLI + loopback PHP HTTP server are executed with synthetic
`baseInfo.php` and *only* owned `127.0.0.1` panel HTTP fixtures. The PHP
process is not run as root; its file visibility is restricted with
`open_basedir`. HTTP(S) proxy environment is deliberately closed to external
destinations; public Telegram and payment endpoints are not invoked.

| Executed case | Actual acceptance observation | Status |
| --- | --- | --- |
| Published ZIP and manifest | All **92** runtime members identical to Git release SHA, byte/payload/hash verified | PASS |
| Real whole `config.php` bootstrap | 40 original global facades; seven actual fake-panel HTTP requests; paired original 23-table DB and fake config | PASS |
| Public `health.php` POST | Actual PHP server responds HTTP **405** with `method_not_allowed`, no data/panel request | PASS |
| Public `settings/subLink.php` missing/invalid token | Both respond unchanged `Wrong token`, no provider request, no SQL writes | PASS |
| Public valid synthetic subscription token | HTTP **200** with strict base64 body, eight actual owned provider requests per stage; identical output/wire | PASS |
| Subscription side effects | **Only** `orders_list.remark` and `orders_list.link` change identically on two databases; all other fields/tables remain equal/unchanged | PASS |
| Source/private/schema identity | Both installed trees retain original 92-member runtime and fake private config; original 23-table DDL unchanged | PASS |

**Important legacy detail:** the existing valid subLink GET is **not
read-only**: it updates the stored order remark/link and makes an HTTP
`updateClient` panel request. Those writes are deliberately asserted rather
than falsely claiming no SQL mutation. The panel request has a generated
16-character client `subId`; the test accepts the randomness of **only this
specific payload field**, verifies its format, and requires equality of all
other provider-request fields, ordering and output.

The original full V0–V9 historical suites remain in the official
[post-merge CI #38562677182](https://github.com/ach1992/bot-refactor/actions/runs/38562677182).
The present script **adds** on-disk public-release HTTP acceptance; it does not
pretend to replace full finance, webhook, admin, FPM or operator proof.

## Still NOT TESTED / distinct owner gate

- An actually installed **Owner-controlled staging** bot, with its existing
  PHP-FPM/OPcache, Telegram webhook, Cron schedules, persisted wizard states,
  old previously sent inline callback buttons and panel credentials.
- End-user and administrator live HTTP/webhook scenarios spanning support,
  broadcast, VPN subscription state, checkout, wallet, manual bank/gateway,
  TRON settlement and scheduled renewal. Existing old unsafe money handling
  in [Issue #26](https://github.com/ach1992/bot-refactor/issues/26) is
  **not** authorized to be operated with real funds.
- Real V5/V6/V7/V8 component owner acceptance ([#14](https://github.com/ach1992/bot-refactor/issues/14),
  [#16](https://github.com/ach1992/bot-refactor/issues/16),
  [#24](https://github.com/ach1992/bot-refactor/issues/24)) and V1–V4
  historical operator gates; all previous component **source merges are
  complete**, but automated tests do not resolve the missing installed checks.
- Full isolated restore of a real V6.1 backup ZIP was previously **declined
  by owner**. Never perform a restore test against real backup/customer data
  without a fresh explicit permission and appropriate anonymization/consent.

## Safe next owner staging check (not executed)

Use an isolated server/database with **synthetic accounts only**, private
non-production credentials and no live payment methods or financial Cron.
Confirm in a controlled window (1) original URL/webhook and Cron file paths,
(2) `/start` and old-keyboard navigation for fresh and in-progress synthetic
user, (3) synthetic admin/agent role access, support wizard/reply, broadcast
pause/resume, (4) owned synthetic panel subscription creation/link, and
(5) code-only rollback without database rollback. Record actual steps and
redacted observed results (including PHP-FPM and OPcache version) in
[Issue #27](https://github.com/ach1992/bot-refactor/issues/27).

**Approval boundary:** owner approval for real installed test/staging is
separate from the approval to publish V9 RC1 or develop these tests.
Do **not** upload real `baseInfo.php`, passwords, customer rows, paid-order
tokens, wallet ledger, backup archives or webhook secrets to GitHub.
