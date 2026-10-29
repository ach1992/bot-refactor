#!/usr/bin/env python3
"""Source-real destination sinks/transport; SELECT-only disposable MariaDB, no network."""
from __future__ import annotations
import argparse,hashlib,json,pathlib,re,subprocess
from html.parser import HTMLParser
import test_access_state as owned
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='a070a47c5796e8244a3fe7fbca21e301977c3dcf'
DB=owned.NAMES[1]
FILES=['messages/loader.php','messages/fa/user.php','messages/fa/admin.php',
       'src/Presentation/Text.php','src/Admin/InstanceContent.php','src/Admin/Access.php',
       'src/Telegram/Messages.php','src/Telegram/Markup.php','src/Telegram/Response.php']
DRIVER=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message){if($level===E_DEPRECATED)return true;throw new ErrorException($message,0,$level);});
$root=$argv[1];$socket=$argv[2];$database=$argv[3];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','destination_reader','',$database,0,$socket);
$connection->set_charset('utf8mb4');$connection->query('START TRANSACTION READ ONLY');
$botState=$spec['state'];$before=$botState;$from_id=1001;$admin=9001;$message_id=77;$callbackId='synthetic';
$userId=$user_id=$applicant=1001;$price=$amount=245000;$cancelKey='CANCEL';$txt='حساب آزمایشی';
$events=[];$data='reciveApplications';$text='/start';$joinLink='https://t.me/+LEGACY_SYNTHETIC';
function bot($method,$data=[]){global $events;$events[]=['method'=>$method,'payload'=>$data];return(object)['ok'=>true,'result'=>(object)['message_id'=>77]];}
require $root.'/messages/loader.php';require $root.'/facades.php';
$mainValues=achbotMessages('legacy_messages');$buttonValues=achbotMessages('legacy_buttons');
if(isset($spec['join_template']))$mainValues['join_channel_message']=$spec['join_template'];
require $root.'/initialization.php';require $root.'/src/Admin/Access.php';
$userInfo=$connection->query('SELECT * FROM users WHERE userid=1001')->fetch_assoc();
if($spec['sink']==='instance'){
    require $root.'/main-keys.php';
    sendMessage($mainValues['start_message'],getMainKeys(),'HTML');
}else require $root.'/sink-'.$spec['sink'].'.php';
$legacy=\AchBot\Presentation\Text\replacePlaceholders(['sample'=>'SUPPORTID LOCKCHANNEL userid',
    'nested'=>['sample'=>'userid SUPPORTID']],$before,'42');
