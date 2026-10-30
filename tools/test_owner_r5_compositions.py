#!/usr/bin/env python3
"""Source-linked native message expressions: distinct bot-owned heading icons.

The bounded matrix evaluates current PHP presentation expressions with synthetic
values and the actual loader. It does not dispatch routes, SQL or outbound calls.
Separate keyboard labels and mutually exclusive branches are separate surfaces;
identical heading labels may repeat within a rendered message.
"""
from __future__ import annotations
import argparse, copy, functools, html, json, pathlib, re, subprocess, tempfile, unicodedata
import catalog_owner_r5 as r5
ROOT=pathlib.Path(__file__).resolve().parents[1]
REFERENCE='9dab78bac830184818b5b0c899e530c9a2069c93'
CORRECTIONS={
    'wallet.wallet_transfer.wallet_transfer_restart.telegram_update_transfer_update_id.9ccad7d18e':('شناسه پیام تلگرام', 'شناسه به‌روزرسانی تلگرام'),
    'wallet.wallet_transfer.wallet_transfer_restart.text.f7206f6d33':('🎫', '📥'),
    'payment.referral_reward.give_referral_reward.text.c0780ce268':('💰', '💼'),
    'payment.payment_lifecycle.pay_ux_reference.text.d031212b3c':('🎫 شناسهٔ تلگرام', '👤 شناسه عددی'),
    'subscription.account_deletion.shared_effect_notice':('⚠️', '🚫'),
    'manual_payment.manual_receipts.message.f6331b33af':('💰 <b>پرداخت با موجودی</b>', '💳 <b>پرداخت با موجودی</b>'),
    'wallet.wallet_transfer.wallet_transfer_restart.text.77d4a377ae':('💼', '🔄'),
    'wallet.wallet_settlement.process_wallet_payment.external_effect_unverified_verify_before.10e2ccf8a3':('⚠️', '🔎'),
    'renewal.extensions.external_effect_unverified_verify_before.10e2ccf8a3':('⚠️', '🔎'),
    'gateway.gateway_return.np_alert_operator.text.7d4d150298':('💰', '💵'),
    'gateway.gateway_return.np_alert_operator.text.04d4b236f8':('⚠️', '🔎'),
    'broadcast.routes.pm_noop.text.b59e6ba611':('📌', '📂'),
    'manual_payment.manual_receipts.text.8ec86d6a5e':('💰', '💵'),
    'manual_payment.manual_receipts.text.c0780ce268':('💰', '💼'),
    'payment.checkout.user_i_d_uid_for_log.6a12f817c0':('🎫', '👤'),
    'payment.discount_administration.add_discount_code.text.66fafb9fd4':('▪️', '🏷'),
    'payment.discount_administration.add_discount_code.text.9cd9d5658f':('▪️', '🔢'),
    'payment.discount_administration.add_discount_code.text.5919cd752d':('▪️', '👤'),
    'payment.discount_administration.add_discount_code.text.b7cf332a32':('▪️', '👥'),
    'payment.discount_administration.add_discount_code.text.77f1958ae0':('▪️', '🎯'),
    'payment.discount_administration.add_discount_code.text.d2d2b624b9':('▪️', '💰'),
    'payment.discount_administration.add_discount_code.text.0b9a6e5bc7':('▪️', '💵'),
}
def check(ok,message):
    if not ok:raise AssertionError(message)
def command(*args,root=ROOT,**kwargs):return subprocess.check_output(args,cwd=root,**kwargs)
def load_catalogs(root):
    raw=command('php','-r','$r=[];foreach(["user","admin"]as$a)$r[$a]=require "messages/fa/".$a.".php";echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);',root=root)
    return {a:r5.flatten(g)for a,g in json.loads(raw).items()}
NOTIFICATION_KEYS={
    'admin.roles.notifications_on','admin.roles.notifications_off',
    'admin.roles.notifications_saved','admin.roles.notifications_failed',
}
DISPLAY_CONTEXT_BASE='748994636f9fc2cf4c0ea4c7c810093c0dd108ef'
DISPLAY_CONTEXT_ENTRIES={'admin_settings.display.delivery_configs': {'text': '🛒 کانفیگ\u200cهای بعد از خرید', 'parameters': []},
 'admin_settings.display.view_configs': {'text': '📋 کانفیگ\u200cهای صفحهٔ سفارش', 'parameters': []},
 'admin_settings.display.delivery_selection': {'text': '🛒 کانفیگ\u200cهای بعد از خرید: {selection}',
                                               'parameters': ['selection']},
 'admin_settings.display.view_selection': {'text': '📋 کانفیگ\u200cهای صفحهٔ سفارش: {selection}',
                                           'parameters': ['selection']},
 'admin_settings.display.choose_context': {'text': 'بخش موردنظر را برای انتخاب کانفیگ مشخص کنید.',
                                           'parameters': []},
 'admin_settings.display.context_guide': {'text': 'انتخاب کانفیگ\u200cهای بعد از خرید و صفحهٔ سفارش مستقل '
                                                  'است. تا زمان ویرایش هر بخش، انتخاب فعلی آن حفظ '
                                                  'می\u200cشود.',
                                          'parameters': []},
 'admin_settings.display.editing_context': {'text': 'تنظیم انتخاب کانفیگ برای {context}',
                                            'parameters': ['context']}}
