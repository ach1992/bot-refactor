#!/usr/bin/env python3
"""Source-real Persian/readability and display-help transport regression for Owner R5."""
from __future__ import annotations
import copy,html,json,pathlib,re,subprocess
from html.parser import HTMLParser
import catalog_owner_r5 as r5
ROOT=pathlib.Path(__file__).resolve().parents[1]
def check(ok,message):
    if not ok:raise AssertionError(message)
def command(*args,**kwargs):return subprocess.check_output(args,cwd=ROOT,**kwargs)
def current():
    raw=command('php','-r','$r=[];foreach(["user","admin"]as$a)$r[$a]=require "messages/fa/".$a.".php";echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);')
    return {a:r5.flatten(g)for a,g in json.loads(raw).items()}
class GuideParser(HTMLParser):
    def __init__(self):super().__init__(convert_charrefs=True);self.stack=[];self.blocks=[];self.buffer=[];self.in_pre=False
    def handle_starttag(self,tag,attrs):
        check(tag in ['b','code','pre'],'unsupported_guide_tag:'+tag);check(not attrs,'unexpected_guide_attributes')
        self.stack.append(tag)
        if tag=='pre':check(not self.in_pre,'nested_pre');self.in_pre=True;self.buffer=[]
    def handle_endtag(self,tag):
        check(self.stack and self.stack.pop()==tag,'unbalanced_guide_markup')
        if tag=='pre':self.blocks.append(''.join(self.buffer));self.in_pre=False
    def handle_data(self,data):
        if self.in_pre:self.buffer.append(data)
def main():
    sides=current();old,counts=r5.verify_and_restore(sides);merged={**sides['user'],**sides['admin']}
    controls=r5.negative_controls(sides)
    check(not any('🆔'in r5.text(e)or'شناسه تلگرام'in r5.text(e)for e in merged.values()),'obsolete_id_wording_or_marker')
    # Data-only code/URI/native labels and continuation fragments stay intact.
    # These four intentionally stable units are composed inside Persian sentences.
    expected_fragments=['notifications.warnings.send.1e5349d9b2','notifications.warnings.send.0458ad0612','jobs.gift2all.gift_text.8f714dee13','jobs.gift2all.text.b70363d685']
    check(all(merged[k]=={**old['user'],**old['admin']}[k]for k in expected_fragments),'stable_unit_fragment_changed')
    line_exceptions={
      'legacy_messages.config_details_message','subscription.presentation.config_message.text.2953f9a4ad',
      'subscription.buy_helpers.build_links_and_send_qr.acc_text.698f512161',
      'bot.bot.webhook_ingress_authenticated.message.312da09ba8',
      'plans.administration.edit_custom_plan.message.7b314b58c9',
      'plans.intervals.add_new_day_plan.message.3bf1033cb0',
      'plans.intervals.add_new_volume_plan.message.d36c49c30e',
      'subscription.sub_link_settings.message.9f2715dc7c',
      'broadcast.routes.broadcast_start.text.2af0046efa',
      *expected_fragments,
    }
    bad=[]
    for key,entry in merged.items():
        if key in line_exceptions:continue
        pre=False
        for line in r5.text(entry).splitlines():
            if '<pre>'in line:pre=True
            if not pre:
                value=re.sub('<[^>]+>','',line).strip();m=re.search('[A-Za-z0-9\u0600-\u06ff{]',value)
                if m and value[m.start()]in'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789{'and re.search('[\u0600-\u06ff]',value):bad.append(key+':'+line)
            if '</pre>'in line:pre=False
    check(not bad,'non_persian_prose_start:'+str(bad))
    duplicate_markers=[]
    for key,entry in merged.items():
        seen=set();pre=False
        for line in r5.text(entry).splitlines():
            if '<pre>'in line:pre=True
            if not pre:
                clean=re.sub(r'^\s*(?:<b>)?','',line);match=re.match(r'^(\S+)\s+',clean)
                if match and any(ord(c)>9000 for c in match[1]):
                    if match[1]in seen:duplicate_markers.append(key+':'+match[1])
                    seen.add(match[1])
            if '</pre>'in line:pre=False
    check(not duplicate_markers,'duplicate_heading_marker:'+str(duplicate_markers))
    native=r'''require 'src/Subscription/ServerDisplaySettings.php';require 'src/Telegram/Messages.php';
$reports=[];foreach(['marzban','pasarguard']as$provider){
 $guide=achbotServerDisplayGuide($provider);$calls=[];
 $request=static function($method,$payload)use(&$calls){$calls[]=[$method,$payload];return(object)['ok'=>true];};
 \AchBot\Telegram\Messages\sendMessage($request,1001,$guide,null,'HTML');
 if(count($calls)!==1||$calls[0][0]!=='sendMessage'||$calls[0][1]['parse_mode']!=='HTML'||$calls[0][1]['text']!==$guide)throw new RuntimeException('help_transport_changed');
 $reports[$provider]=['guide'=>$guide,'parse_mode'=>$calls[0][1]['parse_mode']];
}echo json_encode($reports,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'''
    guides=json.loads(command('php','-r',native));blocks=0
    for provider,item in guides.items():
        guide=item['guide'];check(len(guide)<4096,'guide_exceeds_message_limit')
        parser=GuideParser();parser.feed(guide);parser.close();check(not parser.stack,'unclosed_guide_markup')
        check(len(parser.blocks)==(2 if provider=='marzban'else 1),'guide_example_count')
        first=json.loads(parser.blocks[0]);check(first=={'mode':'native','policy':{'max_links':10}},'native_example_semantics')
        if provider=='marzban':
            second=json.loads(parser.blocks[1]);check(second['mode']=='marzban_legacy'and second['legacy']['showIndexes']==[2,4]and second['legacy']['linkIndices']==[0,2],'legacy_example_semantics')
        blocks+=len(parser.blocks)
    source=(ROOT/'src/Subscription/ServerDisplaySettings.php').read_text()
    fixed="if ($field==='document') sendMessage($prompt,$cancelKey,'HTML');"
    check(fixed in source and 'else sendMessage($prompt,$cancelKey);'in source,'guide_handler_not_html')
    original=command('git','show',r5.BASE+':src/Subscription/ServerDisplaySettings.php').decode()
    check(fixed not in original and 'sendMessage($prompt,$cancelKey);'in original,'archived_guide_RED_missing')
    # Exact receipt amount text keeps the existing PRICE token on one line.
    for name in ['buy_custom_account_request','buy_new_much_account_request','buy_new_account_request','renew_account_request_message','increase_account_request_message','increase_wallet_request_message']:
        value=r5.text(merged['legacy_messages.'+name])
        check(sum('PRICE'in l for l in value.splitlines())==1,'receipt_PRICE_line_drift:'+name)
        check('MANUAL-QUOTE'not in value,'new_receipt_parser_token')
    print(json.dumps({'status':'PASS',**counts,'r5_negative_controls':controls,'persian_prose_start_violations':0,'duplicate_heading_markers':0,'preserved_unit_fragments':len(expected_fragments),'guide_provider_cases':2,'guide_JSON_examples':blocks,'actual_HTML_transport_cases':2,'archived_wrong_parse_RED':True,'receipt_PRICE_template_cases':6,'real_Telegram_rendering':'NOT_TESTED'},ensure_ascii=False))
if __name__=='__main__':main()
