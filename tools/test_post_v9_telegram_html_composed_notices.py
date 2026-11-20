#!/usr/bin/env python3
"""Output parity of fixed-catalog and number-only composed Telegram notices.

Only explicitly reviewed source-owned compositions are covered. Dynamic
instance overrides, persisted broadcasts, support/caption text and callback
labels are deliberately not converted by this contract.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

import test_post_v9_telegram_html_callsite_parity as lexical

ROOT = Path(__file__).resolve().parents[1]
# A distinctive fragment within each audited sendMessage's first argument.
# Marker uniqueness is verified against PHP-executable calls, never grep lines.
TARGETS = (
    ('bot.php', 'gateway_audience_settings.message.56c51082c2'),
    ('bot.php', 'payment_settings.gateway_rule_admin.exempt_saved'),
    ('bot.php', 'add_marzban_panel_group.message.01f02c2ac6'),
    ('src/Finance/Routes/WalletTransfer.php', 'wallet_transfer_restart.message.1286cd29ed'),
    ('src/Finance/Routes/PrePayment.php', 'pre_payment.message.6a728137d5'),
    ('src/Finance/Routes/ManualReceipts.php', 'broadcast.routes.pm_noop.message.72fe3f7c5d'),
    ('src/Plans/Intervals.php', 'admin_settings.management.review_required'),
    ('src/Plans/AddonAdmin.php', 'addon_scopes.unavailable&addon_scopes.filter_blocked'),
    ('src/Subscription/ServerDisplaySettings.php', 'handle_server_display_admin.text.ed95738546'),
    ('src/Finance/WalletStart.php', 'start_wallet_payment.message.1f46b2b87f'),
    ('src/Purchase/EntryAndBulk.php', 'bulk_reporting.delivery_needs_review'),
)
EXPECTED_SOURCE_SHA256 = 'd53d5a64b328906de59a56eb7241942c55727c71a7fd9edabcef240703cdfdda'
EXPECTED_GOLDEN_SHA256 = '00604bcfe75687fff13d3a6a3d532a0d700d0ba6dbd58ddf73d094ecce1d9195'
HTML_UNSAFE = re.compile(r'[<>&*_`\[\]\\]|https?://|tg://|\bt\.me/', re.I)


def sha(obj: object) -> str:
    return hashlib.sha256(json.dumps(obj,ensure_ascii=False,
        separators=(',', ':'),sort_keys=True).encode('utf-8')).hexdigest()


def inventory(sources: dict[str,str], html_required: bool) -> list[dict]:
    """Identify every target as exactly one active PHP sendMessage call."""
    executable={}
    for path,source in sources.items():
        found=[]
        for start in lexical.executable_send_starts(source):
            end,args=lexical.parse_arguments(source,source.index('(',start))
            if args:
                found.append((start,end,args))
        executable[path]=found
    out=[]
    for file,marker in TARGETS:
        hits=[(start,end,args) for start,end,args in executable[file]
              if all(part in lexical.canonical_expression(args[0]) for part in marker.split('&'))]
        if len(hits)!=1:
            raise AssertionError(f'{file}: expected one executable send containing {marker}; got {len(hits)}')
        start,end,args=hits[0]
        if html_required and (len(args)!=3 or not lexical.HTML.fullmatch(args[2].strip())):
            raise AssertionError(f'{file}: explicit HTML mode missing for {marker}')
        if not 2<=len(args)<=3:
            raise AssertionError(f'{file}: unexpected send signature for {marker}')
        out.append(dict(file=file,marker=marker,start=start,end=end,args=args))
    positions={}
    for row in out:
        positions.setdefault(row['file'], []).append(row['start'])
    for file,starts in positions.items():
        if starts!=sorted(starts):
            raise AssertionError(f'{file}: reviewed notification ordering changed')
    return out


def source_digest(rows: list[dict]) -> str:
    return sha([[row['file'],row['marker'],
                lexical.canonical_expression(row['args'][0]),
                lexical.canonical_expression(row['args'][1])]
               for row in rows])


CASES = [
    {'name':'default','text':'ordinary','unavailable':False,'invalidated':False,
     'step':'none','kind':'save','uncertain':False,'amount':100000,
     'review_count':0,'remove_keyboard':None},
    {'name':'alternate','text':'fixture-cancel','unavailable':True,'invalidated':True,
     'step':'addon:filter','kind':'cancel','uncertain':True,'amount':10000000,
     'review_count':3,'remove_keyboard':'fixture-remove'},
    {'name':'mixed','text':'دیگر 🧪 <>&_`','unavailable':False,'invalidated':True,
     'step':'none','kind':'cancel','uncertain':False,'amount':2500000,
     'review_count':1,'remove_keyboard':'fixture-other'},
]


def render(rows: list[dict]) -> list[dict]:
    # The first two arguments are the actual, exact PHP producer expressions.
    # The source hash is checked BEFORE source expressions are executed.
    expression=','.join('['+row['args'][0]+','+row['args'][1]+']' for row in rows)
    php=r'''
    require 'messages/loader.php';
    function getMainKeys() {return 'fixture-main';}
    $cases=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);$out=[];
    function produce($case){
      $text=$case['text'];$buttonValues=['cancel'=>'fixture-cancel'];
      $unavailable=$case['unavailable'];$invalidated=$case['invalidated'];
      $step=$case['step'];$kind=$case['kind'];
      $GLOBALS['achbotIntervalWriteUncertain']=$case['uncertain'];
      $amount=$case['amount'];$deliveryReview=array_fill(0,$case['review_count'],'failure');
      $cancelKey='fixture-cancel-key';$removeKeyboard='fixture-remove';
      $keys='fixture-wallet-keys';
      if($case['remove_keyboard']===null) unset($GLOBALS['removeKeyboard']);
      else $GLOBALS['removeKeyboard']=$case['remove_keyboard'];
      return ['name'=>$case['name'],'pairs'=>[EXPRESSION]];
    }
    foreach($cases as $case) $out[]=produce($case);
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    '''.replace('EXPRESSION',expression)
    result=subprocess.run(['php','-n','-r',php,json.dumps(CASES,ensure_ascii=False)],
                          cwd=ROOT,text=True,capture_output=True)
    if result.returncode:
        raise AssertionError('PHP rendering failed: '+result.stderr[-3000:]+result.stdout[-3000:])
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError(repr(result.stdout[:2500])+" stderr="+repr(result.stderr[:1000])) from error


def check_rendered(rows: list[dict]) -> int:
    assert len(rows)==len(CASES)
    count=0
    for case,actual in zip(CASES,rows):
        assert actual['name']==case['name']
        assert len(actual['pairs'])==len(TARGETS)
        for i,pair in enumerate(actual['pairs']):
            assert len(pair)==2 and isinstance(pair[0],str), (case['name'],i)
            if HTML_UNSAFE.search(pair[0]):
                raise AssertionError('not parse-mode-safe: '+TARGETS[i][1]+' '+repr(pair[0]))
            count+=1
    return count


def mutators(sources:dict[str,str], reference:str) -> int:
    """Prove the scope rejects a disabled send, changed copy or typed source."""
    originals=inventory(sources,True)
    first=originals[0];file=first['file'];source=sources[file]
    fragment=source[first['start']:first['end']+1]
    bad=[
        ('removed',file,source[:first['start']]+source[first['end']+1:]),
        ('commented',file,source[:first['start']]+'/* '+fragment+' */'+source[first['end']+1:]),
        ('keyboard',file,source[:first['start']]+fragment.replace('$cancelKey','null',1)+source[first['end']+1:]),
        ('catalog',file,source[:first['start']]+fragment.replace('56c51082c2','74d707fe02',1)+source[first['end']+1:]),
        ('parser',file,source[:first['start']]+fragment.replace("'HTML'","'MarkDown'",1)+source[first['end']+1:]),
    ]
    for label,key,old,new in (
        ('unsafe_wallet_amount','start_wallet_payment.message.1f46b2b87f',
         'number_format($amount)','$text'),
        ('unsafe_bulk_count','bulk_reporting.delivery_needs_review',
         'count($deliveryReview)','$text'),
    ):
        row=next(r for r in originals if key in r['marker'])
        path=row['file'];value=sources[path]
        expression=value[row['start']:row['end']+1]
        if expression.count(old)!=1:
            raise AssertionError('expected numeric expression missing: '+key)
        replacement=expression.replace(old,new,1)
        bad.append((label,path,value[:row['start']]+replacement+value[row['end']+1:]))
    for label,path,variant in bad:
        changed=dict(sources)
        changed[path]=variant
        try:
            candidate=inventory(changed,True)
            if source_digest(candidate)==reference:
                raise AssertionError(f'mutation {label} escaped audit')
        except AssertionError as error:
            if str(error)==f'mutation {label} escaped audit':raise
    return len(bad)


def main() -> None:
    p=argparse.ArgumentParser()
    p.add_argument('--print-audit',action='store_true')
    args=p.parse_args()
    sources={file:(ROOT/file).read_text(encoding='utf-8') for file,_ in TARGETS}
    items=inventory(sources,html_required=not args.print_audit)
    origin=source_digest(items)
    if not args.print_audit and origin!=EXPECTED_SOURCE_SHA256:
        raise AssertionError('reviewed source/keyboard expressions changed: '+origin)
    raw=render(items)
    golden=sha(raw)
    cases=check_rendered(raw)
    if args.print_audit:
        print(json.dumps({'source_sha256':origin,'golden_sha256':golden,'cases':cases}))
        return
    if golden!=EXPECTED_GOLDEN_SHA256:
        raise AssertionError('prechange PHP rendered output changed: '+golden)
    rejected=mutators(sources,origin)
    print(json.dumps({'status':'PASS','complete':True,'producers':len(items),
                      'samples':len(CASES),'rendered_messages':cases,
                      'mutation_cases':rejected,'source_sha256':origin,
                      'golden_sha256':golden,'network_calls':0}))


if __name__=='__main__':
    main()
