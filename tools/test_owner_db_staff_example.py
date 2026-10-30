#!/usr/bin/env python3
"""Current synthetic-example policy over real package membership; no private config."""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import tempfile
from pathlib import Path

import package_post_v9 as package

ROOT = Path(__file__).resolve().parents[1]
BASE = 'f570db225beac9e429f11fbc73d84acaf3750cf6'
STAFF_LINES = {
    'admins_list': b'$admins_list = [0];\n',
    'special_admins': b'$special_admins = [0];\n',
}


def check() -> dict:
    names = package.members()
    example = (ROOT/'baseInfo.example.php').read_bytes()
    original = package.git('show', package.V9+':baseInfo.example.php')
    previous = package.git('show', BASE+':baseInfo.example.php')
    original_lines = original.splitlines(keepends=True)
    retired = (b'$hideConnectLinkForMarzban =', b'$showIndexes =',
               b'$hideConfigIfMany =', b'$linkIndices =',
               b'$USDTPrice =', b'$USDTPriceagent =',
               b'$admins_list =', b'$special_admins =', b'$inviteReward =')
    removed = [line for line in original_lines if line.startswith(retired)]
    assert len(removed) == 9
    assert example == b''.join(line for line in original_lines if not line.startswith(retired))
    assert previous != example and all(previous.count(line) == 1 for line in STAFF_LINES.values())
    assert example.count(b'$admin = 0;\n') == 1
    assert all(line not in example for line in STAFF_LINES.values())
    mutations = {'archived_two_staff_assignments': previous}
    mutations.update({key+'_reintroduced': example.replace(
        b'$admin = 0;\n', b'$admin = 0;\n'+line, 1)
        for key, line in STAFF_LINES.items()})
    mutations['invitation_reintroduced'] = example.replace(b'$admin = 0;\n', b'$admin = 0;\n$inviteReward = 0;\n',1)
    mutations['unrelated_primary_owner'] = example.replace(b'$admin = 0;', b'$admin = 73;', 1)
    mutations['unrelated_synthetic_database_user'] = example.replace(
        b"$dbUserName = 'REPLACE_WITH_DB_USER';",
        b"$dbUserName = 'UNEXPECTED_SYNTHETIC_USER';", 1)
    assert all(raw != example for raw in mutations.values())
    cases = []
    # Nested under this checkout so real git -C resolves its pinned history.
    # Only the disposable copy's synthetic example is mutated.
    with tempfile.TemporaryDirectory(prefix='.staff-example-policy-', dir=ROOT) as temp:
        stage = Path(temp)
        for name in [*names, 'database/legacy-schema.sql']:
            target = stage/name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT/name, target)
        original_root = package.ROOT
        try:
            package.ROOT = stage
            assert package.members() == names
            cases.append('new_example_exact_nine_authorized_removals_green')
            for label, raw in mutations.items():
                (stage/'baseInfo.example.php').write_bytes(raw)
                try:
                    package.members()
                except RuntimeError as error:
                    assert str(error) == 'Unexpected current synthetic config template change'
                else:
                    raise AssertionError('membership policy accepted '+label)
                cases.append(label+'_red')
            (stage/'baseInfo.example.php').write_bytes(example)
            assert package.members() == names
        finally:
            package.ROOT = original_root
    assert (ROOT/'baseInfo.example.php').read_bytes() == example
    return {
        'complete': True, 'cases': len(cases), 'scenarios': cases,
        'actual_package_members': True, 'source_real': True,
        'authorized_assignment_removals': 9, 'invitation_assignment_removals': 1, 'additional_staff_assignment_removals': 2,
        'baseline_source': BASE, 'archived_two_staff_assignments_red': True,
        'staff_reintroduction_red': sorted(STAFF_LINES),
        'unrelated_template_mutation_red': ['primary_owner', 'synthetic_database_user'],
        'runtime_members': len(names), 'original_v9_example_sha256': hashlib.sha256(original).hexdigest(),
        'private_config_read': False, 'external_calls': 0,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run-disposable', action='store_true', required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    result = check()
    if args.report:
        args.report.write_text(json.dumps(result, indent=2)+'\n')
    print(json.dumps(result))


if __name__ == '__main__':
    main()
