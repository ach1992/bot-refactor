#!/usr/bin/env python3
"""Rehearse V3 -> V4 -> V3 file replacement using the unchanged prior smoke driver."""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import rehearse_swap as prior
import v4_contract as c


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--report',type=Path,required=True)
    args=ap.parse_args()
    with c.previous() as (old,lock):
        c.verify(c.ROOT,old,lock)
        report=prior.drill(old,c.ROOT,lock,'integrated V3 -> V4 candidate -> V3')
    report.update(c.identity())
    report['previous_commit']=c.V3
    args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({'transition':report['transition'],'phases':len(report['phases']),
        'cli_smoke_executions':sum(p['cli_cases'] for p in report['phases']),
        'actual_database_continuity_tested':False,'fpm_opcache_tested':False,
        'operator_manual_acceptance':False,'candidate_head':report['candidate_head'],
        'candidate_tree':report['candidate_tree'],'worktree_dirty':report['worktree_dirty']},sort_keys=True))

if __name__=='__main__':main()
