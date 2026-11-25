#!/usr/bin/env python3
"""Source-real parity: retired global message maps vs current context resolver.

The reference renderer is the original accepted Stable source algorithm,
using the current immutable catalog and original instance parser. It runs
in an isolated PHP CLI without config.php, database or Telegram. Retained
catalog values are independently guarded by copy_relocation_parity.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
BASE = "4e21e18a3ba50897abc6b393ef0c7f58db39bbf9"
PHP = r'''
error_reporting(E_ALL);
set_error_handler(static function(int $code,string $message): never {
    throw new ErrorException($message,0,$code);
});
require 'messages/loader.php';
require 'src/Admin/InstanceContent.php';
eval(base64_decode($argv[1]));
$source=achbotMessages('legacy_messages');
$states=[
    ['supportID'=>'@support_name','lockChannel'=>'@public_group',
     'helpChannel'=>'@help','instanceMessages'=>[]],
    ['supportID'=>'A_B[foo]* <&','lockChannel'=>'-100123456',
     'helpChannel'=>'https://example.invalid/a?x=1&y=2','instanceMessages'=>[
        'start_message'=>['format'=>'html','text'=>'<b>خوش آمدید userid</b> <code>SUPPORTID</code>'],
        'user_welcome_message'=>['format'=>'html','text'=>'سلام <i>userid</i>'],
        'reached_main_menu'=>['format'=>'html','text'=>'<code>menu</code>'],
        'purchase_note'=>['format'=>'html','text'=>'نکته: SUPPORTID userid']]],
    ['supportID'=>'','lockChannel'=>'','instanceMessages'=>[
        'start_message'=>null,
        'purchase_note'=>['format'=>'html','text'=>'<b>خرید &amp; تماس</b>']]],
    ['supportID'=>'name'.chr(96).'test_*','lockChannel'=>'<#>&','instanceMessages'=>[
        'start_message'=>['format'=>'html','text'=>'<a href="https://example.invalid/?x=1&amp;y=2">link</a>'],
        'purchase_note'=>['format'=>'html','text'=>'<code>userid</code>']]],
];
$context=['start_message','user_welcome_message','reached_main_menu',
          'banned','join_channel_message','send_config_uuid',
          'agency_request_declined','new_member_joined',
          'buy_subscription_detail','buy_much_subscription_detail'];
$cases=0;
foreach($states as $state){
    foreach(['',1001,'نام🙌'] as $actor){
        $GLOBALS['botState']=$state;
        $GLOBALS['from_id']=$actor;
        $old=$source;
        if(is_string($old['join_channel_message']??null))
            $old['join_channel_message']=str_replace('CHANNEL-ID','LOCKCHANNEL',
                                                       $old['join_channel_message']);
        $old=\AchBot\Presentation\Text\replacePlaceholders($old,$state,$actor,[
            'banned'=>'HTML','send_config_uuid'=>'HTML',
            'join_channel_message'=>'HTML','agency_request_declined'=>null]);
        $old=achbotInstanceContentValues($old,$state,$actor);
        foreach($context as $key){
            if(in_array($key,['banned','send_config_uuid'],true)){
                $expected=strtr($source[$key],[
                  'SUPPORTID'=>htmlspecialchars((string)($state['supportID']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
                  'LOCKCHANNEL'=>htmlspecialchars((string)($state['lockChannel']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
                  'userid'=>htmlspecialchars((string)$actor,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')]);
                if($expected!==achbotCurrentMessage($key)) throw new RuntimeException('html_context_escape:'.$key);
            }
            if($old[$key]!==achbotCurrentMessage($key))
                throw new RuntimeException('context_parity_changed:'.$key);
            $cases++;
        }
        foreach($source as $key=>$original){
            if(!in_array($key,$context,true)){
                if($old[$key]!==$original)
                    throw new RuntimeException('unexpected_context_template:'.$key);
                if(achbotMessage('legacy_messages.'.$key)!==$original)
                    throw new RuntimeException('direct_legacy_copy_changed:'.$key);
                $cases++;
            }
        }
    }
}
try{achbotCurrentMessage('unexpected_context_key');}
catch(OutOfBoundsException $expected) {
    echo json_encode(['status'=>'PASS','complete'=>true,
       'cases'=>$cases,'states'=>count($states),
       'current_context_keys'=>count($context),
       'legacy_catalog_keys'=>count($source),'network_calls'=>0],
       JSON_THROW_ON_ERROR);
    exit(0);
}
throw new RuntimeException('unknown_context_key_did_not_fail');
'''


def main() -> None:
    # Source-real result cannot hide accidental changes to the old low-level
    # renderer or per-instance content precedence: both are still the original
    # accepted implementation, with only the new narrow resolver appended.
    relative = "src/Presentation/Text.php"
    old = subprocess.check_output(["git", "show", BASE + ":" + relative], cwd=ROOT)
    expected = b"\n".join(line for line in old.split(b"\n") if b"==='markdown'" not in line and not (b"strtr($text" in line and b"'_" in line))
    assert expected == (ROOT / relative).read_bytes(), "text_renderer_changed_without_goldens"
    relative = "src/Admin/InstanceContent.php"
    old = subprocess.check_output(["git", "show", BASE + ":" + relative], cwd=ROOT)
    current = (ROOT / relative).read_bytes()
    start = old.index(b"function achbotInstanceMessage(")
    end = old.index(b"function achbotInstanceContentValues(")
    current_start = current.index(b"function achbotInstanceMessage(")
    assert old[start:end] == current[current_start:current_start + end - start], (
        "instance_message_precedence_changed_without_goldens")

    import base64
    start = old.index(b"function achbotInstanceContentValues(")
    end = old.index(b"function achbotInstanceMenu(",start)
    # The exact original implementation is isolated from the current app.
    reference=base64.b64encode(old[start:end]).decode("ascii")
    raw = subprocess.check_output(["php", "-r", PHP,reference], cwd=ROOT, text=True)
    result = json.loads(raw)
    assert result["status"] == "PASS" and result["cases"] > 850
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
