#!/usr/bin/env python3
"""Old/new sConfigUpdate QR callback under a non-root PHP working directory.

Proves that include_once resolves the packaged QR loader after moving the
callback away from the original root bot.php, and checks an intentional
regression mutation that restores the ambiguous relative include.
"""
from __future__ import annotations
import argparse
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path
import v6_contract as c

ROOT=c.ROOT
LEGACY_INCLUDE=b"include_once 'phpqrcode/qrlib.php';"
ANCHORED_INCLUDE=b"include_once dirname(__DIR__, 2) . '/phpqrcode/qrlib.php';"

RUNNER=r'''<?php
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1') exit(3);
$data='sConfigUpdate3';
$mainValues=['please_wait_message'=>'Please wait'];
$userInfo=['temp'=>json_encode(['id'=>0,'uuid'=>'fixture-uuid','remark'=>'fixture','marzban'=>true])];
$botState=['configLinkState'=>'on'];
$botUrl='https://bot.fixture.invalid/';
$trace=[];$warnings=[];$exception=null;
function alert($txt){$GLOBALS['trace'][]=['alert',$txt];}
function getMarzbanUserInfo($id,$remark){
    $GLOBALS['trace'][]=['read.marzban',$id,$remark];
    return (object)['links'=>['vless://fixture-account']];
}
function RandomString($length=9,$type='all'){return 'fixture-qr';}
function sendPhoto(...$args){$GLOBALS['trace'][]=['sendPhoto',$args];}
function delMessage(){throw new RuntimeException('Unexpected cancellation');}
set_error_handler(function($level,$message){
    if(error_reporting() & $level)$GLOBALS['warnings'][]=$level.': '.$message;
    return true;
});
error_reporting(E_ALL);
ob_start();
try{require __DIR__.'/route.php';}
catch(Throwable $e){$exception=get_class($e).': '.$e->getMessage();}
$stdout=ob_get_clean();
file_put_contents(__DIR__.'/result.json',json_encode([
    'events'=>$trace,'warnings'=>$warnings,'exception'=>$exception,
    'stdout'=>$stdout,'qr_loaded'=>class_exists('QRcode',false),
    'generated_png_exists'=>file_exists(getcwd().'/fixture-qr.png')
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
'''
QR_STUB=r'''<?php
class QRcode {
    static function png($link,$file,$ecc,$pixels,$frame){
        $GLOBALS['trace'][]=['qr.png',$link,$file,$ecc,$pixels,$frame];
        file_put_contents($file,'synthetic-QR-'.strlen($link));
    }
}
'''

def stage(root:Path,changed:bool):
    (root/'phpqrcode').mkdir(parents=True)
    (root/'phpqrcode/qrlib.php').write_text(QR_STUB)
    (root/'runner.php').write_text(RUNNER)
    orig=c.reference('bot.php')
    start,end=next((a,b) for path,a,b in c.ROUTES if path=='src/Subscription/ConfigRefresh.php')
    _,body=c.region(orig,start,end,b'')
    if body.count(LEGACY_INCLUDE)!=1:
        raise RuntimeError('Original V5 QR include changed')
    if changed:
        dest=root/'src/Subscription/ConfigRefresh.php'
        dest.parent.mkdir(parents=True)
        shutil.copyfile(ROOT/'src/Subscription/ConfigRefresh.php',dest)
        (root/'route.php').write_text("<?php require __DIR__.'/src/Subscription/ConfigRefresh.php';\n")
    else:
        (root/'route.php').write_bytes(b'<?php\n'+body)

def run(root:Path,external_cwd:Path):
    dest=root/'result.json'
    if dest.exists():dest.unlink()
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'ACHBOT_PRIVATE_TEST':'1','LANG':'C.UTF-8'}
    command=['php','-n','-d','open_basedir='+str(root)+':'+str(external_cwd),
             '-d','allow_url_fopen=0','-d','display_errors=0','-d','log_errors=0',str(root/'runner.php')]
    proc=subprocess.run(command,cwd=external_cwd,env=env,capture_output=True,timeout=12)
    if proc.returncode or proc.stdout or proc.stderr or not dest.exists():
        raise RuntimeError('Controlled PHP QR runtime failed '+str((proc.returncode,proc.stdout[-350:],proc.stderr[-350:])))
    return json.loads(dest.read_text())

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    c.verify()
    with tempfile.TemporaryDirectory(prefix='achbot-v6-qr-cwd-') as tmp:
        work=Path(tmp); old=work/'old'; new=work/'new'; outside=work/'foreign-cwd'
        outside.mkdir();stage(old,False);stage(new,True)
        a=run(old,outside);b=run(new,outside)
        if a!=b or a['warnings'] or a['exception'] or not a['qr_loaded'] or a['generated_png_exists']:
            raise RuntimeError('QR callback differed after extraction outside root CWD: '+json.dumps({'old':a,'new':b},ensure_ascii=False)[:2100])
        expected=[name for name,*rest in a['events']]
        if expected!=['alert','read.marzban','qr.png','sendPhoto']:
            raise RuntimeError('Incomplete QR route fixture: '+str(expected))
        # Negative control: replacing the anchored include with the old
        # relative literal must make this exact previously untested mode fail.
        target=new/'src/Subscription/ConfigRefresh.php'
        source=target.read_bytes()
        if source.count(ANCHORED_INCLUDE)!=1:
            raise RuntimeError('QR call-site lacks one anchored include')
        target.write_bytes(source.replace(ANCHORED_INCLUDE,LEGACY_INCLUDE,1))
        mutation=run(new,outside)
        if mutation==a or not mutation['exception'] or mutation['qr_loaded']:
            raise RuntimeError('Expected QR path negative mutation was not detected')
    report={'v5_reference':c.BASE,'production_qr_loader_unchanged':True,
            'old_and_new_non_root_cwd_qr_telegram_wire_equal':True,
            'original_png_cleanup_preserved':True,
            'wrong_relative_path_negative_control_detected':True,
            'real_php_cli_with_synthetic_qr_library':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
