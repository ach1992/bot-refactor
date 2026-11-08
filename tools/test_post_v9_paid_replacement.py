#!/usr/bin/env python3
"""Issue #117: source-real staff-attested replacement, never original POST replay.

PHP 8.5, disposable socket-only MariaDB, synthetic Marzban native account and
actual loopback PasarGuard HTTP fixture. No live providers, payments or Telegram.
"""
from __future__ import annotations
import argparse
import json
import re
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_hosted_linked_recovery as hosted
import test_post_v9_wallet_recovery as wallet
import provider_storage_fixture as storage

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

# Original proof is created by the actual source checkout/007/native flow in
# hosted.ORIGINAL. Its request and payment are NEVER synthesized as settled in
# this runner. The new native write path stays behind source-reserved 021 rows.
# A zero original-007 prefix is valid in this recovery fixture; PHP range(1,0)
# is [1,0], not an empty list, so narrow the synthetic original crash driver
# without altering the historic source-real provisioner it invokes.
ORIGINAL=hosted.ORIGINAL.replace(
    "foreach (range(1,(int)($spec['create_prefix']??$count)) as $ordinal) {",
    "foreach (((int)($spec['create_prefix']??$count))>0"
    "?range(1,(int)($spec['create_prefix']??$count)):[] as $ordinal) {")
assert ORIGINAL != hosted.ORIGINAL
DRIVER=hosted.RECOVERY
old=DRIVER[DRIVER.index('function createServerAccount(...$args) {'):
           DRIVER.index('function getMarzbanUser(...$args) {')]
new=r'''function createServerAccount(...$args) {
    if (($args[0]??null)!=='marzban') throw new RuntimeException('wrong_native_adapter');
    $name=(string)($args[4]??'');
    $q=$GLOBALS['connection']->prepare("SELECT id,ordinal,operation_id,state,effect_kind
        FROM service_effects WHERE remote_username=? LIMIT 1");
    $q->bind_param('s',$name);$q->execute();$e=$q->get_result()->fetch_assoc();$q->close();
    if (!$e || $e['effect_kind']!=='replacement' || $e['state']!=='dispatching' ||
        $e['operation_id']!==(string)($args[14]??''))
        throw new RuntimeException('replacement_native_not_pre_reserved');
    $GLOBALS['events'][]=['event'=>'REPLACEMENT_POST','id'=>(int)$e['id']];
    // A fixture marker models native persistence beyond a lost HTTP reply.
    $path=dirname($GLOBALS['socket']).'/paid-repl-'.(int)$e['id'].'.native';
    if (file_put_contents($path,'created')===false)
        throw new RuntimeException('native_fixture_store_failed');
    if (($GLOBALS['spec']['lost_post']??false)===true)
        throw new RuntimeException('fixture_native_reply_lost');
    return [(object)['success'=>true],$name,12345];
}
'''
assert old.count('function createServerAccount')==1
DRIVER=DRIVER.replace(old,new,1)
old=DRIVER[DRIVER.index('function getMarzbanUser(...$args) {'):
           DRIVER.index('function buildLinksAndSendQr(...$args) {')]
new=r'''function getMarzbanUser(...$args) {
    if (($args[3]??false)!==true) throw new RuntimeException('native_strict_read_required');
    $name=(string)($args[1]??'');
    $q=$GLOBALS['connection']->prepare("SELECT id,effect_kind,operation_id,requested_expire,requested_bytes
        FROM service_effects WHERE remote_username=? LIMIT 1");
    $q->bind_param('s',$name);$q->execute();$e=$q->get_result()->fetch_assoc();$q->close();
    if (!$e || $e['effect_kind']!=='replacement') return null;
    $path=dirname($GLOBALS['socket']).'/paid-repl-'.(int)$e['id'].'.native';
    $GLOBALS['events'][]=['event'=>'REPLACEMENT_NATIVE_GET','id'=>(int)$e['id']];
    if (!is_file($path)) return null;
    return (object)['username'=>$name,'note'=>'achbot:v1:'.$e['operation_id'],
        'expire'=>(int)$e['requested_expire'],'data_limit'=>(int)$e['requested_bytes'],
        'status'=>'active','subscription_url'=>'/sub/fixture-original',
        'links'=>['vless://fixture.invalid']];
}
'''
assert old.count('function getMarzbanUser')==1
DRIVER=DRIVER.replace(old,new,1)
DRIVER=DRIVER.replace("$pay=(int)$spec['pay'];$paymentId=$pay;",
    "$GLOBALS['socket']=$argv[2];$pay=(int)$spec['pay'];$paymentId=$pay;",1)
