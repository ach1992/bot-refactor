#!/usr/bin/env python3
"""Actual PHP 8.x, owned MariaDB, and stateful loopback Marzban PUT/GET acceptance.

Tests that customer/admin success cannot follow a merely-acknowledged PUT.
The only automatic repair is a bounded absolute write of the same target
following confirmed unchanged baseline. Never a second relative increment
or a second traffic reset. No installed panel, customer or payment.
"""
import argparse
import shutil
import json
import copy
import contextlib
import io
import time
import threading
from http.server import ThreadingHTTPServer
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

import test_access_state as owned
import test_v6_native as native
import test_post_v9_marzban as marzban

ROOT=Path(__file__).resolve().parents[1]
GIB=1073741824

# Post-V9-only provider fault injection. The immutable historical V6 native
# fixture must not be changed to bless a new candidate.
class EditProviderHandler(native.Handler):
    def handle_request(self):
        server=self.server
        editing=(self.command=='PUT' and '/api/user/' in self.path)
        pre_put=None
        if editing and hasattr(server,'edit_account'):
            if getattr(server,'foreign_before_put',None) is not None:
                server.edit_account.update(copy.deepcopy(server.foreign_before_put))
                server.foreign_before_put=None
            pre_put=copy.deepcopy(server.edit_account)
        if '/api/user/' in self.path:
            if self.command=='GET' and getattr(server,'next_plan_on_get_n',None) is not None:
                n=getattr(server,'edit_get_count',0)+1
                server.edit_get_count=n
                if n==server.next_plan_on_get_n:
                    server.edit_account['next_plan']=copy.deepcopy(server.next_plan_on_get_value)
                    server.next_plan_on_get_n=None
            if (self.command=='GET' and
                getattr(server,'foreign_after_put',None) is not None and
                getattr(server,'edit_put_count',0)==1):
                server.edit_account.update(server.foreign_after_put)
                server.foreign_after_put=None
            if (self.command=='GET' and
                getattr(server,'move_after_first_get',None) is not None and
                not getattr(server,'moved',False)):
                server.moved=True
                server.move_after_first_get()
            if (self.command=='GET' and
                getattr(server,'edit_reset_count',0)>0 and
                getattr(server,'reset_readback_override_enabled',False)):
                server.edit_account['used_traffic']=server.reset_readback_override
                server.reset_readback_override_enabled=False
            if (self.command=='GET' and
                getattr(server,'edit_reset_count',0)>0 and
                getattr(server,'reset_config_override',None) is not None):
                server.edit_account.update(server.reset_config_override)
                server.reset_config_override=None
            # This is after the PINNED request reached endpoint A, but
            # before PHP observes its response. It must not switch to B.
            if (self.command=='POST' and self.path.endswith('/reset') and
                getattr(server,'move_after_reset',None) is not None):
                server.move_after_reset()
                server.move_after_reset=None
        wire_request=None
        prior_stream=None
        old_header=None
        if editing and pre_put is not None:
            # Normalize omitted UserModify limit fields ONLY for the frozen
            # legacy synthetic provider's internal parser. Restore the exact
            # wire body to the recorded trace, so tests inspect true bytes.
            count=int(self.headers.get('Content-Length','0'))
            original_bytes=self.rfile.read(count)
            wire_request=json.loads(original_bytes)
            expanded={'expire':pre_put['expire'],
                      'data_limit':pre_put['data_limit'],**wire_request}
            expanded_bytes=json.dumps(expanded).encode('utf-8')
            prior_stream=self.rfile
            old_header=self.headers.get('Content-Length')
            self.rfile=io.BytesIO(expanded_bytes)
            self.headers.replace_header('Content-Length',str(len(expanded_bytes)))
        try:
            super().handle_request()
        finally:
            if prior_stream is not None:
                self.rfile=prior_stream
                self.headers.replace_header('Content-Length',old_header)
        if (self.command=='POST' and self.path.endswith('/reset') and
            getattr(server,'drop_next_plan_on_update',False) and
            getattr(server,'edit_account',None) is not None):
            # Upstream reset_user_data_usage clears any queued next_plan.
            server.edit_account['next_plan']=None
        if editing and pre_put is not None:
            # Pinned Marzban persists (data_limit or None), so the *GET*
            # after a successful unlimited PUT returns explicit JSON null.
            # The immediate PUT reply is not payment/fulfillment proof.
            if wire_request.get('data_limit','not-supplied') == 0:
                server.edit_account['data_limit']=None
            if getattr(server,'drop_next_plan_on_update',False):
                # Upstream update_user clears next_plan when omitted.
                server.edit_account['next_plan']=None
            server.trace[-1]['body']=original_bytes.decode('utf-8')
            # The historical synthetic provider always writes 'active' on a
            # successful PUT; this post-V9 overlay models Marzban's actual
            # optional UserModify fields without altering the frozen fixture.
            # Present keys are writable; omitted fields retain the concurrent
            # administrator's state. Limits can naturally reactivate expired
            # or quota-limited subscriptions, but not disabled accounts.
            request=wire_request
            account=server.edit_account
            for field in ('status','inbounds','proxies','note',
                          'data_limit_reset_strategy'):
                account[field]=copy.deepcopy(request[field] if field in request
                                             else pre_put[field])
            if 'status' not in request:
                if (pre_put['status']=='expired' and
                    account['expire']>time.time()):
                    account['status']='active'
                elif (pre_put['status']=='limited' and
                      (account['data_limit']==0 or
                       account['data_limit']>account['used_traffic'])):
                    account['status']='active'

