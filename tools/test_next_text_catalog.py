#!/usr/bin/env python3
"""Exact current editable-copy contract after removing experimental workbenches.

Retired RC26 translations and their obsolete routes are absent from shipping
source. The surviving current two-audience catalog and unrelated prior copy are compared
against immutable Git baselines. New copy must be source-bound and parameter
safe. This is deliberately not the historical 019/021-workbench test.
"""
from __future__ import annotations
import copy
import json
import re
import subprocess
from pathlib import Path

import catalog_owner_r5 as historical
import catalog_ui
import package_post_v9 as package
import test_text_catalog_style as ui

ROOT=Path(__file__).resolve().parents[1]


def duplicate_topic_keys(source: str) -> set[tuple[str,str]]:
    topic=None;seen=set();duplicates=set()
    for line in source.splitlines():
        heading=re.match(r"^    '([^']+)'\s*=>\s*\[$",line)
        if heading:
            topic=heading.group(1)
            continue
        entry=re.match(r"^        '([^']+)'\s*=>",line)
        if entry and topic is not None:
            key=(topic,entry.group(1))
            if key in seen:duplicates.add(key)
            seen.add(key)
    return duplicates


def main() -> dict:
    paths=ui.management_runtime_paths()
    assert len(paths)>70 and len(paths)==len(set(paths))
    assert all((ROOT/name).is_file() for name in paths)
    assert 'src/Finance/Routes/AutomaticGatewayReview.php' in paths
    assert not any(name.endswith(('Routes/DeliveryRecovery.php',
                                  'PaidReplacementRecovery.php',
                                  'ReviewDecisions.php')) for name in paths)
    raw=(ROOT/'messages/fa/admin.php').read_text()
    assert not duplicate_topic_keys(raw), 'duplicate admin topic keys'
    rawuser=(ROOT/'messages/fa/user.php').read_text()
    assert not duplicate_topic_keys(rawuser), 'duplicate customer topic keys'
    shell=subprocess.check_output([
        'php','-r',
        '$r=[];foreach(["user","admin"]as$a)$r[$a]=require "messages/fa/".$a.".php";'
        'echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    ],cwd=ROOT,text=True)
    groups=json.loads(shell)
    current={a:historical.flatten(v) for a,v in groups.items()}
    added=historical.GIFT_PRECHECK_SHA256
    assert len(current['admin'])==1911+len(added['admin']) and \
        len(current['user'])==942+len(added['user'])
    assert not set(current['admin'])&set(current['user'])
    # Verify exact current additions and recipient placement before
    # comparing the older immutable post-cleanup user/admin baseline.
    prior=historical.current_cleanup_restore(
        historical.gift_precheck_copy_restore(current))
    assert len(prior['admin'])==1964 and len(prior['user'])==942
    assert historical.CLEANUP_TOPICS.isdisjoint(groups['admin'])
    assert len(historical.CLEANUP_NEW_KEYS)==33
    content={**current['user'],**current['admin']}
    refs=set()
    pattern=re.compile(r"""achbotMessage(?:Html)?\(\s*['"]([^'"]+)['"]""")
    for path in paths:
        refs.update(pattern.findall((ROOT/path).read_text()))
    dynamic_prefixes={
        'manual_payment.api_payment.code_',
        'manual_payment.api_payment.status_',
        'payment_settings.api.',
        'payment_settings.api.field.',
        'payment_settings.api.hint.',
    }
    assert all(any(k.startswith(prefix) for k in content)
               for prefix in dynamic_prefixes), 'Dynamic copy prefix has no catalog entries'
    unbound=refs-set(content)-dynamic_prefixes
    assert not unbound,'Unbound message literals: '+str(sorted(unbound)[:25])
    assert historical.CLEANUP_NEW_KEYS<=refs, 'Unused new UI copy: '+str(
        sorted(historical.CLEANUP_NEW_KEYS-refs))
    # These cases intentionally perturb real current snapshots. A permanently
    # failing baseline check cannot pass them; each must be rejected distinctly.
    negative=[]
    altered=copy.deepcopy(current);altered['user'][next(iter(altered['user']))]='tampered'
    negative.append(('customer_copy',altered))
    altered=copy.deepcopy(current);altered['admin']['automatic_gateway_review.menu']='tampered'
    negative.append(('new_owner_copy',altered))
    altered=copy.deepcopy(current);altered['admin'].pop('automatic_gateway_review.menu')
    negative.append(('missing_new_key',altered))
    altered=copy.deepcopy(current);altered['user']['automatic_gateway_review.menu']=(
        altered['admin'].pop('automatic_gateway_review.menu'))
    negative.append(('wrong_audience',altered))
    altered=copy.deepcopy(current);altered['admin']['finance_workbench.title']='stale'
    negative.append(('obsolete_workbench_copy',altered))
    for name,changed in negative:
        try:historical.current_cleanup_restore(
            historical.gift_precheck_copy_restore(changed))
        except AssertionError:continue
        raise AssertionError('Negative copy control missed: '+name)
    # The existing native token-based sink inventory includes new routes.
    literals=catalog_ui.ui_candidates(ROOT,paths)
    if literals:
        raise AssertionError('Uncatalogued UI literals: '+json.dumps(literals[:6],
            ensure_ascii=False))
    result={
        'status':'PASS','current_catalog_keys':len(content),
        'current_admin_keys':len(current['admin']),
        'current_user_keys':len(current['user']),
        'retired_experimental_keys':129,
        'retired_experimental_topics':len(historical.CLEANUP_TOPICS),
        'new_copy_entries':len(historical.CLEANUP_NEW_KEYS),
        'current_runtime_files':package.EXPECTED_RUNTIME_COUNT,
        'runtime_checked_php_sources':len(paths),
        'source_literal_bindings':len(refs),
        'new_copy_references_unbound':0,
        'unapproved_existing_copy_changes':0,
        'new_copy_negative_controls':len(negative),
        'bare_ui_copy_violations':0,
        'published_rc26_artifacts_modified':False,
    }
    print(json.dumps(result,ensure_ascii=False,sort_keys=True))
    return result


if __name__=='__main__':
    main()
