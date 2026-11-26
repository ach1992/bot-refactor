<?php
/**
 * Current-source, network-free acceptance for opt-in Telegram presentation.
 * No production token, account, database or Telegram API operation is used.
 */
require_once __DIR__ . '/../src/Telegram/Presentation.php';

use function AchBot\Telegram\Presentation\prepare;
use function AchBot\Telegram\Presentation\dispatch;
use function AchBot\Telegram\Presentation\decoratedMarkup;

$cases = 0;
function requireSame($expected, $actual, string $why): void {
    global $cases;
    $cases++;
    if ($expected !== $actual) {
        throw new RuntimeException($why . ' expected=' .
            json_encode($expected,JSON_UNESCAPED_UNICODE) . ' actual=' .
            json_encode($actual,JSON_UNESCAPED_UNICODE));
    }
}
function presentationPolicy(bool $premium=true, bool $style=true): array {
    return [
        'premium_emoji_enabled' => $premium,
        'premium_emoji_ids' => [
            '✅' => '1234567890123456789',
            '🧑‍💻' => '9876543210123456789',
            '❌' => '1234567890',
        ],
        'button_styles_enabled' => $style,
        'button_styles' => [
            'callback_data' => ['confirm'=>'success','delete'=>'danger'],
            'reply_text' => ['📞 ارسال شماره'=>'primary'],
        ],
    ];
}

$keys = json_encode([
    'inline_keyboard' => [[
        ['text'=>'✅ تأیید','callback_data'=>'confirm'],
        ['text'=>'🧑‍💻 ارتباط','callback_data'=>'contact'],
        ['text'=>'❌ حذف','callback_data'=>'delete'],
    ]],
], JSON_UNESCAPED_UNICODE);
$base = [
    'chat_id'=>123,'text'=>'✅ <b>انجام شد</b>','parse_mode'=>'HTML',
    'reply_markup'=>$keys
];

unset($GLOBALS['achbotTelegramPresentation']);
requireSame($base, prepare('sendMessage',$base),'default identity for message');
requireSame(['reply_markup'=>$keys],prepare('editMessageReplyMarkup',['reply_markup'=>$keys]),
    'default identity for keyboard edit');
requireSame(['text'=>'*✅ bold*','parse_mode'=>'Markdown'],prepare('sendMessage',
    ['text'=>'*✅ bold*','parse_mode'=>'Markdown']),'old Markdown remains unchanged');
$wire = [];
$ok = (object)['ok'=>true,'result'=>(object)['message_id'=>100]];
requireSame($ok, dispatch('sendMessage',$base,static function ($value) use (&$wire,$ok) {
    $wire[]=$value;
    return $ok;
}),'disabled output unchanged');
requireSame([$base],$wire,'disabled exactly one unchanged wire call');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy();
$expected = $base;
$expected['text']='<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> <b>انجام شد</b>';
$expectedKeys=json_decode($keys,true);
$expectedKeys['inline_keyboard'][0][0]['text']='تأیید';
$expectedKeys['inline_keyboard'][0][0]['style']='success';
$expectedKeys['inline_keyboard'][0][0]['icon_custom_emoji_id']='1234567890123456789';
$expectedKeys['inline_keyboard'][0][1]['text']='ارتباط';
$expectedKeys['inline_keyboard'][0][1]['icon_custom_emoji_id']='9876543210123456789';
$expectedKeys['inline_keyboard'][0][2]['text']='حذف';
$expectedKeys['inline_keyboard'][0][2]['style']='danger';
$expectedKeys['inline_keyboard'][0][2]['icon_custom_emoji_id']='1234567890';
$decorated=prepare('sendMessage',$base);
requireSame($expected['text'],$decorated['text'],'HTML mapped by grapheme');
requireSame($expectedKeys,json_decode($decorated['reply_markup'],true),'inline actions/style/id');
requireSame($base['chat_id'],$decorated['chat_id'],'recipient unchanged');

