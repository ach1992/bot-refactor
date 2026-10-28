"""Synthetic current shared-storage SQL for native integration fixtures only."""
from __future__ import annotations
import hashlib,json

def text(value):
    if value is None:return 'NULL'
    return "CONVERT(UNHEX('"+str(value).encode().hex()+"') USING utf8mb4)"

def connection(server_id,connection_id,url,instance='b'*32,key='fixture-private-key'):
    return ("UPDATE server_config SET type='pasarguard',panel_url="+text(url)+",password="+text(key)+
        ",provider_connection_id="+str(connection_id)+",provider_instance_key="+text(instance)+
        ",provider_version='5.5.1',provider_enabled=1 WHERE id="+str(server_id)+";")

def clone(server_id,source_id):
    fields='panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links,provider_connection_id,provider_instance_key,provider_version,provider_enabled'
    return 'INSERT INTO server_config(id,'+fields+') SELECT '+str(server_id)+','+fields+' FROM server_config WHERE id='+str(source_id)+';'

def identity(effect_id,order_id,connection_id,url,remote_id,created_at,username,marker,entitlement,*,server_id=2,plan_id=7,existing=False,reference_id=None):
    """Persist one synthetic originating CREATE's evidence and its local order link."""
    if isinstance(entitlement,str):entitlement=json.loads(entitlement)
    snapshot=dict(provider='pasarguard',panel_id=connection_id,instance_key='b'*32,
        endpoint_sha256=hashlib.sha256(url.encode()).hexdigest(),group_ids=entitlement['group_ids'])
    encoded=json.dumps(snapshot,separators=(',',':'))
    ent=json.dumps(entitlement,separators=(',',':'))
    reference_id=effect_id if reference_id is None else reference_id
    out=''
    if not existing:
        operation=marker.removeprefix('achbot:v1:')
        assert len(operation)==32 and all(ch in '0123456789abcdef'for ch in operation)
        out=("INSERT INTO service_effects(id,source_kind,source_ref,actor_userid,ordinal,operation_id,target_server_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state,order_id,provider_snapshot) VALUES("+
            ','.join([str(effect_id),"'admin_bulk'",text('fixture-native:'+str(effect_id)),'9001','1',text(operation),str(server_id),text('pg:'+'b'*32),text(snapshot['endpoint_sha256']),str(plan_id),text(username),str(entitlement.get('expire')or 0),str(entitlement.get('data_limit',0)),text(hashlib.sha256(encoded.encode()).hexdigest()),"'local_recorded'",str(order_id),text(encoded)])+");")
    out+=("UPDATE service_effects SET provider_reference_id="+str(reference_id)+",provider_connection_id="+str(connection_id)+",provider_remote_user_id="+str(remote_id)+",provider_remote_created_at="+text(created_at)+",provider_operation_marker="+text(marker)+",provider_entitlement_snapshot="+text(ent)+",provider_state='linked',provider_verified_at=UTC_TIMESTAMP() WHERE id="+str(effect_id)+";")
    out+=("UPDATE orders_list SET provider_effect_id="+str(effect_id)+",provider_remote_user_id="+str(remote_id)+",provider_remote_created_at="+text(created_at)+" WHERE id="+str(order_id)+";")
    return out
