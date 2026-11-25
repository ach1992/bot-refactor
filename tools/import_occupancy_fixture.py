"""Owned signed-subscription proof for CREATE/import name-occupancy regressions."""
from __future__ import annotations
import time
from urllib.parse import unquote,urlsplit
from existing_subscription_fixture import mint,parse_token


def external_user(url,uid,username):
    return dict(id=uid,username=username,created_at='2026-01-01T00:00:00Z',
        note='independent-native-origin',data_limit=2*1073741824,used_traffic=0,
        expire=int(time.time())+30*86400,group_ids=[1,2],status='active',
        data_limit_reset_strategy='no_reset',next_plan=None,auto_delete_in_days=None,
        on_hold_expire_duration=None,on_hold_timeout=None,
        subscription_url=url+'/native-subscriptions/'+mint(uid,int(time.time())),
        proxy_settings={'vless':{'id':'import-fixture'}})


def public_info(path,users):
    path=unquote(urlsplit(path).path)
    if not (path.startswith('/native-subscriptions/') and path.endswith('/info')):
        return None
    token=path[len('/native-subscriptions/'):-len('/info')]
    proof=parse_token(token)
    user=next((u for u in users if proof and u['id']==proof[0] and
        u['subscription_url'].endswith('/'+token)),None)
    return (200,{k:user[k] for k in ('id','username','created_at')}) if user else (404,{})


PHP_IMPORT=r'''
/** Source-real import: actual authenticated Native lookup, signed /info
 * proof, local order and source credential, then the supported adopter.
 * The optional barrier is confined to the disposable test transaction. */
function fixtureImportOccupancy(mysqli $db,array $spec): array {
    $server=(int)$spec['server'];$owner=(int)($spec['owner']??9001);
    $actor=9001;$url=$spec['url'];
    $remote=achbotProviderExistingUser($db,$server,$url);
    $proof=$remote->provider_import_proof??null;
    if (!is_array($proof) || ($proof['verified']??false)!==true)
        throw new RuntimeException('fixture_import_preview_unverified');
    if (!$db->begin_transaction()) throw new RuntimeException('fixture_import_begin');
    try {
        $s=achbotProviderStatement($db,'INSERT INTO add_link(remark,token,added_by) VALUES (?,?,?)',
            'ssi',[$remote->username,$proof['token'],$actor]);$s->close();
        $s=achbotProviderStatement($db,"INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,autoRenew)
            VALUES (?,?,'',0,?,0,?,'',?,'[]',0,1,?,'off')",
            'isisis',[$owner,$proof['token'],$server,$remote->username,$proof['expiry']??0,(string)time()]);
        $order=(int)$s->insert_id;$s->close();
        achbotProviderAdoptExistingLocked($db,$server,$remote,$proof,$owner,$order,$actor,9001);
        if (isset($spec['barrier'])) {
            if ((int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==1)
                throw new RuntimeException('fixture_import_transaction_lost');
            $dir=$spec['barrier'];
            file_put_contents($dir.'/ready','1');
            $deadline=hrtime(true)+8*1000000000;
            do {
                clearstatcache(true,$dir.'/release');
                if (is_file($dir.'/release')) break;
                usleep(20000);
            } while (hrtime(true)<$deadline);
            if (!is_file($dir.'/release')) throw new RuntimeException('fixture_import_barrier_timeout');
        }
        if (!$db->commit()) throw new RuntimeException('fixture_import_commit');
        $a=achbotProviderAccountByOrder($db,$order,true);
        if (!$a || $a['state']!=='linked') throw new RuntimeException('fixture_import_unlinked');
        $effect=achbotProviderRow($db,'SELECT target_key FROM service_effects WHERE order_id=?','i',[$order]);
        return ['order'=>$order,'effect'=>$a['effect_id'],'native'=>$a['remote_user_id'],'target'=>$effect['target_key']];
    } catch (Throwable $e) {
        $db->rollback();throw $e;
    }
}
'''
