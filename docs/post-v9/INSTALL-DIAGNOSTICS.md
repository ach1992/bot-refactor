# Diagnose AChBot runtime version before changing a live bot

## Current-release verification

For a new installation, start with [fresh installation](FRESH-INSTALL.md). Use
the **selected release's own** trusted manifest and matching verification tool.
Published RC18 and RC19 each have 115 members; their
source hashes differ. Do not use the historical RC1/RC2 hashes below for them.

Obtain the expected source commit and manifest SHA256 through a trusted
release/CI channel independently of the downloaded JSON, then run from the
matching private setup checkout:

```sh
python3 tools/verify_installed_runtime.py \
  --install-dir /actual/webhook/serving/directory \
  --manifest /private/setup/runtime.zip.manifest.json \
  --expect-commit EXPECTED_EXACT_SOURCE_COMMIT \
  --trusted-manifest-sha256 INDEPENDENT_TRUSTED_MANIFEST_SHA256
```

Replace both uppercase placeholders with actual verified values. Exit 0 means
all manifest-listed on-disk files match; exit 1 reports mismatches and exit 2
invalid/untrusted inputs. The tool does not read baseInfo.php, contact a service
or prove which compiled PHP bytes the webhook currently serves. It does not
detect unexpected extra files; reconcile retired paths using the selected
release's inventory and rollout guide. Matching file count alone proves neither
source identity nor complete installation.

Check actual webhook hostname/path, the serving PHP handler/OPcache and a newly
opened bot menu independently, keeping token/secret/private configuration
private. Do not rerun SQL to diagnose old inline keyboards. Any runtime/cache,
webhook or scheduler changes on an existing target require its own scoped
operational decision.

## Historical RC1/RC2 incident and examples

The remaining release-specific examples preserve the earlier incident evidence.

**Historical incident baseline:** The RC2 release description and menu incident below record the earlier RC2 checkpoint; “current” in that description refers to that checkpoint. For RC16, use its trusted full manifest and [RC15 → RC16 guide](RC15-TO-RC16.md), with current scope in the [README](../../README.md). The read-only provenance method remains applicable; release-specific hashes and inventories belong to their recorded version.

AChBot **v0.10.0-rc.2** is the current source-only Marzban security-hotfix release; RC1 remains an immutable historical reference. It really contains **Server Management → 🏷 مدیریت گروه‌های پنل مرزبان** and the **➕ ثبت سرور مرزبان** button, not X-UI onboarding. Its server admin keyboard is constructed in the released `config.php`; the `manageMarzbanPanelGroups` callback is in `bot.php`. The separate `panel_group_id` SQL migration does **not** update PHP files, Telegram inline keyboards or webhook paths.

