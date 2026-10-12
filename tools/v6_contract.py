#!/usr/bin/env python3
"""AChBot V6: exact V5-source extraction and unchanged legacy contracts.

Only --materialize writes files, for local authoring on the isolated V6 branch.
Verification reconstructs all changed runtime from the pinned *merged* V5 Git blobs.
"""
from __future__ import annotations
import argparse
import hashlib
import re
import subprocess
from pathlib import Path
import v5_contract as v5

ROOT=Path(__file__).resolve().parents[1]
BASE='38dcdfc0703911133d3bc4b5df145f46b0b7a070'
BASE_TREE='4a480545d770bfc51674e1196b33911f31e159e0'
CHANGED=['bot.php','config.php']
MODULES={
    'src/Panel/Accounts.php': 'AchBot\\Panel\\Accounts',
    'src/Subscription/AccountDetails.php': 'AchBot\\Subscription\\AccountDetails',
    'src/Subscription/ExternalAccounts.php': None,
    'src/Subscription/ManualAccounts.php': None,
    'src/Subscription/OrderManagement.php': None,
    'src/Subscription/ConfigRefresh.php': None,
    'src/Subscription/CustomerRoutes.php': None,
    'src/Subscription/SubLinkSettings.php': None,
    'src/Subscription/AccountDeletion.php': None,
    'src/Subscription/AccountTransfers.php': None,
}
PANEL_FUNCS='''deleteClient editInboundRemark editInboundTraffic changeInboundState renewInboundUuid changeClientState renewClientUuid editClientRemark editClientTraffic deleteInbound resetIpLog resetClientTraffic addInboundAccount getNewHeaders getConnectionLink updateConfig editInbound getMarzbanToken getMarzbanJson getMarzbanUserInfo getMarzbanUser getMarzbanHosts addMarzbanUser editMarzbanConfig resetMarzbanTraffic renewMarzbanUUID deleteMarzban changeMarzbanState getJson getNewCert addUser'''.split()
DETAIL_FUNCS='''findOrder getPlanFullName getServerConfigById getOrderTrafficInfo getProtocolNamesRow buildAdminProtocolButtons buildUserProtocolButtons getUserOrderDetailKeys getOrderDetailKeys'''.split()
# Ordered original call-site slots. Keep each require at its exact original route location.
# Start/end are anchors observed in the pinned V5 Git source, not line-number guesses.
ROUTES=[
 ('src/Subscription/ExternalAccounts.php',b'// BEGIN Edit by ACh\n',b'// END Edit by ACh\n'),
 ('src/Subscription/ManualAccounts.php',b'//Added by ACh\n',b'// \xd9\x87\xd9\x86\xd8\xaf\xd9\x84 \xd8\xaf\xda\xa9\xd9\x85\xd9\x87 \xd9\x86\xd9\x85\xd8\xa7\xdb\x8c\xd8\xb4 \xd8\xa7\xd8\xb4\xd8\xaa\xd8\xb1\xd8\xa7\xda\xa9'),
 ('src/Subscription/OrderManagement.php',b'// \xd9\x87\xd9\x86\xd8\xaf\xd9\x84 \xd8\xaf\xda\xa9\xd9\x85\xd9\x87 \xd9\x86\xd9\x85\xd8\xa7\xdb\x8c\xd8\xb4 \xd8\xa7\xd8\xb4\xd8\xaa\xd8\xb1\xd8\xa7\xda\xa9',b"if(preg_match('/sConfigRenewPlan"),
 ('src/Subscription/AccountTransfers.php',b"\n\nif(preg_match('/switchLocation",b'if($data=="giftVolumeAndDay"'),
 ('src/Subscription/ConfigRefresh.php',b"if (preg_match('/sConfigUpdate",b"if (($data == 'addNewPlan'"),
 ('src/Subscription/CustomerRoutes.php',b'function cancelSearchKeyboard()',b"if (preg_match('/^renewAccountAsk"),
 ('src/Subscription/SubLinkSettings.php',b"if(preg_match('/^editSubLinks",b"if(preg_match('/^wizwizdeleteserver"),
]

def git(*args:str)->bytes:
    return subprocess.run(['git','-C',str(ROOT),*args],check=True,capture_output=True).stdout

def reference(name:str)->bytes:
    return git('show',BASE+':'+name)

