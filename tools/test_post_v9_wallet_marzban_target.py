#!/usr/bin/env python3
"""Original Wallet Marzban target through actual helper/Core/adapter + local HTTP.

Two isolated HTTP panels have the same account username. A deterministic
test-only callback changes server_config AFTER Checkout's observed comparison,
or inside the HTTP GET. No mocked Native dispatch, real funds, or deployment.
"""
from __future__ import annotations

import argparse
import copy
import json
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_post_v9_marzban as marzban
import test_post_v9_marzban_edits as edits

ROOT=Path(__file__).resolve().parents[1]
DB=native.DBS[1]

WRAPPER=r'''
function achbotSupportsPanel($type) { return achbotSupportsProviderType($type); }
require_once __DIR__.'/src/Purchase/Core.php';
require_once __DIR__.'/src/Finance/RenewalHelper.php';
function achbotWalletMarzbanTargetHarness($sid,$payId,$original,$mode,$otherUrl) {
    global $connection;
    // This assertion represents the Checkout.php comparison already passed
    // using the original locked Wallet/plan snapshot.
    [$serverInfo,$cfg]=getServerInfoAndConfig($connection,$sid);
    if (($cfg['type']??null)!=='marzban' ||
        rtrim((string)($cfg['panel_url']??''),'/')!==$original['panel_url'])
        throw new RuntimeException('fixture_checkout_expected_original_target');
    if ($mode==='late_provider_url' || $mode==='late_provider_type') {
        $newType=$mode==='late_provider_type'?'pasarguard':'marzban';
        $s=$connection->prepare('UPDATE server_config SET panel_url=?,type=? WHERE id=?');
        $s->bind_param('ssi',$otherUrl,$newType,$sid);
        $s->execute();$s->close();
    }
    if ($mode==='late_adapter_read') {
        // The normal router has already looked up 'marzban'. Mimic the
        // final interleaving *after* that read, before adapter's own read.
        $change=['pay_id'=>$payId,'remark'=>$original['remark'],
            'days'=>$original['days'],'volume'=>$original['volume'],
            '_wallet_renew_target'=>$original];
        $s=$connection->prepare('UPDATE server_config SET panel_url=? WHERE id=?');
        $s->bind_param('si',$otherUrl,$sid);$s->execute();$s->close();
        return achbotMarzbanServiceEditAdapter($connection,$sid,$change);
    }
    return renewConfigOnServer($connection,$sid,0,'fixture-uuid',$original['remark'],
        $original['days'],$original['volume'],true,$payId,$original);
}
'''


def run() -> list[str]:
    cases=[]
    with owned.owned_database() as (work,env), \
         edits.owned_edit_provider() as (a,a_url), \
         edits.owned_edit_provider() as (b,b_url):
        native.seed(work,env,a_url)
        source=work/'wallet-marzban-target-candidate'
        marzban.prepare(work,source,True)
        with (source/'functions-under-test.php').open('a') as f:
            f.write('\n'+WRAPPER)
        native.sql(work,env,("INSERT INTO server_info "
            "(id,title,ucount,remark,flag,active,state) VALUES "
            "(2,'Wallet pinned source',9,'fixture','',1,1)"),DB)
        native.sql(work,env,'UPDATE server_plans SET server_id=2,inbound_id=0 WHERE id=7',DB)
        native.sql(work,env,('INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,'
            'volume,day,price,description,request_date,state) VALUES '
            "('wallet-marzban-target',1001,'RENEW_SCONFIG',7,2,0,0,100,"
            "'{}',UNIX_TIMESTAMP(),'processing_wallet')"),DB)
        payid=int(native.sql(work,env,
             "SELECT id FROM pays WHERE hash_id='wallet-marzban-target'",DB).strip())
        original={'provider':'marzban','server_id':2,'panel_url':a_url,
                  'remark':'fixture-user','pay_id':payid,'days':30,'volume':2}

        def sql(cmd):
            return native.sql(work,env,cmd,DB)
        def reset():
            sql("UPDATE server_config SET type='marzban',panel_url='"+
                a_url.replace("'","''")+"' WHERE id=2")
            for panel in (a,b):
                panel.mode='edit-verified';panel.edit_account=copy.deepcopy(edits.INITIAL)
                panel.trace.clear();panel.edit_put_count=0;panel.edit_reset_count=0
                panel.next_plan_on_get_n=None
                panel.foreign_before_put=None
                panel.move_after_first_get=None;panel.moved=False
        def invoke(mode):
            result=native.run(work,env,source,DB,
                {'function':'achbotWalletMarzbanTargetHarness',
                 'args':[2,payid,original,mode,b_url]})
            if (mode=='late_provider_type' and
                result['exception']=='RuntimeException: wallet_renew_provider_changed' and
                not result['warnings'] and not result['stdout']):
                return {'success':False,'uncertain':True,
                        'msg':'wallet_renew_provider_changed'}
            if result['exception'] or result['warnings'] or result['stdout']:
                raise RuntimeError(('pinned_wallet_native_runtime',mode,result))
            assert isinstance(result['result'],dict),result
            return result['result']
        def writes(panel):
            return sum(e['method'] in ('PUT','POST') and
                       not e['path'].endswith('/api/admin/token')
                       for e in panel.trace)
        reset()
        response=invoke('unchanged')
        assert response['success'] is True and response['verified'] is True,response
        assert a.edit_put_count==1 and a.edit_reset_count==1,(a.trace,response)
        assert not b.trace and a.edit_account['data_limit']==2*edits.GIB
        cases.append('unchanged_original_target_fulfills_via_actual_helper_router_and_native_adapter')
        for mode in ('late_provider_url','late_provider_type','late_adapter_read'):
            reset()
            response=invoke(mode)
            assert response['success'] is False and response.get('uncertain') is True,(mode,response)
            assert response['msg'] in ('wallet_renew_physical_target_changed',
                'wallet_renew_provider_changed'),(mode,response)
            assert not a.trace and not b.trace,(mode,a.trace,b.trace)
            assert a.edit_put_count==b.edit_put_count==0
            cases.append(mode+'_refuses_same_username_on_replacement_physical_target')

        # A changes config during the first authenticated Native GET; the
        # real adapter's subsequent isPinned() must stop before RESET and PUT.
        reset()
        def change_during_get():
            sql("UPDATE server_config SET panel_url='"+b_url+"' WHERE id=2")
        a.move_after_first_get=change_during_get
        response=invoke('unchanged')
        assert response['success'] is False and response.get('uncertain') is True,response
        assert writes(a)==0 and not b.trace,(a.trace,b.trace,response)
        cases.append('config_repoint_during_real_native_get_cannot_reset_or_put_replacement')
        reset()
        response=invoke('unchanged')
        assert response['success'] is True and response['verified'] is True,response
        assert writes(a)>0 and not b.trace
        cases.append('restored_original_target_verifies_without_substitution')
    return cases


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=run()
    result={'complete':True,'cases':len(checks),'checks':checks,
            'actual_helper_core_adapter':True,'two_local_http_panels':True,
            'disposable_mariadb':True,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
