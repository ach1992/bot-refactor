"""Shared private socket-only MariaDB fixture for current source tests.

The optional legacy seed is only for partial-schema/provenance tests. It
never executes a historical bot, requires no historical test driver and
never connects to an installed database.
"""
from __future__ import annotations
import contextlib
import os
import pwd
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
NAMES=['achbot_v4_old','achbot_v4_new']
SEED="""
INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES
(1001,'Fixture','fixture_user','0',50000,'1700000000',0,'sent'),
(9001,'Fixture Admin','fixture_admin','0',0,'1700000000',0,'sent'),
(9002,'Fixture Delegate','fixture_delegate','0',0,'1700000000',1,'sent');
INSERT INTO setting (id,type,value) VALUES (50,'TICKETS_CATEGORY','Technical');
INSERT INTO chats (id,user_id,create_date,title,category,state,rate) VALUES(7,1001,1700000000,'Existing fixture','Technical',0,0);
INSERT INTO chats_info (id,chat_id,sent_date,msg_type,text) VALUES (70,7,1700000000,'USER','Existing message');
"""

def current_wallet_report_ddl():
    # Partial route fixtures keep their historical negative-schema checks,
    # then install the exact current report dependencies, not mock DDL.
    import re
    source=(ROOT/'database/current-schema.sql').read_text()
    return "\n".join(re.search(r"CREATE TABLE `"+name+r"` \(.*?\n\) ENGINE=.*?;",source,re.S)[0]
                      for name in ("service_operations","payment_followup_intents","payment_followup_attempts"))


@contextlib.contextmanager
def owned_database(schema_path=ROOT/'database/legacy-schema.sql'):
    for tool in ['mariadb-install-db','mariadbd','mariadb','php']:
        if not shutil.which(tool):raise RuntimeError('Missing existing prerequisite: '+tool)
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','HOME':'/nonexistent'}
    with tempfile.TemporaryDirectory(prefix='achbot-access-native-') as tmp:
        d=Path(tmp);(d/'data').mkdir();(d/'tmp').mkdir()
        (d/'.achbot-access-db').write_text('achbot-v4-fixture-only\n')
        # Bootstrap runs alongside other disposable MariaDB fixtures. The
        # server receives a private --tmpdir below; its bootstrap needs the
        # same isolation or concurrent mysql.tmp_* DDL may collide in /tmp.
        env['TMPDIR']=str(d/'tmp')
        with (d/'process.log').open('wb') as log:
            user=pwd.getpwuid(os.getuid()).pw_name
            try:
                subprocess.run(['mariadb-install-db','--no-defaults','--datadir='+str(d/'data'),
                    '--auth-root-authentication-method=normal','--skip-test-db','--user='+user,
                    '--tmpdir='+str(d/'tmp')],
                    env=env,check=True,stdout=log,stderr=log,timeout=45)
            except subprocess.CalledProcessError as error:
                log.flush()
                raise RuntimeError('Disposable MariaDB bootstrap failed (exit '+
                    str(error.returncode)+'): '+
                    (d/'process.log').read_text(errors='replace')[-6000:]) from error
            cmd=['mariadbd','--no-defaults','--datadir='+str(d/'data'),'--socket='+str(d/'mysql.sock'),
                '--pid-file='+str(d/'mysql.pid'),'--log-error='+str(d/'server.log'),'--skip-networking','--skip-name-resolve',
                '--innodb-buffer-pool-size=32M','--innodb-log-file-size=8M','--key-buffer-size=8M','--aria-pagecache-buffer-size=8M',
                '--max-connections=10','--tmpdir='+str(d),'--user='+user]
            server=subprocess.Popen(cmd,env=env,stdout=log,stderr=log)
            client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(d/'mysql.sock'),'-uroot','--batch']
            try:
                for _ in range(100):
                    if server.poll() is not None:
                        log_path=d/'server.log'
                        detail=log_path.read_text(errors='replace')[-2500:] if log_path.exists() else 'no server log'
                        raise RuntimeError('Owned fixture exited before readiness: '+detail)
                    if (d/'mysql.sock').exists() and subprocess.run(client+['-e','SELECT 1'],env=env,capture_output=True,timeout=3).returncode==0:break
                    time.sleep(.1)
                else:raise RuntimeError('Owned fixture not ready')
                for name in NAMES:
                    subprocess.run(client+['-e','CREATE DATABASE '+name+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],env=env,check=True,capture_output=True,timeout=5)
                    sql=schema_path.read_bytes()+SEED.encode()
                    # MariaDB DDL on a shared development VM may briefly be
                    # I/O-bound. A 10-second subprocess timeout incorrectly
                    # classified slow fixture initialization as product failure.
                    subprocess.run(client+[name],input=sql,env=env,check=True,capture_output=True,timeout=30)
                yield d,env
            finally:
                if server.poll() is None:server.terminate()
                try:server.wait(timeout=10)
                except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)


