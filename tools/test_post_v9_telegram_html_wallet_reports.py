#!/usr/bin/env python3
"""Current-source wallet notification rendering parity, without DB or Telegram.

Source-owned integer operation facts and fixed catalog content are the only
values allowed in this migration. The golden was captured against current
Stable main immediately before migration (89d758c1); only explicit parse
modes may differ. Per-instance prompts and arbitrary admin/customer text are
not audited here and must retain their legacy modes.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import test_post_v9_telegram_html_callsite_parity as source_audit

ROOT = Path(__file__).resolve().parents[1]
EXPECTED_GOLDEN = "57cee4c7bddae598c4744fa4bd8170174ef1ac72d1d6b276fd0c990c5cfeb336"
EXPECTED_REASON = "62a6d5602923bd75a96e24e48c4cf1d667db2257c822979e02a9bf6c6733c2eb"

# The source function is token-extracted, not copied or reimplemented here.
DRIVER = r'''
error_reporting(E_ALL);
require $argv[1].'/tools/source_scan.php';
require $argv[1].'/messages/loader.php';
require $argv[1].'/src/Admin/InstanceContent.php';
$events=[];
function sendMessage(...$args) {
    $GLOBALS['events'][] = $args;
    return (object)['ok'=>true];
}
function getMainKeys() { return 'fixture-main-keyboard'; }
$from_id=9001;
$removeKeyboard='fixture-remove-keyboard';
$botState=['instanceMessages'=>[
    'reached_main_menu'=>['format'=>'html','text'=>'فهرست اصلی']]];

$source=$argv[1].'/src/Finance/Routes/AdminWallet.php';
foreach (['achbotAdminWalletDebtCallback',
          'achbotAdminWalletOperation',
          'achbotAdminWalletReportResult'] as $name) {
    eval(achbotExtractFunction($source,$name));
}
$cases=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$out=[];
foreach ($cases as $case) {
    $events=[];
    $operation=achbotAdminWalletOperation($case['target'],$case['kind'],
        $case['amount'],$case['before'],$case['after'],$case['update']);
    $operation['pending_step']=$case['pending_step'];
    achbotAdminWalletReportResult($case['result'],$operation,$case['update']);
    $out[]=['case'=>$case['name'],'events'=>$events];
}
// Freeform text cannot enter the integer-only original report facts.
$unsafe=['<user>&', 'name_with_underscore', '`bad`', 'فارسی 🧪'];
foreach ($unsafe as $value) {
    try {
        achbotAdminWalletOperation($value,'credit',1,0,1,123);
        throw new RuntimeException('unsafe integer input accepted');
    } catch (TypeError $expected) {}
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''


def cases() -> list[dict]:
    base = dict(kind='debt', target=1001, amount=125, before=100,
                after=-25, update=902, pending_step='pending:verified:token')
    scenarios = [
        ('debt_negative', 'debt_required', {}),
        ('debt_minimum', 'debt_required', dict(target=1, amount=1, before=0, after=-1, update=903)),
        ('debt_maximum', 'debt_required', dict(target=2147483647, amount=2147483647,
            before=0, after=-2147483647, update=2147483647,
            pending_step='reason=<tag>&_`[🧪]')),  # hash only; never displayed
        ('applied_credit', 'applied', dict(kind='credit', amount=1000000,
            before=-10000, after=990000, update=904)),
        ('applied_debit', 'applied', dict(kind='debit', amount=25,
            before=100, after=75, update=905)),
        ('applied_signed', 'applied', dict(kind='debit', amount=2147483647,
            before=0, after=-2147483647, update=906)),
        ('already_applied_credit', 'already_applied', dict(kind='credit', after=225, update=907)),
        ('already_applied_debit', 'already_applied', dict(kind='debit', update=908)),
        ('balance_changed', 'balance_changed', {}),
        ('stale', 'stale', {}),
        ('invalid', 'invalid', {}),
        ('unknown', 'review', {}),
    ]
    rows=[dict(base, name=name, result=state, **overrides)
          for name, state, overrides in scenarios]
    for row in rows:
        expected=row['before']+(row['amount'] if row['kind']=='credit' else -row['amount'])
        assert row['after']==expected, row['name']
    return rows


def actual() -> list[dict]:
    run = subprocess.run(['php','-r',DRIVER,str(ROOT),
                          json.dumps(cases(), ensure_ascii=False)],
                         cwd=ROOT,capture_output=True,text=True)
    if run.returncode: raise RuntimeError(repr((run.stdout[-4000:],run.stderr[-4000:])))
    return json.loads(run.stdout)


def canonicalize(rows: list[dict]) -> list[dict]:
    """Drop only the wire parse mode to preserve every other sent argument."""
    result=[]
    for row in rows:
        messages=[]
        for args in row['events']:
            assert 1 <= len(args) <= 4, (row['case'],args)
            messages.append([args[0], args[1] if len(args)>1 else None,
                             args[3] if len(args)>3 else None])
        result.append(dict(case=row['case'],messages=messages))
    return result


def digest(obj: object) -> str:
    return hashlib.sha256(json.dumps(obj,ensure_ascii=False,
        sort_keys=True,separators=(',',':')).encode('utf-8')).hexdigest()


def verify(rows: list[dict]) -> int:
    expected = {
        'debt_negative':1, 'debt_minimum':1, 'debt_maximum':1,
        'applied_credit':3, 'applied_debit':3, 'applied_signed':3,
        'already_applied_credit':3, 'already_applied_debit':3,
        'balance_changed':1, 'stale':1, 'invalid':1, 'unknown':1,
    }
    assert len(rows)==len(expected) and set(r['case'] for r in rows)==set(expected)
    count=0
    for row in rows:
        name=row['case']
        events=row['events']
        assert len(events)==expected[name], (name,events)
        for i,args in enumerate(events):
            count += 1
            assert len(args)>=3 and args[2]=='HTML', (name,i,args)
            if name.startswith('applied_') or name.startswith('already_applied_'):
                if i==1:  # Preexisting HTML operator menu; leave it intact.
                    assert args[:3]==['فهرست اصلی','fixture-main-keyboard','HTML']
                    continue
                if i==2:
                    assert len(args)==4 and isinstance(args[3],int)
                else:
                    assert len(args)==3 and args[1]=='fixture-remove-keyboard'
            else:
                assert i==0 and len(args)==3
            # Literal Persian/Unicode and typed ASCII integers only. No
            # Markdown/HTML control characters, so both render text exactly
            # as sent with zero entities and no formatted links.
            assert not re.search(r'[<>&*_`\[\]\\]',args[0]),(name,args[0])
        if name.startswith('debt_'):
            keyboard=json.loads(events[0][1])
            buttons=[item for row in keyboard['inline_keyboard'] for item in row]
            assert len(buttons)==2
            assert buttons[0]['callback_data'].startswith('walletDebtYes:')
            assert buttons[1]['callback_data'].startswith('walletDebtNo:')
    return count



def reason_prompts() -> list[str]:
    """Evaluate the other changed producer's exact source expression in PHP."""
    source=(ROOT/'src/Finance/Routes/AdminWallet.php').read_text()
    eligible=[]
    for start in source_audit.executable_send_starts(source):
        _,args=source_audit.parse_arguments(source,source.index('(',start))
        if args and 'admin_wallet_report_result.message.b890d4f5e3' in args[0]:
            eligible.append(args)
    assert len(eligible)==1, 'reason producer removed/duplicated'
    expression,keyboard,mode=eligible[0]
    assert keyboard.strip()=='null' and source_audit.HTML.fullmatch(mode.strip()), eligible[0]
    values=[]
    for kind in ('debt','credit','debit'):
        php="require 'messages/loader.php';$kind=$argv[1];echo json_encode("+expression+",JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);"
        rendered=json.loads(subprocess.check_output(['php','-n','-r',php,kind],cwd=ROOT,text=True))
        assert not re.search(r'[<>&*_`\[\]\\]',rendered), rendered
        values.append(rendered)
    return values


def main() -> None:
    parser=argparse.ArgumentParser()
    parser.add_argument('--print-golden',action='store_true')
    args=parser.parse_args()
    rows=actual()
    golden=digest(canonicalize(rows))
    reason=digest(reason_prompts())
    if args.print_golden:
        print(json.dumps({"wallet_report":golden,"reason_prompts":reason}))
        return
    assert EXPECTED_GOLDEN and golden==EXPECTED_GOLDEN, ('rendering mismatch',golden)
    assert EXPECTED_REASON and reason==EXPECTED_REASON, ('reason mismatch',reason)
    sends=verify(rows)
    print(json.dumps({'status':'PASS','cases':len(rows),'sends':sends,
                      'rendered_sha256':golden,'reason_sha256':reason}))


if __name__=='__main__':
    main()