SIMPLE_DISPLAY_ENTRIES={'admin_settings.invite_reward_label': '🎁 پاداش هر دعوت', 'admin_settings.display.simple_delivery': '🛍 تنظیمات ارسال و نمایش بعد از خرید', 'admin_settings.display.simple_view': '📋 تنظیمات ارسال و نمایش صفحه سفارش', 'admin_settings.display.simple_on': '✅ روشن', 'admin_settings.display.simple_off': '⛔ خاموش', 'admin_settings.display.simple_sub': '🔗 لینک اشتراک', 'admin_settings.display.simple_cfg': '📄 کانفیگ', 'admin_settings.display.simple_aux': '🔗 لینک کمکی', 'admin_settings.display.simple_qr': '🔳 ارسال QR کد', 'admin_settings.display.simple_choose': '✏️ انتخاب کانفیگ\u200cها', 'admin_settings.display.simple_guide': '⚙️ هر بخش مستقل است؛ برای تغییر وضعیت روی دکمهٔ روبه\u200cروی عنوان بزنید.\nاگر انتخابی ذخیره نشده باشد، 3 کانفیگ اول نمایش داده می\u200cشود. دکمه\u200cهای دریافت QR در صفحه سفارش از تنظیمات ربات پیروی می\u200cکنند.', 'admin_settings.display.simple_names': '✏️ نام دقیق هر کانفیگ را در یک خط بنویسید؛ حداکثر 10 نام، به ترتیب دلخواه.\nمثال:\nآلمان\nهلند\n\nبرای بازگشت به انتخاب خودکار 3 کانفیگ اول، /auto را بفرستید.\nاگر اندازهٔ کل پیام کافی نباشد، از انتهای فهرست کانفیگ\u200cها کم می\u200cشود تا لینک اشتراک، لینک\u200cهای کمکی و اطلاعات سفارش کامل بمانند. هیچ کانفیگی ناقص ارسال نمی\u200cشود.\nدکمهٔ انصراف، انتخاب قبلی را نگه می\u200cدارد.', 'payment_settings.invite_reward_prompt': 'پاداش هر دعوت به تومان (عدد صحیح از 0 تا 999999999؛ مقدار 0 یا /clear برای غیرفعال\u200cکردن)', 'payment_settings.invite_reward_hint': '🎁 مبلغ پاداش را با عدد صحیح انگلیسی از 0 تا 999999999 به تومان وارد کنید؛ برای غیرفعال\u200cکردن 0 یا /clear را بفرستید.'}
SIMPLE_DISPLAY_LABELS={'admin_settings.config.get_server_config_keys.button.3a95407812': ('⚙️ نمایش لینک و QR', '⚙️ نمایش کانفیگ\u200cها و لینک اشتراک'), 'provider.provider_admin.pg_server_admin_keys.button.3a95407812': ('⚙️ نمایش لینک و QR', '⚙️ نمایش کانفیگ\u200cها و لینک اشتراک')}
@functools.lru_cache(maxsize=1)
def display_context_baseline():
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    return {a:r5.flatten(json.loads(command('php','-r',decode,input=command('git','show',DISPLAY_CONTEXT_BASE+':messages/fa/'+a+'.php'))))for a in ['user','admin']}
def display_context_followup_proof(current):
    # Exactly seven new admin entries relative to the accepted 7489946 source.
    # Existing entries, audiences, params and earlier amendment layers stay exact.
    current,_=r5.verify_api_and_restore(current)
    baseline=display_context_baseline();record=r5.manifest()
    check(set(current['user'])==set(baseline['user'])
          and set(current['admin'])==set(baseline['admin'])|set(DISPLAY_CONTEXT_ENTRIES)|set(SIMPLE_DISPLAY_ENTRIES),
          'display_context_addition_or_removal_scope_drift')
    check(all(current[a][key]==SIMPLE_DISPLAY_LABELS.get(key,(entry,entry))[1] for a,rows in baseline.items()for key,entry in rows.items()),
          'display_context_changed_existing_entry')
    for key,expected in DISPLAY_CONTEXT_ENTRIES.items():
        check(all(key not in rows for rows in baseline.values()),'display_context_key_existed:'+key)
        item=record['added_keys'].get(key,{})
        check(current['admin'].get(key)==expected and key not in current['user']
              and item.get('audience')=='admin' and item.get('entry')==expected
              and item.get('source_paths')==['src/Subscription/ServerDisplaySettings.php'],
              'display_context_entry_slots_or_recipient_drift:'+key)
    for key,expected in SIMPLE_DISPLAY_ENTRIES.items():
        item=record['added_keys'].get(key,{})
        check(current['admin'].get(key)==expected and key not in current['user'] and item.get('entry')==expected
              and item.get('audience')=='admin' and item.get('source_paths'),
              'simple_display_entry_or_recipient_drift:'+key)
    for key,(before,after) in SIMPLE_DISPLAY_LABELS.items():
        check(baseline['admin'].get(key)==before and current['admin'].get(key)==after and key not in current['user'],
              'simple_display_label_drift:'+key)
def display_context_negative_controls(current):
    mutations=[]
    key='admin_settings.display.delivery_selection'
    bad=copy.deepcopy(current);bad['admin']['admin_settings.display.unlisted']='ناشناخته';mutations.append(bad)
    bad=copy.deepcopy(current);bad['user'][key]=bad['admin'].pop(key);mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'][key]['parameters']=['context'];mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'][key]['text']='متن ثبت‌نشده: {selection}';mutations.append(bad)
    bad=copy.deepcopy(current);bad['user']['legacy_messages.banned']='تغییر خارج از دامنه';mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'].pop('admin.roles.notifications_saved');mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin']['admin_settings.display.simple_names']='متن ثبت‌نشده';mutations.append(bad)
    bad=copy.deepcopy(current);bad['user']['admin_settings.invite_reward_label']=bad['admin'].pop('admin_settings.invite_reward_label');mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'][next(iter(SIMPLE_DISPLAY_LABELS))]='عنوان ثبت‌نشده';mutations.append(bad)
    for bad in mutations:
        try:display_context_followup_proof(bad)
        except AssertionError:continue
        raise AssertionError('display_context_addition_negative_control_missed')
    return len(mutations)
