#!/usr/bin/env python3
"""Exercise shipping getMainKeys with safe fixture state and the real text catalogs."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]

PHP = r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) { throw new ErrorException($message,0,$level); });
$root=$argv[1];
$spec=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
require_once $root.'/src/Presentation/Text.php';
$catalog=require $root.'/messages/fa/user.php';
$buttonValues=$catalog['legacy_buttons'];
$admin=9001;
$from_id=(int)($spec['admin']??false?9001:2002);
$userInfo=['is_agent'=>($spec['agent']??false)?1:0,
           'isAdmin'=>($spec['admin']??false),
           'freetrial'=>!empty($spec['trial_eligible'])?'not_used':'used'];
$botState=['agencyState'=>'on','sellState'=>'on',
           'testAccount'=>!empty($spec['trial_on'])?'on':'off',
           'sharedExistence'=>'off','individualExistence'=>'off',
           'searchState'=>'off','supportID'=>'',
           'cooperationUrl'=>!empty($spec['extra'])?'@fixture_partners':'',
           'helpChannel'=>'@fixture_help'];
class Result {
    public int $num_rows;
    private int $index=0;
    public function __construct(private array $rows){$this->num_rows=count($rows);}
    public function fetch_assoc(){return $this->rows[$this->index++]??null;}
}
class Statement {
    public function execute():void {}
    public function get_result():Result {
        global $spec;
        return new Result(!empty($spec['extra'])?
            [['id'=>7,'type'=>'MAIN_BUTTONSسؤال متداول']]:[]);
    }
    public function close():void {}
}
class DB {public function prepare($query):Statement {return new Statement();}}
$connection=new DB();
function achbotMessage($key) {
    return match($key) {
        'instance_content.help_button'=>'💬 پشتیبانی',
        'admin_settings.config.get_main_keys.button.b7d6aa7173'=>'درخواست خارجی',
        'admin_settings.config.get_main_keys.button.11b0188fcb'=>'ادمین اول',
        'admin_settings.config.get_main_keys.button.d9c51cced4'=>'ادمین دوم',
        default=>throw new RuntimeException('unexpected catalog reference:'.$key),
    };
}
$source=file_get_contents($root.'/config.php');
$start=strpos($source,'function getMainKeys(){');
$end=strpos($source,'function getAgentKeys(){',$start);
if ($start===false || $end===false) throw new RuntimeException('getMainKeys source missing');
eval(substr($source,$start,$end-$start));
$rows=json_decode(getMainKeys(),true,512,JSON_THROW_ON_ERROR)['inline_keyboard'];
$rows=array_values(array_filter($rows,static fn($row)=>!empty($row)));
echo json_encode(['rows'=>$rows,'labels'=>[
    'my_subscriptions'=>$buttonValues['my_subscriptions'],
    'invite_friends'=>$buttonValues['invite_friends'],
    'my_info'=>$buttonValues['my_info'],
    'application_links'=>$buttonValues['application_links'],
    'sharj'=>$buttonValues['sharj'],
    'buy_subscriptions'=>$buttonValues['buy_subscriptions'],
    'request_agency'=>$buttonValues['request_agency'],
    'test_account'=>$buttonValues['test_account'],
]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def check(root: Path = ROOT) -> dict:
    cases=0
    with tempfile.TemporaryDirectory(prefix='achbot-main-menu-') as temp:
        driver=Path(temp)/'menu.php'
        driver.write_text(PHP)
        def execute(**kwargs):
            result=subprocess.run(
                ['php',str(driver),str(root),json.dumps(kwargs)],
                capture_output=True,text=True,timeout=10)
            if result.returncode:
                raise RuntimeError('Main menu fixture: '+repr(result.stderr[-1800:]))
            return json.loads(result.stdout)

        regular=execute()
        labels=regular['labels']
        expected=[
            [('requestAgency','✚ درخواست همکاری ✚')],
            [('increaseMyWallet','💳 شارژ کیف پول'),('buySubscription','🛍 خرید اشتراک جدید')],
            [('myInfo','🔐 حساب کاربری'),('mySubscriptions','🔄 مشاهده اشتراک‌ها')],
            [('inviteFriends','🧲 کسب درآمد'),('reciveApplications','📚 آموزش و قوانین')],
        ]
        support=[{'text':'💬 پشتیبانی','url':'https://t.me/fixture_help'}]
        free_trial=[{'text':labels['test_account'],'callback_data':'getTestAccount'}]

        # Updated Owner decision: when eligible, the unchanged trial row
        # precedes account/view, while it remains completely absent when
        # disabled or already used. The next two rows retain their order.
        for label,options,eligible in [
            ('trial_off_used',{},False),
            ('trial_off_eligible',{'trial_eligible':True},False),
            ('trial_on_eligible',{'trial_on':True,'trial_eligible':True},True),
            ('trial_on_used',{'trial_on':True},False),
        ]:
            rows=regular['rows'] if not options else execute(**options)['rows']
            ordered_rows=([expected[0],expected[1],
                           [('getTestAccount',labels['test_account'])],
                           expected[2],expected[3]] if eligible else expected)
            assert [[(b.get('callback_data'),b['text']) for b in row]
                    for row in rows[:len(ordered_rows)]]==ordered_rows,(label,rows)
            assert len(rows)==5+int(eligible),(label,rows)
            assert rows[-1]==support,(label,rows)
            trial_rows=[row for row in rows
                        if any(b.get('callback_data')=='getTestAccount' for b in row)]
            assert trial_rows==([free_trial] if eligible else []),(label,rows)
            if eligible:
                assert rows[2]==free_trial,(label,rows)
            cases+=1

        # Agent and admin eligibility is unchanged. Whenever the trial row
        # exists, it also appears before their account/subscription row.
        agent=execute(agent=True,trial_on=True,trial_eligible=True)
        agent_rows=agent['rows']
        assert [b.get('callback_data') for b in agent_rows[0]]==['agencySettings']
        assert [b.get('callback_data') for b in agent_rows[2]]==['agentOneBuy','agentMuchBuy']
        assert agent_rows[3]==free_trial,agent_rows
        assert [b.get('callback_data') for b in agent_rows[4]]==['myInfo','agentConfigsList']
        assert [b.get('callback_data') for b in agent_rows[5]]==['inviteFriends','reciveApplications']
        assert agent_rows[-1]==support,agent_rows
        cases+=1

        # Existing administrator exception: free trial remains available
        # despite freetrial='used', and referral remains unavailable.
        admin=execute(admin=True,trial_on=True)
        admin_rows=admin['rows']
        assert admin_rows[2]==free_trial,admin_rows
        assert [b.get('callback_data') for b in admin_rows[3]]==['myInfo','mySubscriptions']
        assert [b.get('callback_data') for b in admin_rows[4]]==['reciveApplications']
        assert admin_rows[-1]==support,admin_rows
        assert not any(b.get('callback_data')=='inviteFriends'
                       for row in admin_rows for b in row),admin_rows
        cases+=1

        extra=execute(extra=True,trial_on=True,trial_eligible=True)
        erows=extra['rows']
        assert erows[:2]==regular['rows'][:2],erows
        assert erows[2]==free_trial and erows[-1]==support,erows
        assert [b.get('callback_data') for b in erows[3]]==['myInfo','mySubscriptions'],erows
        assert [b.get('callback_data') for b in erows[4]]==['inviteFriends','reciveApplications'],erows
        assert any(b.get('url')=='https://t.me/fixture_partners'
                   for row in erows for b in row),erows
        assert any(b.get('callback_data')=='showMainButtonAns7'
                   for row in erows for b in row),erows
        cases+=1

    # Match the actual menu's label (not an old alias) in every identified
    # instruction that directs the user to this specific menu button.
    user_source=(root/'messages/fa/user.php').read_text()
    admin_source=(root/'messages/fa/admin.php').read_text()
    references=(
        'manual_receipts.message.f6331b33af',
        'gateway_return.perform_payment_effect.message.dd866497c7',
        'warnings.msg.804056ecc4',
        'external_accounts.extract_username_from_link.message.b2f5ea228c',
        'manual_accounts.import_completed',
        'presentation.present.text.d60293ba07',
    )
    for key in references:
        line=next((line for line in user_source.splitlines() if "'"+key+"'" in line),None)
        assert line is not None and 'مشاهده اشتراک‌ها' in line,key
    admin_reference='manual_accounts.detect_server_id_from_remark.msg.10aa1e12ec'
    assert any(admin_reference in line and 'مشاهده اشتراک‌ها' in line
               for line in admin_source.splitlines())
    assert 'تمدید یا مشاهده اشتراک ها' not in user_source+admin_source
    assert 'کسب درآمد با معرفی' not in user_source+admin_source
    assert labels['my_subscriptions']=='🔄 مشاهده اشتراک‌ها'
    assert labels['invite_friends']=='🧲 کسب درآمد'

    # The historical copy proofs must see only a validated backward projection
    # of the ten Owner-approved changed entries, never a loosened baseline.
    import catalog_owner_r5 as history
    sources=history.menu_20261113_source()
    assert {a:len(item['changed']) for a,item in sources.items()}=={'user':9,'admin':1}
    catalog=subprocess.run(
        ['php','-r', '$r=[];foreach(["user","admin"]as$a)$r[$a]=require '
         '"messages/fa/".$a.".php";echo json_encode($r,JSON_UNESCAPED_UNICODE|'
         'JSON_THROW_ON_ERROR);'],
        cwd=root,capture_output=True,text=True,check=True,timeout=10)
    current={a:history.flatten(groups) for a,groups in json.loads(catalog.stdout).items()}
    projected=history.menu_20261113_copy_restore(current)
    for audience,item in sources.items():
        for key in item['changed']:
            assert current[audience][key]==item['after'][key]
            assert projected[audience][key]==item['before'][key]
    for audience,key in [('user','legacy_buttons.my_subscriptions'),
                         ('user','manual_payment.manual_receipts.message.f6331b33af'),
                         ('admin','subscription.manual_accounts.detect_server_id_from_remark.msg.10aa1e12ec')]:
        changed=copy.deepcopy(current)
        changed[audience][key]='not approved'
        try:history.menu_20261113_copy_restore(changed)
        except AssertionError:pass
        else:raise AssertionError('menu copy allowed unapproved mutation: '+key)

    return {'verified':True,'real_menu_scenarios':cases,
            'approved_catalog_deltas':10,'catalog_negative_controls':3,
            'copy_reference_checks':len(references)+1,
            'original_callbacks_and_feature_gates_preserved':True,
            'no_network_or_customer_data':True}

if __name__=='__main__':
    print(json.dumps(check(),ensure_ascii=False))
