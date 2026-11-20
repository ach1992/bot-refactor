#!/usr/bin/env python3
"""Source-real rendered parity for active plan price and interval prompts.

The retired serverNames editor no longer has a runnable producer. Current
source-owned text, HTML parse mode, and keyboard rendering stay pinned.
"""
from __future__ import annotations

import hashlib
from html import escape
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess

import test_post_v9_telegram_html_callsite_parity as scan

ROOT = Path(__file__).resolve().parents[1]
SITES = (
    ('src/Plans/Administration.php', 'administration.edit_custom_plan.message.207313a746'),
    ('src/Plans/Intervals.php', '$title'),
)
EXPECTED_SOURCE_SHA = 'e8890c02adc21479700a0ed997335a008206d1a446f0915f30597dc58ffe22ca'
TITLE_PROVENANCE = (
    ('src/Plans/Administration.php', r'\$title\s*=\s*\$match\[1\].*?;',
     'cc781c71c6e9c4327a4db6847b53b3fa25aef3ee49363e2025dc76220813779b'),
    ('src/Plans/Intervals.php', r'\$title\s*=\s*\$rows\?.*?;',
     '08bbf8700279b8decd7fe9037fb6eed1fc4d285fed6ff752892f746a51ab46ae'),
)
ADMIN_TITLES = {'dayPrice': 'هر روز', 'gbPrice': 'هر گیگ'}
INTERVAL_TITLES = {
    'day_nonempty': ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇',
    'volume_nonempty': ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇',
    'day_empty': '📅 لیست پلن های زمانی خالی است.',
    'volume_empty': '📦 لیست پلن های حجمی خالی است.',
}
INTERVAL_KEYS = {
    'day_nonempty': 'plans.intervals.day_plan_settings.msg.0eb1f0debf',
    'volume_nonempty': 'plans.intervals.volume_plan_settings.msg.573ae79cf6',
    'day_empty': 'plans.intervals.day_plan_settings.message.f253e3780e',
    'volume_empty': 'plans.intervals.volume_plan_settings.message.63f1e596ef',
}
CASES = [
    {'admin': 'dayPrice', 'interval': 'day_nonempty', 'cancel': 'لغو ↩️'},
    {'admin': 'gbPrice', 'interval': 'volume_nonempty', 'cancel': 'بازگشت'},
    {'admin': 'dayPrice', 'interval': 'day_empty', 'cancel': '↪️ انصراف'},
    {'admin': 'gbPrice', 'interval': 'volume_empty', 'cancel': 'لغو'},
]


def digest(value: object) -> str:
    return hashlib.sha256(json.dumps(value, ensure_ascii=False,
        separators=(',', ':')).encode()).hexdigest()


def utf16_len(text: str) -> int:
    return len(text.encode('utf-16-le')) // 2


