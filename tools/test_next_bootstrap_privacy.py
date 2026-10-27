#!/usr/bin/env python3
"""Source-native bootstrap prefix, fake mysqli, owned loopback HTTP; no database."""
from __future__ import annotations
import json,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='f3c6fa956edcdfcb057a05a80da2c05741de5a69'
CANARY='PRIVATE_BOOTSTRAP_CANARY_host_database_password_exception'
BOUNDARY="require_once __DIR__ . '/src/Purchase/Core.php';"
DRIVER=r'''<?php
$case=$_GET['case']??($argv[1]??'valid');
class mysqli {
    public string $connect_error='';
    public string $charset='';
    public int $charsetCalls=0;
    function __construct(...$args) {
        global $case;
        if ($args!==['localhost','fixture_user','PRIVATE_BOOTSTRAP_CANARY_host_database_password_exception','fixture_db'])
            throw new RuntimeException('unexpected_fixture_inputs');
        if ($case==='connect_throw') throw new RuntimeException('PRIVATE_BOOTSTRAP_CANARY_host_database_password_exception');
        if ($case==='connect_warning') trigger_error('PRIVATE_BOOTSTRAP_CANARY_host_database_password_exception',E_USER_WARNING);
        if (in_array($case,['connect_error','connect_warning'],true))
            $this->connect_error='PRIVATE_BOOTSTRAP_CANARY_host_database_password_exception';
    }
    function set_charset($charset) {
        global $case;
        $this->charset=$charset;$this->charsetCalls++;
        if ($case==='charset_throw') throw new RuntimeException('PRIVATE_BOOTSTRAP_CANARY_host_database_password_exception');
        if ($case==='charset_warning') trigger_error('PRIVATE_BOOTSTRAP_CANARY_host_database_password_exception',E_USER_WARNING);
        return !in_array($case,['charset_false','charset_warning'],true);
    }
}
function mysqli_report(...$args) {throw new RuntimeException('forbidden_global_reporting_change');}
require __DIR__.'/config-prefix.php';
echo 'CONTINUED:'.$connection->charset.':'.$connection->charsetCalls;
'''
def check(ok,message):
    if not ok:raise AssertionError(message)
def prefix(source):
    check(source.count(BOUNDARY)==1,'bootstrap boundary drift')
    return source.split(BOUNDARY,1)[0]
def request(url):
    try:
        with urllib.request.urlopen(url,timeout=5) as r:return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.headers
def main():
    source=(ROOT/'config.php').read_text()
    old=subprocess.check_output(['git','show',BASE+':config.php'],cwd=ROOT).decode()
    cases=['connect_throw','connect_error','connect_warning','charset_throw','charset_false','charset_warning']
    checks=0
    with tempfile.TemporaryDirectory(prefix='achbot-bootstrap-privacy-') as directory:
        work=Path(directory)
        shutil.copytree(ROOT/'messages',work/'messages')
        (work/'settings').mkdir()
        for name in ['values.php','jdf.php']:shutil.copyfile(ROOT/'settings'/name,work/'settings'/name)
        (work/'baseInfo.php').write_text("<?php $dbUserName='fixture_user';$dbPassword='"+CANARY+"';$dbName='fixture_db';")
        (work/'driver.php').write_text(DRIVER)
        file=work/'config-prefix.php';file.write_text(prefix(old))
        for case in ['connect_throw','connect_error']:
            run=subprocess.run(['php','-n','-d','display_errors=1','driver.php',case],cwd=work,capture_output=True,text=True,timeout=5)
            check(CANARY in run.stdout+run.stderr,'archived bootstrap disclosure RED missing: '+case);checks+=1
        file.write_text(prefix(source))
        expected=subprocess.check_output(['php','-r','require "messages/loader.php";echo achbotMessage("bot.bootstrap.unavailable");'],cwd=ROOT).decode()
        for case in cases+['valid']:
            run=subprocess.run(['php','-n','-d','display_errors=1','driver.php',case],cwd=work,capture_output=True,text=True,timeout=5)
            check(CANARY not in run.stdout+run.stderr,'CLI bootstrap canary disclosed: '+case)
            if case=='valid':
                check(run.returncode==0 and run.stdout=='CONTINUED:utf8mb4:1' and not run.stderr,'valid bootstrap did not continue')
            else:
                check(run.returncode==1 and run.stdout==expected,'failed bootstrap continued or changed safe response: '+case)
                stage='connection' if case.startswith('connect_') else 'charset'
                check(run.stderr=='AChBot bootstrap_unavailable stage='+stage+'\n','bootstrap diagnostic metadata drift: '+case)
            checks+=1
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        with (work/'http.log').open('w+') as log:
            server=subprocess.Popen(['php','-n','-d','display_errors=1','-S','127.0.0.1:'+str(port),'-t',str(work)],cwd=work,stdout=log,stderr=log)
            try:
                deadline=time.monotonic()+5
                while True:
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                    except OSError:
                        check(time.monotonic()<deadline,'owned HTTP listener unavailable');time.sleep(.03)
                for case in cases+['valid']:
                    code,body,headers=request('http://127.0.0.1:'+str(port)+'/driver.php?case='+case)
                    check(CANARY not in body,'HTTP bootstrap canary disclosed: '+case)
                    if case=='valid':check(code==200 and body=='CONTINUED:utf8mb4:1','HTTP valid bootstrap did not continue')
                    else:
                        check(code==503 and body==expected and 'CONTINUED' not in body,'HTTP failure did not stop bootstrap: '+case)
                        check(headers['Content-Type'].lower()=='text/plain; charset=utf-8','HTTP failure content type')
                    checks+=1
            finally:server.terminate();server.wait(timeout=5)
            log.seek(0);check(CANARY not in log.read(),'HTTP log leaked raw bootstrap data')
    print(json.dumps({'status':'PASS','cases':checks,'archived_disclosure_RED':2,'actual_bootstrap_prefix':True,
                      'exception_and_nonthrowing_connection_charset':True,'owned_http_only':True,'real_database_or_provider_calls':0}))
if __name__=='__main__':main()