@contextlib.contextmanager
def owned_edit_provider():
    server=ThreadingHTTPServer(('127.0.0.1',0),EditProviderHandler)
    server.trace=[]
    worker=threading.Thread(target=server.serve_forever,daemon=True)
    worker.start()
    try:
        yield server,'http://127.0.0.1:'+str(server.server_address[1])
    finally:
        server.shutdown()
        worker.join(timeout=6)
        server.server_close()

INITIAL={
    'username':'fixture-user','status':'active',
    'data_limit':2*GIB,'used_traffic':GIB,
    'expire':1800000100,'inbounds':{},'proxies':{},
    'note':'fixture','data_limit_reset_strategy':'no_reset',
    'subscription_url':'/sub/fixture'
}

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    with owned.owned_database() as (work,env),owned_edit_provider() as (panel,url):
        native.seed(work,env,url)
        source=work/'candidate'
        marzban.prepare(work,source,True)
        core=source/'src/Purchase/Core.php'
        core.parent.mkdir(parents=True,exist_ok=True)
        core.write_bytes((ROOT/'src/Purchase/Core.php').read_bytes())
        # Test the SAME general dispatcher used by checkout, wallet, bank
        # receipts, auto-renew and future panel adapters; no hidden fake
        # provider support is inferred for an unimplemented panel.
        with (source/'functions-under-test.php').open('a') as facade:
            facade.write("\nrequire_once __DIR__.'/src/Purchase/Core.php';\n"
                         "function achbotOwnedEditRouter($serverId,$change){"
                         "global $connection;"
                         "return achbotApplyServiceEdit($connection,$serverId,$change);}\n")

        for mode,kwargs,want,puts,resets in (
            ('edit-verified',{'plus_volume':1},'verified',1,0),
            ('edit-lost-applied',{'plus_volume':1},'reconciled',1,0),
            ('edit-repair',{'plus_volume':1},'repaired',2,0),
            ('edit-lost-repair',{'plus_volume':1},'repaired',2,0),
            ('edit-badbody-repair',{'plus_volume':1},'repaired',2,0),
            ('edit-noop',{'plus_volume':1},'unverified',2,0),
            ('edit-bad-number-baseline',{'plus_volume':1},'unverified',0,0),
            ('edit-bad-number-readback',{'plus_volume':1},'unverified',1,0),
            ('edit-foreign',{'plus_volume':1},'unverified',1,0),
            ('edit-reset-verified',{'days':30,'volume':3},'verified',1,1),
            ('edit-reset-lie',{'days':30,'volume':3},'reset_unverified',0,1),
        ):
            panel.mode=mode
            panel.trace.clear()
            panel.edit_account=INITIAL.copy()
            panel.edit_put_count=0
            panel.edit_reset_count=0
            result=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user',**kwargs}]})
            if result['exception'] or result['warnings'] or result['stdout']:
                raise RuntimeError('Actual PHP Marzban edit runtime failure: '+repr((mode,result)))
            value=result['result']
            if not isinstance(value,dict):
                raise RuntimeError('Missing provider edit verdict: '+repr((mode,value)))
            if want in ('verified','reconciled','repaired'):
                if value.get('success') is not True or value.get('verified') is not True:
                    raise RuntimeError('Provider confirmed without verified GET: '+repr((mode,value)))
                if want=='reconciled' and value.get('reconciled') is not True:
                    raise RuntimeError('Lost response was not reconciled: '+repr((mode,value)))
                if want=='repaired' and value.get('repaired') is not True:
                    raise RuntimeError('Safe corrective absolute PUT was not detected: '+repr((mode,value)))
            elif value.get('success') is not False or value.get('uncertain') is not True:
                raise RuntimeError('Unknown/unapplied provider operation misreported success: '+repr((mode,value)))
            trace=[e['method'] for e in panel.trace]
            if (panel.edit_put_count!=puts or panel.edit_reset_count!=resets or
                trace.count('PUT')!=puts or trace.count('GET')<1):
                raise RuntimeError('Wrong bounded Marzban wire requests: '+
                    repr((mode,puts,resets,trace,panel.edit_put_count,panel.edit_reset_count)))
            if mode=='edit-reset-lie' and value.get('msg')!='marzban_reset_readback_unverified':
                raise RuntimeError('Traffic reset non-effect did not block renewal')
            if want in ('verified','reconciled','repaired'):
                expected_volume=(3 if 'volume' in kwargs else 3)*GIB
                if panel.edit_account['data_limit']!=expected_volume:
                    raise RuntimeError('Remote quota did not really change: '+mode)
            cases.append(mode+'_source_native_'+want)

        # Provider quota null/zero both mean unlimited; absence, bad numbers
        # and malformed quoted fields must fail closed BEFORE remote effects.
        for label,base,change,expected,reset in (
            ('existing_null_days',None,{'plus_day':1},0,0),
            ('existing_zero_days',0,{'plus_day':1},0,0),
            ('unlimited_renewal',None,{'days':30,'volume':0},0,1),
            ('unlimited_to_finite',None,{'volume':3},3*GIB,1),
            ('finite_to_unlimited',2*GIB,{'volume':0},0,1),
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_account['data_limit']=base
            panel.edit_put_count=0
            panel.edit_reset_count=0
            outcome=native.run(work,env,source,native.DBS[1],{
                'function':'achbotOwnedEditRouter',
                'args':[2,{'remark':'fixture-user',**change}]})
            assert outcome['exception'] is None and not outcome['warnings'],(label,outcome)
            result=outcome['result']
            assert result['success'] is True and result['verified'] is True,(label,result)
            assert result['applied_bytes']==expected,(label,result)
            assert panel.edit_put_count==1 and panel.edit_reset_count==reset,(label,panel.trace)
            remote_want=(None if expected==0 else expected) if 'volume' in change else base
            assert panel.edit_account['data_limit']==remote_want,(label,panel.edit_account)
            cases.append('review_r6_unlimited_'+label+'_exact_verified_readback')

        for label,quota in (('null',None),('zero',0)):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_account['data_limit']=quota
            panel.edit_put_count=0
            panel.edit_reset_count=0
            outcome=native.run(work,env,source,native.DBS[1],{
                'function':'achbotOwnedEditRouter',
                'args':[2,{'remark':'fixture-user','plus_volume':1}]})
            assert outcome['exception'] is None,(label,outcome)
            assert outcome['result']['success'] is False,(label,outcome)
            assert outcome['result']['msg']=='marzban_unlimited_volume_extension',(label,outcome)
            assert panel.edit_put_count==0 and panel.edit_reset_count==0,(label,panel.trace)
            cases.append('review_r6_unlimited_increment_refused_without_downgrade_'+label)

        for label,raw in (
            ('missing','<ABSENT>'),('fractional',1.5),('negative',-1),
            ('exponent','1e3'),('malformed','NaN'),('overflow','99999999999999999999999')
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            if raw=='<ABSENT>': del panel.edit_account['data_limit']
            else: panel.edit_account['data_limit']=raw
            panel.edit_put_count=0
            panel.edit_reset_count=0
            outcome=native.run(work,env,source,native.DBS[1],{
                'function':'achbotOwnedEditRouter',
                'args':[2,{'remark':'fixture-user','plus_day':1}]})
            assert outcome['exception'] is None,(label,outcome)
            assert outcome['result']['success'] is False,(label,outcome)
            assert panel.edit_put_count==0 and panel.edit_reset_count==0,(label,panel.trace)
            cases.append('review_r6_invalid_'+label+'_no_remote_effect')

        # Upstream Marzban deletes a queued next_plan on both RESET and
        # UserModify when the field is omitted. A paid-service edit must
        # not silently discard an independently scheduled future plan.
        for label,change in (
            ('quota',{'plus_volume':1}),
            ('combined_renew',{'days':30,'volume':3}),
        ):
            panel.mode='edit-reset-verified' if 'volume' in change else 'edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            expected={'data_limit':4*GIB,'expire':1840000100,
                      'add_remaining_traffic':False,'fire_on_either':True}
            panel.edit_account['next_plan']=copy.deepcopy(expected)
            panel.drop_next_plan_on_update=True
            panel.edit_put_count=0
            panel.edit_reset_count=0
            try:
                result=native.run(work,env,source,native.DBS[1],{
                    'function':'editMarzbanConfig',
                    'args':[2,{'remark':'fixture-user',**change}]})
                assert result['exception'] is None,(label,result)
                assert result['result']['success'] is False,(label,result)
                assert result['result']['uncertain'] is True,(label,result)
                assert result['result']['msg']=='marzban_next_plan_requires_review',(label,result)
                assert panel.edit_put_count==0 and panel.edit_reset_count==0,(label,panel.trace)
                assert panel.edit_account['next_plan']==expected,(label,panel.edit_account)
                cases.append('review_upstream_next_plan_'+label+'_preserved_before_remote')
            finally:
                panel.drop_next_plan_on_update=False

        # An external scheduler may add a future plan after our first
        # authenticated GET but before the first remote side effect. Recheck
        # directly before RESET/PUT; never erase that newly queued plan.
        for label,change in (
            ('quota',{'plus_volume':1}),
            ('combined_renew',{'days':30,'volume':3}),
        ):
            panel.mode='edit-reset-verified' if 'volume' in change else 'edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            future={'data_limit':5*GIB,'expire':1890000100,
                    'add_remaining_traffic':True,'fire_on_either':True}
            panel.edit_get_count=0
            panel.next_plan_on_get_n=2
            panel.next_plan_on_get_value=copy.deepcopy(future)
            panel.drop_next_plan_on_update=True
            panel.edit_put_count=0
            panel.edit_reset_count=0
            try:
                got=native.run(work,env,source,native.DBS[1],{
                    'function':'editMarzbanConfig',
                    'args':[2,{'remark':'fixture-user',**change}]})
                assert got['exception'] is None,(label,got)
                assert got['result']['success'] is False,(label,got)
                assert got['result']['uncertain'] is True,(label,got)
                assert panel.edit_put_count==0 and panel.edit_reset_count==0,(label,panel.trace)
                assert panel.edit_account['next_plan']==future,(label,panel.edit_account)
                cases.append('review_upstream_next_plan_'+label+'_detected_before_first_effect')
            finally:
                panel.next_plan_on_get_n=None
                panel.drop_next_plan_on_update=False

        # Reviewer B1: no corrective absolute PUT can overwrite a foreign
        # status/configuration change even if expiry, quota and note are still
        # identical to the original observed baseline.
        for label,drift in (
            ('disabled',{'status':'disabled'}),
            ('expired',{'status':'expired'}),
            ('inbounds',{'inbounds':{'vless':['other-inbound']}}),
            ('proxies',{'proxies':{'vless':{'flow':'other-flow'}}}),
            ('reset_strategy',{'data_limit_reset_strategy':'day'}),
            ('note',{'note':'operator-changed'}),
        ):
            panel.mode='edit-repair'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_put_count=0
            panel.foreign_after_put=drift
            outcome=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user','plus_volume':1}]})
            assert outcome['exception'] is None and outcome['result']['success'] is False,(label,outcome)
            assert outcome['result']['uncertain'] is True,(label,outcome)
            assert panel.edit_put_count==1,(label,panel.trace)
            for key,expected in drift.items():
                assert panel.edit_account[key]==expected,(label,panel.edit_account)
            cases.append('review_b1_foreign_'+label+'_no_corrective_put')

        # Root-cause negative control: a foreign operator may modify STATUS
        # or config after the original GET but before the FIRST PUT. That PUT
        # must not itself carry unrelated fields which erase that new state.
        for label,drift in (
            ('disabled',{'status':'disabled'}),
            ('inbounds',{'inbounds':{'vless':['foreign-inbound']}}),
            ('proxies',{'proxies':{'vless':{'flow':'changed'}}}),
            ('reset_strategy',{'data_limit_reset_strategy':'day'}),
            ('note',{'note':'foreign-note'}),
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_put_count=0
            panel.foreign_before_put=drift
            outcome=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user','plus_volume':1}]})
            assert outcome['exception'] is None and outcome['result']['success'] is False,(label,outcome)
            assert outcome['result']['uncertain'] is True,(label,outcome)
            assert panel.edit_put_count==1,(label,panel.trace)
            for field,expected in drift.items():
                assert panel.edit_account[field]==expected,(label,panel.edit_account)
            sent=[json.loads(e['body']) for e in panel.trace if e['method']=='PUT']
            assert len(sent)==1 and set(sent[0])=={'data_limit'},(label,sent)
            cases.append('review_b1_foreign_before_initial_put_'+label+'_not_overwritten')

        # The untouched limit must also stay untouched when another actor
        # adjusts it immediately before our FIRST PUT. A duration-only PUT
        # cannot restore stale quota; quota-only cannot restore stale expiry.
        for label,drift,edit,expected in (
            ('quota_edit_preserves_foreign_expiry',
             {'expire':1800500000},{'plus_volume':1},{'data_limit'}),
            ('day_edit_preserves_foreign_quota',
             {'data_limit':5*GIB},{'plus_day':1},{'expire'}),
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_put_count=0
            panel.foreign_before_put=drift
            outcome=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user',**edit}]})
            assert outcome['exception'] is None and outcome['result']['success'] is False,(label,outcome)
            assert outcome['result']['uncertain'] is True,(label,outcome)
            assert panel.edit_put_count==1,(label,panel.trace)
            for field,value in drift.items():
                assert panel.edit_account[field]==value,(label,panel.edit_account)
            sent=[json.loads(e['body']) for e in panel.trace if e['method']=='PUT']
            assert len(sent)==1 and set(sent[0])==expected,(label,sent)
            cases.append('review_b1_'+label)

        # A disabled account at BASELINE is not an implicit authorization
        # for the very first edit to reactivate it.
        panel.mode='edit-verified'
        panel.trace.clear()
        panel.edit_account=copy.deepcopy(INITIAL)
        panel.edit_account['status']='disabled'
        panel.edit_put_count=0
        denied=native.run(work,env,source,native.DBS[1],
            {'function':'editMarzbanConfig',
             'args':[2,{'remark':'fixture-user','plus_volume':1}]})
        assert denied['exception'] is None and denied['result']['success'] is False,denied
        assert panel.edit_put_count==0 and panel.edit_account['status']=='disabled'
        cases.append('review_b1_disabled_original_not_reactivated')

        # Legitimate paid renewal of naturally expired/limited subscriptions
        # remains possible, but administrator-disabled accounts stay disabled.
        for label,status,change in (
            ('expired_days','expired',{'plus_day':1}),
            ('expired_full_renew','expired',{'days':30,'volume':3}),
            ('limited_quota','limited',{'plus_volume':1}),
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_account['status']=status
            panel.edit_put_count=0
            panel.edit_reset_count=0
            result=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user',**change}]})
            assert result['exception'] is None and result['result']['success'] is True,(label,result)
            assert panel.edit_account['status']=='active',result
            payloads=[json.loads(e['body']) for e in panel.trace if e['method']=='PUT']
            assert len(payloads)==1 and set(payloads[0])==(
                {'expire','data_limit'} if 'volume' in change and 'days' in change
                else {'expire'} if 'days' in change or 'plus_day' in change
                else {'data_limit'}),panel.trace
            cases.append('review_b1_authorized_'+label+'_reactivation')
        panel.mode='edit-verified'
        panel.trace.clear()
        panel.edit_account=copy.deepcopy(INITIAL)
        panel.edit_account['status']='disabled'
        declined=native.run(work,env,source,native.DBS[1],
            {'function':'editMarzbanConfig',
             'args':[2,{'remark':'fixture-user','plus_day':1}]})
        assert declined['exception'] is None and declined['result']['success'] is False,declined
        assert not any(e['method']=='PUT' for e in panel.trace)
        cases.append('review_b1_disabled_duration_does_not_override_operator')

        # Reviewer B2: config drift between the first authenticated GET and
        # RESET must never redirect credentials or reset to another physical
        # endpoint. No provider is real: both endpoints are loopback fixtures.
        with owned_edit_provider() as (foreign,foreign_url):
            foreign.mode='edit-reset-verified'
            for when in ('before_reset','after_reset'):
                panel.mode='edit-reset-verified'
                panel.trace.clear()
                foreign.trace.clear()
                panel.edit_account=copy.deepcopy(INITIAL)
                panel.edit_put_count=0
                panel.edit_reset_count=0
                panel.moved=False
                panel.move_after_first_get=None
                panel.move_after_reset=None
                changed=lambda: native.sql(work,env,
                    "UPDATE server_config SET panel_url='"+foreign_url+"' WHERE id=2",
                    native.DBS[1])
                if when=='before_reset':
                    panel.move_after_first_get=changed
                else:
                    panel.move_after_reset=changed
                try:
                    denied=native.run(work,env,source,native.DBS[1],
                        {'function':'editMarzbanConfig',
                         'args':[2,{'remark':'fixture-user','days':30,'volume':3}]})
                    assert denied['exception'] is None and denied['result']['success'] is False,(when,denied)
                    assert denied['result']['uncertain'] is True,(when,denied)
                    assert not foreign.trace,(when,foreign.trace)
                    assert panel.edit_put_count==0,(when,panel.trace)
                    if when=='before_reset':
                        assert panel.edit_reset_count==0,panel.trace
                    else:
                        assert panel.edit_reset_count==1,panel.trace
                    cases.append('review_b2_endpoint_drift_'+when+'_no_foreign_request')
                finally:
                    native.sql(work,env,
                        "UPDATE server_config SET panel_url='"+url+"' WHERE id=2",
                        native.DBS[1])
                    panel.move_after_first_get=None
                    panel.move_after_reset=None

        # Reviewer R3: an acknowledged RESET is not complete unless the
        # exact same account reports canonical integer zero usage.
        for label,prior,observed in (
            ('partial',GIB,GIB//2),
            ('zero_to_nonzero',0,1024),
            ('fractional',GIB,0.5),
            ('exponent',GIB,'0e0'),
            ('overflow',GIB,'9223372036854775808'),
            ('missing',GIB,None),
        ):
            panel.mode='edit-reset-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_account['used_traffic']=prior
            panel.edit_put_count=0
            panel.edit_reset_count=0
            panel.reset_readback_override=observed
            panel.reset_readback_override_enabled=True
            result=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user','days':30,'volume':3}]})
            assert result['exception'] is None and result['result']['success'] is False,(label,result)
            assert result['result']['uncertain'] is True,(label,result)
            assert result['result']['msg']=='marzban_reset_readback_unverified',(label,result)
            assert panel.edit_reset_count==1 and panel.edit_put_count==0,(label,panel.trace)
            cases.append('review_r3_reset_'+label+'_must_stay_under_review')
        panel.reset_readback_override=None
        panel.reset_readback_override_enabled=False

        # Traffic reset can succeed while an independent operator changes
        # account status/inbounds before the first corrective full-field PUT.
        for label,config in (
            ('status',{'status':'disabled'}),
            ('inbounds',{'inbounds':{'vless':['foreign-inbound']}}),
        ):
            panel.mode='edit-reset-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_put_count=0
            panel.edit_reset_count=0
            panel.reset_config_override=config
            result=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user','days':30,'volume':3}]})
            assert result['exception'] is None and result['result']['success'] is False,(label,result)
            assert result['result']['uncertain'] is True,(label,result)
            assert panel.edit_reset_count==1 and panel.edit_put_count==0,(label,panel.trace)
            cases.append('review_b1_after_reset_foreign_'+label+'_no_put')

        # Reviewer R4: local orders_list.expire_date is signed 32-bit INT.
        # Remote edits outside that range must not be dispatched.
        for label,original,change,allowed in (
            ('max',2147483647-86400,{'plus_day':1},True),
            ('beyond',2147483647-86400+1,{'plus_day':1},False),
            ('long_renew',1800000100,{'days':36500,'volume':3},False),
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=copy.deepcopy(INITIAL)
            panel.edit_account['expire']=original
            panel.edit_put_count=0
            outcome=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user',**change}]})
            assert outcome['exception'] is None,(label,outcome)
            assert outcome['result']['success'] is allowed,(label,outcome)
            assert panel.edit_put_count==int(allowed),(label,panel.trace)
            if not allowed:
                assert not any(e['method']=='POST' and e['path'].endswith('/reset')
                    for e in panel.trace),panel.trace
            cases.append('review_r4_signed_expiry_'+label)

        # The legacy quote schema accepts FLOAT, while the old audit stores
        # integer units. Reject unsupported fractions rather than claiming
        # an incomplete 1.5 GB / 0.5 day paid service as fulfilled.
        for label,change in (
            ('volume_delta',{'plus_volume':1.5}),
            ('day_delta',{'plus_day':0.5}),
            ('plan_quota',{'days':30,'volume':1.5})
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=INITIAL.copy()
            rejected=native.run(work,env,source,native.DBS[1],{
                'function':'editMarzbanConfig',
                'args':[2,{'remark':'fixture-user',**change}]})
            assert rejected['exception'] is None and rejected['result']['success'] is False,rejected
            assert rejected['result']['msg'] in ('invalid_volume','invalid_days'),rejected
            assert not any(x['method'] in ('PUT','DELETE') or '/reset' in x['path']
                           for x in panel.trace),panel.trace
            cases.append('fractional_'+label+'_never_truncated_or_approved')

        # Genuine Marzban performs exact GET+PUT+GET using the shared
        # provider-neutral dispatcher. A plausible future panel type remains
        # explicitly UNSUPPORTED until its adapter/verified-readback exists.
        panel.mode='edit-verified'
        panel.trace.clear()
        panel.edit_account=INITIAL.copy()
        panel.edit_put_count=0
        good=native.run(work,env,source,native.DBS[1],{
            'function':'achbotOwnedEditRouter',
            'args':[2,{'remark':'fixture-user','plus_volume':1}]})
        assert good['exception'] is None and good['result']['success'] is True,good
        assert good['result']['verified'] is True
        assert any(event['method']=='PUT' for event in panel.trace)
        cases.append('panel_neutral_dispatch_to_current_verified_marzban_adapter')

        native.sql(work,env,"UPDATE server_config SET type='pasarguard' WHERE id=2",
                   native.DBS[1])
        panel.trace.clear()
        future=native.run(work,env,source,native.DBS[1],{
            'function':'achbotOwnedEditRouter',
            'args':[2,{'remark':'fixture-user','plus_volume':1}]})
        assert future['exception'] is None and future['result']['success'] is False,future
        assert future['result']['msg']=='unsupported_service_adapter',future
        assert panel.trace==[],panel.trace
        native.sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2",
                   native.DBS[1])
        cases.append('unimplemented_future_panel_denied_without_remote_dispatch')

        # Each parallel PHP worker needs its OWN fixture directory. Sharing
        # native.php/case.json/result.json would falsely test file races rather
        # than the real two-process MariaDB advisory-lock behavior.
        sites=[]
        for worker in (0,1):
            site=work/('service-edit-worker-'+str(worker))
            shutil.copytree(source,site)
            sites.append(site)

        # Two different payments applying independent increments to one
        # account are serialized. No lost update is permissible.
        panel.mode='edit-verified'
        panel.edit_account=INITIAL.copy()
        panel.edit_put_count=0
        panel.edit_reset_count=0
        with ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(
                lambda worker:native.run(work,env,sites[worker],native.DBS[1],{
                   'function':'editMarzbanConfig',
                   'args':[2,{'remark':'fixture-user','plus_volume':1}]
                }),range(2)))
        if any(r['exception'] or r['warnings'] or r['stdout'] or
               r['result'].get('success') is not True for r in results):
            raise RuntimeError('Parallel distinct modifications did not both verify: '+repr(results))
        if panel.edit_account['data_limit']!=4*GIB or panel.edit_put_count!=2:
            raise RuntimeError('Lost update under independent concurrent modifications')
        cases.append('two_concurrent_distinct_edits_serialized_no_lost_increment')

        # Physical aliases can use DIFFERENT URLs and still share a Marzban
        # user database. 001's explicit group ID, not raw endpoint spelling,
        # must be the account-level serialization key.
        native.sql(work,env,
            (ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text(),
            native.DBS[1])
        native.sql(work,env,
            "INSERT INTO setting (id,type,value) VALUES "
            "(900001,'ACHBOT_MARZBAN_PANEL_GROUP','Fixture physical panel')",
            native.DBS[1])
        native.sql(work,env,
            "INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
            "VALUES (2,'Physical panel',9,'fixture','',1,1),"
            "(3,'Physical alias',9,'fixture','',1,1) "
            "ON DUPLICATE KEY UPDATE active=1,state=1",
            native.DBS[1])
        native.sql(work,env,
            "UPDATE server_info SET panel_group_id=900001 WHERE id IN (2,3)",
            native.DBS[1])
        alias=url.replace('127.0.0.1','localhost')
        native.sql(work,env,
            "UPDATE server_config SET type='marzban',"
            f"panel_url='{alias}' WHERE id=3",
            native.DBS[1])
        panel.mode='edit-verified'
        panel.trace.clear()
        panel.edit_account=INITIAL.copy()
        panel.edit_put_count=0
        with ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(native.run,work,env,sites[worker],native.DBS[1],{
                'function':'editMarzbanConfig',
                'args':[sid,{'remark':'fixture-user','plus_volume':1}]
            }) for worker,sid in enumerate((2,3))]
            values=[f.result() for f in futures]
        assert all(r['exception'] is None and r['result']['success'] is True
                   for r in values),values
        assert panel.edit_account['data_limit']==4*GIB
        assert panel.edit_put_count==2
        cases.append('physical_panel_alias_different_urls_group_lock_no_lost_increment')

        # A stale or unknown physical grouping cannot authorize any edit.
        native.sql(work,env,
            "UPDATE server_info SET panel_group_id=900002 WHERE id=3",
            native.DBS[1])
        panel.trace.clear()
        unknown=native.run(work,env,source,native.DBS[1],{
            'function':'editMarzbanConfig',
            'args':[3,{'remark':'fixture-user','plus_volume':1}]})
        assert unknown['exception'] is None
        assert unknown['result']['success'] is False
        assert unknown['result']['msg']=='marzban_group_identity_unverified'
        assert panel.trace==[]
        cases.append('missing_physical_panel_group_rejects_before_remote_dispatch')

    report={'complete':True,'cases':len(cases),'scenarios':cases,
        'actual_php_mysqli_curl':True,'real_provider_requests':0,
        'owned_mariadb_only':True,'production_effects':0,
        'absolute_correction_not_relative_replay':True}
    if args.report:
        args.report.parent.mkdir(parents=True,exist_ok=True)
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