STAFF_GUIDE_KEY='admin.roles.choose_level'
STAFF_GUIDE_BEFORE='پشتیبان: اطلاعات کاربر، مسدودسازی و ویرایش سفارش'
STAFF_GUIDE_AFTER='پشتیبان: اطلاعات کاربر، مسدودسازی، ویرایش سفارش و بررسی رسید پرداخت (تأیید یا رد)'
def staff_followup_proof(current,archived):
    # New notification entries and this one role sentence have separate,
    # explicit lineage; they never change the 22 historical corrections.
    record=r5.manifest();r5.verify_and_restore(current,record)
    current,_=r5.verify_api_and_restore(current)
    added={key for audience,rows in current.items()for key in rows if key not in archived[audience]}
    check(added==NOTIFICATION_KEYS|set(DISPLAY_CONTEXT_ENTRIES)|set(SIMPLE_DISPLAY_ENTRIES),'notification_catalog_addition_scope_drift')
    check(all(set(archived[a])-set(SETTINGS_RETIRED_ENTRIES)<=set(current[a])for a in archived),'staff_followup_removed_historical_entry')
    for key in NOTIFICATION_KEYS:
        item=record['added_keys'].get(key,{})
        check(item.get('audience')=='admin'and current['admin'].get(key)==item.get('entry')
              and r5.params(current['admin'][key])==[],'notification_catalog_lineage_or_recipient:'+key)
    before=archived['admin'][STAFF_GUIDE_KEY];after=current['admin'][STAFF_GUIDE_KEY]
    check(r5.text(before).count(STAFF_GUIDE_BEFORE)==1,'staff_guide_original_sentence_drift')
    check(r5.text(after)==r5.text(before).replace(STAFF_GUIDE_BEFORE,STAFF_GUIDE_AFTER,1)
          and r5.params(after)==r5.params(before),'staff_guide_other_words_slots_or_markup_changed')
SETTINGS_LABEL_AMENDMENTS={'instance_content.settings_button': ('admin', '📝 متن\u200cها و نشانی\u200cها', '📝 متن\u200cهای ربات'), 'instance_content.title': ('admin', '📝 متن\u200cها و نشانی\u200cهای ربات\nبخش موردنظر را برای ویرایش انتخاب کنید.', '📝 متن\u200cهای ربات\nمتن موردنظر را برای ویرایش انتخاب کنید.'), 'instance_content.unconfigured': ('user', {'text': '📝 متن این بخش هنوز تنظیم نشده است.\nمدیر ربات می\u200cتواند آن را از «تنظیمات ربات ← متن\u200cها و نشانی\u200cها» تنظیم کند.\n🎫 شناسه عددی: {userId}', 'parameters': ['userId']}, {'text': '📝 متن این بخش هنوز تنظیم نشده است.\nمدیر ربات می\u200cتواند آن را از «تنظیمات ربات ← متن\u200cهای ربات» تنظیم کند.\n🎫 شناسه عددی: {userId}', 'parameters': ['userId']})}
SETTINGS_NAVIGATION_BASE='54975245d6358f5d4e7fde720e09da945803ae17'
SETTINGS_RETIRED_ENTRIES={'admin_settings.config.get_bot_setting_keys.button.ce5bb2416c': ('admin', '⚙️ نمایش لینک و QR برای هر سرور'),
 'instance_content.support': ('admin', '📨 نشانی پشتیبانی'),
 'instance_content.reward_channel': ('admin', '📢 کانال گزارش\u200cها'),
 'instance_content.lock_channel': ('admin', '🔐 کانال عضویت اجباری')}
@functools.lru_cache(maxsize=1)
def settings_navigation_baseline():
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    return {a:r5.flatten(json.loads(command('php','-r',decode,input=command('git','show',SETTINGS_NAVIGATION_BASE+':messages/fa/'+a+'.php'))))for a in ['user','admin']}
def settings_label_followup_proof(current,archived):
    # Exact post-RC16 label/path and four known retirement deltas, separate
    # from the 22 heading corrections and staff follow-up; no generic exemption.
    baseline=settings_navigation_baseline()
    for key,(audience,before,after)in SETTINGS_LABEL_AMENDMENTS.items():
        other='user'if audience=='admin'else'admin'
        check(baseline[audience].get(key)==before and archived[audience].get(key)==before
              and key not in baseline[other] and key not in archived[other],
              'settings_label_original_or_recipient_drift:'+key)
        check(current[audience].get(key)==after and key not in current[other]
              and r5.params(after)==r5.params(before),
              'settings_label_other_words_slots_or_recipient_changed:'+key)
    removed={key for audience,rows in archived.items()for key in rows if key not in current[audience]}
    check(removed==set(SETTINGS_RETIRED_ENTRIES),'settings_navigation_retirement_scope_drift')
    for key,(audience,before)in SETTINGS_RETIRED_ENTRIES.items():
        other='user'if audience=='admin'else'admin'
        check(baseline[audience].get(key)==before and archived[audience].get(key)==before
              and key not in baseline[other] and key not in archived[other],
              'settings_retired_original_or_recipient_drift:'+key)
        check(key not in current[audience] and key not in current[other],
              'settings_retired_entry_reintroduced:'+key)
