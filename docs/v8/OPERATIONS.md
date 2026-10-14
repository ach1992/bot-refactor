# V8 source-only staging, verification and rollback

V8 is **not** permission to deploy to a real bot. Do not change or
reconfigure the original older working installation or its aaPanel/Cron,
real `baseInfo.php`, MariaDB records, payment tokens, transaction ledgers,
VPN accounts, QR assets or backup ZIPs.

For a separately authorized, owner-controlled deployment only:

1. Take an operator-managed protected backup of existing source and
   private state using established procedures. Record existing exact
   installed version, callbacks, worker schedules and restore path.
2. Compare the committed exact SHA/TREE and the `package_v8.py` ZIP
   manifest to the independently accepted candidate. The ZIP contains
   only the 92 allowlisted runtime members; never `baseInfo.php` or data.
3. Follow the existing safe operator swap contract: replace source only,
   retain the original 23-table schema, existing private files, actual
   Cron schedule/filenames and payment callback URLs. Do not substitute
   empty/dummy credentials on the real deployment.
4. Verify against *authorized, nonfinancial* smoke evidence first.
   Reconciliation involving real payments, wallets, renewal or Cron
   requires an additional explicit owner gate and controlled procedure.
5. If regressions appear, revert source members to the known-good
   matching V7 package **without a database rollback**. Verify actual
   on-disk runtime members against the version-specific manifest, including
   **removal of all 27 V8-only module files** when reverting to the 65-member
   V7 release. Never delete non-manifest/private files or assume copying
   V7 files over V8 removes the additional PHP modules. Do not try
   to undo real monetary transitions by restoring a database dump; stop
   financial processors and consult the operator for transaction
   reconciliation before any monetary intervention.

The disposable acceptance suite rehearses `V7 → V8 → V7 → V8` with
a persisted paid order, the original wallet state, synthetic coupon and
order-linked renewal/increase rows, exact 65/92/65/92 source inventory,
unchanged original DDL and preserved private sentinels. Its rollback
evidence captures complete-row discount/renewal digests in each of four
phases **on the same DB without restoration**. This is source and SQL
compatibility evidence, not live installation approval.

Operator gates that remain open: V5 #14, V6 #16, V7 #21 installed acceptance,
V6.1 #18 full MariaDB restore (explicitly not tested), V8 #24 independent
HIGH_ASSURANCE review, then **separate owner authorization for merge,
release and deployment**. V9 cleanup is a new, later authority scope.
