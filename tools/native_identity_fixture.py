"""Disposable Native identity fixture used by current import and DELETE tests.

Only socket MariaDB and local HTTP. The deliberately partial historical
schema exercises retained account provenance; no historical payment
executor, live provider or payment evidence API is started.
"""
from __future__ import annotations
import contextlib
import datetime as dt
import hashlib
import json
import subprocess
import threading
from pathlib import Path
from types import SimpleNamespace
from urllib.parse import urlsplit
import database_fixture as owned
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
DB=owned.SIDE[1]
DAY=4
VOLUME=5
DAY_PRICE=1200
VOLUME_PRICE=2200
INSTANCE="b"*32
OPERATION="a"*32

PHP = r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$msg){throw new ErrorException($msg,0,$level);});
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;
require_once $root.'/src/Panel/ProviderRuntime.php';
achbotPgLoad();
try {
    $imported=($spec['imported_empty']??false)===true;
    $panel=achbotPgServerPanel($connection,2);
    $identity=['remote_user_id'=>17,
        'remote_created_at'=>'2026-10-26T05:00:00.123456+00:00',
        'operation_marker'=>'achbot:v1:'.str_repeat('a',32)];
    $read=achbotPgRead($panel,$identity);
    if(($read['success']??false)!==true) throw new RuntimeException('fixture_pg_initial_identity');
    $user=$read['user'];
    $ent=['data_limit'=>$user['data_limit'],'expire'=>$user['expire'],
        'group_ids'=>$user['group_ids'],'status'=>$user['status']];
    if($connection->begin_transaction()!==true)throw new RuntimeException('fixture_begin');
    if ($imported) {
        // Real source adoption, not a fabricated CREATE entitlement: the
        // source requires exact order, actor, bearer credential and
        // external-import provenance. Imported accounts may have Groups=[].
        require_once $root.'/src/Panel/ProviderImport.php';
        $token='fixture-import-token';
        $proof=['target'=>achbotPgImportTarget($panel),'token'=>$token,
            'input_sha256'=>hash('sha256','https://alias.invalid/import/'.$token)];
        achbotProviderAdoptAccount($connection,2,$user,$proof,1001,41,1001,9001);
    } else {
        $snap=achbotProviderSnapshot($connection,2,7);
        achbotProviderReserveAccount($connection,1,$snap,'fixture_user',$identity['operation_marker'],$ent);
    }
    if(!$connection->commit())throw new RuntimeException('fixture_commit');
    if (!$imported) {
        achbotProviderVerifyAccount($connection,1,$user);
        achbotProviderLinkAccount($connection,1,41);
    }
    $context=achbotPgOrderContext($connection,41);
    $result=['linked'=>$context['account']['state']==='linked',
        'imported'=>$imported,
        'identity_mode'=>$context['identity']['identity_mode']??null,
        'groups'=>$user['group_ids']];

 echo json_encode($result,JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
 $connection->rollback();
 throw $e;
}
'''

class DriftHandler(pg.Handler):
    """Deterministic post-fence changes, observed only by a local provider."""

    def handle_request(self):
        s = self.server
        path = urlsplit(self.path).path
        # Separate original CREATE race: the owner closes the payment after
        # the staff lease/permission preflight but during Native observation.
        # Local verification must re-check payment/Archive under its own lock.
        hook=getattr(s,'create_reconcile_hook',None)
        if (self.command=='GET' and path.startswith('/api/user/by-username/')
                and callable(hook)):
            s.create_reconcile_hook=None
            hook()
            s.create_reconcile_hook_fired=True
        # Recovery-only intermediate race: another panel operator changes
        # Native state AFTER the coordinator GET but BEFORE the provider's
        # separate authenticated GET immediately preceding RESET or PUT.
        if (self.command == 'GET' and path == '/api/user/by-id/17' and
                getattr(s,'recovery_drift_at',None) is not None):
            s.recovery_drift_seen=getattr(s,'recovery_drift_seen',0)+1
            if s.recovery_drift_seen==s.recovery_drift_at:
                s.user.update(json.loads(json.dumps(s.recovery_drift_change)))
                s.recovery_drift_fired=True
        if (self.command == "GET" and path == "/api/user/by-id/17"
                and getattr(s, "drift", None) and not getattr(s, "drift_fired", False)
                and s.mutation_claimed()):
            s.drift_fired = True
            if s.drift == "revoke":
                s.allowed_group_ids = [5]
            elif s.drift == "disable":
                s.disabled_group_ids = [2]
            elif s.drift == "remove":
                s.available_group_ids = [5]
            elif s.drift == "membership":
                s.user["group_ids"] = [5]
            elif s.drift == "empty_to_nonempty":
                s.user["group_ids"] = [2]
            elif s.drift == "authority_fail":
                s.mode = "permission_missing"
            elif s.drift == "foreign":
                s.user["created_at"] = "2026-10-25T05:00:00Z"
        super().handle_request()


@contextlib.contextmanager
def native_panel(mutation_claimed):
    import http.server
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), DriftHandler)
    server.trace = []
    server.user = json.loads(json.dumps(pg.USER))
    server.mode = "ok"
    server.write_count = 0
    server.links_requested = False
    server.drift = None
    server.drift_fired = False
    server.mutation_claimed = mutation_claimed
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield server, "http://127.0.0.1:" + str(server.server_port)
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)


def quote_sql(value):
    """Only fixture-owned, never user input; avoid SQL injection."""
    return "'" + str(value).replace("'", "''") + "'"


@contextlib.contextmanager
def sandbox(*, imported_empty=False):
    # Every scenario owns its own MariaDB socket and fresh native/API state;
    # a durable failed mutation in one case can never be silently repaired to
    # make a later test pass.
    with owned.owned_database() as (work, env):
        owned.init(work, env, "http://127.0.0.1:9")

        def sql(statement):
            return owned.sql(work, env, statement, DB).strip()

        for name in (
            "001_marzban_panel_group_id.sql",
            "003_payment_attempts.sql",
            "022_gateway_review_archive.sql",
            "006_payment_message_refs.sql",
            "007_service_effects.sql",
            "008_referral_awards.sql",
            "009_bank_buy_invoice_claims.sql",
            "011_provider_identity.sql",
            "012_shared_provider_storage.sql",
            "014_api_payment_evidence.sql",
            "018_payment_delivery_intents.sql",
            "015_scoped_addons.sql",
            "057_derived_addon_scope.sql",
        ):
            sql((ROOT / "migrations/post-v9" / name).read_text())

        def mutation_claimed():
            return sql(
                "SELECT COUNT(*) FROM service_effects WHERE id=1 "
                "AND provider_state='mutating'") == "1"

        with native_panel(mutation_claimed) as (provider, provider_url):
            if imported_empty:
                provider.user["group_ids"] = []
                # No enabled Group must be invented for a Group-less
                # imported subscription, even if credentials allow none.
                provider.allowed_group_ids = []
            provider_hash = hashlib.sha256(provider_url.encode()).hexdigest()
            snapshot = json.dumps({
                "provider": "pasarguard", "panel_id": 1,
                "instance_key": INSTANCE,
                "endpoint_sha256": provider_hash,
                "group_ids": [2, 5],
            }, separators=(",", ":"))
            epoch = int(dt.datetime.fromisoformat(
                pg.USER["expire"].replace("Z", "+00:00")).timestamp())
            sql("UPDATE server_config SET type='pasarguard',"
                f"panel_url={quote_sql(provider_url)},"
                "password='fixture-private-key',"
                "provider_connection_id=1,provider_instance_key="
                f"{quote_sql(INSTANCE)},provider_version='5.5.1',"
                "provider_enabled=1 WHERE id=2")
            # Current PG native access is explicit on each plan, never
            # inherited from a server-level default.
            sql("UPDATE server_plans SET provider_group_ids='[2,5]' WHERE id IN (7,8)")
            for migration in ("016_remove_pasarguard_server_groups.sql",
                              "017_shared_plan_changes.sql"):
                sql((ROOT / "migrations/post-v9" / migration).read_text())
            if imported_empty:
                sql("INSERT INTO add_link(remark,token,added_by) "
                    "VALUES ('fixture_user','fixture-import-token',1001)")
                sql("INSERT INTO orders_list "
                    "(id,userid,token,transid,fileid,server_id,inbound_id,remark,"
                    "uuid,expire_date,link,amount,status,date,autoRenew) VALUES "
                    f"(41,1001,'fixture-import-token','',0,2,0,'fixture_user',"
                    f"'fixture-native-uuid',{epoch},'[]',0,1,'fixture','off')")
            else:
                sql("INSERT INTO orders_list "
                    "(id,userid,token,transid,fileid,server_id,inbound_id,remark,"
                    "uuid,expire_date,link,amount,status,date) VALUES "
                    f"(41,1001,'fixture-token','',7,2,0,'fixture_user',"
                    f"'fixture-native-uuid',{epoch},'[]',100,1,'fixture')")
                sql("INSERT INTO service_effects "
                    "(id,source_kind,source_ref,actor_userid,ordinal,operation_id,"
                    "target_server_id,target_key,endpoint_sha256,plan_id,"
                    "remote_username,requested_expire,requested_bytes,"
                    "request_sha256,provider_snapshot) VALUES ("
                    f"1,'trial','fixture-native-identity',1001,1,"
                    f"{quote_sql(OPERATION)},2,'pg:fixture',"
                    f"{quote_sql(provider_hash)},7,'fixture_user',{epoch},"
                    f"{pg.USER['data_limit']},REPEAT('c',64),{quote_sql(snapshot)})")
            sql("INSERT INTO increase_day(id,volume,price) "
                "VALUES (703,3,300)")
            for kind, offer, units, price in [
                ("day", 701, DAY, DAY_PRICE),
                ("volume", 702, VOLUME, VOLUME_PRICE),
            ]:
                table = "increase_day" if kind == "day" else "increase_plan"
                sql(f"INSERT INTO {table} "
                    "(id,volume,price,server_id,group_ids) "
                    f"VALUES ({offer},{units},{price},2,'[2,5]')")

            if imported_empty:
                # Exact Server day and Global volume fallbacks. Group-only
                # rows 701/702 must remain ineligible for Groups=[].
                sql("INSERT INTO increase_day "
                    "(id,volume,price,server_id) VALUES "
                    f"(704,{DAY},{DAY_PRICE},2)")
                sql("INSERT INTO increase_plan "
                    "(id,volume,price) VALUES "
                    f"(705,{VOLUME},{VOLUME_PRICE})")

            driver=work/'native-identity.php'
            driver.write_text(PHP)
            proc=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','extension=curl','-d','extension=ctype','-d','allow_url_fopen=0',
                '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                str(driver),str(ROOT),str(work/'mysql.sock'),DB,
                json.dumps({'imported_empty':imported_empty})],
                cwd=work,env=env,capture_output=True,text=True,timeout=30)
            assert proc.returncode==0 and not proc.stderr,(proc.returncode,proc.stderr,proc.stdout)
            linked=json.loads(proc.stdout)
            assert linked['linked'] and linked['groups']==([] if imported_empty else [2,5]),linked
            assert linked['imported'] is imported_empty,linked
            assert linked['identity_mode']==('adopted' if imported_empty else None),linked
            assert provider.write_count==0
            yield sql,SimpleNamespace(fixture_work=work,fixture_env=env,fixture_socket=work/'mysql.sock'),provider,epoch