def guard()->bytes:
    return b"if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {\n    http_response_code(404);\n    exit;\n}\n\n"

def func_extract(src:bytes,names:list[str],namespace:str,path:str)->tuple[bytes,bytes,dict[str,str]]:
    moved=[]; hashes={}
    for name in names:
        regex=rb'(?m)^function\s+'+name.encode()+rb'\s*\(([^\n]*)\)\s*\{'
        matches=list(re.finditer(regex,src))
        if len(matches)!=1:raise RuntimeError('Missing/multiple function '+name)
        m=matches[0]
        closing=re.search(rb'(?m)^}\s*(?:\n|\Z)',src[m.end():])
        if not closing:raise RuntimeError('No closing brace '+name)
        end=m.end()+closing.end()
        body=src[m.start():end]
        if any(token in body for token in (b'__DIR__',b'__FILE__',b'goto ')):
            raise RuntimeError('Relocation-unsafe function '+name)
        sig=m.group(1)
        arguments=re.findall(rb'\$[a-zA-Z_]\w*',sig)
        if not arguments or len(arguments)!=len(sig.split(b',')):
            raise RuntimeError('Complex function signature needs manual review: '+name)
        fname=name.encode()
        wrapper=(b'function '+fname+b'('+sig+b') {\n'
                 +b"    require_once __DIR__ . '/"+path.encode()+b"';\n"
                 +b'    return \\'+namespace.encode()+b'\\'+fname+b'('+b', '.join(arguments)+b');\n}\n')
        if src.count(body)!=1:raise RuntimeError('Ambiguous function body: '+name)
        src=src.replace(body,wrapper,1)
        moved.append(body)
        hashes[name]=hashlib.sha256(body).hexdigest()
    module=(b'<?php\n/** AChBot V6: exact V5 internal '+path.encode()+b' bodies; keep legacy effects. */\n'
            +b'namespace '+namespace.encode()+b';\n'
            +(b'use stdClass;\n' if namespace.startswith('AchBot\\Panel') else b'')
            +b'\n'+guard()+b'\n'.join(moved))
    return src,module,hashes

def region(source:bytes,start:bytes,end:bytes,replacement:bytes)->tuple[bytes,bytes]:
    positions=[m.start() for m in re.finditer(re.escape(start),source)]
    limits=[m.start() for m in re.finditer(re.escape(end),source)]
    if len(positions)!=1 or len(limits)!=1 or positions[0]>=limits[0]:
        raise RuntimeError(f'Ambiguous route span {start[:60]!r}: {len(positions)} {len(limits)}')
    left,right=positions[0],limits[0]
    moved=source[left:right]
    if b'__DIR__' in moved or b'__FILE__' in moved or re.search(rb'\bgoto\b',moved):
        raise RuntimeError('Unsafe route relocation: '+repr(start[:50]))
    return source[:left]+replacement+source[right:],moved

DELETION_START=b"elseif(preg_match('/^deleteMyConfig"
DELETION_END=b"if(preg_match('/increaseADay"

def extract_deletion(source:bytes)->tuple[bytes,bytes]:
    # The original account deletion branches are ELSEIF to an independent
    # gift-dispatch IF immediately above. Preserve that control-flow gate.
    replacement=b"else {\n    require __DIR__ . '/src/Subscription/AccountDeletion.php';\n}\n"
    result,moved=region(source,DELETION_START,DELETION_END,replacement)
    if not moved.startswith(b'elseif(') or b'elseif(' not in moved:
        raise RuntimeError('Original linked deletion branch chain changed')
    normalized=b'if('+moved[len(b'elseif('):]
    module=(b'<?php\n/** AChBot V6: exact linked legacy account deletion branches. */\n'
            +guard()+normalized)
    return result,module