DRIVER=DRIVER.replace("require_once $root.'/src/Finance/DeliveryLedger.php';",
    "require_once $root.'/src/Finance/DeliveryLedger.php';\nrequire_once $root.'/src/Finance/PaidReplacementRecovery.php';",1)
DRIVER=DRIVER.replace("effect_kind='create' AND remote_username=?",
    "effect_kind IN ('create','replacement') AND remote_username=?",1)
DRIVER=DRIVER.replace("    if (($GLOBALS['spec']['native']??'')==='wrong_note') $note='foreign:'.$e['operation_id'];\n    if (($GLOBALS['spec']['native']??'')==='missing') return null;",
    "    if ($e['effect_kind']==='create' && ($GLOBALS['spec']['native']??'')==='wrong_note') $note='foreign:'.$e['operation_id'];\n    if ($e['effect_kind']==='create' && ($GLOBALS['spec']['native']??'')==='missing') return null;\n    if ($e['effect_kind']==='replacement' && !is_file(dirname($GLOBALS['socket']).'/paid-repl-'.(int)$e['id'].'.native')) return null;",1)
DRIVER=DRIVER.replace("SELECT operation_id,remote_username,requested_expire,requested_bytes\n",
    "SELECT id,effect_kind,operation_id,remote_username,requested_expire,requested_bytes\n",1)
needle="    if (($spec['op']??'')==='eligible') $result=achbotHostedLinkedBuyRecoverable($connection,$pay);"
assert DRIVER.count(needle)==1
DRIVER=DRIVER.replace(needle,r'''    if (($spec['op']??'')==='eligible') $result=achbotHostedLinkedBuyRecoverable($connection,$pay);
    elseif (($spec['op']??'')==='reconciled_eligible')
        $result=achbotHostedReconciledBuyRecoverable($connection,$pay);
    elseif (($spec['op']??'')==='replacement_preview') {
        $actor=(int)($spec['actor']??9001);$ordinal=(int)($spec['ordinal']??1);
        $e=achbotHostedReplacementEvidence($connection,$pay,$ordinal,$reader);
        $result=$e===null?null:['reason'=>$e['reason'],'status'=>$e['status'],
            'challenge'=>achbotHostedReplacementChallenge($actor,$pay,$ordinal,$e)];
    }
    elseif (($spec['op']??'')==='replacement_confirm') {
        $actor=(int)($spec['actor']??9001);$ordinal=(int)($spec['ordinal']??1);
        $result=achbotHostedCreateReconciledReplacement($connection,$pay,$ordinal,$actor,9001,
            (string)($spec['challenge']??''),$reader);
    }
    elseif (($spec['op']??'')==='replacement_status') {
        $result=achbotHostedReconciliationSnapshot($connection,$pay);
        $result=$result?['count'=>$result['count'],
            'original'=>array_keys($result['original']),
            'replacement'=>array_keys($result['replacement']),
            'effective'=>array_keys(array_filter($result['lines']))]:null;
    }''',1)

