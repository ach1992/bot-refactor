#!/usr/bin/env python3
"""AChBot V7: mechanically relocate immutable V6.1 purchase/plans source.

No schema, callback, SQL, financial or external-provider semantics are authored here.
The exact original byte segments are the specification and can be regenerated
only from the verified integrated V6.1 Git commit.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path
import backup_hotfix_contract as v61

ROOT = Path(__file__).resolve().parents[1]
BASE = 'ca0405442e98481ec2107772f5d2df94adb2b134'
BASE_TREE = 'da6ad5c94182e8a2bc3e7a02ce968d8f8f0c97eb'
ROUTES = [
    ('src/Purchase/EntryAndBulk.php', b'if(($data == "agentOneBuy"', b"if(preg_match('/payWithTronWallet"),
    ('src/Purchase/Catalogue.php', b"if(preg_match('/selectServer(?<serverId>", b"if(preg_match('/^haveDiscount"),
    ('src/Purchase/TrialCatalogue.php', b'if($data=="getTestAccount"){', b"if((preg_match('/^discountSelectPlan"),
    ('src/Purchase/SelectPlan.php', b"if((preg_match('/^discountSelectPlan", b"if (preg_match('/payCustomWithWallet"),
    ('src/Plans/Intervals.php', b"if($data == 'dayPlanSettings'", b"require __DIR__ . '/src/Support/CreateTicket.php';"),
    ('src/Purchase/TrialProvision.php', b"if (preg_match('/freeTrial", b"if (preg_match('/^showMainButtonAns"),
    ('src/Plans/Administration.php', b"if (($data == 'addNewPlan'", b"if ((require __DIR__ . '/src/Subscription/CustomerRoutes.php') === null) return;"),
    ('src/Plans/Categories.php', b"if(($data=='categoriesSetting'", b"if(($data=='serversSetting'"),
]
HELPERS = [
    ('src/Purchase/Core.php', b"if (!function_exists('getPlanDetails')) {", b"if (!function_exists('buildLinksAndSendQr')) {"),
    ('src/Purchase/OrderWrites.php', b"if (!function_exists('insertOrder')) {", b"if (!function_exists('giveReferralReward')) {"),
    ('src/Purchase/AgentPricing.php', b"if (!function_exists('calculateAgentPrice')) {", b"if (!function_exists('createIncreaseOrder')) {"),
]
MODULES = [x[0] for x in ROUTES + HELPERS]
GUARD = (b"if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {\n"
         b"    http_response_code(404);\n    exit;\n}\n\n")

def git(*args: str) -> bytes:
    return subprocess.check_output(['git','-C',str(ROOT),*args])

def old(path: str) -> bytes:
    return git('show', BASE + ':' + path)

def span(blob: bytes, start: bytes, end: bytes, path: str) -> tuple[bytes,bytes,bytes]:
    if blob.count(start) != 1 or blob.count(end) != 1:
        raise RuntimeError('Ambiguous V7 span: '+path+' '+str((blob.count(start),blob.count(end))))
    a=blob.index(start);b=blob.index(end,a+len(start))
    body=blob[a:b]
    if not body or not body.startswith(start):raise RuntimeError('Empty span: '+path)
    return blob[:a],body,blob[b:]

def expected() -> tuple[dict[str,bytes],dict[str,str]]:
    if git('rev-parse',BASE+'^{tree}').decode().strip() != BASE_TREE:
        raise RuntimeError('Baseline Git tree changed')
    out={};hashes={}
    bot=old('bot.php')
    for name,start,end in ROUTES:
        left,body,right=span(bot,start,end,name)
        if any(t in body for t in (b'goto ', b'__DIR__', b'__FILE__', b'__LINE__', b'__NAMESPACE__')):
            raise RuntimeError('Relocation requires scoped review: '+name)
        # Strict byte containment. Executed inside original require caller scope.
        line_end=b'\r\n' if b'\r\n' in bot[:500] else b'\n'
        hook=b"if ((require __DIR__ . '/"+name.encode()+b"') === null) return;"+line_end
        bot=left+hook+right
        out[name]=b'<?php\n/** AChBot V7: immutable V6.1 purchase or plan route. */\n'+GUARD+body
        hashes[name]=hashlib.sha256(body).hexdigest()
    out['bot.php']=bot
    helpers=old('buy_helpers.php')
    for name,start,end in HELPERS:
        left,body,right=span(helpers,start,end,name)
        if any(t in body for t in (b'goto ', b'__DIR__', b'__FILE__', b'__LINE__', b'__NAMESPACE__')):
            raise RuntimeError('Helper relocation requires scoped review: '+name)
        crlf=b'\r\n' if b'\r\n' in helpers[:150] else b'\n'
        hook=b"require_once __DIR__ . '/"+name.encode()+b"';"+crlf+crlf
        helpers=left+hook+right
        out[name]=b'<?php\n/** AChBot V7: immutable V6.1 purchase helper definitions. */\n'+GUARD+body
        hashes[name]=hashlib.sha256(body).hexdigest()
    out['buy_helpers.php']=helpers
    return out,hashes

def members() -> list[str]:
    return sorted(v61.members()+MODULES)

def verify() -> dict:
    out,hashes=expected()
    pre=v61.members(); all_paths=members()
    if len(pre)!=54 or len(all_paths)!=65 or len(set(all_paths))!=65:
        raise RuntimeError('Invalid original/V7 source allowlist')
    for name in pre:
        path=ROOT/name
        if not path.is_file() or path.is_symlink():raise RuntimeError('Missing/symlink previous member '+name)
        truth=out[name] if name in out else old(name)
        if path.read_bytes()!=truth:raise RuntimeError('V6.1 runtime drift: '+name)
    for name in MODULES:
        path=ROOT/name
        if not path.is_file() or path.is_symlink() or path.read_bytes()!=out[name]:
            raise RuntimeError('V7 extracted module differed: '+name)
    php={p.relative_to(ROOT).as_posix() for p in ROOT.rglob('*.php')
         if p.relative_to(ROOT).parts[0] not in ('tests','tools','.git') and p.name != 'baseInfo.php'}
    expected_php={n for n in all_paths if n.endswith('.php')}
    if php!=expected_php:raise RuntimeError('Unexpected PHP member(s): '+repr(sorted(php^expected_php)))
    # All frozen schema/tools/tests and all sensitive original consumers MUST keep their Git blob identity.
    for path in git('ls-tree','-r','--name-only',BASE,'--','database','tests','tools').decode().splitlines():
        if (ROOT/path).read_bytes()!=old(path):raise RuntimeError('Frozen fixture/schema/test drift: '+path)
    for path in ('config.php','pay/back.php','settings/rewardReport.php','settings/tronChecker.php','settings/subLink.php'):
        if (ROOT/path).read_bytes()!=old(path):raise RuntimeError('High consequence original consumer drift: '+path)
    return {'base':BASE,'base_tree':BASE_TREE,'old_runtime_members':54,'new_runtime_members':65,
            'route_modules':len(ROUTES),'helper_modules':len(HELPERS),'unchanged_schema':True,
            'unchanged_financial_cross_consumers':True,'baseline_body_sha256':hashes}

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--materialize',action='store_true')
    a=parser.parse_args()
    if a.materialize:
        branch=git('branch','--show-current').decode().strip()
        if branch!='feature/v7-plans-purchase-provisioning':raise RuntimeError('Not the authorized V7 worktree')
        out,_=expected()
        for name,data in out.items():
            p=ROOT/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
    z=verify()
    print(json.dumps({k:v for k,v in z.items() if k!='baseline_body_sha256'}))
if __name__=='__main__':main()
