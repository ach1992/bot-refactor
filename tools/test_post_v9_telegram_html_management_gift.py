#!/usr/bin/env python3
"""Actual-PHP golden parity for source-owned management/gift/status notices.

These particular producers only interpolate fixed catalogs, formatted jdate,
configured static policy labels/ranges, or positive integer gift-journal IDs.
Custom admin/customer text, Markdown code/help and stored captions are excluded.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

import test_post_v9_telegram_html_callsite_parity as scan

ROOT = Path(__file__).resolve().parents[1]
# Each expression must identify exactly one PHP-executable call in this file.
TARGETS = (
    ('src/Admin/ManagementFlow.php', "achbotManagementText('canceled')"),
    ('src/Admin/ManagementFlow.php', "achbotManagementText('intro')"),
    ('src/Admin/ManagementFlow.php', "achbotManagementText('review_required')"),
    ('src/Admin/ManagementFlow.php', 'achbotManagementText($public)'),
    ('src/Admin/ManagementFlow.php', 'achbotManagementText(in_array($code'),
    ('bot.php', 'webhook_ingress_authenticated.message.5e45680470'),
    ('bot.php', 'payment_policy_settings.message.0aef5ea61e'),
    ('bot.php', 'change_config_remark_type.message.8f40ac53e7'),
    ('bot.php', 'gift_volume_and_day.message.71a178ae08'),
    ('bot.php', 'gift_volume_and_day.message.5999ce86f9'),
    ('bot.php', 'gift_volume_and_day.message.df8d2180ae'),
)
EXPECTED_SOURCE_SHA256 = 'a38cb205ee5a7cc7a5c8de84a48de3bb4bc3aca1efdd1693bdbd805e4434fce1'
EXPECTED_GOLDEN_SHA256 = 'd53b2a737f1ba2d675df4545ac644e85faca05353f60fe693d6d45cd33ae6514'
HTML_UNSAFE = re.compile(r'[<>&*_`\[\]\\]|https?://|tg://|\bt\.me/', re.I)

GATES = [
    'bot.bot.change_config_remark_type.gate.44b818af28',
    'bot.bot.change_config_remark_type.gate.55d4692d90',
    'bot.bot.change_config_remark_type.gate.6dc3d1ffc2',
    'bot.bot.change_config_remark_type.gate.4267cb9eda',
    'bot.bot.change_config_remark_type.gate.a77ae56406',
    'bot.bot.change_config_remark_type.gate.9d8c0fcdf4',
    'bot.bot.change_config_remark_type.gate.7602e74547',
    'bot.bot.change_config_remark_type.gate.7e0248ed23',
    'bot.bot.change_config_remark_type.gate.e152f81bc6',
    'payment_settings.invite_reward_prompt',
    'bot.bot.change_config_remark_type.gate.4f73f2daea',
]
PUBLIC = [
    'management_input', 'management_connection', 'management_targets',
    'management_stale', 'management_other_flow', 'management_other_action',
    'management_admin_required', 'management_unavailable', 'review_required',
]
ERROR_CODES = [
    'management_stale', 'management_targets', 'management_input',
    'management_other_flow', 'management_other_action', 'management_connection',
    'management_admin_required', 'server_referenced',
    'management_rebind_requires_review', 'management_commit_unknown',
    'unexpected',
]
PREFIX = ['', 'restart','server_saved','plan_saved','plan_updated','settings_saved']


def digest(obj:object) -> str:
    return hashlib.sha256(json.dumps(obj,ensure_ascii=False,
        sort_keys=True,separators=(',',':')).encode('utf-8')).hexdigest()


def cases() -> list[dict]:
    result=[]
    for i in range(max(len(GATES),len(ERROR_CODES),len(PUBLIC),14)):
        result.append(dict(name=f'case_{i:02}',public=PUBLIC[i%len(PUBLIC)],
            code=ERROR_CODES[i%len(ERROR_CODES)],prefix=PREFIX[i%len(PREFIX)],
            field_index=i%7,gate=GATES[i%len(GATES)],
            network=['BEP20','TRC20','ERC20'][i%3],
            amount_id=[1,7,100000,2147483647][i%4],
            stamp=['1405/08/29 12:34:56','1404/01/01 00:00:00'][i%2],
            remove=None if i%2==0 else 'fixture-remove-keyboard'))
    return result


def inventory(sources:dict[str,str],require_html:bool) -> list[dict]:
    found={}
    for file,source in sources.items():
        hits=[]
        for start in scan.executable_send_starts(source):
            end,args=scan.parse_arguments(source,source.index('(',start))
            if args:hits.append((start,end,args))
        found[file]=hits
    rows=[]
    for file,marker in TARGETS:
        hits=[(start,end,args) for start,end,args in found[file]
              if marker in scan.canonical_expression(args[0])]
        if len(hits)!=1:
            raise AssertionError(f'{file} {marker}: {len(hits)} executable sends')
        start,end,args=hits[0]
        if len(args)>3:raise AssertionError(f'{file} {marker}: unrelated send signature')
        if require_html and (len(args)!=3 or not scan.HTML.fullmatch(args[2].strip())):
            raise AssertionError(f'{file} {marker}: missing explicit HTML mode')
        rows.append(dict(file=file,marker=marker,start=start,end=end,args=args))
    for file in sources:
        positions=[r['start'] for r in rows if r['file']==file]
        if positions!=sorted(positions):raise AssertionError('send order changed: '+file)
    return rows


def source_digest(rows:list[dict]) -> str:
    return digest([[r['file'],r['marker'],
        scan.canonical_expression(r['args'][0]),
        scan.canonical_expression(r['args'][1]) if len(r['args'])>1
        and r['args'][1].strip().lower()!='null' else None]
        for r in rows])


def run_real_php(rows:list[dict]) -> list[dict]:
    expressions=','.join('['+r['args'][0]+','+(
        r['args'][1] if len(r['args'])>1 else 'null')+']' for r in rows)
    driver=r'''
    error_reporting(E_ALL);
    require 'tools/source_scan.php';
    require 'messages/loader.php';
    eval(achbotExtractFunction('src/Admin/ManagementFlow.php','achbotManagementText'));
    eval(achbotExtractFunction('config.php','achbotPaymentPolicyFields'));
    $cases=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);$out=[];
    function produce(array $c):array {
        $prefix=$c['prefix']===''?'':achbotManagementText($c['prefix']);
        $public=$c['public'];$code=$c['code'];
        $allowed=['management_stale','management_targets','management_input',
            'management_other_flow','management_other_action','management_connection',
            'management_admin_required','server_referenced','management_rebind_requires_review'];
        $time=$c['stamp'];$field=array_values(achbotPaymentPolicyFields())[$c['field_index']];
        $gate=$c['gate']==='bot.bot.change_config_remark_type.gate.7602e74547'
            ?achbotMessage($c['gate'],['network'=>$c['network']]):achbotMessage($c['gate']);
        $directClaim=['id'=>$c['amount_id']];
        $cancelKey='fixture-cancel';
        $removeKeyboard=$c['remove'];
        return ['case'=>$c['name'],'pairs'=>[EXPRESSION]];
    }
    foreach($cases as $case) $out[]=produce($case);
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    '''.replace('EXPRESSION',expressions)
    run=subprocess.run(['php','-n','-d','extension=tokenizer','-r',driver,
        json.dumps(cases(),ensure_ascii=False)],cwd=ROOT,text=True,capture_output=True)
    if run.returncode or not run.stdout.startswith('['):
        raise AssertionError('PHP golden error: '+repr((run.returncode,run.stdout[:900],run.stderr[-2000:])))
    return json.loads(run.stdout)


def verify_render(rows:list[dict]) -> int:
    expected=cases();assert len(rows)==len(expected)
    count=0
    for case,result in zip(expected,rows):
        assert result['case']==case['name']
        assert len(result['pairs'])==len(TARGETS)
        for i,(value,keyboard) in enumerate(result['pairs']):
            assert isinstance(value,str),(case['name'],i)
            assert keyboard is None or isinstance(keyboard,str),(case['name'],i,keyboard)
            if HTML_UNSAFE.search(value):
                raise AssertionError('not literal safe: '+str((case['name'],TARGETS[i],value)))
            count+=1
    return count


def mutations(sources:dict[str,str],reference:str) -> int:
    rows=inventory(sources,True)
    selected=[rows[1],rows[6],rows[-1]]
    altered=[]
    for row in selected:
        path=row['file'];source=sources[path];old=source[row['start']:row['end']+1]
        altered += [('removed',path,source[:row['start']]+source[row['end']+1:])]
        altered += [('commented',path,source[:row['start']]+'/*'+old+'*/'+source[row['end']+1:])]
        altered += [('mode',path,source[:row['start']]+old.replace("'HTML'","'MarkDown'",1)+source[row['end']+1:])]
    for label,source,before,after in [
        ('unsafe_id','bot.php',"$directClaim['id']",'$text'),
        ('unsafe_policy','bot.php',"$field['min']",'$text'),
    ]:
        row=next(r for r in rows if (label=='unsafe_id' and '71a178ae08' in r['marker']) or
                 (label=='unsafe_policy' and 'payment_policy_settings' in r['marker']))
        content=sources[source];expr=content[row['start']:row['end']+1]
        assert expr.count(before)==1, (label,expr)
        altered.append((label,source,content[:row['start']]+expr.replace(before,after,1)+content[row['end']+1:]))
    for label,file,variant in altered:
        copy=dict(sources);copy[file]=variant
        try:
            revised=inventory(copy,True)
            if source_digest(revised)==reference:
                raise AssertionError(f'mutation escaped: {label}:{file}')
        except AssertionError as exc:
            if str(exc).startswith('mutation escaped:'):raise
    return len(altered)


def main() -> None:
    parser=argparse.ArgumentParser();parser.add_argument('--print-audit',action='store_true')
    args=parser.parse_args()
    sources={file:(ROOT/file).read_text(encoding='utf-8') for file,_ in TARGETS}
    rows=inventory(sources,not args.print_audit)
    source=source_digest(rows)
    if not args.print_audit and source!=EXPECTED_SOURCE_SHA256:
        raise AssertionError('source expressions/keyboard drift: '+source)
    rendered=run_real_php(rows)
    total=verify_render(rendered)
    golden=digest(rendered)
    if args.print_audit:
        print(json.dumps(dict(source_sha256=source,golden_sha256=golden,
                              samples=len(rendered),messages=total)))
        return
    if golden!=EXPECTED_GOLDEN_SHA256:
        raise AssertionError('full PHP prechange output drift: '+golden)
    rejected=mutations(sources,source)
    print(json.dumps(dict(status='PASS',producers=len(rows),samples=len(rendered),
                          outputs=total,rejected_mutations=rejected,
                          source_sha256=source,golden_sha256=golden,network_calls=0)))


if __name__=='__main__':main()