**Resolved menu incident (2026-10-15):** on an aaPanel **OpenLiteSpeed / PHP 8.4 LSAPI** installation, the published `bot.php` and `config.php` bytes matched the release, but a fresh administrator menu initially showed retired X-UI actions. The ZIP preserved fixed 1980 member timestamps. After a separately authorized, one-time, **installation-scoped mtime-only activation** of those two files, the Owner confirmed X-UI disappeared, Panel Groups appeared and group creation succeeded. This is strong before/after evidence consistent with stale LSAPI OPcache; no direct cache snapshot was captured. The menu incident is resolved, **not** the broader account/transfer/payment acceptance. Previously sent Telegram inline messages can retain old keyboards. See [Issue #31](https://github.com/ach1992/bot-refactor/issues/31#issuecomment-6199206127).

## Read-only checks — identify the exact installed release first

1. **Without changing** the live bot, determine the *actual* directory serving Telegram's active webhook. Identify the virtual host document root, actual PHP handler (OpenLiteSpeed LSAPI on the observed aaPanel installation, PHP-FPM on other setups) and running webhook/Cron entrypoints. Keep the bot token, DB password, raw `baseInfo.php`, full webhook API request URL and user details **private**.
2. In that exact directory, verify **two file hashes**:
   ```sh
   cd /actual/webhook/serving/directory
   sha256sum bot.php config.php
   ```
   Expected SHA256 for both **RC1 and RC2** `bot.php`:
   - `bot.php`: `22aded5b8ff8ede484456c3bb60fc1f4eed0ad73eb75b4d31499543ed2c948e7`
   - RC1 `config.php`: `8d11b92bc0362eb5ed939c707f077b450c394734e9f353dd0e0e41c2eed34b4c`
   - RC2 `config.php`: `f18a4c14d860261c92b97321b6c16dbe25e3239124a38aaeb7ca9d0833d10bb1`

   If either differs: the specified directory contains old/custom/mixed code. Confirm which folder the ZIP was extracted into, whether all files actually overwrote their prior versions, and whether the webhook path differs. **Do not blindly overwrite private configuration or delete unrecognized PHP files.**
3. For all **92 public runtime members**, a separate read-only verifier is available in `tools/verify_installed_runtime.py` in the repository (not part of the 92-member runtime ZIP). Download it separately and inspect it before running. Download the official `achbot-v0.10.0-rc.1-runtime.zip.manifest.json` from [Release v0.10.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1). **Authenticate the manifest using an independently trusted release-asset SHA256**; trusting only the commit/hash fields inside the manifest is insufficient because they can be replaced together. The separately verified SHA256 for this immutable public manifest is `d36a40fc13936d3533d6283e2e70ffb1479c06baffb34e016ba779c0b8ffd78e`. Obtain future release digests from a trusted source independent of the downloaded JSON. Example:
   ```sh
   python3 verify_installed_runtime.py \
     --install-dir /actual/webhook/serving/directory \
     --manifest achbot-v0.10.0-rc.1-runtime.zip.manifest.json \
     --expect-commit 6645ac16a1f8a8a99aa8fc2bd203a7937bd01c7c \
     --trusted-manifest-sha256 d36a40fc13936d3533d6283e2e70ffb1479c06baffb34e016ba779c0b8ffd78e
   ```
   **The preceding manifest command applies to RC1 only.** An RC2 installation necessarily differs at `config.php`; use the RC2 release's separately authenticated manifest and its own independent SHA256 trust anchor to verify RC2. A locally customized `settings/values.php` also differs intentionally and must never be overwritten merely to force a matching hash. Do not claim a full 92-member verification by running the RC1 command against RC2.

   Exit status `0` means the independently pinned **manifest bytes** were authenticated and every manifest-listed disk file matches; `1` means differing/missing/unstable files and `2` means invalid inputs, an unauthenticated manifest or an unavailable install root. This verifier requires Linux-style directory-descriptor/no-follow filesystem support and fails closed if unavailable. It uses Python standard library only; **no network, MariaDB connection, SQL write, PHP include, webhook change, service restart, or private config read**. It does not verify unexpected extra files. A passing directory hash is **not proof** that the running webhook serves that directory. The incident's **91/92** match was explained by an intentional, disposable brand-text change to `settings/values.php`, not by a proven corrupt ZIP.
4. If **both hashes match**, open a **fresh** Server Management keyboard by navigating through the bot's current admin main menu rather than trusting previously sent inline-keyboard messages. Privately inspect Telegram `getWebhookInfo` for the active URL (do not paste token-bearing URLs or Telegram API headers). Check whether the URL and site configuration point to the same directory; identify the **actual web SAPI/PHP handler**, its process lifetime, OPcache timestamp settings and serving paths (LSAPI is **not** PHP-FPM). Check whether another deployment handles the same token. **Do not restart OpenLiteSpeed/shared LSAPI workers, reset the webhook or change file mtimes without fresh, installation-specific approval and rollback readiness.** A future ZIP with updated member timestamps still cannot guarantee invalidation of already-cached PHP.
5. If on-disk source, webhook-served path and a freshly generated keyboard agree, validate the admin access and panel group UI on a *test* user. If the discrepancy persists, capture only a redacted screenshot, the two harmless `sha256sum` lines, release tag, which admin step generated the keyboard, PHP version, and a **sanitized** webhook hostname/path (never the bot token or full sensitive URL). Record the failure under [Issue #31](https://github.com/ach1992/bot-refactor/issues/31).

## Migration rule and recovery

Do **not** reapply migration, drop the nullable column, upload the private `baseInfo.php`, disable jobs, alter shared OPcache settings or roll back financial data just to diagnose a stale keyboard. Group creation may have written to the real database; do not delete an Owner-created group without separate authorization. The original 23-table MariaDB schema plus the nullable column is compatible with bounded historical V9 source smoke; production DB restoration needs its own protected backup and verification. Consult [PANEL-GROUPS-MIGRATION.md](PANEL-GROUPS-MIGRATION.md).

Longer-term CLI-first per-instance tracked migration/updater design is in [Issue #34](https://github.com/ach1992/bot-refactor/issues/34). It is **not** a feature of this pre-release.

## Legacy administrator-list diagnostic artifact (separate security gate)

RC1 `config.php` inherited an unconditional writer for `admins_list_check.txt` under the web-served installation root. Owner diagnostics initially observed this artifact at mode `0644` and unauthenticated HEAD HTTP 200 (not proof of response-body disclosure). After the Owner separately applied narrow containment, `admins_list_check.txt` was root-owned mode `0600`, private `baseInfo.php` was `www`-owned mode `0600` for the LSAPI worker, and anonymous GET of the diagnostic route returned HTTP 403. The GET restriction was observed again after RC2 installation; historical content exposure was **not** established or ruled out.

Merged [PR #38](https://github.com/ach1992/bot-refactor/pull/38) removes the writer from RC2 `config.php` and adds source regression coverage. **Source updates do not erase old artifacts or prove the active LSAPI/OPcache bytes.** Preserve the verified permissions and private rollback copy; do not disclose, fetch contents, loosen permissions or delete the old file without a separate scoped decision. The RC2 `config.php`-only installer completed without touching SQL or local `settings/values.php`. On this one aaPanel host, `php -l config.php` previously terminated with a segmentation fault *before replacement*; the later successful installer intentionally avoided PHP CLI. This separate CLI problem is not evidence of LSAPI failure. Do not repeatedly re-run crashing CLI checks or restart shared OpenLiteSpeed services. Full live acceptance and actual isolated SQL restore remain open.
