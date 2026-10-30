<?php
/** Accepted simple display contract; real shared PHP, synthetic data, no effects. */
error_reporting(E_ALL);
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
$root=$argv[1]??dirname(__DIR__);
require_once $root.'/src/Subscription/ServerDisplaySettings.php';
use function AchBot\Subscription\Presentation\present;
use function AchBot\Subscription\Presentation\fitMessage;
use function AchBot\Subscription\Presentation\messageParts;
use function AchBot\Subscription\Presentation\textLength;
$checks=0;
function verify($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function equal($a,$b,$label){verify($a===$b,$label);}
$links=array_map(static fn($i)=>'vless://opaque-'.$i.'@native.invalid:443?type=ws#'.rawurlencode('نام '.$i),range(1,40));
$sub='https://native.invalid/sub/opaque%2Btoken?x=a&y=b';
$backup='https://backup.invalid/sub/opaque%2Btoken?x=a&y=b';
$native=['links'=>$links,'subscription_url'=>$sub,'backup_urls'=>[$backup]];
foreach(['marzban','pasarguard'] as $provider){
    $document=achbotServerDisplayDefault();
    foreach(['delivery','view'] as $context){
        $p=present($provider,$native,[],$document,$context);
        equal($p['links'],array_slice($links,0,3),$provider.$context.'_default_first_three');
        equal($p['qr_config_links'],$links,$provider.$context.'_all_forty_explicit_config_qrs');
        equal($p['qr_subscription_url'],$sub,$provider.$context.'_explicit_subscription_qr');
        equal($p['qr_link'],$sub,$provider.$context.'_delivery_default_subscription_qr');
        verify(str_contains($p['backup_html'],htmlspecialchars($backup,ENT_QUOTES,'UTF-8')),$provider.$context.'_default_auxiliary');
    }
    $before=serialize($document);
    $d=achbotServerDisplayChange($document,$provider,'dconfigs',"نام 5\nنام 2\nنام 1");
    equal(present($provider,$native,[],$d,'delivery')['links'],[$links[4],$links[1],$links[0]],$provider.'_ordered_exact_names');
    equal(present($provider,$native,[],$d,'view')['links'],array_slice($links,0,3),$provider.'_delivery_edit_preserves_view');
    equal(serialize($document),$before,$provider.'_change_does_not_mutate_caller_document');
    $d=achbotServerDisplayChange($d,$provider,'vconfigs','نام 8');
    equal(present($provider,$native,[],$d,'view')['links'],[$links[7]],$provider.'_view_names_independent');
    foreach(['dcfg','dsub','daux','vcfg','vsub','vaux'] as $field){
        $peer=$field[0]==='d'?'view':'delivery';$context=$field[0]==='d'?'delivery':'view';
        $peerBefore=present($provider,$native,[],$d,$peer);
        $changed=achbotServerDisplayChange($d,$provider,$field,'flip');
        equal(present($provider,$native,[],$changed,$peer),$peerBefore,$provider.$field.'_entire_peer_preserved');
        equal(present($provider,$native,[],$changed,$context)['qr_config_links'],$links,$provider.$field.'_qr_not_limited_by_text_flags');
    }
    $hidden=achbotServerDisplayChange($d,$provider,'vsub','flip');
    $p=present($provider,$native,[],$hidden,'view');
    equal($p['subscription_html'],'',$provider.'_primary_text_off');
    equal($p['qr_subscription_url'],$sub,$provider.'_primary_text_off_does_not_disable_explicit_qr');
    verify($p['backup_html']!=='',$provider.'_auxiliary_independent_of_primary_text');
    foreach(['first_config','none','subscription'] as $mode){
        $d=achbotServerDisplayChange($d,$provider,'dqr','cycle');
        equal($d['policy']['delivery']['qr'],$mode,$provider.'_qr_cycle_'.$mode);
        equal(present($provider,$native,[],$d,'delivery')['qr_link'],
            ['first_config'=>$links[4],'none'=>'','subscription'=>$sub][$mode],$provider.'_qr_target_'.$mode);
    }
    $ten=implode("\n",array_map(static fn($i)=>'نام '.$i,range(1,10)));
    $d=achbotServerDisplayChange($d,$provider,'dconfigs',$ten);
    equal(present($provider,$native,[],$d,'delivery')['links'],array_slice($links,0,10),$provider.'_ten_names_boundary');
    foreach([$ten."\nنام 11","نام 1\nنام 1","نام 1\n\nنام 2",str_repeat('a',301),"نام\x00","\xFF",''] as $bad){
        try {achbotServerDisplayChange($d,$provider,'dconfigs',$bad);$denied=false;}
        catch(Throwable $e){$denied=true;}
        verify($denied,$provider.'_invalid_name_input_rejected');
    }
    $auto=achbotServerDisplayChange($d,$provider,'dconfigs','/auto');
    equal(present($provider,$native,[],$auto,'delivery')['links'],array_slice($links,0,3),$provider.'_explicit_auto_first_three');
    equal(present($provider,$native,[],$auto,'view')['links'],[$links[7]],$provider.'_auto_preserves_peer');
    $keys=json_decode(achbotServerDisplayKeys(2147483647,$auto,1800000000),true)['inline_keyboard'];
    $callbacks=array_merge(...array_map(static fn($row)=>array_column($row,'callback_data'),$keys));
    equal(count(array_filter($callbacks,static fn($s)=>str_contains($s,'_dqr_'))),1,$provider.'_one_delivery_qr_button');
    verify(!array_filter($callbacks,static fn($s)=>str_contains($s,'_vqr_')),$provider.'_no_order_page_qr_control');
    verify(!array_filter($callbacks,static fn($s)=>strlen($s)>64),$provider.'_telegram_callback_size');
    equal(count(array_filter($callbacks,static fn($s)=>str_contains($s,'configs_'))),2,$provider.'_both_visible_name_editors');
    $off=achbotServerDisplayChange($auto,$provider,'vcfg','flip');
    $offKeys=json_decode(achbotServerDisplayKeys(1,$off,1),true)['inline_keyboard'];
    verify(!str_contains(json_encode($offKeys),'_vconfigs_') && str_contains(json_encode($offKeys),'_dconfigs_'),$provider.'_conditional_editor_own_context_only');
}
// Upgrading an old policy: explicit ON cannot remain hidden by removed knobs.
foreach(['marzban','pasarguard'] as $provider){
    $old=['mode'=>'native','policy'=>['show_configs'=>false,'max_links'=>0,'hide_if_more_than'=>0,
        'delivery_selectors'=>[['name'=>'نام 4']],'view_selectors'=>[['name'=>'نام 8']]]];
    $peer=present($provider,$native,[],$old,'view');
    $on=achbotServerDisplayChange($old,$provider,'dcfg','flip');
    equal(present($provider,$native,[],$on,'delivery')['links'],[$links[3]],$provider.'_new_ON_overrides_retired_zero_and_hide');
    equal(present($provider,$native,[],$on,'view'),$peer,$provider.'_new_ON_keeps_peer_legacy_choice');
}
foreach(['delivery'=>'d','view'=>'v'] as $context=>$prefix){
    $old=['mode'=>'marzban_legacy','policy'=>['show_configs'=>false],'legacy'=>[
        'showIndexes'=>[1],'linkIndices'=>[0],'hideConfigIfMany'=>true,'hideConnectLinkForMarzban'=>true]];
    $peerContext=$context==='delivery'?'view':'delivery';$peer=present('marzban',$native,[],$old,$peerContext);
    $on=achbotServerDisplayChange($old,'marzban',$prefix.'cfg','flip');
    equal(present('marzban',$native,[],$on,$context)['links'],[$links[0]],'legacy_'.$context.'_explicit_ON_not_hidden');
    equal(present('marzban',$native,[],$on,$peerContext),$peer,'legacy_'.$context.'_ON_preserves_peer');
}
// Legacy positional compatibility shares the same inline count boundary.
// Short configs prove that whole-message size alone cannot enforce ten.
foreach (['delivery','view'] as $context) {
    $indices=[10,1,10,0,2,3,4,5,6,7,8,9];
    $old=['mode'=>'marzban_legacy','policy'=>[], 'legacy'=>[
        'linkIndices'=>$indices,'showIndexes'=>range(1,12),
        'hideConfigIfMany'=>false,'hideConnectLinkForMarzban'=>false]];
    $unchanged=serialize($old);
    $uncapped=$context==='delivery'?array_map(static fn($i)=>$links[$i],$indices):array_slice($links,0,12);
    verify(textLength(AchBot\Subscription\Presentation\codeLinks($uncapped))<4096,
        'legacy_'.$context.'_short_configs_do_not_trigger_size_limit');
    $p=present('marzban',$native,[],$old,$context);
    equal($p['links'],array_slice($uncapped,0,10),'legacy_'.$context.'_inline_maximum_ten_preserves_order_and_duplicates');
    verify($p['limited'] && $p['reason']==='display_limit' && $p['notice_html']!=='',
        'legacy_'.$context.'_count_limit_notice');
    equal($p['qr_config_links'],$links,'legacy_'.$context.'_count_limit_keeps_all_forty_explicit_QRs');
    equal(serialize($old),$unchanged,'legacy_'.$context.'_read_does_not_rewrite_peer_policy');
    $text=fitMessage($p,static fn($p)=>$p['config_html']."\n".$p['subscription_html']."\n".$p['backup_html']."\n".$p['notice_html']);
    verify(count($p['links'])===10 && textLength($text)<=4096,
        'legacy_'.$context.'_complete_short_message_retains_exactly_ten');
}
// Complete-message budget, not a fragment-byte bound.
$long=array_map(static fn($i)=>'vless://native.invalid/'.str_repeat(chr(97+$i),1600).'#'.$i,range(0,9));
$p=present('pasarguard',['links'=>$long,'subscription_url'=>$sub,'backup_urls'=>[$backup]],[],
    ['policy'=>['max_links'=>10]]);
$compose=static fn($p)=>'<b>سفارش 🇮🇷 &amp; وضعیت</b>'."\n".str_repeat('اطلاعات ',70)."\n".$p['config_html']."\n".$p['subscription_html']."\n".$p['backup_html']."\n".$p['notice_html'];
$text=fitMessage($p,$compose);
verify(textLength($text)<=4096,'complete_message_with_emoji_entities_and_facts_fits');
equal($p['links'],[$long[0],$long[1]],'remove_whole_trailing_configs_in_order');
verify(str_contains($text,htmlspecialchars($sub,ENT_QUOTES,'UTF-8')) && str_contains($text,htmlspecialchars($backup,ENT_QUOTES,'UTF-8')),'protected_primary_and_auxiliary_reserved_before_configs');
equal($p['qr_config_links'],$long,'budget_does_not_clip_explicit_config_qr');
equal(textLength('🇮🇷😀&amp;<b>الف</b>'),10,'parsed_utf16_conservative_size');
$protected='<b>'.str_repeat('داده &amp; 🇮🇷 ',600).'<i>پایان</i></b>'."\n".'<code>'.$sub.'</code>'."\n".'<pre><code>'.$long[0].'</code></pre>';
$parts=messageParts($protected);
verify(count($parts)>1,'protected_overflow_splits');
equal(implode('',array_map(static fn($s)=>html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8'),$parts)),
    html_entity_decode(strip_tags($protected),ENT_QUOTES|ENT_HTML5,'UTF-8'),'split_has_no_loss_duplication_or_entity_damage');
foreach($parts as $part){
    verify(textLength($part)<=4096,'every_part_bounded');
    $stack=[];preg_match_all('~</?([a-z]+)>~i',$part,$tags);
    foreach($tags[0] as $i=>$tag){
        if(str_starts_with($tag,'</')) equal(array_pop($stack),$tags[1][$i],'html_closing_tag_matched');
        else $stack[]=$tags[1][$i];
    }
    equal($stack,[],'every_split_balanced');
}
equal(count(array_filter($parts,static fn($s)=>str_contains($s,'<code>'.$long[0].'</code>'))),1,'native_uri_stays_whole_in_one_part');
$exact=str_repeat('a',4096);
equal(messageParts($exact),[$exact],'exact_limit_no_split_or_reformat');
equal(array_map('strlen',messageParts($exact.'b')),[4096,1],'one_character_overflow_boundary');
$p=present('marzban',$native,[],['policy'=>['delivery'=>['names'=>['absent']]]],'delivery');
equal($p['links'],[],'missing_exact_name_never_falls_back_to_other_native_config');
verify(!AchBot\Subscription\Presentation\validPolicy(['view'=>['qr'=>'none']]),'order_context_cannot_store_qr_control');
equal(AchBot\Subscription\Presentation\nativeLinks(["vless://bad.invalid/\xFF"]),[],'invalid_UTF8_config_never_reencoded_as_different_URI');
equal(AchBot\Subscription\Presentation\subscriptionUrl("https://bad.invalid/\xFF"),'','invalid_UTF8_subscription_omitted');
try {messageParts("invalid\xFF");$denied=false;}catch(UnexpectedValueException $e){$denied=true;}
verify($denied,'invalid_UTF8_text_never_certifies_empty_delivery');
// Actual canonical output boundaries compose/split without touching global transport.
$effects=[];$failAt=0;
function sendMessage($text,$keys=null,...$rest){
    $GLOBALS['effects'][]=['kind'=>'send','text'=>$text,'keys'=>$keys];
    if($GLOBALS['failAt']===count($GLOBALS['effects'])) throw new RuntimeException('owned_text_unknown');
    return(object)['ok'=>true];
}
function editText($id,$text,$keys=null,...$rest){
    $GLOBALS['effects'][]=['kind'=>'edit','id'=>$id,'text'=>$text,'keys'=>$keys];return(object)['ok'=>true];
}
require_once $root.'/src/Subscription/Delivery.php';
require_once $root.'/src/Subscription/AccountDetails.php';
verify(sendQrFromLink('',$protected,'navigation','',1001),'actual_delivery_protected_overflow_acknowledged');
equal(array_column($effects,'text'),$parts,'actual_delivery_uses_bounded_parts_without_content_change');
equal(array_column($effects,'keys'),array_merge(array_fill(0,count($parts)-1,null),['navigation']),'delivery_navigation_only_on_final_part');
$effects=[];
verify(AchBot\Subscription\AccountDetails\showMessage(['msg'=>$protected,'keyboard'=>'navigation'],77),'actual_order_output_protected_overflow');
equal(array_column($effects,'text'),$parts,'actual_order_output_parts_preserve_message');
equal($effects[0]['kind'],'edit','order_refresh_edits_existing_first_message');
equal($effects[0]['id'],77,'order_refresh_preserves_original_message_identity');
equal(count(array_filter($effects,static fn($s)=>$s['kind']==='edit')),1,'order_refresh_sends_only_overflow_parts');
$effects=[];$failAt=1;
verify(!sendQrFromLink('',$protected,'navigation','',1001),'ambiguous_first_text_part_never_certifies_delivery');
equal(count($effects),1,'ambiguous_text_exception_not_retried');
echo json_encode(['complete'=>true,'cases'=>$checks,'source_real'=>true,'two_native_providers'=>true,
    'whole_message_budget'=>true,'all_explicit_config_qrs'=>true,'production_effects'=>0],JSON_THROW_ON_ERROR)."\n";
