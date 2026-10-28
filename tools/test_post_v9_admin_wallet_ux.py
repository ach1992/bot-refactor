#!/usr/bin/env python3
"""Issue #26 wallet UX: actual routes/committed notices and private debt consent.

Owned socket-only MariaDB and real PHP/MySQLi workers. Baseline is pinned
RC11 source, not a hand-coded prior-behavior model. No live bot or money.
"""
from __future__ import annotations
import argparse
import contextlib
import json
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_post_v9_admin_wallet as admin

ROOT = Path(__file__).resolve().parents[1]
BASE = '621307be775163f0b85ae9fa5b21b1d0b654cfed'
PRIVATE_REASON = 'Private synthetic contract reason <not customer copy>'

# Override ONLY the mysqli transport boundary. The production function and
# its real journal reconciliation run unchanged. A second real connection
# advances the balance after COMMIT, before the first worker can notify.
COMMIT_DRIVER = r'''class OwnedCommitMysqli extends mysqli {
    public function commit(int $flags=0, ?string $name=null): bool {
        if (!empty($GLOBALS['spec']['commit_rollback'])) {
            parent::rollback();
            throw new RuntimeException('owned_commit_transport_unknown');
        }
        $ok=parent::commit($flags,$name);
        if (!empty($GLOBALS['spec']['after_commit_delta'])) {
            $other=new mysqli('localhost','root','',$GLOBALS['argv'][3],0,$GLOBALS['argv'][2]);
            $delta=(int)$GLOBALS['spec']['after_commit_delta'];
            $other->query('UPDATE users SET wallet=wallet+'.$delta.' WHERE userid=1001');
            $other->close();
        }
        if (!empty($GLOBALS['spec']['commit_ack_lost']))
            throw new RuntimeException('owned_commit_ack_lost');
        return $ok;
    }
}
'''
DRIVER = admin.PHP.replace(
    "$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);",
    COMMIT_DRIVER + "$connection=new OwnedCommitMysqli('localhost','root','',$argv[3],0,$argv[2]);")
assert DRIVER != admin.PHP


def sql(w, e, q):
    return admin.sql(w, e, q)


@contextlib.contextmanager
def source_root(root):
    original = admin.ROOT
    admin.ROOT = root
    try:
        yield
    finally:
        admin.ROOT = original


def reset(w, e, balance=100):
    sql(w,e,"DELETE FROM wallet_adjustments; UPDATE users SET step='none'; "
        f"UPDATE users SET wallet={balance} WHERE userid=1001; "
        'UPDATE users SET wallet=0,isAdmin=1 WHERE userid=9002')


def consent_buttons(result):
    for event in result['events']:
        if event['kind'] != 'sendMessage' or len(event['args']) < 2:
            continue
        keys = event['args'][1]
        if not isinstance(keys,str) or not keys.startswith('{'):
            continue
        callbacks = [b['callback_data'] for row in json.loads(keys)['inline_keyboard'] for b in row]
        if callbacks and callbacks[0].startswith('walletDebtYes:'):
            return callbacks
    return []


def success(result):
    return any(m.startswith('✅') for m in admin.messages(result))


def no_effect(w, e, before=100, pending=None):
    admin.assert_state(w,e,before,0)
    if pending is not None:
        assert admin.step(w,e) == pending


def prepare_debt(w,e,amount=125,reason=PRIVATE_REASON,uid=900,mid=90,actor=9001):
    # Enter through the real Decrease menu, real target and amount messages.
    admin.execute(w,e,actor=actor,data='decreaseUserWallet',update_id=uid-3,message_id=mid-3)
    admin.execute(w,e,actor=actor,text='1001',update_id=uid-2,message_id=mid-2)
    admin.execute(w,e,actor=actor,text=str(amount),update_id=uid-1,message_id=mid-1)
    result=admin.execute(w,e,actor=actor,text=reason,update_id=uid,message_id=mid)
    return result,consent_buttons(result)


