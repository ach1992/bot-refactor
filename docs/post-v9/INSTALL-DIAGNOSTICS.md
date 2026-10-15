# Diagnose AChBot runtime version before changing a live bot

AChBot **v0.10.0-rc.1** is a source-only Marzban release. It really contains **Server Management → 🏷 مدیریت گروه‌های پنل مرزبان** and the **➕ ثبت سرور مرزبان** button, not X-UI onboarding. Its server admin keyboard is constructed in the released \`config.php\`; the \`manageMarzbanPanelGroups\` callback is in \`bot.php\`. The separate \`panel_group_id\` SQL migration does **not** update PHP files, Telegram inline keyboards or webhook paths.

**Owner-observed acceptance gap (2026-10-15):** one reported installation still shows X-UI menus after swapping the release ZIP and applying SQL. This indicates that the observed UI is not the new keyboard constructed from the verified source, **but does not establish why**. Old Telegram inline messages persist; new code does not retroactively replace keyboards on old messages.

## Read-only check — original published release

1. **Without changing** the live bot, determine the *actual* directory serving Telegram's active webhook. Also inventory PHP-FPM/aaPanel virtual host document root and all running webhook/Cron entrypoints. Keep the bot token, DB password, raw \`baseInfo.php\`, full webhook API request URL and user details **private**.
2. In that exact directory, verify **two file hashes**:
   \`\`\`sh
   cd /actual/webhook/serving/directory
   sha256sum bot.php config.php
   \`\`\`
   Expected SHA256 for **v0.10.0-rc.1**:
   - \`bot.php\`: \`22aded5b8ff8ede484456c3bb60fc1f4eed0ad73eb75b4d31499543ed2c948e7\`
   - \`config.php\`: \`8d11b92bc0362eb5ed939c707f077b450c394734e9f353dd0e0e41c2eed34b4c\`

   If either differs: the specified directory contains old/custom/mixed code. Confirm which folder the ZIP was extracted into, whether all files actually overwrote their prior versions, and whether the webhook path differs. **Do not blindly overwrite private configuration or delete unrecognized PHP files.**
3. For all **92 public runtime members**, a separate read-only verifier is available in \`tools/verify_installed_runtime.py\` in the repository (not part of the 92-member runtime ZIP). Download it separately and inspect it before running. Download the official \`achbot-v0.10.0-rc.1-runtime.zip.manifest.json\` from [Release v0.10.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1). Example:
   \`\`\`sh
   python3 verify_installed_runtime.py \
     --install-dir /actual/webhook/serving/directory \
     --manifest achbot-v0.10.0-rc.1-runtime.zip.manifest.json \
     --expect-commit 6645ac16a1f8a8a99aa8fc2bd203a7937bd01c7c
   \`\`\`
   Exit status \`0\` means every manifest-listed file matches, \`1\` means differing/missing files, \`2\` means invalid inputs. The tool uses Python standard library only; **no network, MariaDB connection, SQL write, PHP include, webhook change, service restart, or private config read**. It does not verify unexpected extra files. A passing directory hash is **not proof** that the running webhook serves that directory.
4. If **both hashes match**, open a **fresh** Server Management keyboard by navigating through the bot's current admin main menu rather than trusting previously sent inline-keyboard messages. Privately inspect Telegram \`getWebhookInfo\` for the active URL (do not paste token-bearing URLs or Telegram API headers). Check whether the URL and aaPanel PHP site point to the same directory; compare the actual active PHP-FPM pool and its OPcache state, including path/reload behavior. Check if another deployment process/worker still handles the same token. Restart **only the correctly identified site-specific PHP-FPM pool if needed and if operationally safe**; do not blindly restart the whole web stack.
5. If source, webhook, PHP-FPM and fresh keyboard match, validate the admin access and panel group UI on a *test* user. If the discrepancy persists, capture only a redacted screenshot, the two harmless \`sha256sum\` lines, release tag, which admin step generated the keyboard, PHP version, and a **sanitized** webhook hostname/path (never the bot token or full sensitive URL). Record the failure under [Issue #31](https://github.com/ach1992/bot-refactor/issues/31).

## Migration rule and recovery

Do **not** reapply migration, drop the nullable column, upload the private \`baseInfo.php\`, disable jobs, or roll back financial data just to diagnose a stale keyboard. The original 23-table MariaDB schema plus the nullable column is compatible with bounded historical V9 source smoke; production DB restoration needs its own protected backup and verification. Consult [PANEL-GROUPS-MIGRATION.md](PANEL-GROUPS-MIGRATION.md).

Longer-term CLI-first per-instance tracked migration/updater design is in [Issue #34](https://github.com/ach1992/bot-refactor/issues/34). It is **not** a feature of this pre-release.