$complex='✅ <code>✅</code> <pre>✅</pre> <a href="https://example.invalid/?x=✅&amp;y=3">✅</a> <tg-emoji emoji-id="9">✅</tg-emoji> <i>🧑‍💻</i> &amp; &#9989;';
$converted=prepare('sendMessage',['text'=>$complex,'parse_mode'=>'HTML'])['text'];
requireSame(
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> '.
    '<code>✅</code> <pre>✅</pre> <a href="https://example.invalid/?x=✅&amp;y=3">✅</a> '.
    '<tg-emoji emoji-id="9">✅</tg-emoji> '.
    '<i><tg-emoji emoji-id="9876543210123456789">🧑‍💻</tg-emoji></i> &amp; &#9989;',
    $converted,'preserve code, pre, link, existing custom emoji and entities');
// Do not turn a literal, automatically linked URL into custom emoji tags.
// This also covers URL query strings with encoded ampersands, Telegram links,
// and an emoji adjacent to (but outside) the URL.
$urlInput='✅ https://example.invalid/sub/✅?x=1&amp;y=🧑‍💻 ✅';
requireSame(
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> '.
    'https://example.invalid/sub/✅?x=1&amp;y=🧑‍💻 '.
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    prepare('sendMessage',['text'=>$urlInput,'parse_mode'=>'HTML'])['text'],
    'bare URL with encoded query and Unicode glyphs untouched');
requireSame(
    ['text'=>'https://example.invalid/✅','parse_mode'=>'HTML'],
    prepare('sendMessage',['text'=>'https://example.invalid/✅','parse_mode'=>'HTML']),
    'no decoration-only URL should leave payload byte-identical');
requireSame(
    'tg://resolve?domain=✅ <tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    prepare('sendMessage',['text'=>'tg://resolve?domain=✅ ✅','parse_mode'=>'HTML'])['text'],
    'Telegram URL untouched, following emoji converted');
requireSame(
    'vless://user@server.invalid:443?security=tls#✅ '.
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    prepare('sendMessage',['text'=>'vless://user@server.invalid:443?security=tls#✅ ✅',
        'parse_mode'=>'HTML'])['text'],
    'VPN configuration URI and fragment emoji remain copyable');
requireSame(
    'ss://secret@server.invalid:443#🧑‍💻 '.
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    prepare('sendMessage',['text'=>'ss://secret@server.invalid:443#🧑‍💻 ✅',
        'parse_mode'=>'HTML'])['text'],
    'generic scheme URI not rewritten');
requireSame(
    'www.example.invalid/✅ <tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    prepare('sendMessage',['text'=>'www.example.invalid/✅ ✅','parse_mode'=>'HTML'])['text'],
    'bare www URL untouched');
requireSame(
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> '.
    'https://example.invalid/?x=✅&amp;y=🧑‍💻 '.
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    prepare('sendMessage',['text'=>'✅ https://example.invalid/?x=✅&y=🧑‍💻 ✅'])['text'],
    'plain input URL remains copyable after HTML escaping');

$literal='A < B & C ✅ "quoted"';
requireSame([
    'text'=>'A &lt; B &amp; C <tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> &quot;quoted&quot;',
    'parse_mode'=>'HTML'
],prepare('sendMessage',['text'=>$literal]),'plain to safely escaped HTML');
requireSame([
    'caption'=>'&lt;x&gt; <tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    'parse_mode'=>'HTML'
],prepare('sendPhoto',['caption'=>'<x> ✅']),'plain media caption safety');
foreach (['sendPhoto','sendDocument','sendVideo','sendAudio','sendVoice',
          'sendAnimation','editMessageCaption'] as $method) {
    requireSame('<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
        prepare($method,['caption'=>'✅','parse_mode'=>'HTML'])['caption'],
        'caption method: '.$method);
}
requireSame(['text'=>'*✅*','parse_mode'=>'Markdown'],prepare('sendMessage',
    ['text'=>'*✅*','parse_mode'=>'Markdown']),'legacy Markdown untouched');
requireSame(['text'=>'*✅*','parse_mode'=>'MarkdownV2'],prepare('sendMessage',
    ['text'=>'*✅*','parse_mode'=>'MarkdownV2']),'MarkdownV2 untouched');
requireSame(['text'=>'✅','entities'=>[]],prepare('sendMessage',
    ['text'=>'✅','entities'=>[]]),'explicit entities untouched');
