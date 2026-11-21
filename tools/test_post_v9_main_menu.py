#!/usr/bin/env python3
"""Source-real main menu: exact pre-refactor JSON plus owner-approved layout.

All rendered values come from the live two-file catalog; the accepted prior
source is only a read-only Git reference. No production DB or Telegram.
"""
from __future__ import annotations

import base64
import itertools
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
BASE = "4e21e18a3ba50897abc6b393ef0c7f58db39bbf9"

PHP = r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) { throw new ErrorException($message,0,$level); });
$root=$argv[1];
$spec=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
require_once $root.'/src/Presentation/Text.php';
require_once $root.'/messages/loader.php';
$buttonValues=achbotMessages('legacy_buttons');
$adminSearchLabel=achbotMessage('admin_settings.config.get_main_keys.button.11b0188fcb');
$adminManageLabel=achbotMessage('admin_settings.config.get_main_keys.button.d9c51cced4');
$admin=9001;
$from_id=!empty($spec['admin'])?9001:2002;
$userInfo=['is_agent'=>array_key_exists('agent_status',$spec)?(int)$spec['agent_status']:(empty($spec['agent'])?0:1),
           'isAdmin'=>!empty($spec['admin'])||!empty($spec['secondary_admin']),
           'freetrial'=>!empty($spec['trial_eligible'])?'not_used':'used'];
$botState=['agencyState'=>'on','sellState'=>!empty($spec['sales_off'])?'off':'on',
           'testAccount'=>!empty($spec['trial_on'])?'on':'off',
           'sharedExistence'=>'off','individualExistence'=>'off',
           'searchState'=>!empty($spec['search_on'])?'on':'off','supportID'=>'',
           'cooperationUrl'=>!empty($spec['extra'])?'@fixture_partners':'',
           'helpChannel'=>'@fixture_help'];
