# -*- coding: utf-8 -*-
from itertools import combinations

out_path = r'd:\saas\tools\_payout_gen_out.txt'
zheng = {'02', '10', '25', '26', '36', '03'}
zmap = {
    '01': '马', '13': '马', '25': '马', '37': '马', '49': '马',
    '12': '羊', '24': '羊', '36': '羊', '48': '羊',
    '11': '猴', '23': '猴', '35': '猴', '47': '猴',
    '10': '鸡', '22': '鸡', '34': '鸡', '46': '鸡',
    '09': '狗', '21': '狗', '33': '狗', '45': '狗',
    '08': '猪', '20': '猪', '32': '猪', '44': '猪',
    '07': '鼠', '19': '鼠', '31': '鼠', '43': '鼠',
    '06': '牛', '18': '牛', '30': '牛', '42': '牛',
    '05': '虎', '17': '虎', '29': '虎', '41': '虎',
    '04': '兔', '16': '兔', '28': '兔', '40': '兔',
    '03': '龙', '15': '龙', '27': '龙', '39': '龙',
    '02': '蛇', '14': '蛇', '26': '蛇', '38': '蛇',
}
zh = set(zmap[b] for b in list(zheng) + ['43'])
odds = {
    'sanzhongsan': 705,
    'erzhonger': 63,
    'erxiao': 3.1,
    'sanxiao': 11,
    'sixiao': 31,
    'wuxiao': 108,
}
data = [
    (1, '复式三中三02.26.43.36.25各50', 'sanzhongsan', 3, ['02', '26', '43', '36', '25'], True),
    (2, '复式三中三02.26.43.36.25各组50', 'sanzhongsan', 3, ['02', '26', '43', '36', '25'], True),
    (3, '复复式三中三02.26.43.36.25.29各组50', 'sanzhongsan', 3, ['02', '26', '43', '36', '25', '29'], True),
    (4, '复式三中三02.26.43.36.25.29.03各组50', 'sanzhongsan', 3, ['02', '26', '43', '36', '25', '29', '03'], True),
    (5, '二中二26-36 50元', 'erzhonger', 2, ['26', '36'], True),
    (6, '二中二26-36 50圆', 'erzhonger', 2, ['26', '36'], True),
    (7, '二中二26-36 50米', 'erzhonger', 2, ['26', '36'], True),
    (8, '复式二中二26-36-10各50', 'erzhonger', 2, ['26', '36', '10'], True),
    (9, '复式二中二26-36-10-30各组50', 'erzhonger', 2, ['26', '36', '10', '30'], True),
    (10, '复式二中二26-36-10-30-02各组50', 'erzhonger', 2, ['26', '36', '10', '30', '02'], True),
    (11, '复式二中二26-36-10-30-02-03各组50', 'erzhonger', 2, ['26', '36', '10', '30', '02', '03'], True),
    (12, '复式二中二26-36-10-30-02-03-04各组50', 'erzhonger', 2, ['26', '36', '10', '30', '02', '03', '04'], True),
    (13, '复式二肖鼠牛虎各50', 'erxiao', 2, list('鼠牛虎'), False),
    (14, '复式二肖鼠牛虎各组50', 'erxiao', 2, list('鼠牛虎'), False),
    (15, '复式二肖鼠牛虎兔各组50', 'erxiao', 2, list('鼠牛虎兔'), False),
    (16, '复式二肖鼠牛虎兔龙各组50', 'erxiao', 2, list('鼠牛虎兔龙'), False),
    (17, '复式二肖鼠牛虎兔龙蛇各组50', 'erxiao', 2, list('鼠牛虎兔龙蛇'), False),
    (18, '复式二肖鼠牛虎兔龙蛇马各组50', 'erxiao', 2, list('鼠牛虎兔龙蛇马'), False),
    (19, '复式三肖鼠牛虎兔各50', 'sanxiao', 3, list('鼠牛虎兔'), False),
    (20, '复式三肖鼠牛虎兔龙各组50', 'sanxiao', 3, list('鼠牛虎兔龙'), False),
    (21, '复式三肖鼠牛虎兔龙蛇各组50', 'sanxiao', 3, list('鼠牛虎兔龙蛇'), False),
    (22, '复式三肖鼠牛虎兔龙蛇马各组50', 'sanxiao', 3, list('鼠牛虎兔龙蛇马'), False),
    (23, '复式四肖鼠牛虎兔龙各50', 'sixiao', 4, list('鼠牛虎兔龙'), False),
    (24, '复式四肖鼠牛虎兔龙蛇各组50', 'sixiao', 4, list('鼠牛虎兔龙蛇'), False),
    (25, '复式四肖鼠牛虎兔龙蛇马各组50', 'sixiao', 4, list('鼠牛虎兔龙蛇马'), False),
    (26, '复式五肖鼠牛虎兔龙蛇各50', 'wuxiao', 5, list('鼠牛虎兔龙蛇'), False),
    (27, '复式五肖鼠牛虎兔龙蛇马各组50', 'wuxiao', 5, list('鼠牛虎兔龙蛇马'), False),
]


def fg(g, num):
    return '-'.join(g) if num else ''.join(g)


ts = tp = 0
with open(out_path, 'w', encoding='utf-8') as out:
    for no, raw, pt, k, sel, num in data:
        gs = [fg(g, num) for g in combinations(sel, k)]
        hs = [
            fg(g, num) for g in combinations(sel, k)
            if (all(x in zheng for x in g) if num else all(x in zh for x in g))
        ]
        st = len(gs) * 50
        py = len(hs) * 50 * odds[pt]
        ts += st
        tp += py
        sel_s = '-'.join(sel) if num else ''.join(sel)
        out.write('### 第 %d 条\n\n' % no)
        out.write('原文：`%s`\n\n' % raw)
        out.write('- 选号/肖：%s\n' % sel_s)
        out.write('- 展开组数：%d；每组 50 元；下注 **%d** 元\n' % (len(gs), st))
        out.write('- 全部组合：%s\n' % '、'.join(gs))
        out.write('- 中奖组数：**%d**；中奖组合：%s\n' % (len(hs), '、'.join(hs) if hs else '（无）'))
        out.write('- 赔率：%s；派彩：**%s** 元\n\n' % (odds[pt], f'{int(py):,}'))
    out.write('TOTAL_STAKE=%d TOTAL_PAYOUT=%d\n' % (ts, int(tp)))
print(ts, int(tp))