requireSame(['text'=>'✅'],prepare('answerCallbackQuery',
    ['text'=>'✅']),'callback alert remains literal');

$reply = ['keyboard'=>[[['text'=>'📞 ارسال شماره','request_contact'=>true]],
                        [['text'=>'✅ انجام']]],'resize_keyboard'=>true];
$decoratedReply=prepare('sendMessage',['text'=>'Hello',
    'reply_markup'=>json_encode($reply,JSON_UNESCAPED_UNICODE)])['reply_markup'];
$actualReply=json_decode($decoratedReply,true);
requireSame('📞 ارسال شماره',$actualReply['keyboard'][0][0]['text'],
    'reply message text stable for input routing');
requireSame(true,$actualReply['keyboard'][0][0]['request_contact'],
    'reply special action stable');
requireSame('primary',$actualReply['keyboard'][0][0]['style'],
    'reply style configured');
requireSame(false,isset($actualReply['keyboard'][1][0]['icon_custom_emoji_id']),
    'reply input never substituted automatically');
requireSame($reply['resize_keyboard'],$actualReply['resize_keyboard'],'reply layout');
requireSame(['remove_keyboard'=>true],decoratedMarkup(['remove_keyboard'=>true],
    presentationPolicy(),presentationPolicy()['premium_emoji_ids']),
    'remove keyboard unchanged');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy(false,false);
requireSame($base,prepare('sendMessage',$base),'explicit inactive identity');
$GLOBALS['achbotTelegramPresentation']=presentationPolicy(true,false);
requireSame(true,prepare('sendMessage',$base)['text'] !== $base['text'],
    'premium independent of style switch');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy();
$GLOBALS['achbotTelegramPresentation']['premium_emoji_ids']['✅']='invalid';
$invalid=prepare('sendMessage',['text'=>'✅','parse_mode'=>'HTML']);
requireSame(['text'=>'✅','parse_mode'=>'HTML'],$invalid,'reject invalid custom emoji id');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy();
$sent=[];
$rejected=(object)['ok'=>false,'error_code'=>400,
    'description'=>'Bad Request: cannot use custom emoji'];