$botState=array_replace($botState,$spec['state']??[]);
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
        $buttons=[
            ['id'=>7,'type'=>'MAIN_BUTTONSسؤال متداول'],
            ['id'=>8,'type'=>'MAIN_BUTTONSپیگیری سفارش'],
            ['id'=>9,'type'=>'MAIN_BUTTONSراهنمای پرداخت'],
        ];
        $count=$spec['custom_buttons']??(!empty($spec['extra'])?1:0);
        if (!is_int($count) || $count<0 || $count>count($buttons))
            throw new RuntimeException('invalid_fixture_button_count');
        return new Result(array_slice($buttons,0,$count));
    }
    public function close():void {}
}
class DB {
    public function prepare($query):Statement {
        $sql=str_replace(chr(96),'',trim($query));
        if (!preg_match("/\ASELECT\s+\*\s+FROM\s+setting\s+WHERE\s+type\s+LIKE\s+'%MAIN_BUTTONS%'\z/i",$sql))
            throw new RuntimeException('unexpected_custom_button_query');
        return new Statement();
    }
}
$connection=new DB();
$source=file_get_contents($root.'/config.php');
$start=strpos($source,'function getMainKeys(){');
$end=strpos($source,'function getAgentKeys(){',$start);
if ($start===false || $end===false) throw new RuntimeException('getMainKeys source missing');
$current=substr($source,$start,$end-$start);
// Mutations affect only the current function; the accepted Git baseline stays unchanged.
foreach ([
    'mutate_callback'=>["'callback_data'=>\"myInfo\"","'callback_data'=>\"myInfoBROKEN\""],
    'mutate_empty_row'=>["    return json_encode(['inline_keyboard' => \$mainKeys]);",
                          "    \$mainKeys[]=[];\n    return json_encode(['inline_keyboard' => \$mainKeys]);"],
    'mutate_grouping'=>['if(count($temp) >= 2)','if(count($temp) >= 3)'],
] as $probe=>$replacement) {
    if (empty($spec[$probe])) continue;
    if (substr_count($current,$replacement[0])!==1)
        throw new RuntimeException('mutation_target_missing:'.$probe);
    $current=str_replace($replacement[0],$replacement[1],$current);
}
eval($current);
$prior=base64_decode($argv[3],true);
if ($prior===false) throw new RuntimeException('missing_prior_source');
eval($prior);
$rows=json_decode(getMainKeys(),true,512,JSON_THROW_ON_ERROR)['inline_keyboard'];
$previous=json_decode(getMainKeysBaseline(),true,512,JSON_THROW_ON_ERROR)['inline_keyboard'];
// Compare complete Telegram keyboard structure, including empty rows.
// Existing pinned visual checks separately inspect only the nonempty rows.
if ($rows!==$previous)
    throw new RuntimeException('main_menu_wire_drift:'.json_encode(['prior'=>$previous,'now'=>$rows],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$visibleRows=array_values(array_filter($rows,static fn($row)=>!empty($row)));
echo json_encode(['rows'=>$visibleRows,'labels'=>[
    'my_subscriptions'=>$buttonValues['my_subscriptions'],
    'invite_friends'=>$buttonValues['invite_friends'],
    'my_info'=>$buttonValues['my_info'],
    'application_links'=>$buttonValues['application_links'],
    'sharj'=>$buttonValues['sharj'],
    'buy_subscriptions'=>$buttonValues['buy_subscriptions'],
    'request_agency'=>$buttonValues['request_agency'],
    'test_account'=>$buttonValues['test_account'],
    'search_config'=>$buttonValues['search_config'],
    'search_agent_config'=>$buttonValues['search_agent_config'],
    'admin_search'=>$adminSearchLabel,
    'admin_manage'=>$adminManageLabel,
]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def check(root: Path = ROOT) -> dict:
    cases=0
    prior_source=subprocess.check_output(
        ['git','show',BASE+':config.php'],cwd=root,text=True)
    start=prior_source.index('function getMainKeys(){')
    end=prior_source.index('function getAgentKeys(){',start)
    prior_function=prior_source[start:end].replace(
        'function getMainKeys(){','function getMainKeysBaseline(){',1)
    prior_arg=base64.b64encode(prior_function.encode()).decode('ascii')
    with tempfile.TemporaryDirectory(prefix='achbot-main-menu-') as temp:
        driver=Path(temp)/'menu.php'
        driver.write_text(PHP)
        def execute(**kwargs):
            result=subprocess.run(
                ['php',str(driver),str(root),json.dumps(kwargs),prior_arg],
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
        support=[{'text':'📚 کانال آموزش و راهنما','url':'https://t.me/fixture_help'}]
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
        assert [(b.get('callback_data'),b['text']) for b in admin_rows[4]]==[
            ('showUUIDLeft','➕ افزودن اشتراک'),('searchUsersConfig','🔍 جستجوی اشتراک')
        ],admin_rows
        assert [(b.get('callback_data'),b['text']) for b in admin_rows[5]]==[
            ('managePanel','⚙️ مدیریت ربات')],admin_rows
        assert admin_rows[-1]==support,admin_rows
        assert not any(b.get('callback_data') in ('inviteFriends','reciveApplications')
                       for row in admin_rows for b in row),admin_rows
        cases+=1

        # The ordinary customer keeps education and gets the shared add-label
        # only when the existing searchState gate allows the import action.
        ordinary_search=execute(search_on=True)
        ordinary_rows=ordinary_search['rows']
        assert [b.get('callback_data') for b in ordinary_rows[3]]==[
            'inviteFriends','reciveApplications'],ordinary_rows
        assert [(b.get('callback_data'),b['text']) for b in ordinary_rows[-2]]==[
            ('showUUIDLeft','➕ افزودن اشتراک')],ordinary_rows
        assert ordinary_rows[-1]==support,ordinary_rows
        cases+=1

        # The optional add-subscription action precedes the configured
        # cooperation/support URL, not the other way around.  Check both
        # its enabled and disabled states, with the independent help link
        # and a custom button present as in real tenant menus.
        for label,options,expected_enabled in [
            ('customer_enabled',{'search_on':True,'extra':True},True),
            ('customer_disabled',{'extra':True},False),
            ('agent_enabled',{'agent':True,'search_on':True,'extra':True},True),
            ('agent_disabled',{'agent':True,'extra':True},False),
        ]:
            current=execute(**options)['rows']
            cooperation=[i for i,row in enumerate(current)
                         if any(b.get('url')=='https://t.me/fixture_partners' for b in row)]
            imports=[i for i,row in enumerate(current)
                     if any(b.get('callback_data')=='showUUIDLeft' for b in row)]
            assert len(cooperation)==1,(label,current)
            assert imports==([cooperation[0]-1] if expected_enabled else []),(label,current)
            assert current[-1]==support,(label,current)
            assert [b['callback_data'] for row in current for b in row
                    if b.get('callback_data')=='reciveApplications']==['reciveApplications'],(label,current)
            cases+=1

        # Owner's own account may be neither ordinary (0) nor agent (1);
        # all four current staff levels share the same main-menu pairing.
        for name,options in [
            ('owner_nonagent',{'admin':True,'agent_status':-1}),
            ('secondary_admin',{'secondary_admin':True}),
            ('owner_agent',{'admin':True,'agent':True}),
            ('owner_custom_button',{'admin':True,'extra':True}),
            ('staff_sales_disabled',{'secondary_admin':True,'sales_off':True}),
        ]:
            staff=execute(**options)
            srows=staff['rows']
            pairs=[row for row in srows
                   if any(b.get('callback_data')=='searchUsersConfig' for b in row)]
            assert len(pairs)==1,(name,srows)
            assert [(b.get('callback_data'),b['text']) for b in pairs[0]]==[
                ('showUUIDLeft','➕ افزودن اشتراک'),('searchUsersConfig','🔍 جستجوی اشتراک')
            ],(name,srows)
            assert sum(b.get('callback_data')=='showUUIDLeft' for row in srows for b in row)==1
            assert not any(b.get('callback_data') in ('reciveApplications','inviteFriends')
                           for row in srows for b in row),(name,srows)
            assert srows[-1]==support,(name,srows)
            assert [b.get('callback_data') for b in srows[-2]]==['managePanel'],(name,srows)
            cases+=1

        # The search label also belongs to the agent's subscriptions
        # keyboard; this is intentionally not a new admin capability.
        assert labels['search_agent_config']=='🔍 جستجوی اشتراک'
        assert labels['search_config']=='➕ افزودن اشتراک'
        assert labels['admin_search']=='🔍 جستجوی اشتراک'
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
    assert 'تمدید یا مشاهده اشتراک ها' not in user_source+admin_source
    assert 'کسب درآمد با معرفی' not in user_source+admin_source
    assert labels['my_subscriptions']=='🔄 مشاهده اشتراک‌ها'
    assert labels['invite_friends']=='🧲 کسب درآمد'

    # Independently compare the actual wire JSON against the accepted
    # pre-refactor implementation across 32 role/feature combinations and
    # nine additional tenant/administrative variations (including three
    # custom buttons). This detects raw rows, callbacks, grouping, wording
    # and channel destinations without
    # introducing an artificial translation map.
    source_parity_cases=0
    with tempfile.TemporaryDirectory(prefix='achbot-main-menu-matrix-') as tmp:
        runner=Path(tmp)/'menu.php'
        runner.write_text(PHP)
        def verify(spec: dict, *, negative: bool = False) -> bool:
            proc=subprocess.run(
                ['php',str(runner),str(root),json.dumps(spec),prior_arg],
                capture_output=True,text=True,timeout=10)
            if negative:
                return proc.returncode != 0 and 'main_menu_wire_drift' in proc.stderr
            if proc.returncode:
                raise AssertionError('menu_matrix_mismatch:'+repr(spec)+':'+
                                     proc.stderr[-1500:])
            return json.loads(proc.stdout)
        for agent,admin,trial,search,sales in itertools.product((False,True),repeat=5):
            verify({'agent':agent,'admin':admin,'trial_on':trial,
                    'trial_eligible':trial,'search_on':search,'sales_off':sales})
            source_parity_cases+=1
        for extra in [
            {'extra':True},
            {'extra':True,'secondary_admin':True},
            {'state':{'agencyState':'off'}},
            {'state':{'individualExistence':'on','sharedExistence':'on'}},
            {'state':{'helpChannel':''}},
            {'state':{'cooperationUrl':'@alternative','helpChannel':'@alternate_help'}},
            {'admin':True,'agent_status':-1},
            {'agent_status':2,'extra':True},
        ]:
            verify(extra)
            source_parity_cases+=1
        multiple=verify({'custom_buttons':3})
        source_parity_cases+=1
        custom_rows=[
            [button for button in row
             if button.get('callback_data','').startswith('showMainButtonAns')]
            for row in multiple['rows']
        ]
        custom_rows=[row for row in custom_rows if row]
        assert custom_rows==[
            [
                {'text':'سؤال متداول','callback_data':'showMainButtonAns7'},
                {'text':'پیگیری سفارش','callback_data':'showMainButtonAns8'},
            ],
            [{'text':'راهنمای پرداخت','callback_data':'showMainButtonAns9'}],
        ],custom_rows
        assert source_parity_cases==41
        assert verify({'mutate_callback':True},negative=True), (
            'menu_callback_mutation_not_detected')
        assert verify({'mutate_empty_row':True},negative=True), (
            'menu_empty_row_mutation_not_detected')
        assert verify({'custom_buttons':3,'mutate_grouping':True},negative=True), (
            'menu_grouping_mutation_not_detected')

    return {'complete':True,'status':'PASS','verified':True,
            'real_menu_scenarios':cases,
            'source_real_before_after_menu_parity_cases':source_parity_cases,
            'negative_mutated_callback_caught':True,
            'negative_added_empty_row_caught':True,
            'negative_custom_grouping_caught':True,
            'copy_reference_checks':len(references),
            'original_callbacks_and_feature_gates_preserved':True,
            'no_network_or_customer_data':True}

if __name__=='__main__':
    print(json.dumps(check(),ensure_ascii=False))
