# Reproduce V1 and retain V0 as an immutable reference

Use a development checkout with Git history, Python 3 and PHP 8.4 CLI/tokenizer. Native checks also need the existing MariaDB binaries and PHP mysqli/mysqlnd. Do not run beside live secrets or against production. No script installs host packages or changes a running service.

```sh
python3 tools/check_v1.py --report /tmp/achbot-v1-check.json
python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v1-schema.json
```

During authoring, `python3 tools/check_v1.py --focused` skips the full old V0 gate only; its report explicitly marks this as incomplete acceptance evidence. It still checks the extraction envelope, all candidate traces, helper vectors and V1 controls. The full command is required for review. There is no V1 snapshot-recording option.

## Frozen reference, not re-recorded expectations

`tools/v1_contract.py` pins imported source `a89e63713131d16b5d6461fd41c01bb002d0be63` and independently approved V0 `8e225265556afc33992575b216f941369a4ddc97`, tree `58ccdacfba0ed6849a7d8836c751f5760f66e56b`. It materializes that exact local Git tree in a temporary directory; no network fetch, checkout, reset or hooks are invoked. Paths, member types, total size and reference hashes are checked. Missing Git objects fail the gate rather than falling back to the new source.

The full V1 gate runs the original V0 gate in that temporary tree: all 121 traces, 8 harness self-tests (4 mutation controls), inventory verification and 24 original PHP syntax checks. Original tests, fixtures, provenance, SQL failure expectations, scanners and all `docs/v0` files stay frozen. Thus `tools/check.py`, `tests/run.py` and the V0 atlas still describe V0; do not run their byte-lock gate directly on a changed V1 working tree or regenerate the atlas to silence it. The current aggregate command is `tools/check_v1.py`.

The source-envelope check computes the only permitted transformation from original declarations: exact old wrappers; named module implementations; explicit receipt-choice input; internal sibling/recursive calls; and normalization of PHP whitespace tokens only, never string tokens. Every other byte in the two edited legacy files is checked. All other locked files, known evidence and the exact new module inventory are checked too. Unapproved application PHP and symlinked runtime paths fail. This is intentionally a bounded V1 contract; later work needs a newly reviewed envelope, not an ever-growing unchecked allowlist.

## Candidate evidence

The existing tokenizer/extractor and sandbox runner are reused without mutation. All 121 candidate cases compare against the original committed golden traces. Unmoved extracted function bodies must have the original SHA-256; changed wrapper declarations are independently covered by the exact transformation check. Changed source line numbers are not treated as changed function semantics.

An additional 300 input vectors cover every one of the 20 helpers: legacy shapes, Unicode, JSON failures, loose comparisons, omitted and named arguments, extra arguments, invalid types, null/false, photo arrays, existing callback strings, nested replacement order, size thresholds, link selection and subscription rendering. Each runs under weak and strict callers. Original results are compared independently to public wrappers and direct namespace calls: 1,200 comparison pairs, **not 1,200 distinct product features**.

Return types/serialized values, captured output, warning severity/text and exception class/code/text are compared. Only diagnostic internal function labels and PHP's physical `called in ... on line ...` suffix are normalized. No user message, link, label, callback byte or returned string is normalized. Direct internal calls are included to verify independence from legacy bootstrap; this is not proof of every external caller.

Twelve V1 control tests include nine negative controls (changed module output, wrapper argument, missing module, schema drift, unrelated bootstrap drift, golden rewrite, extra module, unapproved application PHP, symlinked modules), direct module execution and reproducible package/secret-file exclusion checks. Two behavior mutations are checked both by the structural contract and by changed differential output. Controls modify only temporary synthetic copies.

Current candidate PHP, including modules and new test PHP, is linted. Python files are compiled in memory. A bounded token/private-key/GitHub-token scan reports paths only; it is not an exhaustive security audit. The historical publication concern in V0-R12 remains a private operator task.

## Native database and CI

The unchanged native launcher creates its own socket-only MariaDB and synthetic schema. The 13 original database cases and 483 successful unique constant preparations remain checked, with the same two known statement defects and four construction fragments expected. Those catalog locations are original-reference locations. The source envelope proves no SQL or native-tested function changes in V1; the catalog is not being misrepresented as a newly regenerated candidate line map.

CI checks the exact PR HEAD with full Git history, read-only repository permission and no deployment step. It runs the aggregate gate, native checks, and builds a 23-file staging-only runtime ZIP plus a candidate/tree/SHA-256 manifest. The artifact also retains test reports. Third-party actions are pinned. Download/build availability never implies independent approval or production acceptance.

## Remaining acceptance

These tests are controlled CLI evidence. Existing live-provider, whole-route, authorization, concurrency, SAPI, asset, scheduler, timezone and deployment gaps documented in V0 remain. New-source wrappers have not been operator-tested on the actual bot. Obtain independent review, then operator acceptance of this exact runtime candidate before merge/next increment; V0 approval does not transfer automatically.
