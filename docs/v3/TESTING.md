# V3 verification and evidence boundaries

Run only in a development checkout using synthetic fixtures. Existing prerequisites are Python 3, PHP 8.4 CLI with tokenizer; native SQL additionally needs MariaDB tools/mysqlnd/mysqli, and the native HTTP transport test needs cURL. Scripts do not install host packages, use real `baseInfo.php`, attach to a configured database, or contact providers.

```sh
python3 tools/check_v3.py --report /tmp/achbot-v3-check.json
python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v3-schema.json
python3 tools/test_v3_transport.py --run-loopback --report /tmp/achbot-v3-wire.json
python3 tools/test_support_state.py --run-disposable --report /tmp/achbot-v3-state.json
python3 tools/test_support_http.py --run-loopback --report /tmp/achbot-v3-direct.json
python3 tools/rehearse_v3.py --report /tmp/achbot-v3-swap.json
```

The `--focused`, `--support-only` and `--case` options on the main driver are authoring shortcuts, never complete acceptance evidence. No V3 snapshot recording option exists. Reports include Git commit/tree and dirty-tree status; precommit results are not exact-commit CI evidence.

## What is executed

| Layer | Evidence | Limit |
|---|---|---|
| Frozen V2/V1/V0 gates | Original gates run from the pinned historical Git tree; 45 prior tooling/fixture/document files unchanged | Prior evidence does not approve a new candidate |
| Runtime envelope | Exact six region moves, 26 entire old files unchanged, fixed module inventory, symlink rejection, original DDL | A byte transformation does not prove that moving scope/control flow is correct |
| Existing current-source behavior | All 121 original cases plus 300 V1 helper vectors per weak/strict calling mode | Finite scenarios, not every route or production role/state |
| Isolated support sequence | 126 old/new inputs, covering each of the 22 route branches, effects, role variants, reentry, malformed/missing/empty/pagination/text/photo/error paths | Other unrelated routes between the six regions are not executed by this layer |
| Whole-router support integration | 32 old/new cases execute actual `bot.php`, bootstrap and legacy helpers under frozen fail-closed doubles | External services and SQL constraints are doubled here; not every possible interaction |
| Test controls | 15 controls, 13 negative; route, wizard state, actor scope, SQL state, require-once reentry, include order, guard, missing/extra/symlink modules, schema/golden drift | Deliberate temporary faults are evidence of sensitivity, not a universal safety proof |
| Native ticket state | 18 sequential requests per side, 36 fresh PHP processes, two original-schema MariaDB databases, five source phases | Scoped support handlers and real unchanged `setUser`, not the entire webhook; no concurrency |
| Native existing DB gate | Existing 23-table/13-case suite and classified baseline SQL failures remain unchanged | Not a claim that all legacy SQL is valid |
| Native transport regression | Existing wire fixtures compare 16 old/new pairs, 32 real loopback cURL requests | Not real Telegram, TLS, DNS or provider behavior |
| New direct-entry protection | GET and POST to each of six handlers return empty 404: 12 checks on an owned PHP development server | Not PHP-FPM or a production web-server configuration test |
| File-swap smoke | V2 -> V3 -> V2, four phases, 25 previous CLI smoke cases per phase: 100 executions | Private sentinels are not a database; native ticket-state evidence is a separate layer |

The support suite observes 24 inputs with matching pre-existing warnings. Those are recorded in the comparison, not silently suppressed into an assurance claim. Expected fixture/read/write exceptions and the legacy empty-list exit are asserted explicitly. Other unexpected exceptions, unconsumed reads, subprocess failures or changed traces fail the checks. Original golden output remains unchanged.

## Comparison design

`support.php` runs either the original support blocks or the actual new includes in a private directory with no native network/DB extensions. Scripted DB reads fail closed, and writes remain observable. It compares ordered SQL with bound types/values, notification calls/arguments, stored step/temp operations, all source-referenced caller variables with type/serialized value, aliases to actor/admin, warnings, exceptions, stdout and completion versus exit. Physical error file/line metadata is not part of the serialized warning contract. SQL layout normalization is inherited from the frozen doubles and does not change quoted literal contents.

`root_entry.php` instead executes the complete old/new `bot.php`. Only a temporary copy of the frozen child launcher is retargeted to the synthetic input setup. Tracked historical files are not edited. This layer uses real existing `setUser`, menus, notification fan-out and Telegram facades, with their I/O leaves doubled. The isolated 126-case sequence intentionally complements, not replaces, these 32 root cases.

## Native persisted-data continuity

`test_support_state.py` owns a rootless temporary MariaDB datadir/socket/marker and two synthetic databases. Both receive the original 23-table schema and identical fixture data. `--no-defaults`, `--skip-networking`, exact socket/datadir/database checks and owned-process cleanup prevent accidental use of an existing DB. It does not expose an endpoint override. PHP has native mysqli but no cURL/FFI/URL access or shell/process capability; provider calls are spies.

One side executes unchanged V2 for every request. The other begins on V2, receives V3 modules before changing its router, completes a ticket after activating V3, starts an administrator reply under V3, **continues that persisted reply after restoring V2**, and reapplies V3 for the customer photo response and later work. Each request uses a fresh PHP process and the actual stored user row. Complete rows from `users`, `chats`, `chats_info` and `setting`, notification events, aliases and warnings are compared after every step. Assertions also check wizard state, title, message role/JSON, closure, rating and schema fingerprints. This is real support-wizard continuity, not merely private-file sentinels.

It does not prove real production schema correspondence, FPM/OPcache behavior, all non-support persisted states, concurrent requests, atomic distributed effects or manual operator acceptance. The reported engine/SQL mode belongs to that test environment. Code rollback does not undo previous database rows or already emitted messages.

## Safety and packaging

New tests mutate only owned temporary copies. Native listeners bind only loopback, use ephemeral ports, disable proxy use and are closed in `finally`; the direct PHP listener also verifies a unique ownership nonce. Native cURL retains the prior fixed synthetic URL/redirect guards. Inspection of changed test code is still required: these controls are not a sandbox for arbitrary hostile code.

The CI job uses the exact candidate, read-only repository permissions and the unchanged pinned action versions. It executes the complete gate, native database/transport/support/direct-entry and source-swap checks before building the 32-file staging package and storing reports. A fresh author-separated review and the owner's applicable integration/acceptance decision remain necessary.

Source body whitespace, including whitespace inside message/SQL strings, is intentionally preserved. New tooling/docs and the changed router pass whitespace checks; moved support bodies can retain pre-existing whitespace warnings. Do not mass-format them to obtain a cosmetically clean import check.