def expected_runtime():
    if git('rev-parse',BASE+'^{tree}').decode().strip()!=BASE_TREE:
        raise RuntimeError('Pinned V5 main tree mismatch')
    result={}; hashes={}
    config=reference('config.php')
    config,panel,ph=func_extract(config,PANEL_FUNCS,MODULES['src/Panel/Accounts.php'],'src/Panel/Accounts.php')
    config,details,dh=func_extract(config,DETAIL_FUNCS,MODULES['src/Subscription/AccountDetails.php'],'src/Subscription/AccountDetails.php')
    result['config.php']=config;result['src/Panel/Accounts.php']=panel
    result['src/Subscription/AccountDetails.php']=details
    hashes['panel']=ph;hashes['account_details']=dh
    bot=reference('bot.php')
    hashes['routes']={}
    for name,start,end in ROUTES:
        repl=(b"if ((require __DIR__ . '/"+name.encode()+b"') === null) return;\n\n")
        bot,moved=region(bot,start,end,repl)
        # PHP resolves include_once paths relative to the current CWD and the
        # including source directory. This particular original bot.php include
        # must remain anchored to the bot root when its route is moved two
        # directories deeper. All other V5 body bytes stay exactly preserved.
        original_body=moved
        if name=='src/Subscription/ConfigRefresh.php':
            old=b"include_once 'phpqrcode/qrlib.php';"
            anchored=b"include_once dirname(__DIR__, 2) . '/phpqrcode/qrlib.php';"
            if moved.count(old)!=1:
                raise RuntimeError('Missing or ambiguous root QR loader')
            moved=moved.replace(old,anchored,1)
        result[name]=(b'<?php\n/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */\n'
                      +guard()+moved)
        hashes['routes'][name]=hashlib.sha256(original_body).hexdigest()
    bot,deletion=extract_deletion(bot)
    result['src/Subscription/AccountDeletion.php']=deletion
    hashes['deletion']=hashlib.sha256(deletion).hexdigest()
    result['bot.php']=bot
    return result,hashes

def runtime_files()->list[str]:
    return sorted(v5.runtime_files()+list(MODULES))

def verify():
    expected,hashes=expected_runtime()
    older=v5.runtime_files()
    for name in older:
        p=ROOT/name
        if not p.is_file() or p.is_symlink():raise RuntimeError('Missing/symlink runtime '+name)
        needed=expected[name] if name in expected else reference(name)
        if p.read_bytes()!=needed:raise RuntimeError('Unexpected runtime modification '+name)
    for name in MODULES:
        p=ROOT/name
        if not p.is_file() or p.is_symlink() or p.read_bytes()!=expected[name]:
            raise RuntimeError('V6 module differs from pinned source '+name)
    all_php={p.relative_to(ROOT).as_posix() for p in ROOT.rglob('*.php')
             if p.relative_to(ROOT).parts[0] not in ('tests','tools','.git') and p.name!='baseInfo.php'}
    expected_php={n for n in runtime_files() if n.endswith('.php')}
    if all_php!=expected_php:raise RuntimeError('Unexpected PHP runtime members '+str(sorted(all_php^expected_php)))
    if 'baseInfo.php' in runtime_files():raise RuntimeError('Private config packaged')
    # Frozen V0-V5 goldens, tools, docs and fixtures must never be re-recorded.
    for area in ('database','tests','docs/v0','docs/v1','docs/v2','docs/v3','docs/v4','docs/v5'):
        prior=[n for n in git('ls-tree','-r','--name-only',BASE,'--',area).decode().splitlines() if n]
        for name in prior:
            p=ROOT/name
            if not p.is_file() or p.read_bytes()!=reference(name):
                raise RuntimeError('Frozen historical evidence changed '+name)
    for name in git('ls-tree','-r','--name-only',BASE,'--','tools').decode().splitlines():
        p=ROOT/name
        if not p.is_file() or p.read_bytes()!=reference(name):raise RuntimeError('Frozen tool changed '+name)
    return {'base':BASE,'base_tree':BASE_TREE,'old_runtime':len(older),
            'new_modules':len(MODULES),'runtime_members':len(runtime_files()),
            'function_count':len(PANEL_FUNCS)+len(DETAIL_FUNCS),
            'route_modules':len(ROUTES)+1,'body_sha256':hashes,
            'historical_fixture_pinned':True,'database_schema_unchanged':True}

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--materialize',action='store_true',help='Authoring only: write expected V6 source to isolated worktree')
    args=ap.parse_args()
    if args.materialize:
        if git('rev-parse','--abbrev-ref','HEAD').decode().strip()!='feature/v6-vpn-account-subscriptions':
            raise SystemExit('Do not materialize outside the isolated V6 feature branch')
        expected,_=expected_runtime()
        for name,body in expected.items():
            p=ROOT/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(body)
    report=verify()
    import json
    print(json.dumps({k:v for k,v in report.items() if k!='body_sha256'}))

if __name__=='__main__':main()
