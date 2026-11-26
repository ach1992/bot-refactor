# Security and private instance data

- Never commit `baseInfo.php`, real SQL rows, `backup/`, logs, local state, admin lists, payment keys, network-panel credentials, channel invite secrets or users' personal information.
- Use `baseInfo.example.php` for examples only. Local `baseInfo.php` must be preserved during future file swaps and stays outside Git.
- `database/legacy-schema.sql` is reference-only SQL without row data. Do not run it against an existing database.
- Treat credentials previously exposed in SQL/history as potentially compromised; rotate active credentials only through a separately authorized recovery plan. Deleting a file cannot revoke a leaked credential.
- Existing TLS verification behavior, input validation, and backup exposure must be audited separately; do not change operational connections as an incidental refactor.
- Until safe equivalents have passed regression tests, do not change callback-data constants or Cron-visible file names for cosmetic renaming.
- Before a public push: inspect staged diff and any added binary/archive, scan for secrets and real data, confirm no untracked private config is staged.
- Security reports should avoid posting active tokens or personal data in public issues.
