# Physical service transfer and plan access

Current Wallet-first service-management contract. Physical Panel Groups
are administrator declarations, not Native access Groups or ownership
proof. The shared [architecture](WALLET-FIRST-ARCHITECTURE.md) owns paid
operation and physical-account conflict rules.

## Administrator workflow

- An authorized administrator assigns, reassigns or clears a logical
  server's physical Panel Group through the current settings. Assignment
  validates the group, supported server and actor, and changes only
  `server_info.panel_group_id`. It does not change orders, plans, financial
  records, provider credentials or Native accounts. Existing work and
  different API URLs/keys do not by themselves forbid declaring a group.
  The administrator is responsible for the declaration; each later
  consumer still verifies actual physical identity and authority.
- Transfer remains available in administrator order details, including a
  clear no-destination state. Only a verified same-physical-panel target
  is eligible. Reassign every active local reference to that exact
  subscription; clear old plan references and disable automatic renewal
  consent on resulting planless orders. No Native move, clone or delete.
- Selecting an active destination plan applies its actual Native Group
  set once, then synchronizes the plan/server of every active linked order.
  If verified access is already identical, commit local selection without
  a PUT. Neither path is a purchase, reset, extension or new debit.
- A confirmed hard-delete of server/plan data retains historical evidence
  as defined by current management rules. A later authorized compensation
  restores original inventory only if the original server exists.

## Ownership and recovery

The common physical-account lease and durable Native/service/plan intent
serialize conflicting service work, import attachment and deletion. Funded
or unresolved Native work must reach completion or authorized resolution
before a conflicting transfer/plan change. Unfunded receipts do not own
Native. Successful subject changes supersede only affected current
unfunded drafts/operations; retain submitted proof, original monetary
evidence and unrelated requests. A subsequent verified receipt credits
Wallet and never executes the superseded service.

Prepared journals have no possible Native dispatch. Commit the dispatch
journal and Native mutation fence together at the last reversible pre-PUT
boundary. Abandoned pre-write preparations may be cancelled under the
lease while preserving evidence; an ambiguous PUT retains the original
hold. An explicitly authorized correction uses the original target;
a failed preflight cannot consume the correction. Authenticate requests
with current credentials, including rotation during recovery. The sealed
intent binds stable physical connection/endpoint, account and desired
Groups, not a replaceable API key. A fresh write requires a currently
eligible plan; verified already-applied readback can finalize even if the
plan is no longer offered.

Readback and local finalization must verify all current original references
and the matching Native incarnation before the common transaction commits.
Never mark a journal settled based on a partial or untrusted response.
Missing current schema or incompatible state requires evidence-based
diagnosis, not an old migration or a synthetic success.

## Verification

Use the [fresh schema](FRESH-INSTALL.md), [Native API contract](PASARGUARD-CONTRACT.md)
and [CI validation](CI-VALIDATION.md). The [installed acceptance #183](https://github.com/ach1992/bot-refactor/issues/183)
owns separately authorized operator/provider validation.