def confirm(w,e,button,uid=901,mid=91,**kwargs):
    return admin.execute(w,e,data=button,update_id=uid,message_id=mid,**kwargs)


def check_notice(result,kind,amount,before,after,uid,target=1001):
    messages=[e['args'] for e in result['events'] if e['kind']=='sendMessage']
    operators=[a[0] for a in messages if str(a[0]).startswith('✅') and len(a)<4]
    customers=[a[0] for a in messages if len(a)>=4 and a[3]==target]
    assert len(operators)==1 and len(customers)==1, messages
    operator,customer=operators[0],customers[0]
    number=lambda n: format(n,',')
    assert f'شناسه عددی کاربر: {target}' in operator
    assert f'مبلغ {number(amount)} تومان' in operator
    assert f'موجودی قبل: {number(before)} تومان' in operator
    assert f'موجودی بعد: {number(after)} تومان' in operator
    assert f'شناسه پیگیری: {uid}' in operator
    verb='افزوده' if kind=='credit' else 'کسر'
    assert verb in operator and verb in customer
    assert f'مبلغ {number(amount)} تومان' in customer
    assert f'پس از این تغییر: {number(after)} تومان' in customer
    for private in [PRIVATE_REASON,'9001','شناسه پیگیری','موجودی قبل','SQL',
                    'wallet_adjustments','RuntimeException']:
        assert private not in customer, (private,customer)
    assert '\\n' not in customer and '\\n' not in operator


def admin_menu(source):
    code=r'''require $argv[1].'/tools/source_scan.php';
if (realpath($argv[2])===realpath($argv[1])) {
 require_once $argv[2].'/messages/loader.php';
 $mainValues=achbotMessages('legacy_messages');$buttonValues=achbotMessages('legacy_buttons');
} else {
 // The pinned archived RC11 baseline retains its original facade unchanged.
 require $argv[2].'/settings/values.php';
}
$from_id=9001;$admin=9001;
function canSeeButton(...$args){return true;}
eval(achbotExtractFunction($argv[2].'/config.php','getAdminKeys'));
echo getAdminKeys();'''
    return json.loads(subprocess.check_output(['php','-n','-d','extension=tokenizer',
        '-r',code,str(ROOT),str(source)],text=True))


def callbacks(menu):
    return [b['callback_data'] for row in menu['inline_keyboard'] for b in row]


def finish(process,barrier):
    return admin.finish_paused_worker(process,barrier)


