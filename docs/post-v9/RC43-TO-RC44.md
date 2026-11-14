# AChBot RC43 to RC44 — provider version metadata is informational

## What changed

RC44 publishes the single merged PR [#185](https://github.com/ach1992/bot-refactor/pull/185). It removes runtime PasarGuard `5.5.1`-only admission checks. Both older and newer reported versions can enter normal API validation; this is **not** a promise that every vendor API is compatible. API response shape, permissions, account identity, physical endpoint/credentials and the existing monetary/replay controls remain required. Marzban did not have an exact-version admission check and its implementation is unchanged.

For newly created PasarGuard `service_plan_changes` intents, `physical_target_format=2` excludes informational version strings from the SHA-256 of the physical target. Actual connection, instance, endpoint, key digest, local order/server and native account identity remain pinned. Old unresolved intents **without** the marker are interpreted using the original version-bearing digest; they are not rewritten or promoted. A version-only metadata change on an old unresolved journal can therefore remain an operator-review hold. Unknown explicit fingerprint formats remain blocked. The API-key rotation path ignores version-only changes while retaining connection/credential checks.

## Source/package delta

The 132-file runtime ZIP differs from RC43 in only six files:

- `src/Admin/ServerWizard.php`
- `src/Panel/PasarGuard.php`
- `src/Panel/ProviderAdmin.php`
- `src/Panel/ProviderStorage.php`
- `src/Subscription/GiftBatch.php`
- `src/Subscription/PhysicalService.php`

No schema migration, payment policy, customer-facing message change, Telegram workflow, cron entry, service data transformation or actual Marzban API change is introduced. Some historical admin texts may still mention `5.5.1`; this is text, not a runtime compatibility gate, and was outside the approved copy-change scope.

## Integrity and verification

- Source merge: `acd0727b9fa04edead999038acf5611b52d5ef36`.
- Git tree: `60e84083415398b2cb866d72c02b7059369da8d9` (identical to independently reviewed candidate tree).
- Reviewed source HEAD: `3a778919cf5872def2b1598099b00f520a37448a`.
- [Exact reviewed-source GitHub CI](https://github.com/ach1992/bot-refactor/actions/runs/43643031288): completed/success; foundation, eight isolated PHP 8.5 families and protected aggregate PASS; official artifact `post-v9-acceptance.json` has `complete=true`.
- Independent review independently confirmed its previous REQUIRED-1 resolved and no additional source BLOCKER/REQUIRED; the reviewer returned `INCOMPLETE / NO_VERDICT` solely because CI was still running at review time. The Owner explicitly authorized release once that **same** CI completed successfully. Do not relabel the review as `APPROVE`.
- Fresh release ZIP was created and verified against the exact merged HEAD with `python3 tools/package_post_v9.py create/verify`; the attached `.manifest.json` binds all 132 source files and ZIP SHA256 to this commit and tree. Check `SHA256SUMS` before using the ZIP. Do not install the GitHub-generated repository source archive.

## Deployment boundary / recovery

This is a **GitHub source-only prerelease**, not a deployment. Nothing here automatically copies ZIPs to Vivan/Hell/Chili, extracts PHP, changes webhooks/Cron, executes SQL migrations, edits `baseInfo.php`, modifies panels, sends Telegram messages or settles payments. Live installation or migration requires its own explicit authorization, preflight of installed versions/SQL and current config, recovery artifact, and post-install checks. Preserve RC43 as the previous published runtime for planned rollback; do not infer restored financial state from a ZIP alone.

The separate Vivan/Hell PasarGuard migration preparation remains open in [Issue #183](https://github.com/ach1992/bot-refactor/issues/183) and Draft [PR #184](https://github.com/ach1992/bot-refactor/pull/184). Neither is integrated into RC44.
