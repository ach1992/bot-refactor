# V0: evidence before refactoring

V0 adds analysis, test tooling and reference fixtures. It does **not** implement the first runtime refactor, fix known legacy defects, migrate the database, or authorize deployment. Its source anchor is `a89e63713131d16b5d6461fd41c01bb002d0be63`.

## Read in this order

1. [Architecture and extraction boundaries](ARCHITECTURE.md): execution order, responsibilities and the smallest useful next boundary.
2. [Database contract](DATABASE-CONTRACT.md): all 23 tables, data representations, logical relationships and transaction constraints.
3. [Behavior matrix](BEHAVIOR-MATRIX.md): capabilities, executable examples, and remaining manual/integration coverage.
4. [Testing](TESTING.md): reproducible commands, isolation, expected failures and evidence limits.
5. [Operations](OPERATIONS.md): external paths, local configuration, scheduler inventory and safe source-swap/rollback prerequisites.
6. [Risk register](RISK-REGISTER.md): observed legacy defects versus unverified risks; none are silently repaired.

[Generated atlas](SOURCE-ATLAS.md) links every discovered function and table reference. [Machine-readable inventory](inventory.json) contains SQL literals, callback expressions, state writes, settings accesses, include edges, side-effect sites and source locations. These are lexical inventories, **not** complete control-flow graphs or measured runtime coverage.

## Durable boundaries

The [project specification](../PROJECT-SPEC.md) remains authoritative. Current candidate identity, execution evidence, review verdict and unresolved acceptance live in [Issue #1](https://github.com/ach1992/bot-refactor/issues/1), its PR and CI; this document is not a substitute for those live records. [Issue #2](https://github.com/ach1992/bot-refactor/issues/2) owns naming compatibility.

The existing exact-candidate independent-review and operator-acceptance gates still apply. Passing V0 does not authorize V1, production rollout, or changes to `baseInfo.php`.
