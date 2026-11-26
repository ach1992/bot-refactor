# AChBot contributor and agent rules

Read [the product contract](docs/PROJECT-SPEC.md) and the relevant current
guide from [README](README.md). The [Wallet-first architecture](docs/post-v9/WALLET-FIRST-ARCHITECTURE.md)
owns the shared engines and controlled data-transfer boundary. Source,
Issues, CI, published tags and installed state are different authorities.

## Implementation

- Work only in the authorized repository and isolated workspace. Preserve
  unrelated changes, real customer identity/entitlement, balances,
  credentials, original financial/Native evidence and authorization.
- Prefer one cohesive maintainable implementation, existing modules and
  actual provider API capabilities. Keep Wallet/service/settlement/
  recovery/delivery independent of concrete provider/method names through
  source-owned registration. PasarGuard is the only shipped Native
  adapter, not a permanent restriction. No speculative framework, invented
  version gate, arbitrary operational limit, obsolete compatibility
  executor or historical pending-workflow replay.
- Never use production customer SQL, private baseInfo.php, tokens or logs
  as fixtures. No provider-side development or direct internal writes.
  Keep historical evidence separate from executable current work.
- Preserve approved user/admin-visible wording, navigation, naming, reports
  and business policies unless explicitly approved first. The two
  [message catalogs](messages/README.md) own static copy; preserve incoming
  administrator messages and literal native config bytes. Permissions are
  enforced independently of notification receiving.
- Require current actor/owner/instance, immutable original amount/target
  and durable once-only evidence before financial or Native effects.
  Serialize conflicting local writes and use authenticated readback with
  the original operation; no blind replay or remote exactly-once claim.
  Log useful stage, reason, correlation and safe IDs, without credentials
  or raw sensitive payloads. Do not conceal material failures.

## Verification and delivery

- Current source validation is PHP 8.5 and required `php85-validation`.
  Use the [CI contract](docs/post-v9/CI-VALIDATION.md) and relevant isolated
  current-source cases; do not revive retired version tests as acceptance.
- Independent author-separated review, exact candidate/base, Owner
  integration approval, release publication, deployment and live acceptance
  are separate gates. No self-approval or unauthorized production effect.
- Build/verify the runtime from a clean committed release HEAD using
  `tools/package_current.py`. No private config, SQL or customer data in
  release assets. Never assume source merge/publication proves installation.
- Keep the single per-installation Cron coordinator and its four ordered
  isolated jobs/private lock. Never launch live Cron as a harmless test,
  remove an active lock or change schedules/webhooks without authority.
- Before authorized schema/financial/Native/deployment changes, establish
  exact target, current state, concurrency, verified recovery and evidence.
  Fresh installs use the consolidated schema only; existing installs use
  separately approved manual transfer. Never rerun historical construction
  SQL or the completed Hell/Vivan PasarGuard cutover.
- Keep durable product/engineering rules in docs, actual unresolved work
  in Issues, reviewed source in PR/Git, results in CI and chronology in
  Releases. Close only genuinely resolved/superseded work. Remove
  task-owned temporary resources only when unique work/evidence is safe.