def correction_proof(current,archived):
    staff_followup_proof(current,archived)
    display_context_followup_proof(current)
    settings_label_followup_proof(current,archived)
    changed={k for a,rows in current.items()for k,e in rows.items()if k in archived[a]and e!=archived[a][k]}
    check(changed==set(CORRECTIONS)|{STAFF_GUIDE_KEY}|set(SETTINGS_LABEL_AMENDMENTS)|set(SIMPLE_DISPLAY_LABELS),'composed_catalog_correction_scope_drift')
    for key,(before,after)in CORRECTIONS.items():
        audience=next(a for a in current if key in current[a])
        old=archived[audience][key];now=current[audience][key]
        check(r5.text(old).count(before)==1,'composed_catalog_literal_anchor_drift:'+key)
        expected=r5.text(old).replace(before,after)
        check(r5.text(now)==expected and r5.params(now)==r5.params(old),'composed_catalog_words_slots_or_markup_changed:'+key)
    return len(CORRECTIONS)
def staff_followup_negative_controls(current,archived):
    mutations=[]
    bad=copy.deepcopy(current);bad['admin']['admin.roles.unlisted_notification']='ناشناخته';mutations.append(bad)
    key=sorted(NOTIFICATION_KEYS)[0]
    bad=copy.deepcopy(current);bad['user'][key]=bad['admin'].pop(key);mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'][key]={'text':'تغییر ثبت‌نشده','parameters':[]};mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'].pop(key);mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'][STAFF_GUIDE_KEY]['text']=r5.text(bad['admin'][STAFF_GUIDE_KEY]).replace('مدیر:','مدیر ارشد:',1);mutations.append(bad)
    bad=copy.deepcopy(current);bad['admin'][STAFF_GUIDE_KEY]['parameters']=[];mutations.append(bad)
    for index,bad in enumerate(mutations):
        try:correction_proof(bad,archived)
        except AssertionError:continue
        raise AssertionError('staff_followup_negative_control_missed:'+str(index))
    return len(mutations)
def numeric_id_variant(value):
    value=unicodedata.normalize('NFKD',value)
    value=''.join(c for c in value if not unicodedata.combining(c) and c!='ـ')
    value=re.sub('[\u200c\u200d\u200e\u200f]',' ',value)
    return re.search(r'شناسه\s*(?:ی\s*)?تلگرام',value) is not None
def heading_collisions(message):
    seen={};bad=[];pre=False
    for raw in message.splitlines():
        if '<pre>' in raw:pre=True
        if not pre:
            line=html.unescape(re.sub('<[^>]+>','',raw)).strip()
            match=re.match(r'^([^\w\s]+)\s+(.+)',line)
            if match and any(ord(c)>9000 for c in match[1]):
                marker=match[1];label=re.split('[:←]',match[2],maxsplit=1)[0].strip()
                if marker in seen and seen[marker]!=label:bad.append((marker,seen[marker],label))
                seen[marker]=label
        if '</pre>' in raw:pre=False
    return bad
TOKENIZE=r'''$r=[];foreach(json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR)as$p=>$s){$r[$p]=[];foreach(token_get_all($s)as$t){if(is_array($t)){if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG]))continue;$r[$p][]=[$t[0],$t[1]];}else$r[$p][]=[0,$t];}}echo json_encode($r,JSON_THROW_ON_ERROR);'''
def expression_arguments(tokens,start,kind):
    depth=[];part=[];arguments=[]
    for token_id,value in tokens[start:]:
        # Punctuation inside quoted/interpolated tokens is never a boundary.
        if token_id==0:
            if value in '([{':depth.append(value)
            elif value in ')]}':
                if not depth:
                    if kind=='call'and value==')':
                        arguments.append(part);return arguments
                    check(kind!='array','expression_unbalanced');return arguments
                check('([{'.index(depth.pop())==')]}'.index(value),'expression_unbalanced')
            elif not depth and value==';':
                check(kind!='array','expression_unbalanced')
                arguments.append(part);return arguments
            elif not depth and kind in ['call','array']and value==',':
                arguments.append(part);part=[];continue
        part.append((token_id,value))
    if kind!='array':return arguments
    check(not depth,'expression_unbalanced')
    if part:arguments.append(part)
    return arguments
def expression_text(tokens):return ''.join(value for _,value in tokens)
def router_message_expression(payload,field):
    # Only a literal keyed payload proves the body sent by the router. Dynamic
    # keys/unpacking or duplicate keys could replace it at runtime.
    if payload and payload[0][1]=='['and payload[-1][1]==']':body=payload[1:-1]
    elif len(payload)>2 and payload[0][1]=='array'and payload[1][1]=='('and payload[-1][1]==')':body=payload[2:-1]
    else:check(False,'router_expression_payload_not_literal')
    entries=expression_arguments(body,0,'array');values={}
    for entry in entries:
        check(len(entry)>=3 and entry[1][1]=='=>','router_expression_payload_key_unsupported')
        key=re.fullmatch(r"""(['"])([A-Za-z_][A-Za-z0-9_]*)\1""",entry[0][1])
        check(key is not None,'router_expression_payload_key_unsupported')
        key=key[2];check(key not in values,'router_expression_duplicate_payload_key:'+key)
        values[key]=entry[2:]
    return values.get(field)
