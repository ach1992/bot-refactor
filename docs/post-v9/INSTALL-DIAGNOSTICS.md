# Diagnose AChBot runtime version before changing a live bot

AChBot **v0.10.0-rc.1** is a source-only Marzban release. It really contains **Server Management → 🏷 مدیریت گروه‌های پنل مرزبان** and the **➕ ثبت سرور مرزبان** button, not X-UI onboarding. Its server admin keyboard is constructed in the released `config.php`; the `manageMarzbanPanelGroups` callback is in `bot.php`. The separate `panel_group_id` SQL migration does **not** update PHP files, Telegram inline keyboards or webhook paths.

**Resolved menu incident (2026-10-15):** on an aaPanel **OpenLiteSpeed / PHP 8.4 LSAPI** installation, the published `bot.php` and `config.php` bytes matched the release, but a fresh administrator menu initially showed retired X-UI actions. The ZIP preserved fixed 1980 member timestamps. After a separately authorized, one-time, **installation-scoped mtime-only activation** of those two files, the Owner confirmed X-UI disappeared, Panel Groups appeared and group creation succeeded. This is strong before/after evidence consistent with stale LSAPI OPcache; no direct cache snapshot was captured. The menu incident is resolved, **not** the broader account/transfer/payment acceptance. Previously sent Telegram inline messages can retain old keyboards. See [Issue #31](https://github.com/ach1992/bot-refactor/issues/31#issuecomment-6199206127).

## Read-only check — original published release

1. **Without changing** the live bot, determine the *actual* directory serving Telegram's active webhook. Identify the virtual host document root, actual PHP handler (OpenLiteSpeed LSAPI on the observed aaPanel installation, PHP-FPM on other setups) and running webhook/Cron entrypoints. Keep the bot token, DB password, raw `baseInfo.php`, full webhook API request URL and user details **private**.
2. In that exact directory, verify **two file hashes**:
   ```sh
   cd /actual/webhook/serving/directory
   sha256sum bot.php config.php
   ```
   Expected SHA256 for **v0.10.0-rc.1**:
   - `bot.php`: `22aded5b8ff8ede484456c3bb60fc1f4eed0ad73eb75b4d31499543ed2c948e7`
   - `config.php`: `8d11b92bc0362eb5ed939c707f077b450c394734e9f353dd0e0e41c2eed34b4c`

   If either differs: the specified directory contains old/custom/mixed code. Confirm which folder the ZIP was extracted into, whether all files actually overwrote their prior versions, and whether the webhook path differs. **Do not blindly overwrite private configuration or delete unrecognized PHP files.**
3. For all **92 public runtime members**, a separate read-only verifier is available in `tools/verify_installed_runtime.py` in the repository (not part of the 92-member runtime ZIP). Download it separately and inspect it before running. Download the official `achbot-v0.10.0-rc.1-runtime.zip.manifest.json` from [Release v0.10.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1). **Authenticate the manifest using an independently trusted release-asset SHA256**; trusting only the commit/hash fields inside the manifest is insufficient because they can be replaced together. The separately verified SHA256 for this immutable public manifest is `d36a40fc13936d3533d6283e2e70ffb1479c06baffb34e016ba779c0b8ffd78e`. Obtain future release digests from a trusted source independent of the downloaded JSON. Example:
   ```sh
   python3 verify_installed_runtime.py \
     --install-dir /actual/webhook/serving/directory \
     --manifest achbot-v0.10.0-rc.1-runtime.zip.manifest.json \
     --expect-commit 6645ac16a1f8a8a99aa8fc2bd203a7937bd01c7c \
     --trusted-manifest-sha256 d36a40fc13936d3533d6283e2e70ffb1479c06baffb34e016ba779c0b8ffd78e
   ```
   Exit status `0` means the independently pinned **manifest bytes** were authenticated and every manifest-listed disk file matches; `1` means differing/missing/unstable files and `2` means invalid inputs, an unauthenticated manifest or an unavailable install root. This verifier requires Linux-style directory-descriptor/no-follow filesystem support and fails closed if unavailable. It uses Python standard library only; **no network, MariaDB connection, SQL write, PHP include, webhook change, service restart, or private config read**. It does not verify unexpected extra files. A passing directory hash is **not proof** that the running webhook serves that directory. The incident's **91/92** match was explained by an intentional, disposable brand-text change to `settings/values.php`, not by a proven corrupt ZIP.
4. If **both hashes match**, open a **fresh** Server Management keyboard by navigating through the bot's current admin main menu rather than trusting previously sent inline-keyboard messages. Privately inspect Telegram `getWebhookInfo` for the active URL (do not paste token-bearing URLs or Telegram API headers). Check whether the URL and site configuration point to the same directory; identify the **actual web SAPI/PHP handler**, its process lifetime, OPcache timestamp settings and serving paths (LSAPI is **not** PHP-FPM). Check whether another deployment handles the same token. **Do not restart OpenLiteSpeed/shared LSAPI workers, reset the webhook or change file mtimes without fresh, installation-specific approval and rollback readiness.** A future ZIP with updated member timestamps still cannot guarantee invalidation of already-cached PHP.
5. If on-disk source, webhook-served path and a freshly generated keyboard agree, validate the admin access and panel group UI on a *test* user. If the discrepancy persists, capture only a redacted screenshot, the two harmless `sha256sum` lines, release tag, which admin step generated the keyboard, PHP version, and a **sanitized** webhook hostname/path (never the bot token or full sensitive URL). Record the failure under [Issue #31](https://github.com/ach1992/bot-refactor/issues/31).

## Migration rule and recovery

Do **not** reapply migration, drop the nullable column, upload the private `baseInfo.php`, disable jobs, alter shared OPcache settings or roll back financial data just to diagnose a stale keyboard. Group creation may have written to the real database; do not delete an Owner-created group without separate authorization. The original 23-table MariaDB schema plus the nullable column is compatible with bounded historical V9 source smoke; production DB restoration needs its own protected backup and verification. Consult [PANEL-GROUPS-MIGRATION.md](PANEL-GROUPS-MIGRATION.md).

Longer-term CLI-first per-instance tracked migration/updater design is in [Issue #34](https://github.com/ach1992/bot-refactor/issues/34). It is **not** a feature of this pre-release.
