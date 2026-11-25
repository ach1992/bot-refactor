#!/usr/bin/env python3
"""Verify historical paid-account references against full post-V9 schema.

All effects and PHP calls run in an owned disposable socket database.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
import tempfile
from pathlib import Path

import native_identity_fixture as api

ROOT=Path(__file__).resolve().parents[1]
CREATED="2026-11-14T21:00:00.000000Z"
USER="historical_fixture"
SOURCE_ID=2
INSTANCE=api.INSTANCE
REMOTE_ID=72


def sqlquote(s):
    return api.quote_sql(s)


def fixture_effect(sql, *, original_planless=False):
    endpoint=hashlib.sha256(sql("SELECT panel_url FROM server_config WHERE id=2").rstrip("/").encode()).hexdigest()
    native={"version":1,"id":REMOTE_ID,"username":USER,"created_at":CREATED,
            "group_ids":[2,5],"data_limit":123400000,"used_traffic":20123,
            "expire":None,"status":"active","native_schedules_clear":True}
    refs=[{"order_id":101,"owner_id":1001,"plan_id":0 if original_planless else 7},
          {"order_id":102,"owner_id":1001,"plan_id":7}]
    snap={"provider":"pasarguard","panel_id":1,"instance_key":INSTANCE,
          "endpoint_sha256":endpoint,"group_ids":[2,5],
          "identity_mode":"historical_cutover",
          "migration":{"version":1,"tenant":"hell","source_server_id":SOURCE_ID,
                       "source_input_sha256":"a"*64,
                       "native_input_sha256":"b"*64,
                       "mapping_input_sha256":"c"*64,
                       "references":refs}}
    marker_id="d"*32
    marker="achbot:adopt:"+marker_id
    digest=hashlib.sha256(json.dumps(snap,separators=(",",":"),ensure_ascii=False).encode()).hexdigest()
    source_ref="legacy:2:"+hashlib.sha256(USER.encode()).hexdigest()
    sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,remark,"
        "uuid,expire_date,link,amount,status,date,autoRenew) VALUES "
        "(101,1001,'historic-token-1','',"+('0' if original_planless else '7')+",2,0,'historical_fixture','original-uuid-1',"
        "0,'[]',10000,1,'fixture','on'),"
        "(102,1001,'historic-token-2','',7,2,0,'historical_fixture','original-uuid-2',"
        "0,'[]',20000,1,'fixture','off')")
    sql("INSERT INTO service_effects (source_kind,source_ref,actor_userid,ordinal,"
        "reserved_count,operation_id,effect_kind,target_server_id,target_key,"
        "endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,"
        "request_sha256,state,order_id,verified_at,provider_snapshot,"
        "provider_connection_id,provider_remote_user_id,provider_remote_created_at,"
        "provider_operation_marker,provider_entitlement_snapshot,provider_state)"
        " VALUES ('legacy_migration',"+sqlquote(source_ref)+",NULL,1,0,"
        +sqlquote(marker_id)+",'adopt',2,"
        +sqlquote("pg:"+INSTANCE)+","+sqlquote(endpoint)+",0,"
        +sqlquote(USER)+",0,123400000,"+sqlquote(digest)
        +",'local_recorded',NULL,UTC_TIMESTAMP(),"
        +sqlquote(json.dumps(snap,separators=(",",":"),ensure_ascii=False))
        +",1,72,"+sqlquote(CREATED)+","+sqlquote(marker)+","
        +sqlquote(json.dumps(native,separators=(",",":"),ensure_ascii=False))
        +",'linked')")
    effect_id=sql("SELECT id FROM service_effects WHERE source_kind='legacy_migration'")
    sql("UPDATE service_effects SET provider_reference_id=id WHERE id="+effect_id)
    sql("UPDATE orders_list SET provider_effect_id="+effect_id+
        ",provider_remote_user_id=72,provider_remote_created_at="+sqlquote(CREATED)+
        " WHERE id IN (101,102)")
    return effect_id


PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message){throw new ErrorException($message,0,$level);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require_once $argv[1].'/src/Panel/ProviderStorage.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$op=$argv[4];
try {
    if ($op==='existing') {
        $one=achbotProviderAccountByOrder($db,101);
        $two=achbotProviderAccountByOrder($db,102);
        $identity=achbotProviderAccountIdentity($db,$one);
        $native=['id'=>72,'created_at'=>'2026-11-14T21:00:00.000000Z',
          'username'=>'historical_fixture','note'=>'old-original-native-note'];
        $valid=achbotPgIdentityMatches($native,$identity);
        $native['created_at']='2026-11-14T21:00:01.000000Z';
        $bad=achbotPgIdentityMatches($native,$identity);
        echo json_encode(['shared'=>$one['id']===$two['id'],
            'native_valid'=>$valid,'native_drift_denied'=>!$bad,
            'order_id_null'=>$one['order_id']===null,
            'identity_mode'=>$identity['identity_mode']]);
    } elseif ($op==='unlisted') {
        achbotProviderAccountByOrder($db,103);
        echo '{"unexpected":true}';
    } elseif ($op==='newimport') {
        $a=achbotProviderAccountByOrder($db,103);
        echo json_encode(['linked'=>$a['remote_user_id']===72]);
    } elseif ($op==='relink') {
        $e=$db->query("SELECT id FROM service_effects WHERE source_kind='legacy_migration' LIMIT 1")->fetch_assoc();
        achbotProviderLinkAccount($db,(int)$e['id'],103);
        echo '{"unexpected":true}';
    } elseif ($op==='owner') {
        achbotProviderAccountByOrder($db,102);
        echo '{"unexpected":true}';
    } elseif ($op==='provenance') {
        achbotProviderAccountIdentity($db,achbotProviderAccountByOrder($db,101));
        echo '{"unexpected":true}';
    }
} catch(Throwable $e){
    echo json_encode(['blocked'=>true,'code'=>$e->getMessage()]);
}
'''


def run():
    checks=[]
    with api.sandbox(imported_empty=False) as (sql, runner, provider, expiry):
        effect_id=fixture_effect(sql)
        with tempfile.TemporaryDirectory(prefix="pg-cutover-runtime-") as d:
            source=Path(d)/"runtime.php"
            source.write_text(PHP)
            def check(op):
                p=subprocess.run(["php",str(source),str(ROOT),
                        str(runner.fixture_socket),api.DB,op],
                        cwd=runner.fixture_work,env=runner.fixture_env,
                        capture_output=True,text=True,timeout=18)
                assert p.returncode==0 and not p.stderr,(p.returncode,p.stdout,p.stderr)
                return json.loads(p.stdout)
            assert check("existing")=={
                "shared":True,"native_valid":True,"native_drift_denied":True,
                "order_id_null":True,"identity_mode":"adopted"}
            checks.append("full_schema_shared_legacy_refs_and_native_identity")
            # A legitimate Marzban import may have been moved to a different
            # current logical server/plan long before the PasarGuard cutover.
            # Its immutable original provider and source server must survive.
            old_origin={"version":1,"kind":"external_import","order_id":101,
                        "owner_userid":1001,"actor_userid":1001,"provider":"marzban",
                        "server_id":999,"remark":USER,"proof_sha256":"a"*64,
                        "native_identity":{
                          "remote_created_at":"2026-03-01T00:00:00.000000Z",
                          "remote_username":USER},
                        "capacity_reserved":False,"provider_account_id":None}
            sql("INSERT INTO setting(type,value) VALUES "
                "('ACHBOT_SERVICE_ORIGIN:101',"+
                sqlquote(json.dumps(old_origin,separators=(",",":")))+")")
            assert check("existing")["shared"] is True
            checks.append("historical_marzban_import_origin_survives_transfer")
            sql("UPDATE setting SET value=JSON_SET(value,'$.provider','pasarguard',"
                "'$.provider_account_id',1) WHERE type='ACHBOT_SERVICE_ORIGIN:101'")
            assert check("existing")["blocked"] is True
            sql("UPDATE setting SET value="+
                sqlquote(json.dumps(old_origin,separators=(",",":")))+
                " WHERE type='ACHBOT_SERVICE_ORIGIN:101'")
            checks.append("foreign_provider_origin_refused")
            sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,"
                "remark,uuid,expire_date,link,amount,status,date,autoRenew,provider_effect_id,"
                "provider_remote_user_id,provider_remote_created_at) VALUES "
                "(103,1001,'bad','',7,2,0,'historical_fixture','original-uuid-3',"
                "0,'[]',10000,1,'fixture','off',"
                "(SELECT id FROM service_effects WHERE source_kind='legacy_migration'),72,"
                +sqlquote(CREATED)+")")
            assert check("unlisted")["blocked"] is True
            checks.append("unlisted_historical_reference_denied")
            # The normal post-migration same-account import path must stay
            # usable, but is not allowed to claim/rewrite the old migration
            # effect as a new originating paid subscription.
            pg_origin={"version":1,"kind":"external_import","order_id":103,
                       "owner_userid":1001,"actor_userid":1001,"provider":"pasarguard",
                       "server_id":2,"remark":USER,"proof_sha256":"b"*64,
                       "native_identity":{"remote_user_id":REMOTE_ID,
                        "remote_created_at":CREATED,"remote_username":USER},
                       "capacity_reserved":False,"provider_account_id":int(effect_id)}
            sql("UPDATE orders_list SET fileid=0,amount=0 WHERE id=103")
            sql("INSERT INTO setting(type,value) VALUES "
                "('ACHBOT_SERVICE_ORIGIN:103',"+
                sqlquote(json.dumps(pg_origin,separators=(",",":")))+")")
            assert check("newimport") == {"linked": True}
            assert check("relink")["code"] == "provider_legacy_cutover_link_forbidden"
            checks.append("new_pasarguard_attachment_allowed_but_migration_anchor_immutable")
            sql("DELETE FROM setting WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
            sql("DELETE FROM orders_list WHERE id=103")
            sql("UPDATE orders_list SET userid=2002 WHERE id=102")
            assert check("owner")["code"]=="provider_legacy_cutover_reference_invalid"
            sql("UPDATE orders_list SET userid=1001 WHERE id=102")
            checks.append("changed_owner_denied")
            sql("UPDATE service_effects SET request_sha256=REPEAT('f',64) "
                "WHERE source_kind='legacy_migration'")
            assert check("provenance")["blocked"] is True
            checks.append("tampered_historical_provenance_denied")
    print(json.dumps({"complete":True,"isolated":True,"checks":checks}))


if __name__=="__main__":
    run()