def expression(tokens,kind,target,anchor,arg=0,operator='='):
    matches=[];router_field={('sendMessage',0):'text',('sendPhoto',1):'caption'}.get((target,arg))
    for i,(_,token) in enumerate(tokens):
        routed=kind=='call'and router_field is not None and token=='achbotNotifyAdmins'
        if token!=target and not routed:continue
        start=i+1
        if kind=='assign':
            if start>=len(tokens)or tokens[start][1]!=operator:continue
            start+=1
        elif kind=='call':
            if start>=len(tokens)or tokens[start][1]!='(':continue
            start+=1
        arguments=expression_arguments(tokens,start,kind)
        if routed:
            if len(arguments)<2 or expression_text(arguments[0])not in [repr(target),'"'+target+'"']:continue
            if anchor not in expression_text(arguments[1]):continue
            selected=router_message_expression(arguments[1],router_field)
        else:selected=arguments[arg]if len(arguments)>arg else None
        if selected is not None and anchor in expression_text(selected):matches.append(expression_text(selected))
    # A duplicated exact expression in two loops is the same presentation proof.
    matches=list(dict.fromkeys(matches))
    check(len(matches)==1,'expression_anchor_missing_or_ambiguous:'+anchor)
    return matches[0]

def routed_expression_controls():
    body=r"\achbotMessage('fixture.message',['nested'=>[1,2],'literal'=>'(,)[]']).$suffix"
    router="achbotNotifyAdmins('sendMessage',['text'=>"+body+",'parse_mode'=>'HTML']);"
    positives={
        'direct':("sendMessage("+body+");",'sendMessage',0),
        'router':(router,'sendMessage',0),
        'nested_metadata_first':("(achbotNotifyAdmins('sendMessage',['reply_markup'=>json_encode(['inline_keyboard'=>[]]),'text'=>"+body+"],['report_channel'=>null])[0]['response']??null);",'sendMessage',0),
        'caption':("achbotNotifyAdmins('sendPhoto',['photo'=>'fixture','caption'=>"+body+"]);",'sendPhoto',1),
        'long_array':('achbotNotifyAdmins("sendMessage",array("parse_mode"=>"HTML","text"=>'+body+'));','sendMessage',0),
        'identical_direct_and_routed':("sendMessage("+body+");"+router,'sendMessage',0),
    }
    negatives={
        'missing_anchor':(router.replace('fixture.message','fixture.other'),'sendMessage',0),
        'wrong_method':(router.replace("'sendMessage'","'sendPhoto'"),'sendMessage',0),
        'wrong_body_field':(router.replace("'text'=>","'caption'=>"),'sendMessage',0),
        'metadata_anchor':("achbotNotifyAdmins('sendMessage',['text'=>'safe','reply_markup'=>"+body+"]);",'sendMessage',0),
        'duplicate_text_key':("achbotNotifyAdmins('sendMessage',['text'=>"+body+",'text'=>'replacement']);",'sendMessage',0),
        'identical_duplicate_text_key':("achbotNotifyAdmins('sendMessage',['text'=>"+body+",'text'=>"+body+"]);",'sendMessage',0),
        'dynamic_key':("achbotNotifyAdmins('sendMessage',[$field=>"+body+"]);",'sendMessage',0),
        'unpacked_override':("achbotNotifyAdmins('sendMessage',['text'=>"+body+",...$extra]);",'sendMessage',0),
        'nonliteral_payload':("achbotNotifyAdmins('sendMessage',$payload+['text'=>"+body+"]);",'sendMessage',0),
        'different_direct_and_routed':("sendMessage("+body+");"+router.replace(body,body+".'different'"),'sendMessage',0),
        'photo_anchor_outside_caption':("achbotNotifyAdmins('sendPhoto',['photo'=>"+body+",'caption'=>'safe']);",'sendPhoto',1),
        'unbalanced_payload':("achbotNotifyAdmins('sendMessage',['text'=>"+body+");",'sendMessage',0),
    }
    fixtures={**positives,**negatives}
    tokens=json.loads(command('php','-r',TOKENIZE,input=json.dumps({name:'<?php '+item[0]for name,item in fixtures.items()}).encode()))
    expected=expression(tokens['direct'],'call','sendMessage','fixture.message')
    for name,(_,target,arg) in positives.items():
        check(expression(tokens[name],'call',target,'fixture.message',arg)==expected,
              'router_expression_positive_drift:'+name)
    for name,(_,target,arg) in negatives.items():
        try:expression(tokens[name],'call',target,'fixture.message',arg)
        except AssertionError:continue
        check(False,'router_expression_negative_missed:'+name)
    return len(positives),len(negatives)