$instance=achbotInstanceMessage($before,'start_message',$from_id);
$connection->rollback();
echo json_encode(['events'=>$events,'state'=>$botState,'support_global'=>$supportID,
    'channel_global'=>$channelLock,'legacy_compat'=>$legacy,'instance'=>$instance],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
"""
class VisibleHTML(HTMLParser):
    def __init__(self):super().__init__(convert_charrefs=True);self.parts=[]
    def handle_data(self,data):self.parts.append(data)
def visible_html(value):
    p=VisibleHTML();p.feed(value);return ''.join(p.parts)
def command(*args):return subprocess.check_output(args,cwd=ROOT)
def function_source(source,name):
    start=source.index('function '+name+'(')
    return source[start:source.index('\nfunction ',start+1)]
def line_source(source,needle,start=''):
    rows=[line.strip() for line in source.splitlines() if needle in line and (not start or start in line)]
    assert len(rows)==1,(needle,len(rows));return rows[0]
def fragments(sources):
    cfg=sources['config.php'];bot=sources['bot.php']
    init=line_source(cfg,"$supportID = $botState['supportID'];")+'\n'+line_source(cfg,"$channelLock = $botState['lockChannel'];")+'\n'
    a=cfg.index('function replaceAllPlaceholdersInMainValues(');b=cfg.index('\nfunction getSupportUrl(',a)
    init+=cfg[a:b]
    facades='\n'.join(function_source(cfg,name) for name in ['sendMessage','editText','alert'])
    a=bot.index("if($data == 'reciveApplications') {");b=bot.index("if ($text == $buttonValues['cancel'])",a);app=bot[a:b]
    a=bot.index('        $joinUrl = ');b=bot.index('\n        exit;',a);join=bot[a:b]
    plain=line_source(sources['src/Agent/Applications.php'],"sendMessage($mainValues['agency_request_declined']")
    alert="alert($mainValues['agency_request_declined']);";assert alert in sources['src/Agent/Applications.php']
    channel=line_source(sources['src/Plans/Administration.php'],'plans.administration.edit_custom_plan.channel_lock.5d72dee564')
    following=sources['src/Plans/Administration.php'].split(channel,1)[1].splitlines()
    channel+='\n'+next(line.strip() for line in following if 'sendMessage($txt,'in line)
    return {'initialization':init,'facades':facades,'sink-app':app,'sink-join':join,
        'sink-banned':line_source(bot,"sendMessage($mainValues['banned'])"),
        'sink-import':line_source(sources['src/Subscription/ExternalAccounts.php'],"sendMessage($mainValues['send_config_uuid']"),
        'sink-plain':plain,'sink-alert':alert,
        'sink-manual':line_source(sources['src/Finance/Routes/ManualReceipts.php'],'manual_payment.manual_receipts.message.f6331b33af','sendMessage'),
        'sink-gateway':line_source(sources['src/Finance/GatewayReturn.php'],"$supportID = $botState['supportID'] ?? '';")+'\n'+line_source(sources['src/Finance/GatewayReturn.php'],'gateway.gateway_return.perform_payment_effect.message.dd866497c7','sendMessage'),
        'sink-channel':channel,'main-keys':function_source(cfg,'getMainKeys')}
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=pathlib.Path);args=parser.parse_args();checks=[]
    def check(ok,label):assert ok,label;checks.append(label)
    paths=['config.php','bot.php','src/Agent/Applications.php','src/Plans/Administration.php',
        'src/Subscription/ExternalAccounts.php','src/Finance/Routes/ManualReceipts.php','src/Finance/GatewayReturn.php']
    current={p:(ROOT/p).read_text()for p in paths}
    archived={p:command('git','show',BASE+':'+p).decode()for p in paths}
    parts={'current':fragments(current),'archived':fragments(archived)}
    for name,key in [('sink-manual','manual_payment.manual_receipts.message.f6331b33af'),
                     ('sink-gateway','gateway.gateway_return.perform_payment_effect.message.dd866497c7')]:
        check(parts['current'][name].replace("\\achbotMessageHtml('"+key,"\\achbotMessage('"+key)==parts['archived'][name],name+'_only_destination_encoding_differs')
    membership="$channelLock = $botState['lockChannel'];\n$joniedState= bot('getChatMember', ['chat_id' => $channelLock,'user_id' => $from_id])->result->status;"
    check(membership in current['config.php'] and membership in archived['config.php'],'membership_query_and_raw_chat_id_unchanged')
    fragment_hashes={label:{key:hashlib.sha256(value.encode()).hexdigest()for key,value in selected.items()}for label,selected in parts.items()}
    with owned.owned_database()as(work,env):
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(query):
            p=subprocess.run(client,input=query.encode(),env=env,capture_output=True,check=True);return p.stdout.decode().strip()
        download='https://download.example/app?literal=&lt;&a=1&b=2'
        sql("INSERT INTO needed_sofwares(id,title,link,status)VALUES(801,'راهنمای آزمایشی','"+download+"',1);")
        sql("INSERT INTO setting(type,value)VALUES('BOT_STATES','{\"walletState\":\"on\"}'),('PAYMENT_KEYS','{\"nowpayment\":\"SYNTHETIC_ONLY\"}');")
        sql("CREATE USER 'destination_reader'@'localhost'; GRANT SELECT ON "+DB+".* TO 'destination_reader'@'localhost';")
        snapshot="SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT type,value FROM setting ORDER BY id;SELECT * FROM orders_list ORDER BY id;"
        before=sql(snapshot);roots={}
        for label,selected in parts.items():
            root=work/label;root.mkdir();roots[label]=root
            for path in FILES:
                target=root/path;target.parent.mkdir(parents=True,exist_ok=True)
                target.write_bytes((ROOT/path).read_bytes()if label=='current'else command('git','show',BASE+':'+path))
            for name,body in selected.items():(root/(name+'.php')).write_text('<?php\n'+body+'\n')
            (root/'driver.php').write_text(DRIVER)
        defaults={key:'off'for key in re.findall(r"\$botState\['([^']+)'\]",parts['current']['main-keys'])}
        defaults.update({'lockChannel':'@channel_name','cooperationUrl':'','helpChannel':'',
            'lockChannelJoinUrl':'https://t.me/+INVITE_SYNTHETIC?literal=&lt;&a=1&b=2',
            'instanceMessages':{'start_message':{'format':'html','text':'<b>متن فروشگاه</b> SUPPORTID userid'}}})
        def invoke(label,sink,state,**options):
            root=roots[label];cmd=['php','-d','display_errors=0','-d','log_errors=1','-d',f'error_log={work}/{label}.log',
                '-d','allow_url_fopen=0','-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail,fsockopen,stream_socket_client',
                str(root/'driver.php'),str(root),str(work/'mysql.sock'),DB,json.dumps({'sink':sink,'state':state,**options})]
            p=subprocess.run(cmd,env=env,capture_output=True,timeout=15)
            assert p.returncode==0 and not p.stderr,(label,sink,p.returncode,p.stdout[-600:],p.stderr,(work/(label+'.log')).read_text()[-1400:]if(work/(label+'.log')).exists()else'')
            return json.loads(p.stdout)
        destinations=['@support_name','https://t.me/public_help?start=site&ref=menu',
            'https://support.example/help?a=1&b=two',
            'https://support.example/help?copy=&copy;&less=&lt;&quote=&quot;&amp=&amp;',
            'https://support.example/userid?next=LOCKCHANNEL&less=&lt;&amp=&amp;',
            'https://support.example/help?q=a_b*[c]&end=done',
            'https://support.example/CHANNEL-ID/userid?next=LOCKCHANNEL&literal=&amp;']
        red=[]
        for index,destination in enumerate(destinations):
            state=dict(defaults,supportID=destination)
            for sink in ['app','join','banned','import','plain','alert','manual','gateway','channel','instance']:
                out=invoke('current',sink,state)
                check(out['state']==state and out['support_global']==destination and out['channel_global']==state['lockChannel'],f'{index}_{sink}_shared_raw_data_unchanged')
                check(len(out['events'])==1 and out['events'][0]['method'].lower()in['sendmessage','editmessagetext','answercallbackquery'],f'{index}_{sink}_only_transport')
                payload=out['events'][0]['payload'];text=payload['text'];mode=(payload.get('parse_mode')or'').lower()
                if sink in['app','join','manual','gateway','instance']:
                    check(mode=='html'and destination in visible_html(text),f'{index}_{sink}_html_roundtrip')
                    check(re.search(r'&(?!amp;|lt;|gt;|quot;|#[0-9]+;|#x[0-9a-fA-F]+;)',text)is None,f'{index}_{sink}_telegram_html_entities')
                elif sink in['banned','import']:
                    unescaped=re.sub(r'\\([_*'+chr(96)+r'\[])',r'\1',text)
                    check(mode=='markdown'and destination in unescaped,f'{index}_{sink}_markdown_roundtrip')
                elif sink in['plain','alert']:
                    check('parse_mode'not in payload and destination in text,f'{index}_{sink}_plain_bytes')
                else:check(mode=='html'and state['lockChannel']in visible_html(text),f'{index}_{sink}_channel_label')
                if sink in['app','join','instance']:
                    markup=payload['reply_markup'];keyboard=markup['inline_keyboard']if isinstance(markup,dict)else json.loads(markup)['inline_keyboard']
                    if sink=='app':check(keyboard[0][0]['url']==download,f'{index}_download_keyboard_raw_url')
                    elif sink=='join':check(keyboard[0][0]['url']==state['lockChannelJoinUrl'],f'{index}_join_keyboard_raw_url')
                    else:
                        urls=[b.get('url')for row in keyboard for b in row]
                        expected='https://t.me/'+destination[1:]if destination.startswith('@')else destination
                        check(expected in urls,f'{index}_cooperation_keyboard_raw_url')
                        old=invoke('archived',sink,state)
                        check(out['instance']==old['instance'],f'{index}_instance_renderer_unchanged')
                if index==4 and sink in['app','join','plain','manual','gateway']:
                    old=invoke('archived',sink,state);p=old['events'][0]['payload']
                    original=visible_html(p['text'])if(p.get('parse_mode')or'').lower()=='html'else p['text']
                    if destination not in original:red.append(sink)
            old=invoke('archived','plain',state);new=invoke('current','plain',state)
            check(old['legacy_compat']==new['legacy_compat'],f'{index}_three_argument_legacy_compatibility')
        state=dict(defaults,supportID='@support_name',lockChannel='https://t.me/channel?less=&lt;&amp=&amp;')
        out=invoke('current','channel',state);old=invoke('archived','channel',state)
        check(state['lockChannel']in visible_html(out['events'][0]['payload']['text']),'channel_html_roundtrip')
        check(state['lockChannel']not in visible_html(old['events'][0]['payload']['text']),'channel_archived_red')
        legacy_template='عضویت: <b>CHANNEL-ID</b>\nپشتیبانی: SUPPORTID\nشناسه: userid'
        literal='https://support.example/CHANNEL-ID/userid?next=LOCKCHANNEL&literal=&amp;'
        state=dict(defaults,supportID=literal,lockChannel='https://t.me/channel?literal=SUPPORTID&less=&lt;')
        current_join=invoke('current','join',state,join_template=legacy_template)
        old_join=invoke('archived','join',state,join_template=legacy_template)
        rendered=visible_html(current_join['events'][0]['payload']['text'])
        check(literal in rendered and state['lockChannel']in rendered and '1001'in rendered,'legacy_join_template_all_slots_one_pass')
        check(literal not in visible_html(old_join['events'][0]['payload']['text']),'literal_CHANNEL_ID_destination_archived_red')
        check(set(red)=={'app','join','plain','manual','gateway'},'archived_real_sinks_red')
        check(sql(snapshot)==before,'wallet_orders_payments_settings_unchanged')
    report={'result':'PASS','cases':len(checks),'checks':checks,'source_real':True,
        'actual_transport_payloads':True,'read_only_original_schema':True,'no_external_calls':True,
        'no_money_or_settings_mutation':True,'baseline_red':True,'baseline_red_source':BASE,
        'baseline_red_sinks':red+['channel'],'source_fragment_sha256':fragment_hashes}
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
