#!/usr/bin/env python3
"""Source-real Persian/readability and display-help transport regression for Owner R5."""
from __future__ import annotations
import argparse,copy,html,json,pathlib,re,subprocess
from html.parser import HTMLParser
import catalog_owner_r5 as r5
import test_owner_r5_compositions as compositions
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
    ap=argparse.ArgumentParser();ap.add_argument('--composition-source-root',type=pathlib.Path,default=ROOT,help='Read-only current source for the composed-message matrix; catalogs stay in this checkout.')
    args=ap.parse_args()
    sides=current();old,counts=r5.verify_and_restore(sides);merged={**sides['user'],**sides['admin']}
    delivery_controls=r5.delivery_recovery_copy_negative_controls(sides)
    check(delivery_controls==4,'delivery_recovery_copy_negative_controls_missing')
    removal_controls=r5.server_removal_copy_negative_controls(sides)
    check(removal_controls==4,'server_removal_unsafe_copy_negative_control_missing')
    controls=r5.negative_controls(sides)
    gift_controls=r5.gift_catalog_negative_controls(sides)
    # Two specific RC25 approved tracking-hash buttons now deliberately use
    # the compact ID symbol; numeric-user-ID headers remain prohibited.
    rc25_hash_buttons={
      'manual_payment.receipt_helpers.build_admin_keyboard_with_stats.hash_id.286c7d3faf':'🆔 {hashId}',
      'manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604':'🆔 {hash_id}'
    }
    # The new automatic-gateway detail shows an actual @handle, never a
    # numeric Telegram/customer ID. This exact legacy label is distinct
    # from the separately labelled numeric customer ID in the new inbox.
    handle_key='automatic_gateway_review.handle'
    handle_label='🔖 شناسه تلگرام: @{handle}'
    check(r5.text(merged.get(handle_key,''))==handle_label and
          all(r5.text(merged.get(k,''))==v for k,v in rc25_hash_buttons.items()) and
          {k for k,e in merged.items() if '🆔' in r5.text(e)}==set(rc25_hash_buttons) and
          not any(compositions.numeric_id_variant(r5.text(e))
                  for k,e in merged.items() if k!=handle_key),
          'approved_tracking_hash_buttons_only_no_numeric_user_id_header')
    # Data-only code/URI/native labels and continuation fragments stay intact.
    # These four intentionally stable units are composed inside Persian sentences.
    expected_fragments=['notifications.warnings.send.1e5349d9b2','notifications.warnings.send.0458ad0612','jobs.gift2all.gift_text.8f714dee13','jobs.gift2all.text.b70363d685']
    check(all(merged[k]=={**old['user'],**old['admin']}[k]for k in expected_fragments),'stable_unit_fragment_changed')
    # Owner-approved quantity-first labels intentionally begin with {title},
    # which resolves to ASCII digits (e.g. 10 گیگ). Admit only these exact
    # customer templates, not other arbitrary non-Persian-starting prose.
    quantity_first={
      'renewal.extensions.button.fbb9f61105': '{title} روز ← {price}',
      'renewal.extensions.button.249377f2b7': '{title} گیگ ← {price}',
    }
    check(all(r5.text(merged[k])==value for k,value in quantity_first.items()),
          'owner_addon_quantity_first_templates_must_match_exact_copy')
    line_exceptions={
      *quantity_first,
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
    # Global raw-template scan; separately test bounded actual compositions.
    # Repeated identical field labels are deliberate in sender/recipient sections.
    duplicate_markers=[(key,problem)for key,entry in merged.items()
                       for problem in compositions.heading_collisions(r5.text(entry))]
    # RC25 explicitly gives Toman and Rial the same currency glyph, but
    # their distinct full labels must both remain; every other collision
    # remains a regression.
    expected_shared_currency={
       ('legacy_messages.brw_cart_to_cart_detail',
        ('💵','مبلغ به تومان','مبلغ به ریال')),
       ('payment.api_payment.bank_invoice',
        ('💵','مبلغ به تومان','مبلغ به ریال'))
    }
    check(set(duplicate_markers)==expected_shared_currency and
          len(duplicate_markers)==2 and
          all('💵 مبلغ به تومان' in r5.text(merged[k]) and
              '💵 مبلغ به ریال' in r5.text(merged[k])
              for k,_ in expected_shared_currency),
          'duplicate_heading_marker_outside_two_RC25_currency_templates:'+str(duplicate_markers))
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
        first=json.loads(parser.blocks[0]);check(first=={'mode':'native','policy':[]},'native_example_semantics')
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
    composed=compositions.audit(args.composition_source_root,ROOT)
    print(json.dumps({'status':'PASS',**counts,**composed,'delivery_recovery_copy_negative_controls':delivery_controls,'server_removal_copy_negative_controls':removal_controls,'r5_negative_controls':controls,'gift_catalog_negative_controls':gift_controls,'persian_prose_start_violations':0,'duplicate_heading_markers':0,'preserved_unit_fragments':len(expected_fragments),'guide_provider_cases':2,'guide_JSON_examples':blocks,'actual_HTML_transport_cases':2,'archived_wrong_parse_RED':True,'receipt_PRICE_template_cases':6,'real_Telegram_rendering':'NOT_TESTED'},ensure_ascii=False))
if __name__=='__main__':main()