def spec(name,path,kind,target,anchor,arg=0):return dict(name=name,path=path,kind=kind,target=target,anchor=anchor,arg=arg)
SPECS=[
 spec('wallet_transfer_report','src/Finance/Routes/WalletTransfer.php','assign','$channelMessage','wallet_transfer_restart.channel_message.4dcd886908'),
 spec('referral_customer_balances','src/Finance/ReferralReward.php','assign','$buyerMsg','give_referral_reward.buyer_msg.51caba68b5'),
 spec('referral_operator','src/Finance/ReferralReward.php','assign','$operatorMsg','give_referral_reward.operator_msg.7c8cfd9d4f'),
 spec('payment_reference','src/Finance/PaymentLifecycle.php','return','return','pay_ux_reference.label.edb6e627ea'),
 spec('merchant_operator_review','src/Finance/GatewayReturn.php','assign','$message','np_alert_operator.message.00aa2f3ddb'),
 spec('wallet_uncertain_effect','src/Finance/WalletSettlement.php','assign','$adminErr','process_wallet_payment.admin_err.51996ec850'),
 spec('manual_operator_review','src/Finance/Routes/ManualReceipts.php','assign','$body','manual_receipts.body.8725ef87b4'),
 spec('manual_approved_balances','src/Finance/Routes/ManualReceipts.php','assign','$adminMessages','manual_receipts.admin_messages.31d90c5e69'),
 spec('manual_customer_wallet_credit','src/Finance/Routes/ManualReceipts.php','call','sendMessage','manual_receipts.message.f568baa065'),
 spec('broadcast_detail','src/Broadcast/Routes.php','assign','$out','pm_noop.out.94647cb3a5'),
 spec('checkout_review','src/Finance/Routes/Checkout.php','call','sendMessage','checkout.message.54c81d71b7'),
 spec('discount_details','src/Finance/Routes/DiscountAdministration.php','assign','$text','add_discount_code.text.3a4c0517b4'),
 spec('shared_delete_warning','src/Subscription/AccountDeletion.php','call','editText','account_deletion.message.acb79596be',1),
 spec('manual_renewal_uncertain','src/Finance/Routes/RenewalCheckout.php','assign','$err','renewal_checkout.err.a6df2ab8a6'),
 spec('wallet_renewal_uncertain','src/Finance/Routes/RenewalCheckout.php','assign','$adminErr','renewal_checkout.admin_err.7f953ff1fb'),
 spec('manual_days_uncertain','src/Finance/Routes/Extensions.php','assign','$err','extensions.err.28163d77c1'),
 spec('wallet_days_uncertain','src/Finance/Routes/Extensions.php','assign','$adminErr','extensions.admin_err.70cf66359f'),
 spec('manual_volume_uncertain','src/Finance/Routes/Extensions.php','assign','$err','extensions.err.322385c9dd'),
 spec('wallet_volume_uncertain','src/Finance/Routes/Extensions.php','assign','$adminErr','extensions.admin_err.128069a4b4'),
 spec('new_ticket_text','src/Support/CreateTicket.php','assign','$txt','create_ticket.text.fe45cc8381'),
 spec('new_ticket_photo','src/Support/CreateTicket.php','assign','$txt','create_ticket.text.4479736037'),
 spec('ticket_followup_text','src/Support/CustomerConversation.php','assign','$txt','user_all_tickets.text.a79a2fa69e'),
 spec('ticket_followup_photo','src/Support/CustomerConversation.php','assign','$txt','user_all_tickets.text.46d8580795'),
 spec('wallet_private_failure','src/Finance/Routes/WalletTransfer.php','assign','$adminReport','wallet_transfer_failure.2e028e487d'),
 spec('wallet_private_notice_failure','src/Finance/Routes/WalletTransfer.php','assign','$notice','wallet_transfer_notification_failure.9a285d7145'),
 spec('wallet_customer_review','src/Finance/Routes/WalletTransfer.php','call','sendMessage','wallet_transfer_restart.message.6924ce253f'),
 spec('wallet_recipient_credit','src/Finance/Routes/WalletTransfer.php','call','$sendTransferNotice','wallet_transfer_restart.text.ecbce64eed'),
 spec('wallet_sender_debit','src/Finance/Routes/WalletTransfer.php','call','$sendTransferNotice','wallet_transfer_restart.text.8e5e1ee663'),
]
PHP=r'''error_reporting(E_ALL);set_error_handler(static function($s,$m){throw new ErrorException($m,0,$s);});
require $argv[1].'/messages/loader.php';
function achbotFinanceDiagnosticReason($e){return 'fixture_review_required';}
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$out=[];
foreach($input as$name=>$expr){$out[$name]=(static function($expr){
 $from_id=1001;$to_id=9002;$sender_name='کاربر آزمایشی';$receiver_name='گیرنده آزمایشی';
 $sender_username='@fixture_sender';$receiver_username='@fixture_receiver';
 $sender_before=50000;$sender_after=49700;$receiver_before=50000;$receiver_after=50300;
 $amount=300;$before=50000;$after=50300;$payId=701;$buyer=1001;$inviter=9002;
 $pay=['hash_id'=>'fixture_order','user_id'=>1001];$hash='fixture_order';
 $identity='کاربر آزمایشی';$subject='خرید اشتراک';$quote='2.50 USD';$childCount=1;
 $a=['user_id'=>1001,'price'=>150000,'plan_title'=>'پلن نمونه','order_hash'=>'fixture_order',
 'provider_reference'=>'fixture_invoice','payment_id'=>'fixture_payment','extra_evidence_count'=>0,'updated_at'=>'2026-10-29 00:00:00'];
 $reason='نیازمند بررسی';$safe=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $esc=$safe;$case=$a+['user_name'=>'کاربر آزمایشی','user_handle'=>'fixture_user','quote_amount'=>'2.50','quote_currency'=>'USD','stage'=>'needs_review'];
 $handle='fixture_user';$attemptId=702;$cursor=0;$methodName='بانکی';$userId=1001;$currentWallet=50000;$newWallet=50300;$hash_id='fixture_receipt';
 $hashId='fixture_order';$uidForLog=1001;$price=300;$supportID='@fixture_support';
 $bid=801;$title='پیام نمونه';$tp='پیام';$g='همه';$dt='2026-10-29';$previewText='متن آزمایشی';$total=20;$pinned=10;$deleted=0;
 $amountText='10 درصد';$dateText='2026-11-29';$countText='10';$canUseText='1';$audienceText='همه';$targetText='همه سرویس‌ها';$minPriceText='100,000 تومان';$maxDiscountText='20,000 تومان';
 $remark='اشتراک نمونه';$match=['fixture','fixture_order'];$e=new RuntimeException('fixture');
 $reference='FIXTURE_REF';$transferUpdateId=9001;$sqlstate='22003';$commitStarted=false;$transactionOutcome='confirmed_rolled_back';$rollbackApi='returned_success';$noticeReference='FIXTURE_NOTICE';$failedRoles='recipient';
 $first_name='کاربر آزمایشی';$username='fixture_user';$ticketCat='پشتیبانی';$ticketTitle='درخواست نمونه';$text='متن نمونه';$caption='شرح تصویر';
 return eval('return '.$expr.';');})($expr);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'''
