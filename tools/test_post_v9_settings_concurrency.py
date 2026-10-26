#!/usr/bin/env python3
"""PR #60 F3: source-real BOT_STATES lost-update regression (synthetic DB only).

Two independent PHP processes start from identical stale globals. The second
admin changes a different field after the first admin disables Wallet.
Previously this restored the stale audience, while serialized fresh-document
updates must preserve both settings. No production config/DB is accessed.
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import subprocess
import time

import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_checkout_serialization as checkout

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

DRIVER = r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$dbname=$argv[1];$socket=$argv[2];$functions=$argv[3];
$who=$argv[4];$ready=$argv[5];$release=$argv[6];$initial=$argv[7];
$connection=new mysqli('localhost','root','',$dbname,0,$socket);
$connection->set_charset('utf8mb4');
$botState=json_decode($initial,true,512,JSON_THROW_ON_ERROR);
require $functions;
file_put_contents($ready,'snapshot_loaded');
$until=microtime(true)+12;
while (!is_file($release) && microtime(true)<$until) usleep(10000);
if (!is_file($release)) throw new RuntimeException('timeout');
if ($who==='audience') {
    $result=setSettings('gatewayAudience',['wallet'=>'off']);
} elseif ($who==='reward') {
    // Exercises the same field-scoped writer used by rewardReport Cron.
    $result=setSettings('lastRewardMessage',1700009000);
} else {
    $result=setSettings('paymentDraftMinutes',45);
}
echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR);
"""

def source_functions(previous=False):
    content=(subprocess.check_output(['git','show',
             '9774a93c1f98204ce8373f467c62f62a3660903b:config.php'],
             cwd=ROOT) if previous else (ROOT/'config.php').read_bytes())
    if previous:
        a=content.index(b'function setSettings($field, $value){')
    else:
        a=content.index(b'function achbotMutateBotStates(')
    b=content.index(b'\nfunction getRejectedAgentList()',a)
    return b'<?php\n'+content[a:b]+b'\n'

def sql(w,e,q):
    return checkout.sql(w,e,q)

def settings(w,e):
    raw=sql(w,e,"SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1")
    return json.loads(raw)

def wait(path,process):
    deadline=time.monotonic()+5
    while not path.exists() and time.monotonic()<deadline:
        if process.poll() is not None:
            raise AssertionError(('worker_ended',process.communicate()))
        time.sleep(.01)
    assert path.exists(),str(path)

def run_race(w,e,func,initial,seq,index,previous=False):
    sql(w,e,"DELETE FROM setting WHERE type='BOT_STATES'")
    raw=json.dumps(initial,separators=(',',':'))
    sql(w,e,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','"+
        raw.replace("'","''")+"')")
    folder=w/('setting-race-'+str(index));folder.mkdir()
    jobs={}
    for name in sorted(seq):
        ready=folder/(name+'.ready');release=folder/(name+'.release')
        jobs[name]=(subprocess.Popen(['php','-n','-d','extension=mysqlnd',
                    '-d','extension=mysqli',str(w/'setting-fixtures'/'driver.php'),DB,
                    str(w/'mysql.sock'),str(func),name,str(ready),str(release),raw],
                    cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE),
                    ready,release)
    try:
        for proc,ready,_ in jobs.values():wait(ready,proc)
        for name in seq:
            proc,_,release=jobs[name]
            release.write_text('commit')
            out,err=proc.communicate(timeout=11)
            assert proc.returncode==0 and not err,(name,out,err)
        final=settings(w,e)
        expected_deadline=45 if 'timeout' in seq else 30
        if previous:
            assert final['gatewayAudience']['wallet']=='all' and final['paymentDraftMinutes']==expected_deadline,final
        else:
            assert final['gatewayAudience']['wallet']=='off' and final['paymentDraftMinutes']==expected_deadline,final
        if 'reward' in seq:
            assert final['lastRewardMessage']==1700009000,final
        assert final.get('otherSetting')=='preserved'
        return 'f3_'+('red_cached_settings_lost_revocation' if previous
            else ('audience_reward_atomic_distinct_writes' if 'reward' in seq
                  else '_'.join(seq)+'_atomic_distinct_admin_writes'))
    finally:
        for proc,_,release in jobs.values():
            release.write_text('done')
            if proc.poll() is None:proc.kill();proc.communicate(timeout=4)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    scenarios=[]
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        work=w/'setting-fixtures';work.mkdir()
        old=work/'old-settings.php';old.write_bytes(source_functions(True))
        current=work/'new-settings.php';current.write_bytes(source_functions(False))
        driver=work/'driver.php';driver.write_text(DRIVER)
        initial={'gatewayAudience':{'wallet':'all'},'paymentDraftMinutes':30,
                 'otherSetting':'preserved'}
        # A fixture from the previous exact reviewed HEAD must demonstrate RED.
        scenarios.append(run_race(w,e,old,initial,('audience','timeout'),0,True))
        scenarios.append(run_race(w,e,current,initial,('audience','timeout'),1))
        scenarios.append(run_race(w,e,current,initial,('timeout','audience'),2))
        # A separate Cron writer previously overwrote the whole BOT_STATES
        # document from its stale request-global copy. Verify its real source
        # now uses the same atomic writer, then race that writer with an admin.
        cron=(ROOT/'settings/rewardReport.php').read_text()
        assert "setSettings('lastRewardMessage', $nextRewardMessage)" in cron
        assert "UPDATE `setting` SET `value`" not in cron
        assert "INSERT INTO `setting`" not in cron
        scenarios.append(run_race(w,e,current,initial,('audience','reward'),3))
    result={'complete':True,'cases':len(scenarios),'scenarios':scenarios,
            'old_source_discriminated':True,'source_real_php':True,
            'owned_mariadb_only':True,'production_effects':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