class HtmlEntities(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.output = ''
        self.entities: list[list[object]] = []
        self.start: int | None = None

    def handle_starttag(self, tag: str, attrs: list) -> None:
        if tag != 'code' or attrs or self.start is not None:
            raise AssertionError('unexpected HTML entity or attributes: ' + tag)
        self.start = utf16_len(self.output)

    def handle_endtag(self, tag: str) -> None:
        if tag != 'code' or self.start is None:
            raise AssertionError('unexpected HTML end: ' + tag)
        end = utf16_len(self.output)
        self.entities.append(['code', self.start, end - self.start])
        self.start = None

    def handle_data(self, data: str) -> None:
        self.output += data


def parse_html(text: str) -> dict:
    parser = HtmlEntities()
    parser.feed(text)
    parser.close()
    if parser.start is not None:
        raise AssertionError('unclosed HTML code span')
    return {'text': parser.output, 'entities': parser.entities}


def parse_legacy(text: str) -> dict:
    if re.search(r'[<>&*_`\[\]\\]|(?:https?://|\bwww\.)', text):
        raise AssertionError('legacy simple-text assumption violated: ' + repr(text))
    return {'text': text, 'entities': []}


def inventory(sources: dict[str, str], require_html: bool = True) -> list[list[str]]:
    result = []
    for filename, marker in SITES:
        source = sources[filename]
        hits = []
        for pos in scan.executable_send_starts(source):
            _, args = scan.parse_arguments(source, source.index('(', pos))
            if args and (scan.canonical_expression(args[0]) == marker if marker == '$title'
                         else marker in scan.canonical_expression(args[0])):
                hits.append(args)
        if len(hits) != 1:
            raise AssertionError(f'{filename}:{marker}: expected one active call, got {len(hits)}')
        args = hits[0]
        if require_html and (len(args) != 3 or not scan.HTML.fullmatch(args[2].strip())):
            raise AssertionError(f'{filename}:{marker}: explicit HTML mode missing')
        if len(args) < 2:
            raise AssertionError('keyboard argument lost')
        result.append([filename, marker, scan.canonical_expression(args[0]),
                       scan.canonical_expression(args[1])])
    return result


def verify_provenance(sources: dict[str, str]) -> None:
    for filename, pattern, sha in TITLE_PROVENANCE:
        found = re.findall(pattern, sources[filename], flags=re.S)
        if len(found) != 1 or hashlib.sha256(
            scan.canonical_expression(found[0]).encode()).hexdigest() != sha:
            raise AssertionError('source-owned finite title provenance changed: ' + filename)


def execute_current(rows: list[list[str]]) -> list:
    driver = r'''
    require 'messages/loader.php';
    $rows=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    $cases=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    $out=[];
    foreach($cases as $case) {
        $cancelKey=$case['cancel'];
        $keyboard=json_encode(['inline_keyboard'=>[[['text'=>$case['cancel'],
            'callback_data'=>'fixture_callback']]]], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $pairs=[];
        foreach($rows as $index=>$row) {
            $title=$index===1
                ?achbotMessage($case['interval_key'])
                :achbotMessage($case['admin']==='dayPrice'
                    ?'plans.administration.edit_custom_plan.text.b641937e16'
                    :'plans.administration.edit_custom_plan.text.c84922eddf');
            $pairs[]=eval('return ['.$row[2].','.$row[3].'];');
        }
        $out[]=$pairs;
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    '''
    cases = [{**case, 'interval_key': INTERVAL_KEYS[case['interval']]}
             for case in CASES]
    proc = subprocess.run(['php', '-n', '-r', driver,
        json.dumps(rows, ensure_ascii=False),json.dumps(cases, ensure_ascii=False)],
        cwd=ROOT, text=True, capture_output=True)
    if proc.returncode:
        raise AssertionError('source-real PHP failed: ' + repr((proc.returncode,proc.stdout[-1500:],proc.stderr[-1500:])))
    return json.loads(proc.stdout)


def expected_case(case: dict) -> list:
    keyboard = json.dumps({'inline_keyboard': [[{'text': case['cancel'],
        'callback_data': 'fixture_callback'}]]}, ensure_ascii=False, separators=(',', ':'))
    return [
        (parse_legacy('لطفا هزینه ' + ADMIN_TITLES[case['admin']] + ' را به تومان وارد کنید.'), case['cancel']),
        (parse_legacy(INTERVAL_TITLES[case['interval']]), keyboard),
    ]


def verify_rendered(rows: list[list[str]]) -> int:
    actual = execute_current(rows)
    assert len(actual) == len(CASES)
    normalized=[]
    for case, pairs in zip(CASES, actual):
        if len(pairs) != len(SITES):
            raise AssertionError('PHP output pair count changed')
        expected = expected_case(case)
        for (text, key), (model, expected_key) in zip(pairs, expected):
            rendered = parse_html(text)
            if rendered != model or key != expected_key:
                raise AssertionError(f'output/entity/keyboard mismatch: {case} {rendered!r} {model!r}')
            normalized.append([rendered, key])
    return len(normalized)


def verify_negative_mutations(sources: dict[str, str]) -> int:
    # Fail if a source call is disabled, its keyboard changed, its parse-mode
    # reverted, or an independently sourced title becomes arbitrary content.
    changes = [
        ('src/Plans/Administration.php',
         "sendMessage(\\achbotMessage('plans.administration.edit_custom_plan.message.207313a746') . $title . \\achbotMessage('plans.administration.edit_custom_plan.message.ae9d133be8'),$cancelKey,'HTML')",
         "sendMessage(\\achbotMessage('plans.administration.edit_custom_plan.message.207313a746') . $title . \\achbotMessage('plans.administration.edit_custom_plan.message.ae9d133be8'),$cancelKey,'MarkDown')"),
        ('src/Plans/Intervals.php', 'sendMessage($title,$keyboard,\'HTML\')',
         'sendMessage($title,$cancelKey,\'HTML\')'),
        ('src/Plans/Intervals.php', 'sendMessage($title,$keyboard,\'HTML\')',
         '/* sendMessage($title,$keyboard,\'HTML\') */'),
        ('src/Plans/Administration.php',
         "$title = $match[1] == \"dayPrice\"?", "$title = $text == \"dayPrice\"?"),
    ]
    rejected = 0
    for filename, old, new in changes:
        src = sources[filename]
        if src.count(old) != 1:
            raise AssertionError('negative test target drift: ' + filename)
        variant = dict(sources)
        variant[filename] = src.replace(old, new, 1)
        try:
            verify_provenance(variant)
            if digest(inventory(variant)) != EXPECTED_SOURCE_SHA:
                raise AssertionError('audited source fingerprint differs')
        except AssertionError:
            rejected += 1
        else:
            raise AssertionError('mutation escaped review contract: ' + filename)
    return rejected


def main() -> None:
    sources = {filename: (ROOT / filename).read_text() for filename, _ in SITES}
    verify_provenance(sources)
    rows = inventory(sources)
    if digest(rows) != EXPECTED_SOURCE_SHA:
        raise AssertionError('text or keyboard source contract changed')
    # Plain literal punctuation has no implicit format entity. Keep explicit
    # HTML escaping and UTF-16 code-span proof independent of retired routes.
    tests = ('<>&_`[]\\', '👨\u200d👩\u200d👧\u200d👦', 'درود \u200f و فارسی')
    for sample in tests:
        if parse_html('<code>' + escape(sample) + '</code>') != {
            'text': sample, 'entities': [['code', 0, utf16_len(sample)]]}:
            raise AssertionError('HTML escaping/unicode entity fixture failed')
    count = verify_rendered(rows)
    negative = verify_negative_mutations(sources)
    print(json.dumps({'status': 'PASS', 'complete': True,
                      'producers': len(rows), 'samples': len(CASES),
                      'cases': count, 'rejected_mutations': negative,
                      'source_sha256': EXPECTED_SOURCE_SHA,
                      'network_calls': 0}, ensure_ascii=False))


if __name__ == '__main__':
    main()