def render(expressions,catalog_root):
    return json.loads(command('php','-r',PHP,str(catalog_root),input=json.dumps(expressions).encode()))
def audit(source_root=ROOT,catalog_root=ROOT):
    paths={s['path']for s in SPECS}|{'config.php','src/Finance/ReceiptHelpers.php'}
    source={p:(source_root/p).read_text()for p in paths}
    source['prior_direct_checkout']=command('git','show','68e94b5f90b13cab88835421d83afb78eb573c0d:src/Finance/Routes/Checkout.php').decode()
    tokenized=json.loads(command('php','-r',TOKENIZE,input=json.dumps(source).encode()))
    expressions={s['name']:expression(tokenized[s['path']],s['kind'],s['target'],s['anchor'],s['arg'])for s in SPECS}
    prior_checkout=expression(tokenized['prior_direct_checkout'],'call','sendMessage','checkout.message.54c81d71b7')
    check(expressions['checkout_review']==prior_checkout,'routed_checkout_expression_changed')
    router_cases,router_controls=routed_expression_controls()
    # Current source's incremental stats loops: execute only the presentation
    # expressions, twice, retaining the intentional repeated identical labels.
    stats={
     'admin_agent_stats':['admin_settings.config.get_bot_report_keys.text.900e008efe','admin_settings.config.send_chunked_messages.text.833406bb32','admin_settings.config.get_bot_report_keys.text.fa6bc48623','admin_settings.config.get_bot_report_keys.text.9a3a995798','admin_settings.config.get_bot_report_keys.text.8fdd85c8a9'],
     'admin_plan_stats':['admin_settings.config.get_bot_report_keys.text.696814adc4','admin_settings.config.get_bot_report_keys.text.549ee88ce8','admin_settings.config.get_bot_report_keys.text.0ad85f8c38'],
    }
    for name,anchors in stats.items():
        pieces=[]
        for i,anchor in enumerate(anchors):
            # The shared ID row also exists in sendChunkedMessages; identical
            # tokenized expression bytes are deduplicated by expression().
            pieces.append(expression(tokenized['config.php'],'assign','$text',anchor,operator='='if i==0 else'.='))
        expressions[name]='(static function(){ $userId=1001;$totalOrders=3;$totalPaid="150,000";$formattedDate="2026-10-29";$planName="پلن نمونه";$planCount=3;return '+pieces[0]+'.('+'.'.join(pieces[1:])+').('+'.'.join(pieces[1:])+');})()'
    current=render(expressions,catalog_root)
    for name,value in current.items():check(not heading_collisions(value),'composed_heading_collision:'+name+':'+str(heading_collisions(value)))
    # Pure source quote expressions plus the actual insertion tail cover all six
    # existing receipt templates without dispatching payment or querying a DB.
    # Ledger-pinning/recipient/mutation proof remains in the dedicated receipt test.
    quote_tokens=tokenized['src/Finance/ReceiptHelpers.php']
    for method,anchor in [('bank','header.12c0babe12'),('crypto','header.cc6f426145')]:
        expr=expression(quote_tokens,'assign','$quoteLine',anchor)
        tail=source['src/Finance/ReceiptHelpers.php'].split("    $priceAt = strpos($caption, 'PRICE');",1)
        check(len(tail)==2,'actual_receipt_insertion_tail_missing')
        tail="$priceAt = strpos($caption, 'PRICE');"+tail[1].split('\n}\n',1)[0]
        for key in ['buy_custom_account_request','buy_new_account_request','buy_new_much_account_request','renew_account_request_message','increase_account_request_message','increase_wallet_request_message']:
            name='receipt_'+method+'_'+key
            expressions[name]="(static function(){ $details=['quote_amount'=>'150003'];$asset='USDT';$network='BEP20';$caption=achbotMessage('legacy_messages."+key+"');$quoteLine="+expr+';'+tail+'})()'
    current=render(expressions,catalog_root)
    for name,value in current.items():check(not heading_collisions(value),'composed_heading_collision:'+name+':'+str(heading_collisions(value)))
    with tempfile.TemporaryDirectory(prefix='achbot-heading-reference-')as temp:
        stage=pathlib.Path(temp);(stage/'messages/fa').mkdir(parents=True)
        (stage/'messages/loader.php').write_bytes((catalog_root/'messages/loader.php').read_bytes())
        for audience in ['user','admin']:
            (stage/'messages/fa'/f'{audience}.php').write_bytes(command('git','show',REFERENCE+':messages/fa/'+audience+'.php'))
        archived=render(expressions,stage)
        current_catalogs=load_catalogs(catalog_root);archived_catalogs=load_catalogs(stage)
        correction_count=correction_proof(current_catalogs,archived_catalogs)
        _,api_proof=r5.verify_api_and_restore(current_catalogs)
        api_controls=r5.api_negative_controls(current_catalogs)
        staff_controls=staff_followup_negative_controls(current_catalogs,archived_catalogs)
        display_context_controls=display_context_negative_controls(current_catalogs)
    # Existing private-failure composition: this value comes from update_id,
    # not message_id. The copy correction must preserve the supplied number.
    update_expr=expression(tokenized['src/Finance/Routes/WalletTransfer.php'],'assign','$transferUpdateId',"'update_id'")
    check(update_expr=="$amountSource['update_id']",'wallet_transfer_update_id_source_binding_drift')
    check('شناسه به‌روزرسانی تلگرام: <code>9001</code>\n' in current['wallet_private_failure'],'telegram_update_id_label_or_value_changed')
    check('شناسه پیام تلگرام:' not in current['wallet_private_failure'],'telegram_update_id_mislabeled_as_message')
    check('شناسه پیام تلگرام: <code>9001</code>\n' in archived['wallet_private_failure'],'archived_wrong_telegram_update_label_RED_missing')
    reds=[name for name,value in archived.items()if heading_collisions(value)]
    expected_red={s['name']for s in SPECS[:2]+SPECS[3:19]}
    check(set(reds)==expected_red,'archived_composed_RED_scope_drift:'+str(reds))
    # Actual combined output negatives: template-only tests cannot catch these.
    report=current['wallet_transfer_report']
    mutations=[('wallet_after_marker',report,report.replace('🔄 بعد:','💼 بعد:')),
               ('wallet_destination_marker',report,report.replace('📥 <b>مقصد','🎫 <b>مقصد')),
               ('manual_new_balance',current['manual_approved_balances'],current['manual_approved_balances'].replace('💼 موجودی جدید:','💰 موجودی جدید:')),
               ('merchant_invoice',current['merchant_operator_review'],current['merchant_operator_review'].replace('💵 <b>مبلغ فاکتور:','💰 <b>مبلغ فاکتور:')),
               ('discount_distinct_fields',current['discount_details'],current['discount_details'].replace('👥 گروه کاربران:','👤 گروه کاربران:')),
               ('warning_followup',current['wallet_uncertain_effect'],current['wallet_uncertain_effect'].replace('🔎 نتیجه عملیات','⚠️ نتیجه عملیات'))]
    for name,original,value in mutations:
        check(value!=original,'composition_negative_fixture_drift:'+name)
        check(heading_collisions(value),'composition_negative_missed:'+name)
    check(not heading_collisions('🧾 نام: فرستنده\n🧾 نام: گیرنده'),'identical_heading_reuse_rejected')
    check(not heading_collisions('📦 حجم: 10\n<pre>📦 RAW: 20</pre>'),'raw_code_misclassified_as_heading')
    variants=['شناسه تلگرام','شناسهٔ تلگرام','شناسه\u200cتلگرام','شناسه‌ی تلگرام','شناسه  تلگرام']
    check(all(numeric_id_variant(v)for v in variants),'numeric_id_variant_negative_missed')
    preserved=['شناسه پیام تلگرام','شناسه به‌روزرسانی تلگرام','شناسه چت تلگرام']
    check(not any(numeric_id_variant(v)for v in preserved),'genuine_telegram_object_id_misclassified')
    check(not numeric_id_variant('شناسه عددی'),'requested_numeric_id_misclassified')
    return {**api_proof,'api_catalog_negative_controls':api_controls,'routed_message_expression_cases':router_cases,'routed_message_expression_negative_controls':router_controls,'routed_checkout_expression_preserved':True,'composed_catalog_correction_keys':correction_count,'settings_navigation_label_amendments':len(SETTINGS_LABEL_AMENDMENTS),'settings_navigation_retired_keys':len(SETTINGS_RETIRED_ENTRIES),'display_context_catalog_additions':len(DISPLAY_CONTEXT_ENTRIES),'simple_display_catalog_additions':len(SIMPLE_DISPLAY_ENTRIES),'simple_display_label_changes':len(SIMPLE_DISPLAY_LABELS),'display_context_catalog_negative_controls':display_context_controls,'notification_catalog_additions':len(NOTIFICATION_KEYS),'staff_role_description_amendments':1,'staff_catalog_negative_controls':staff_controls,'telegram_update_id_label_cases':1,'telegram_update_id_numeric_value_preserved':True,'archived_wrong_telegram_update_label_RED':True,'composed_heading_cases':len(current),'composed_heading_source_paths':len(paths),'composed_heading_archived_RED':len(reds),'composed_heading_negative_controls':len(mutations),'identical_heading_reuse_cases':1,'raw_code_heading_exclusions':1,'numeric_id_variant_negative_controls':len(variants),'preserved_telegram_object_id_cases':len(preserved),'preserved_numeric_id_cases':1,'composition_scope':'source_expression_wallet_finance_receipt_support_admin_stats_matrix','real_Telegram_rendering':'NOT_TESTED'}
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--source-root',type=pathlib.Path,default=ROOT);ap.add_argument('--catalog-root',type=pathlib.Path,default=ROOT);args=ap.parse_args()
    print(json.dumps({'status':'PASS',**audit(args.source_root,args.catalog_root)},ensure_ascii=False))
if __name__=='__main__':main()
