#!/usr/bin/env python3
"""data/releases.json の検証。エラーが1件でもあれば終了コード1（コミット・pushしないこと）。

使い方: python3 scripts/validate.py
"""
import json
import re
import subprocess
import sys
import datetime

PATH = 'data/releases.json'
JP = {'jp_official', 'jp_route', 'jp_parallel', 'jp_unknown', 'jp_none'}
BUY = {'buy_online', 'buy_unknown'}
REGIONS = {'アイラ', 'スペイサイド', 'ハイランド', 'アイランズ', 'ローランド', 'キャンベルタウン', 'グレーン', 'その他', None}
CUR = {'GBP', 'USD', 'EUR', 'JPY'}
REQ = ['id', 'bottler_id', 'name', 'name_en', 'source_url', 'source_name', 'jp_status', 'buy_status']

err, warn = [], []
raw = open(PATH, encoding='utf-8').read()
try:
    d = json.loads(raw)
except Exception as e:
    print('NG: JSONとして読めません', e)
    sys.exit(1)

if json.dumps(d, ensure_ascii=False, indent=1) + '\n' != raw:
    warn.append('整形が規定と違います（indent=1, ensure_ascii=False, 末尾改行）。scripts/validate.py --fix で直せます')

today = datetime.date.today()
for k in ('updated', 'count', 'fx', 'labels', 'bottlers', 'releases'):
    if k not in d:
        err.append(f'トップレベルに {k} がありません')
R = d.get('releases', [])
B = {b['id']: b for b in d.get('bottlers', [])}
if d.get('count') != len(R):
    err.append(f"count({d.get('count')}) と releases の件数({len(R)}) が一致しません")
for c in ('GBP', 'USD', 'EUR'):
    v = d.get('fx', {}).get(c)
    if not isinstance(v, (int, float)) or not (50 < v < 400):
        err.append(f'fx.{c} の値がおかしい: {v}')

try:
    _nj = json.load(open('data/names_ja.json', encoding='utf-8'))
    names_ja = set(_nj['distilleries'].values()) | set(_nj.get('others', {}).values())
except Exception:
    names_ja = set()

ids = set()
for r in R:
    rid = r.get('id', '?')
    for k in REQ:
        if not r.get(k):
            err.append(f'{rid}: {k} が空です')
    if rid in ids:
        err.append(f'{rid}: id が重複しています')
    ids.add(rid)
    if not re.fullmatch(r'[a-z0-9\-]+', rid or ''):
        err.append(f'{rid}: id は英小文字・数字・ハイフンのみ')
    if r.get('bottler_id') not in B:
        err.append(f"{rid}: bottler_id {r.get('bottler_id')} が bottlers にありません")
    if re.search(r'[()（）\[\]【】]', r.get('name', '')):
        err.append(f"{rid}: name に括弧があります → {r.get('name')}")
    if r.get('jp_status') not in JP:
        err.append(f"{rid}: jp_status が不正 {r.get('jp_status')}")
    if r.get('buy_status') not in BUY:
        err.append(f"{rid}: buy_status が不正 {r.get('buy_status')}")
    if r.get('jp_status') == 'jp_route' and not r.get('jp_note'):
        err.append(f'{rid}: jp_route なのに jp_note（輸入元名）が空です')
    if r.get('jp_status') == 'jp_route':
        imp = (B.get(r.get('bottler_id')) or {}).get('jp_importer')
        if not imp or imp not in (r.get('jp_note') or ''):
            err.append(f'{rid}: jp_route は bottlers の jp_importer（{imp}）が必要で、jp_note にその名前を書くこと')
    if r.get('jp_status') == 'jp_parallel' and '並行' not in (r.get('jp_note') or ''):
        err.append(f'{rid}: jp_parallel なのに jp_note に「並行」がありません')
    if r.get('buy_status') == 'buy_online' and not r.get('buy_note'):
        err.append(f'{rid}: buy_online なのに buy_note（販売先）が空です')
    if r.get('region') not in REGIONS:
        err.append(f"{rid}: region が不正 {r.get('region')}")
    p = r.get('price')
    if p:
        if p.get('currency') not in CUR or not isinstance(p.get('amount'), (int, float)) or p['amount'] <= 0:
            err.append(f'{rid}: price が不正 {p}')
    if not str(r.get('source_url', '')).startswith('http'):
        err.append(f'{rid}: source_url が不正')
    for k in ('announced', 'first_seen'):
        v = r.get(k)
        if v:
            try:
                dt = datetime.date.fromisoformat(v) if len(v) == 10 else None
                if dt and dt > today + datetime.timedelta(days=1):
                    err.append(f'{rid}: {k} が未来の日付 {v}')
            except ValueError:
                err.append(f'{rid}: {k} の形式が不正 {v}')
    if not r.get('announced') and not r.get('first_seen'):
        err.append(f'{rid}: announced も first_seen も空です')
    dist = r.get('distillery')
    if names_ja and dist and dist not in names_ja:
        warn.append(f'{rid}: 蒸留所名「{dist}」が names_ja.json にありません（ブランド名・非公表なら可）')

# 前回コミットから件数が大きく減っていないか（データ消失の防止）
try:
    prev = json.loads(subprocess.run(['git', 'show', 'HEAD:' + PATH], capture_output=True, text=True, check=True).stdout)
    pn = len(prev.get('releases', []))
    if pn and len(R) < pn * 0.8:
        err.append(f'releases が前回の{pn}件から{len(R)}件に大きく減っています。削除は発表から1年超のものだけです')
    lost = {r['id'] for r in prev.get('releases', [])} - ids
    if lost:
        warn.append(f'前回からなくなった id: {sorted(lost)[:20]}')
except Exception:
    pass

if len(sys.argv) > 1 and sys.argv[1] == '--fix':
    d['count'] = len(R)
    open(PATH, 'w', encoding='utf-8').write(json.dumps(d, ensure_ascii=False, indent=1) + '\n')
    print('整形と count を修正しました。もう一度検証してください')
    sys.exit(0)

for w in warn:
    print('注意:', w)
for e in err:
    print('NG:', e)
print(f'releases {len(R)}件 / エラー {len(err)}件 / 注意 {len(warn)}件')
sys.exit(1 if err else 0)