def sql_command(work:Path,env:dict,sql:str,dbname=None):
    cmd=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),
         '-uroot','--batch','--skip-column-names','--raw']
    if dbname:cmd.append(dbname)
    cmd+=['-e',sql]
    p=subprocess.run(cmd,env=env,capture_output=True,timeout=15)
    if p.returncode:raise RuntimeError('Isolated MariaDB SQL failed: '+p.stderr.decode(errors='replace')[:600])
    return p.stdout.decode()



# Only test database identity; keep both fixture names for paired proofs.
DBS=tuple(NAMES)
SIDE=DBS
sql=sql_command

def seed(work,env,port):
    for db in DBS:
        sql(work,env,'DELETE FROM server_config WHERE id IN (2,3,4); DELETE FROM server_plans WHERE id=7; DELETE FROM orders_list WHERE id=91;',db)
        for id,typ in [(2,'marzban'),(3,'sanaei'),(4,'alireza')]:
            safe=port.replace("'","''")
            sql(work,env,f"INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,sub_links) VALUES ({id},'{safe}','127.0.0.1','','none','','','none','{{}}','{typ}','fixture-admin','fixture-password','');",db)
        sql(work,env,"INSERT INTO server_plans (id,fileid,catid,server_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni) VALUES (7,'7',1,3,1,'Fixture','vless',30,2,'normal',100,'description','',1,0,'fixture','{\"inbounds\":{\"vless\":[\"inbound\"]}}');",db)
        sql(work,env,"INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES (91,1001,REPEAT('a',30),'fixture',7,3,7,'fixture-user','fixture-uuid','vless',1800000100,'[\"vless://fixture\"]',100,1,'fixture');",db)

def init(work:Path,env:dict,url:str):
    for db in SIDE:
        sql(work,env,'DELETE FROM orders_list; DELETE FROM pays; DELETE FROM server_plans; DELETE FROM server_info; DELETE FROM server_categories; UPDATE users SET step=\'none\', freetrial=NULL, temp=NULL, wallet=50000, is_agent=0, discount_percent=NULL;',db)
    seed(work,env,url)
    sqls="""
DELETE FROM orders_list;
INSERT INTO server_info (id,title,ucount,remark,flag,active,state)
 VALUES (2,'Fixture purchase server','10','buyfixture','',1,1);
INSERT INTO server_categories (id,server_id,title,parent,step,active)
 VALUES (1,'2','Fixture category',0,4,1);
UPDATE server_plans SET server_id=2,inbound_id=0,acount=6,catid=1,price=100,
 protocol='vless',days=30,volume=2,type='normal',active=1,
 custom_sni='{"inbounds":{"vless":["inbound"]}}' WHERE id=7;
INSERT INTO server_plans (id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni)
 VALUES (8,'8',1,2,0,6,'Free fixture','vless',30,2,'normal',0,'fixture-free','',1,0,'fixture','{"inbounds":{"vless":["inbound"]}}');
UPDATE users SET is_agent=1, discount_percent='{"normal":10,"plans":{},"servers":{}}', wallet=50000 WHERE userid=9002;
"""
    for db in SIDE:sql(work,env,sqls,db)
