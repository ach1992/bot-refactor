# V2 staging source replacement and rollback

The artifact is a complete 26-file runtime package, **not** a production rollout or database migration. Build only after checks from a clean committed development checkout:

```sh
python3 tools/package_v2.py --output /tmp/achbot-v2-runtime.zip
```

The adjacent manifest records commit, tree, ZIP SHA-256 and per-file SHA-256. Output must be outside the repository and must not already exist. The allowlist includes the five V1 modules plus `Transport.php`, `Messages.php` and `UpdateBindings.php`; it excludes real configuration, SQL, test tooling, logs/cache and private state. Example configuration is not a replacement for an existing `baseInfo.php`. Existing runtime assets absent from the original upload still must be preserved.

## Controlled staging procedure

1. Use a separate non-production bot and synthetic/authorized test data. Record the package identity and actual PHP/SAPI, extensions, DB/schema, web routing, scheduler CWD and OPcache/process handling. Do not post credentials or private URLs in GitHub.
2. Quiesce webhook/worker execution or use the deployment's established atomic source-switch mechanism. Preserve the current database, `baseInfo.php`, assets, writable/private files, ownership and permissions.
3. Copy/verify **all `src/` dependencies first**, including the scoped `UpdateBindings.php` adapter, before activating the matching `config.php`. Keep the full runtime package from one candidate. Do not copy the GitHub repository archive (with tests/tools) into a web root. The new bindings file is deliberately executed in `config.php`'s variable scope; do not wrap it in a function or change it to a cached mapping.
4. Apply the existing deployment's process/OPcache handling. Verify hashes and PHP syntax. No webhook re-registration, Cron rename, database import/migration or `baseInfo.php` edit is required by V2.
5. Compare customer/admin/agent menus, old sent inline keyboards, messages/captions, named/default destinations, receipt/document/photo sends, edit/delete/forward, pin/unpin fallback, alerts, file links, and representative `429`/provider failures using the non-production setup. Exercise message/callback inputs, an existing wizard and normal/duplicate purchase/renewal/insufficient-balance smoke paths. Tests should inspect outcomes and side effects, not only a green HTTP response.
6. Record actual executed cases and unverified ones with candidate/tree identity in Issue #6. The delegated private CLI file-swap drill is additional evidence, not a substitute for FPM/OPcache, real DB continuity or manual acceptance.

## Code-only rollback

Quiesce writes/workers and restore the previous integrated V1 runtime from `a7ac4a86a84ccf781ef44130833dd76e923fdc47`. Preserve current DB/configuration/state; do not restore an old SQL dump as part of rollback. Old source may leave the V2 files unused; do not delete an unrelated `src` directory. Apply the normal process/OPcache procedure and repeat the same smoke checks, including pre-existing conversations and old buttons. Local code rollback cannot undo provider/payment/Telegram side effects already emitted.

Deployment and acceptance require the owner's applicable approval. No command in the package builder or CI deploys this source. Independent review is still required for V2; the V1 approval does not transfer to this candidate.