$send = static function (array $payload) use (&$sent,$rejected,$ok) {
    $sent[]=$payload;
    return count($sent)===1 ? $rejected : $ok;
};
requireSame($ok,dispatch('sendMessage',$base,$send),'known 400 recovered');
requireSame(2,count($sent),'one rejection => one fallback send');
requireSame($base,$sent[1],'retry uses exact original payload');
foreach ([
    null,
    (object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests'],
    (object)['ok'=>false,'error_code'=>500,'description'=>'Server Error'],
    (object)['ok'=>false,'error_code'=>400,'description'=>'chat not found'],
    (object)['ok'=>true,'result'=>(object)['message_id'=>11]],
] as $response) {
    $calls=0;
    $returned=dispatch('sendMessage',$base,static function ($payload) use (&$calls,$response) {
        $calls++;return $response;
    });
    requireSame($response,$returned,'preserve ambiguous/ordinary response');
    requireSame(1,$calls,'do not duplicate ambiguous send');
}
$objects=[(object)['ok'=>false,'error_code'=>400,'description'=>"can't parse entities"],
          ['ok'=>false,'error_code'=>400,'description'=>'CUSTOM_EMOJI_INVALID']];
foreach($objects as $response) {
    $calls=0;
    dispatch('sendMessage',$base,static function ($payload) use (&$calls,$response,$ok) {
        $calls++;return $calls===1?$response:$ok;
    });
    requireSame(2,$calls,'known formatting rejection original retry');
}
// Generic message and media helpers share the same explicit rejection proof.
require_once dirname(__DIR__).'/src/Telegram/Messages.php';
foreach ([null,(object)['ok'=>false,'description'=>"can't parse entities"],
    (object)['ok'=>false,'error_code'=>'400','description'=>"can't parse entities"],
    (object)['ok'=>false,'error_code'=>500,'description'=>"can't parse entities"]] as $unknown) {
    foreach (['sendMessage','sendPhoto'] as $method) {
        $calls=0;$send=static function ($name,$payload) use (&$calls,$unknown) {$calls++;return $unknown;};
        $fn='\\AchBot\\Telegram\\Messages\\'.$method;
        $result=$method==='sendMessage'?$fn($send,1001,'<b>hello</b>'):$fn($send,1001,'photo','<b>hello</b>');
        requireSame($unknown,$result,'unknown response preserved');
        requireSame(1,$calls,'unknown text/media response never retried');
    }
    requireSame(false,\AchBot\Telegram\Presentation\rejectedDecoration($unknown),'unknown presentation rejection not certified');
}
foreach (['sendMessage','sendPhoto'] as $method) {
    $wire=[];$send=static function ($name,$payload) use (&$wire,$ok) {
        $wire[]=$payload;return count($wire)===1?(object)['ok'=>false,'error_code'=>400,'description'=>"can't parse entities"]:$ok;
    };
    $fn='\\AchBot\\Telegram\\Messages\\'.$method;
    requireSame($ok,$method==='sendMessage'?$fn($send,1001,'<b>hello</b>'):$fn($send,1001,'photo','<b>hello</b>'),'known rejection permits one original fallback');
    requireSame(2,count($wire),'one fallback only');
    requireSame(false,isset($wire[1]['parse_mode']),'known rejection fallback literal');
}
// The two migrated fixed Markdown-code prompts have identical visible text,
// line breaks, and literal copyable code contents in HTML. Message catalog
// keys remain stable; no dynamic input or callback text is involved.
require_once __DIR__ . '/../messages/loader.php';
$legacyCodePrompts = [
    'subscription.order_management.manageorderdatabase.message.e16b131103' => [
        'markdown'=>'✏️ لطفا `remark` مربوط به سفارش را وارد کنید:',
        'html'=>'✏️ لطفا <code>remark</code> مربوط به سفارش را وارد کنید:',
    ],
    'subscription.sub_link_settings.message.9f2715dc7c' => [
        'markdown'=>"🔗 لطفاً لینک‌های کمکی اشتراک سرور را وارد کنید.\n".
            "📌 لینک‌ها را با کاما (,) از هم جدا کنید.\n\n".
            "مثال:\n`https://sub1.domain.com,https://sub2.domain.com`",
        'html'=>"🔗 لطفاً لینک‌های کمکی اشتراک سرور را وارد کنید.\n".
            "📌 لینک‌ها را با کاما (,) از هم جدا کنید.\n\n".
            "مثال:\n<code>https://sub1.domain.com,https://sub2.domain.com</code>",
    ],
];
foreach ($legacyCodePrompts as $key=>$pair) {
    $candidate = achbotMessage($key);
    requireSame($pair['html'],$candidate,'exact migrated HTML template: '.$key);
    requireSame(str_replace('`','',$pair['markdown']),strip_tags($candidate),
        'identical visible text and copyable content: '.$key);
}
// The new durable manifest is the existing renderer's real message
// boundaries, including the last-text-only keyboard and original mode.
require_once dirname(__DIR__).'/src/Subscription/Delivery.php';
require_once dirname(__DIR__).'/src/Telegram/Response.php';
$caption=str_repeat('<code>'.str_repeat('x',1200).'</code> &amp; ',6);
$oldParts=\AchBot\Subscription\Presentation\messageParts($caption);
requireSame(true,count($oldParts)>1,'multipart fixture is actually split');
$keys='{"inline_keyboard":[]}';
$packet=['kind'=>'qr','qr'=>'https://fixture.invalid/sub/1','caption'=>$caption,'keys'=>$keys];
$parts=achbotSubscriptionDeliveryParts($packet);
requireSame(['kind'=>'qr','qr'=>$packet['qr'],'caption'=>null],array_shift($parts),'QR original payload');
requireSame($oldParts,array_column($parts,'text'),'frozen HTML parts preserve exact prior boundaries');
foreach ($parts as $i=>$part) {
    requireSame('HTML',$part['mode'],'original parse mode');
    requireSame($i===count($parts)-1?$keys:null,$part['keys'],'last text keyboard only');
}
$packet['qr']='';
requireSame($parts,achbotSubscriptionDeliveryParts($packet),'no QR if no link');
requireSame([['kind'=>'text','text'=>'a < b','keys'=>null,'mode'=>null]],
    achbotSubscriptionDeliveryParts(['kind'=>'text','text'=>'a < b','mode'=>'plain']),'plain historical notice');
requireSame([['kind'=>'text','text'=>'<b>a</b>','keys'=>null,'mode'=>'HTML']],
    achbotSubscriptionDeliveryParts(['kind'=>'text','text'=>'<b>a</b>','mode'=>'HTML']),'HTML historical notice');
$rate=(object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests','parameters'=>(object)['retry_after'=>35]];
requireSame($rate,\AchBot\Telegram\Response\deliveryTransportResponse($rate,429,0),'matching HTTP/API rate limit');
foreach ([$rate,(object)['ok'=>true],(object)['ok'=>false,'error_code'=>400,'description'=>"can't parse entities"]] as $response) {
    requireSame(null,\AchBot\Telegram\Response\deliveryTransportResponse($response,500,0),'5xx is always uncertain');
    requireSame(null,\AchBot\Telegram\Response\deliveryTransportResponse($response,429,28),'transport error is uncertain');
}

// Canonical HTML admission from actual Telegram UTF-16 entities; raw values
// remain copyable and invalid entity metadata falls back to literal input.
use function AchBot\Telegram\Presentation\inputHtml;
use function AchBot\Telegram\Presentation\incomingHtml;
requireSame('🔥 <b>سلام</b> &amp; &lt;tag&gt;', inputHtml('🔥 سلام & <tag>',
    [['type'=>'bold','offset'=>3,'length'=>4]]),'UTF16 RTL entity and raw escape');
requireSame('<b><i>سلام</i></b>',inputHtml('سلام',[
    ['type'=>'bold','offset'=>0,'length'=>4],['type'=>'italic','offset'=>0,'length'=>4]]),'nested entities');
requireSame(null,inputHtml('🔥x',[['type'=>'bold','offset'=>1,'length'=>1]]),'invalid surrogate offset');
requireSame(null,inputHtml('abc',[['type'=>'text_link','offset'=>0,'length'=>3,'url'=>'javascript:alert(1)']]),'unsafe link');
requireSame('abc',incomingHtml('abc',[['type'=>'text_link','offset'=>0,'length'=>3,'url'=>'javascript:alert(1)']]),'literal fallback');
requireSame('<a href="https://example.invalid/?x=1&amp;y=2">link</a>',inputHtml('link',[
    ['type'=>'text_link','offset'=>0,'length'=>4,'url'=>'https://example.invalid/?x=1&y=2']]),'link attributes');
requireSame('<pre><code class="language-php">a &lt; b</code></pre>',inputHtml('a < b',[
    ['type'=>'pre','offset'=>0,'length'=>5,'language'=>'php']]),'code language');
requireSame('<blockquote expandable>abc</blockquote>',inputHtml('abc',[
    ['type'=>'expandable_blockquote','offset'=>0,'length'=>3]]),'expandable quote');
requireSame('<a href="tg://user?id=123">name</a>',inputHtml('name',[
    ['type'=>'text_mention','offset'=>0,'length'=>4,'user'=>(object)['id'=>123]]]),'mention');
requireSame('<tg-emoji emoji-id="123456789">🔥</tg-emoji>',inputHtml('🔥',[
    ['type'=>'custom_emoji','offset'=>0,'length'=>2,'custom_emoji_id'=>'123456789']]),'custom emoji');
requireSame(str_repeat('&lt;',4096),inputHtml(str_repeat('<',4096)),'no arbitrary template limit in shared renderer');
requireSame('',inputHtml(''),'empty media caption');
requireSame('&lt;b&gt;raw&lt;/b&gt; _ * &#039;',incomingHtml("<b>raw</b> _ * '"),'free text is not markup');
echo json_encode(['status'=>'PASS','cases'=>$cases,'pass'=>true,
    'scope'=>'isolated-presentation-no-network','default_unchanged'=>true],
    JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