def cleanup(process):
    if process.poll() is None:
        process.kill();process.wait(timeout=4)


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        (w/'owned-wallet-driver.php').write_text(DRIVER)
        sql(w,e,admin.MIGRATION.read_text())
        baseline=w/'rc11-source'
        for path in ['config.php','settings/values.php','src/Telegram/UpdateBindings.php',
                     'src/Finance/Routes/AdminWallet.php','src/Finance/WalletAdjustments.php']:
            dest=baseline/path;dest.parent.mkdir(parents=True,exist_ok=True)
            dest.write_bytes(subprocess.check_output(['git','-C',str(ROOT),'show',BASE+':'+path]))

        assert 'createUserWalletDebt' in callbacks(admin_menu(baseline))
        cases.append('red_rc11_actual_menu_has_third_wallet_control')
        with source_root(baseline):
            reset(w,e)
            admin.move_to_reason(w,e,'credit',25)
            out=admin.execute(w,e,text=PRIVATE_REASON,update_id=880)
            assert success(out)
            target=[x['args'][0] for x in out['events'] if x['kind']=='sendMessage' and len(x['args'])>=4]
            assert len(target)==1 and '25' not in target[0] and '125' not in target[0]
            assert not any('موجودی قبل:' in m for m in admin.messages(out))
            cases.append('red_rc11_committed_notices_lack_required_facts')
            reset(w,e)
            out,buttons=prepare_debt(w,e)
            no_effect(w,e)
            assert not buttons and any('معتبر نیست' in m for m in admin.messages(out))
            cases.append('red_rc11_decrease_cannot_offer_inflow_debt_consent')

        current=callbacks(admin_menu(ROOT))
        assert 'increaseUserWallet' in current and 'decreaseUserWallet' in current
        assert 'createUserWalletDebt' not in current
        cases.append('two_original_wallet_menu_controls')

        reset(w,e)
        out=admin.execute(w,e,data='createUserWalletDebt',update_id=887)
        assert admin.step(w,e)=='decreaseUserWallet'
        admin.execute(w,e,text='1001',update_id=888)
        assert admin.step(w,e)=='decreaseWalletUser1001'
        no_effect(w,e)
        cases.append('old_top_level_debt_callback_restarts_safe_decrease')

        reset(w,e)
        for kind,amount,before,after,uid in [('debit',25,100,75,890),('credit',50,75,125,891)]:
            admin.move_to_reason(w,e,kind,amount)
            out=admin.execute(w,e,text=PRIVATE_REASON,update_id=uid)
            check_notice(out,kind,amount,before,after,uid)
            assert not consent_buttons(out)
        assert admin.balance(w,e)==125 and admin.nledger(w,e)==2
        cases.append('normal_debit_and_credit_truthful_committed_private_notices')

        reset(w,e)
        preview,buttons=prepare_debt(w,e)
        no_effect(w,e)
        pending=admin.step(w,e)
        assert pending.startswith('achbotWalletDebt:1001:125:100:90:900:')
        assert len(buttons)==2 and all(len(b.encode())<=64 for b in buttons)
        assert 'موجودی قبل: 100' in admin.messages(preview)[0]
        assert 'موجودی بعد: -25' in admin.messages(preview)[0]
        assert PRIVATE_REASON not in admin.messages(preview)[0]
        cases.append('decrease_negative_preview_persists_locked_intent_without_money')
        out=confirm(w,e,buttons[0],wire_text='Untrusted bot message is not the reason')
        admin.assert_state(w,e,-25,1)
        assert admin.step(w,e)=='none'
        assert admin.journal(w,e,901)=='9001:1001:debt:125:100:-25'
        assert sql(w,e,'SELECT reason FROM wallet_adjustments')==PRIVATE_REASON
        check_notice(out,'debt',125,100,-25,901)
        cases.append('fresh_private_consent_commits_original_reason_and_global_event')
        out=confirm(w,e,buttons[0])
        admin.assert_state(w,e,-25,1)
        assert not success(out)
        cases.append('confirmation_redelivery_has_no_second_effect')

        reset(w,e)
        _,buttons=prepare_debt(w,e)
        out=confirm(w,e,buttons[1])
        no_effect(w,e,pending='none')
        assert any('لغو شد' in m for m in admin.messages(out))
        assert not success(confirm(w,e,buttons[0],uid=902))
        no_effect(w,e,pending='none')
        cases.append('explicit_debt_cancel_invalidates_confirm')

        reset(w,e)
        _,old=prepare_debt(w,e)
        _,new=prepare_debt(w,e,uid=910,mid=100)
        pending=admin.step(w,e)
        assert old[0]!=new[0]
        assert not success(confirm(w,e,old[0],uid=911,mid=101))
        no_effect(w,e,pending=pending)
        check_notice(confirm(w,e,new[0],uid=912,mid=102),'debt',125,100,-25,912)
        cases.append('old_step_nonce_cannot_authorize_restarted_flow')

        reset(w,e)
        _,old=prepare_debt(w,e)
        _,new=prepare_debt(w,e)
        pending=admin.step(w,e)
        assert old[0]!=new[0], 'reconstructed source must get a new nonce'
        assert not success(confirm(w,e,old[0]))
        no_effect(w,e,pending=pending)
        check_notice(confirm(w,e,new[0],uid=902,mid=92),'debt',125,100,-25,902)
        cases.append('identical_replayed_source_cannot_recreate_old_consent_nonce')

        reset(w,e)
        admin.move_to_reason(w,e,'debt',125,amount_message_id=89)
        out=admin.execute(w,e,text=PRIVATE_REASON,update_id=900,message_id=90)
        no_effect(w,e)
        buttons=consent_buttons(out)
        assert len(buttons)==2
        check_notice(confirm(w,e,buttons[0]),'debt',125,100,-25,901)
        cases.append('legacy_pending_debt_reason_requires_current_explicit_consent')

        for name,spec in [
            ('group',dict(wire_chat=-1001234,wire_chat_type='supergroup')),
            ('other_dm',dict(wire_chat=9003)),
            ('forged_binding',dict(wire_actor=9002,bound_actor_override=9001)),
            ('old_update',dict(uid=900)),
            ('old_message',dict(mid=90)),
            ('mixed_envelope',dict(update_kind='mixed')),
        ]:
            reset(w,e)
            _,buttons=prepare_debt(w,e)
            pending=admin.step(w,e)
            out=confirm(w,e,buttons[0],**spec)
            no_effect(w,e,pending=pending)
            assert not success(out),name
            cases.append('debt_consent_rejects_'+name)

        reset(w,e)
        _,buttons=prepare_debt(w,e,actor=9002)
        pending=admin.step(w,e,9002)
        sql(w,e,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        out=confirm(w,e,buttons[0],actor=9002,cached_admin_override=True)
        no_effect(w,e)
        assert admin.step(w,e,9002)==pending and not success(out)
        cases.append('locked_debt_consent_rechecks_delegated_admin_revocation')

        reset(w,e)
        _,buttons=prepare_debt(w,e)
        sql(w,e,'UPDATE users SET wallet=99 WHERE userid=1001')
        out=confirm(w,e,buttons[0])
        no_effect(w,e,before=99,pending='none')
        assert any('تغییر کرده' in m for m in admin.messages(out)) and not success(out)
        cases.append('changed_balance_invalidates_exact_debt_preview')

        reset(w,e,balance=-25)
        _,buttons=prepare_debt(w,e,amount=10)
        check_notice(confirm(w,e,buttons[0]),'debt',10,-25,-35,901)
        admin.assert_state(w,e,-35,1)
        cases.append('deepening_existing_debt_also_needs_explicit_consent')

        reset(w,e)
        _,buttons=prepare_debt(w,e)
        # Another operation consumes the final confirmation update/leg on
        # a DIFFERENT target, so preview balance remains unchanged.
        admin.move_to_reason(w,e,'credit',1,actor=9002,target=9002)
        assert success(admin.execute(w,e,actor=9002,text='other action',update_id=901))
        pending=admin.step(w,e)
        out=confirm(w,e,buttons[0])
        admin.assert_state(w,e,100,1)
        assert admin.step(w,e)==pending and not success(out)
        assert sql(w,e,'SELECT adjustment_type FROM wallet_adjustments')=='credit'
        cases.append('debt_confirmation_cannot_reuse_global_telegram_operation_leg')

        reset(w,e)
        _,buttons=prepare_debt(w,e)
        contender,barrier=admin.start_worker_with_cached_step(w,e,'debt-two-confirmations',
            data=buttons[0],update_id=902,message_id=92)
        try:
            first=confirm(w,e,buttons[0])
            second=finish(contender,barrier)
            admin.assert_state(w,e,-25,1)
            assert success(first) and not success(second)
        finally:
            cleanup(contender)
        cases.append('concurrent_distinct_confirmations_one_atomic_debt')

        for finance_first in (False,True):
            reset(w,e)
            _,buttons=prepare_debt(w,e)
            if finance_first:
                paused,barrier=admin.start_worker_with_cached_step(w,e,'debt-nav-finance-first',
                    data='managePanel',update_id=903,message_id=93,run_real_manage_panel_route=True)
                try:
                    assert success(confirm(w,e,buttons[0]))
                    out=finish(paused,barrier)
                    edits=[x['args'][1] for x in out['events'] if x['kind']=='editText']
                    assert len(edits)==1 and 'در حال بررسی' in edits[0]
                    assert 'مبلغی از این درخواست اعمال نشد' not in edits[0]
                    admin.assert_state(w,e,-25,1)
                finally:
                    cleanup(paused)
            else:
                paused,barrier=admin.start_worker_with_cached_step(w,e,'debt-nav-first',
                    data=buttons[0],update_id=901,message_id=91)
                try:
                    admin.execute(w,e,data='managePanel',update_id=902,message_id=92,
                        run_real_manage_panel_route=True)
                    assert not success(finish(paused,barrier))
                    no_effect(w,e,pending='none')
                finally:
                    cleanup(paused)
            cases.append('debt_managepanel_race_'+('finance_first' if finance_first else 'navigation_first'))

        reset(w,e)
        _,buttons=prepare_debt(w,e)
        paused,barrier=admin.start_worker_with_cached_step(w,e,'debt-inline-cancel-first',
            data=buttons[0],update_id=901,message_id=91)
        try:
            confirm(w,e,buttons[1],uid=902,mid=92)
            assert not success(finish(paused,barrier))
            no_effect(w,e,pending='none')
        finally:
            cleanup(paused)
        cases.append('inline_cancel_and_cached_confirmation_share_step_lock')

        for lost_ack in (False,True):
            reset(w,e)
            admin.move_to_reason(w,e,'credit',25)
            out=admin.execute(w,e,text=PRIVATE_REASON,update_id=920,
                after_commit_delta=777,commit_ack_lost=lost_ack)
            admin.assert_state(w,e,902,1)
            assert admin.journal(w,e,920)=='9001:1001:credit:25:100:125'
            check_notice(out,'credit',25,100,125,920)
            cases.append('journal_not_later_wallet_read_'+('lost_commit_ack' if lost_ack else 'confirmed_commit'))

        reset(w,e)
        _,buttons=prepare_debt(w,e)
        out=confirm(w,e,buttons[0],commit_ack_lost=True)
        check_notice(out,'debt',125,100,-25,901)
        admin.assert_state(w,e,-25,1)
        cases.append('debt_lost_commit_ack_reconciles_same_unique_journal')

        reset(w,e)
        _,buttons=prepare_debt(w,e)
        pending=admin.step(w,e)
        out=confirm(w,e,buttons[0],commit_rollback=True)
        no_effect(w,e,pending=pending)
        assert not success(out) and any('مشخص نیست' in m for m in admin.messages(out))
        cases.append('unconfirmed_commit_without_journal_remains_under_review')

        reset(w,e)
        admin.move_to_reason(w,e,'debit',125)
        out=admin.execute(w,e,text=PRIVATE_REASON,update_id=930,commit_ack_lost=True)
        no_effect(w,e)
        assert admin.step(w,e).startswith('achbotWalletDebt:')
        assert not success(out) and not consent_buttons(out)
        assert any('مشخص نیست' in m for m in admin.messages(out))
        cases.append('lost_debt_preview_commit_ack_never_claims_money_or_confirmation')

        reset(w,e)
        admin.move_to_reason(w,e,'debit',125)
        pending=admin.step(w,e)
        sql(w,e,"CREATE TRIGGER owned_debt_preview_failure BEFORE UPDATE ON users "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-preview-failure'")
        try:
            out=admin.execute(w,e,text=PRIVATE_REASON,update_id=931,mysqli_report_off=True)
            no_effect(w,e,pending=pending)
            assert not success(out) and not consent_buttons(out)
        finally:
            sql(w,e,'DROP TRIGGER owned_debt_preview_failure')
        cases.append('silent_preview_step_update_failure_rolls_back_without_consent')

    report=dict(complete=True,cases=len(cases),scenarios=cases,baseline=BASE,
        disposable_db=True,source_native_php=True,real_php_workers=2,
        production_mutations=0,external_provider_calls=0)
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))


if __name__=='__main__':
    main()