def check():
    cases=[]
    with owned.owned_database() as (work,env),wallet.wallet_panel() as (server,url):
        purchase.init(work,env,url)
        for fn in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                   '007_service_effects.sql','011_provider_identity.sql',
                   '012_shared_provider_storage.sql'):
            hosted.sql(work,env,(ROOT/'migrations/post-v9'/fn).read_text())
        hosted.sql(work,env,storage.connection(2,1,url)+
            "UPDATE server_plans SET provider_group_ids='[2,5]' WHERE id IN (7,8)")
        for fn in ('016_remove_pasarguard_server_groups.sql',
                   '017_shared_plan_changes.sql','018_payment_delivery_intents.sql',
                   '019_payment_review_decisions.sql','020_payment_followup_intents.sql',
                   '021_paid_reconciliation_provenance.sql'):
            hosted.sql(work,env,(ROOT/'migrations/post-v9'/fn).read_text())
        driver=work/'reconciled-hosted.php';driver.write_text(DRIVER)
        original=work/'original-hosted.php';original.write_text(ORIGINAL)
        def sql(query):return hosted.sql(work,env,query)
        def run(pay,pg=False,**opts):
            spec={'pay':pay,**opts}
            cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli']
            if pg:cmd.extend(['-d','extension=curl','-d','extension=ctype'])
            disabled='exec,shell_exec,system,passthru,popen,proc_open,mail'
            if not pg:disabled='curl_init,curl_exec,'+disabled
            cmd.extend(['-d','disable_functions='+disabled,str(driver),str(ROOT),
                        str(work/'mysql.sock'),DB,json.dumps(spec)])
            p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=55)
            if p.returncode or not p.stdout.strip():
                raise RuntimeError('php85_replacement_runner '+repr((
                    p.returncode,p.stderr[-1800:],p.stdout[-600:],spec)))
            data=json.loads(p.stdout)
            if p.stderr:data['stderr']=p.stderr[-2000:]
            return data
        def seed(tag,panel='marzban',provider='nextpay',count=1,create_prefix=0,link_prefix=0):
            assert tag.isalnum() and tag.isascii()
            sql('UPDATE server_info SET ucount=20,active=1,state=1 WHERE id=2; '
                'UPDATE users SET wallet=50000 WHERE userid=1001')
            if panel=='pasarguard':sql(storage.connection(2,1,url))
            else:sql("UPDATE server_config SET type='marzban' WHERE id=2")
            ref='hosted-'+tag
            sql('INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,agent_count,price,'
                'day,volume,request_date,state,payid) '
                f"VALUES('{tag}',1001,'BUY_SUB',7,2,{count},100,0,0,UNIX_TIMESTAMP(),"
                f"'pending','{ref}')")
            pay=int(sql(f"SELECT id FROM pays WHERE hash_id='{tag}'"))
            sql('INSERT INTO payment_attempts (pay_row_id,provider,order_hash,'
                'provider_reference,quote_amount,quote_currency,local_amount_toman,'
                'stage,provider_status) '
                f"VALUES({pay},'{provider}','{tag}','{ref}',100,'toman',100,'pending','verified')")
            spec={'pay':pay,'provider':provider,'panel':panel,
                  'create_prefix':create_prefix,'link_prefix':link_prefix}
            cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                 '-d','extension=curl','-d','extension=ctype',
                 '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                 str(original),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)]
            server.block_posts=False;server.trace=[];server.mode='ok'
            r=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=40)
            assert r.returncode==0 and not r.stderr,(r.stdout,r.stderr)
            assert json.loads(r.stdout)['paid_claim'] is True
            assert sql(f'SELECT state FROM pays WHERE id={pay}')=='paid'
            return pay
        def balance():return sql('SELECT CONCAT(ucount,":",wallet) FROM server_info i JOIN users u ON u.userid=1001 WHERE i.id=2')
        def rows(pay):return sql(f"SELECT COUNT(*) FROM service_effects WHERE pay_row_id={pay} AND effect_kind='replacement'")
        def effect(pay):return sql(f"SELECT CONCAT(id,':',state,':',reserved_count,':',actor_userid) FROM service_effects WHERE pay_row_id={pay} AND effect_kind='replacement'")
        def preview(pay,ordinal=1,**kw):
            x=run(pay,op='replacement_preview',ordinal=ordinal,**kw)
            assert not x['error'] and x['result'] is not None,x
            return x['result']['challenge']
        def confirm(pay,challenge,ordinal=1,**kw):
            return run(pay,op='replacement_confirm',ordinal=ordinal,
                       challenge=challenge,**kw)
        sql("UPDATE users SET isAdmin=1 WHERE userid=9002")
        sql("INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','manager')")
        pay=seed('missingfirst')
        assert balance()=='20:50000'
        ch=preview(pay,actor=9002)
        assert run(pay,op='replacement_preview')['result']['reason']=='original_missing'
        out=confirm(pay,ch,actor=9002)
        assert out['error'] is None and out['result']['ordinal']==1,out
        assert rows(pay)=='1' and balance()=='19:50000' and ':9002' in effect(pay)
        assert [e['event'] for e in out['events']].count('REPLACEMENT_POST')==1,out
        assert confirm(pay,ch,actor=9002)['error'] is not None
        assert rows(pay)=='1' and balance()=='19:50000'
        cases.append('manager_missing_first_007_signed_one_replacement_capacity_once')
        result=run(pay)
        assert result['error'] is None and result['orders']==1 and len(result['delivery'])==1,result
        assert result['attempt']=={'provider':'nextpay','stage':'done','provider_status':'delivered'},result
        assert balance()=='19:50000' and not any(e['event']=='REPLACEMENT_POST' for e in result['events'])
        cases.append('replacement_get_only_links_one_original_paid_order_and_seals_018')
        # Manager/Operator/Support use the same existing payments permission;
        # display/access does not confer server-administration authority.
        for role in ('operator','support'):
            sql(f"UPDATE setting SET value='{role}' WHERE type='ADMIN_ROLE_9002'")
            pay=seed(role+'pay')
            challenge=preview(pay,actor=9002)
            result=confirm(pay,challenge,actor=9002)
            assert result['error'] is None and rows(pay)=='1' and balance()=='19:50000',result
            assert ':9002' in effect(pay)
            delivered=run(pay)
            assert delivered['error'] is None and delivered['orders']==1,delivered
            cases.append(role+'_payments_authorized_first_paid_replacement')
        sql("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9002'")
        # Unknown native reply must retain original replacement reservation;
        # the next operation observes the same native marker, never POSTs again.
        pay=seed('lostreply')
        ch=preview(pay)
        lost=confirm(pay,ch,lost_post=True)
        assert lost['error'] and rows(pay)=='1' and balance()=='19:50000',lost
        assert ':dispatching:' in effect(pay)
        recovered=run(pay)
        assert recovered['error'] is None and recovered['orders']==1,recovered
        assert not any(e['event']=='REPLACEMENT_POST' for e in recovered['events'])
        cases.append('lost_replacement_native_response_get_only_recovery_no_repost')
        # Foreign original marker: never adopt or rewrite original CREATE.
        pay=seed('foreignold',create_prefix=1,link_prefix=0)
        assert balance()=='19:50000'
        ch=preview(pay,native='wrong_note',actor=9002)
        result=confirm(pay,ch,native='wrong_note',actor=9002)
        assert result['error'] is None and rows(pay)=='1' and balance()=='19:50000',result
        assert sql(f"SELECT CONCAT(effect_kind,':',state,':',reserved_count) FROM service_effects WHERE pay_row_id={pay} AND effect_kind='create'")=='create:verified:1'
        assert run(pay)['error'] is None and sql(f'SELECT COUNT(*) FROM payment_delivery_intents WHERE pay_row_id={pay}')=='1'
        cases.append('foreign_original_kept_auditable_and_replacement_preserves_capacity')
        # Original first CREATE already owns full bulk capacity: an absent
        # internal ordinal must never deduct that original quantity twice.
        pay=seed('bulkgap',count=2,create_prefix=1,link_prefix=0)
        assert balance()=='18:50000'
        challenge=preview(pay,ordinal=2,actor=9002)
        result=confirm(pay,challenge,ordinal=2,actor=9002)
        assert result['error'] is None and rows(pay)=='1' and balance()=='18:50000',result
        finished=run(pay)
        assert finished['error'] is None and finished['orders']==2 and len(finished['delivery'])==2,finished
        assert sql(f"SELECT COUNT(*) FROM service_effects WHERE pay_row_id={pay} AND effect_kind='create'")=='1'
        cases.append('bulk_missing_ordinal_only_replacement_native_no_second_inventory_hold')
        # Simulate legacy 007 journal damage on an *owned synthetic DB*:
        # an internal noncontiguous gap is safe to reconcile because ordinal
        # one still proves the exact full-count original capacity claimant.
        pay=seed('internalgap',count=3,create_prefix=3,link_prefix=0)
        assert balance()=='17:50000'
        sql(f"DELETE FROM service_effects WHERE pay_row_id={pay} AND effect_kind='create' AND ordinal=2")
        challenge=preview(pay,ordinal=2,actor=9002)
        result=confirm(pay,challenge,ordinal=2,actor=9002)
        assert result['error'] is None and rows(pay)=='1' and balance()=='17:50000',result
        finished=run(pay)
        assert finished['error'] is None and finished['orders']==3 and len(finished['delivery'])==3,finished
        cases.append('noncontiguous_internal_gap_preserves_original_full_count_capacity_owner')
        # Losing original ordinal one is NOT evidence of unreserved
        # inventory when later 007 rows exist. The surviving ordinal must
        # be strictly confirmed via Native GET; source 007 guarantees that
        # creating it required an earlier full-count reservation. A second
        # capacity decrement would be wrong even on explicit replacement.
        pay=seed('missinganchor',count=2,create_prefix=2,link_prefix=0)
        assert balance()=='18:50000'
        sql(f"DELETE FROM service_effects WHERE pay_row_id={pay} AND effect_kind='create' AND ordinal=1")
        challenge=preview(pay,actor=9002)
        proof=run(pay,op='replacement_preview',actor=9002,ordinal=1)
        assert proof['result']['reason']=='original_missing_proven' and \
               proof['result']['status']=='original_capacity_verified',proof
        completed=confirm(pay,challenge,actor=9002)
        assert completed['error'] is None and rows(pay)=='1' and balance()=='18:50000',completed
        assert ':0:9002' in effect(pay)
        finished=run(pay)
        assert finished['error'] is None and finished['orders']==2 and len(finished['delivery'])==2,finished
        cases.append('missing_first_with_authenticated_later_original_reuses_prior_capacity_once')
        pay=seed('brokenlater',count=2,create_prefix=2,link_prefix=0)
        assert balance()=='18:50000'
        sql(f"DELETE FROM service_effects WHERE pay_row_id={pay} AND effect_kind='create' AND ordinal=1")
        denied=run(pay,op='replacement_preview',actor=9002,ordinal=1,native='wrong_note')
        assert denied['result'] is None and rows(pay)=='0' and balance()=='18:50000',denied
        cases.append('missing_first_later_native_foreign_blocks_second_capacity_claim')
        # An HMAC belonging to one staff member cannot authorize a different
        # person's replacement, even if the latter is the primary Owner.
        pay=seed('actorpin')
        challenge=preview(pay,actor=9002)
        wrong=confirm(pay,challenge,actor=9001)
        assert wrong['error'] and rows(pay)=='0' and balance()=='20:50000',wrong
        assert confirm(pay,challenge,actor=9002)['error'] is None
        cases.append('staff_challenge_actor_identity_bound_owner_cannot_replay_delegate')
        # Revoke staff after a preview: signed callback is NOT a permission.
        pay=seed('staleactor')
        ch=preview(pay,actor=9002)
        sql("UPDATE users SET isAdmin=0 WHERE userid=9002")
        revoked=confirm(pay,ch,actor=9002)
        assert revoked['error'] and rows(pay)=='0' and balance()=='20:50000',revoked
        sql("UPDATE users SET isAdmin=1 WHERE userid=9002")
        cases.append('demoted_manager_old_signed_button_cannot_claim_new_native_account')
        # Private staff authorizations, no leaked Owner-only payment tools.
        route=run(pay,op='route',actor=9002,chat=9002,
                  callback='hostedReplP_'+str(pay)+'_1')
        assert route['error'] is None and rows(pay)=='0',route
        assert any(e['event']=='OWNER_EDIT' and
                   'hostedReplC_'+str(pay)+'_1_' in str(e['markup'])
                   for e in route['events']),route
        nonprivate=run(pay,op='route',actor=9002,chat=1001,
                       callback='hostedReplP_'+str(pay)+'_1')
        assert not any(e['event']=='OWNER_EDIT' for e in nonprivate['events'])
        noadmin=run(pay,op='route',actor=1001,chat=1001,
                    callback='hostedReplP_'+str(pay)+'_1')
        assert not any(e['event']=='OWNER_EDIT' for e in noadmin['events'])
        cases.append('staff_private_preview_denies_nonstaff_and_nonprivate_without_side_effects')
        # PasarGuard uses the actual source reserve/POST/readback against an
        # HTTP loopback account store rather than a mocked PG return value.
        pay=seed('pgmissingfirst',panel='pasarguard')
        ch=preview(pay,pg=True)
        server.trace=[]
        reply=confirm(pay,ch,pg=True)
        assert reply['error'] is None and any(m=='POST' for m,_,_ in server.trace),reply
        server.block_posts=True;server.trace=[]
        finished=run(pay,pg=True)
        assert finished['error'] is None and finished['orders']==1,finished
        assert not any(m=='POST' for m,_,_ in server.trace)
        assert balance()=='19:50000'
        cases.append('pasarguard_first_replacement_real_loopback_create_and_get_delivery')
        return {'complete':True,'php85_required':True,'source_real':True,
                'disposable_mariadb':True,'native_provider_loopback':True,
                'original_post_replays':0,'double_wallet_debits':0,'scenarios':cases}

if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--report');args=ap.parse_args()
    data=check()
    if args.report:Path(args.report).write_text(json.dumps(data))
    print(json.dumps(data))
