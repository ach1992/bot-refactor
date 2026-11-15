# AChBot contributor and agent rules

## Current authority and product boundary

- The current unified native **Marzban + PasarGuard** implementation is the first forward **Stable** baseline (v0.10.0). No compatibility obligation exists for obsolete deployed bot versions, historical UI callbacks or retired X-UI payment/provider integrations. Do not invent adapters or synthetic migrations for installations that did not exist.
- **Current customer contracts ARE binding:** preserve already-issued subscription/config URLs and proxy credentials, verified Native account identity, active service entitlements, wallet balances, original order/payment evidence, idempotency, unfinished effects, authorization and recovery. Future Marzban server/plan creation remains supported. PasarGuard plan access is `provider_group_ids`, not legacy `custom_sni`; Marzban may still use that shared column.
- Do not rerun one-time Hell/Vivan historical Marzban→PasarGuard conversion. Keep data recovery and financial provenance separate from bot-version compatibility.
- The canonical scope/policies live in [docs/PROJECT-SPEC.md](docs/PROJECT-SPEC.md); the current stable release boundary is [docs/post-v9/STABLE-BASELINE.md](docs/post-v9/STABLE-BASELINE.md). Source, Issues, CI, tags and actual installed state are distinct authorities. Never record status archaeology in these instructions.

## Engineering

- Reuse existing modules/DB structures and actual provider API capabilities. **Do not hardcode** unsupported provider versions, arbitrary operational ceilings, user policy or unnecessary compatibility layers. Retain real protocol correctness and verified financial/permission invariants.
- Prefer one coherent, maintainable change with minimal dependencies and tests that exercise its actual failure path. Avoid unrelated refactors, excess scaffolding, disposable-only abstractions and duplicate specs.
- Work only in the authorized repository and isolated worktree. Preserve unrelated changes and secrets. Never use customer SQL, `baseInfo.php`, live API keys or production logs as test fixtures. Historical source and source-real tests are frozen evidence, not a requirement to support an old deployed bot.
- Owner approval is needed before introducing **new user-/admin-visible copy or behavior**, including account-name formats, menus and reports. Maintain the Persian text catalogs, explicit configured instance text, RTL-aware UI and literal-copyable provider config bytes. Technical correctness alone is not approval for UX changes.
- Four staff levels have distinct permissions, but all genuinely authorized staff retain approved manual receipt review; receiving notifications never grants access. Preserve administrator-authored Telegram messages, including wizard input; protect sensitive values through access controls and narrow diagnostics, not destructive conversation cleanup.
- No unauthenticated or replayed payment, Native mutation or webhook effect. Confirm owner/provider/instance, original issued amount, actor permission, and durable once-only evidence. Never automatically retry an uncertain external write or infer financial finality from a failed Telegram message.
- Log useful stage, reason, correlation and safe identifiers without credentials or untrusted payloads. Keep operator-diagnosable failures; do not silently swallow material errors or discard functional records.

## Build, verification and delivery

- Current PHP validation target is **PHP 8.5**. Run targeted tests during development and the official eight-family native PHP 8.5 CI with protected aggregate on stable runtime candidates; do not weaken family completeness or substitute selected/doc-only CI for a source change. See [CI contract](docs/post-v9/CI-VALIDATION.md).
- Independent author-separated review, exact candidate identity, current base, Owner integration approval, release creation, installed deployment and live acceptance are **separate gates**. A verified merge or release does not imply production deployment.
- The runtime comes only from the manifest-bound 132-member `tools/package_post_v9.py` package at clean, committed release HEAD; it contains no private config or SQL. Avoid runtime ZIP entry changes without corresponding source inventory and acceptance.
- Per-installation Cron runs the existing top-level `settings/cronRunner.php` coordinator, four isolated sequential child jobs and private per-bot lock. Never manually launch real Cron as a harmless test, change operational schedules without authority, or remove active lock files. A trusted external lock directory is created only by the admitted coordinator when its OS user has permission.
- For schemas, payments, provider effects, deployment, migration, backups or cleanup: establish the exact target, affected state, concurrency, backup/rollback and verification first. Production changes need explicit authorization. The one-time migration scripts and historical source tests must never be casually executed on existing customers.
- Keep README short, current and practical. Preserve useful current contracts/runbooks; put release chronology in GitHub Releases and completion evidence in PRs/Issues. Close only genuinely resolved/superseded Issues, retaining unresolved Owner-gated work. Delete task-owned temporary branches/worktrees only after all unique work and review evidence are safe.
