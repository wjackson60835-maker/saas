import { writeFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";

const __dirname = dirname(fileURLToPath(import.meta.url));

function ent(s) {
  let out = "";
  for (const ch of s) {
    const o = ch.codePointAt(0);
    out += o < 128 ? ch : `&#x${o.toString(16).toUpperCase()};`;
  }
  return out;
}

const lines = [];
lines.push("<!-- ASCII+entities only - safe on Windows/GBK hosting -->");
lines.push('<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">');
lines.push("<style>");
lines.push("html, body { width: 100%; margin: 0; padding: 0; }");
lines.push(".sx th { height: 30px; background: #cec4aa;}");
lines.push(".sx td { padding: 5px 0; text-align: center; border: solid 1px #d8ccb4; word-wrap: break-word; background: #fff;}");
lines.push(".sx td.a-left { text-align: left; padding-left: 5px; padding-right: 5px;}");
lines.push(".sx span { height: 18px; line-height: 18px; margin: 0 2px; padding: 0 2px; font-size: 12px; color: #fff; display: inline-block; background: #f00;}");
lines.push(".sx span.green { background: #009933;}");
lines.push(".sx span.blue { background: #3366ff;}");
lines.push("ul.sx1 { list-style: none; margin: 0; padding: 0; }");
lines.push("ul.sx1 li { float: left; width: 33.33%; padding: 5px 0; list-style: none; }");
lines.push("ul.sx1 li dl { margin: 0; padding: 0; }");
lines.push("ul.sx1 li dl dt img { width: 40px; height: 40px;}");
lines.push('body {max-width: 910px; margin: 0 auto; background: #fff; font-size:14px; font-family: "Microsoft Yahei",Arial;}');
lines.push('.clearfix:after { content: ""; display: table; clear: both; }');
lines.push('</style><base href="." target="_blank"></head><body><div class="sx">');

const thMain = "2026马年（十二生肖号码对照）";
lines.push(
  `<table width="100%"><tr><th>${ent(thMain)}</th></tr><tr><td><ul class="sx1 clearfix">`
);

const rowsZodiac = [
  ["马", "12ma.gif", "[马 鼠]", '<span>01</span><span>13</span><span class="blue">25</span><span class="blue">37</span><span class="green">49</span>'],
  ["羊", "12yang.gif", "[羊 牛]", '<span>12</span><span>24</span><span class="blue">36</span><span class="blue">48</span>'],
  ["猴", "12hou.gif", "[猴 虎]", '<span class="green">11</span><span>23</span><span>35</span><span class="blue">47</span>'],
  ["鸡", "12ji.gif", "[鸡 兔]", '<span class="blue">10</span><span class="green">22</span><span class="green">34</span><span>46</span>'],
  ["狗", "12gou.gif", "[狗 龙]", '<span class="blue">09</span><span class="green">21</span><span class="green">33</span><span>45</span>'],
  ["猪", "12zhu.gif", "[猪 蛇]", '<span>08</span><span class="blue">20</span><span class="green">32</span><span class="green">44</span>'],
  ["鼠", "12shu.gif", "[鼠 马]", '<span>07</span><span>19</span><span class="blue">31</span><span class="green">43</span>'],
  ["牛", "12niu.gif", "[牛 羊]", '<span class="green">06</span><span>18</span><span>30</span><span class="blue">42</span>'],
  ["虎", "12hu.gif", "[虎 猴]", '<span class="green">05</span><span class="green">17</span><span>29</span><span class="blue">41</span>'],
  ["兔", "12tu.gif", "[兔 鸡]", '<span class="blue">04</span><span class="green">16</span><span class="green">28</span><span>40</span>'],
  ["龙", "12long.gif", "[龙 狗]", '<span class="blue">03</span><span class="blue">15</span><span class="green">27</span><span class="green">39</span>'],
  ["蛇", "12she.gif", "[蛇 猪]", '<span>02</span><span class="blue">14</span><span class="blue">26</span><span class="green">38</span>'],
];
for (const [name, gif, bracket, spans] of rowsZodiac) {
  lines.push(`<li><dl><dt>${ent(name)}<img src="./${gif}" alt="">${ent(bracket)}</dt><dd>${spans}</dd></dl></li>`);
}
lines.push("</ul></td></tr></table>");

lines.push(`<table width="100%"><tr><th colspan=2 style="background:#F1F1F1;">${ent("五行对照（2026）")}</th></tr>`);
const wuxing = [
  ["金", "#ffcc00", '<span class="blue">04</span><span>05</span><span class="blue">12</span><span>13</span><span class="green">26</span><span class="green">27</span><span>34</span><span class="blue">35</span><span class="green">42</span><span class="green">43</span>'],
  ["木", "#33cc33", '<span>08</span><span class="blue">09</span><span class="green">16</span><span class="green">17</span><span>24</span><span class="blue">25</span><span class="green">38</span><span class="green">39</span><span>46</span><span class="blue">47</span>'],
  ["水", "#3399ff", '<span>01</span><span class="blue">14</span><span class="green">15</span><span class="green">22</span><span class="blue">23</span><span>30</span><span class="blue">31</span><span class="green">44</span><span class="green">45</span>'],
  ["火", "#ff6600", '<span class="blue">02</span><span class="blue">03</span><span>10</span><span class="blue">11</span><span class="green">18</span><span class="green">19</span><span>32</span><span class="blue">33</span><span class="green">40</span><span class="green">41</span><span class="blue">48</span><span class="green">49</span>'],
  ["土", "#cc9900", '<span class="green">06</span><span class="green">07</span><span>20</span><span class="blue">21</span><span class="green">28</span><span>29</span><span class="blue">36</span><span class="blue">37</span>'],
];
for (const [w, c, sp] of wuxing) {
  lines.push(`<tr><td><font color="${c}">${ent(w)}</font></td><td class="a-left">${sp}</td></tr>`);
}
lines.push("</table>");

lines.push(`<table width="100%"><tr><th colspan=2>${ent("波色（2026）")}</th></tr>`);
lines.push(
  `<tr><td><font color="#ff0000">${ent("红波")}</font></td><td class="a-left"><span>01</span><span>02</span><span>07</span><span>08</span><span>12</span><span>13</span><span>18</span><span>19</span><span>23</span><span>24</span><span>29</span><span>30</span><span>34</span><span>35</span><span>40</span><span>45</span><span>46</span></td></tr>`
);
lines.push(
  `<tr><td><font color="#3366ff">${ent("蓝波")}</font></td><td class="a-left"><span class="blue">03</span><span class="blue">04</span><span class="blue">09</span><span class="blue">10</span><span class="blue">14</span><span class="blue">15</span><span class="blue">20</span><span class="blue">25</span><span class="blue">26</span><span class="blue">31</span><span class="blue">36</span><span class="blue">37</span><span class="blue">41</span><span class="blue">42</span><span class="blue">47</span><span class="blue">48</span></td></tr>`
);
lines.push(
  `<tr><td><font color="#009933">${ent("绿波")}</font></td><td class="a-left"><span class="green">05</span><span class="green">06</span><span class="green">11</span><span class="green">16</span><span class="green">17</span><span class="green">21</span><span class="green">22</span><span class="green">27</span><span class="green">28</span><span class="green">32</span><span class="green">33</span><span class="green">38</span><span class="green">39</span><span class="green">43</span><span class="green">44</span><span class="green">49</span></td></tr>`
);
lines.push("</table>");

lines.push(`<table width="100%"><tr><th colspan=2>${ent("合数单双对照表（2026马年参考）")}</th></tr>`);
lines.push(
  `<tr><td><font color="#3366ff">${ent("合单")}</font></td><td class="a-left"><span>01</span><span class="blue">03</span><span class="green">05</span><span class="green">07</span><span class="blue">09</span><span class="blue">10</span><span>12</span><span class="blue">14</span><span class="green">16</span><span class="green">18</span><span>21</span><span class="blue">23</span><span class="blue">25</span><span class="green">27</span><span class="green">29</span><span>30</span><span class="green">32</span><span>34</span><span class="blue">36</span><span class="green">38</span><span class="blue">41</span><span class="green">43</span><span class="green">45</span><span class="blue">47</span><span class="green">49</span></td></tr>`
);
lines.push(
  `<tr><td><font color="#3366ff">${ent("合双")}</font></td><td class="a-left"><span>02</span><span class="blue">04</span><span class="green">06</span><span>08</span><span class="green">11</span><span>13</span><span class="blue">15</span><span class="green">17</span><span>19</span><span class="blue">20</span><span class="green">22</span><span>24</span><span class="blue">26</span><span class="green">28</span><span class="blue">31</span><span class="green">33</span><span>35</span><span class="blue">37</span><span class="green">39</span><span>40</span><span class="blue">42</span><span class="green">44</span><span>46</span><span class="blue">48</span></td></tr>`
);
lines.push("</table>");

lines.push(`<table width="100%"><tr><th>${ent("生肖属性（2026马年）")}</th></tr>`);
const props = [
  ["生肖马", "午火；本命年；对冲子鼠；号码01、13、25、37、49。"],
  ["生肖羊", "未土；对冲丑牛；号码12、24、36、48。"],
  ["生肖猴", "申金；对冲寅虎；号码11、23、35、47。"],
  ["生肖鸡", "酉金；对冲卯兔；号码10、22、34、46。"],
  ["生肖狗", "戌土；对冲辰龙；号码09、21、33、45。"],
  ["生肖猪", "亥水；对冲巳蛇；号码08、20、32、44。"],
  ["生肖鼠", "子水；对冲午马；号码07、19、31、43。"],
  ["生肖牛", "丑土；对冲未羊；号码06、18、30、42。"],
  ["生肖虎", "寅木；对冲申猴；号码05、17、29、41。"],
  ["生肖兔", "卯木；对冲酉鸡；号码04、16、28、40。"],
  ["生肖龙", "辰土；对冲戌狗；号码03、15、27、39。"],
  ["生肖蛇", "巳火；对冲亥猪；号码02、14、26、38。"],
  ["【琴肖】", "兔、蛇、鸡"],
  ["【棋肖】", "鼠、牛、狗"],
  ["【书肖】", "虎、龙、马、猴"],
  ["【画肖】", "羊、猪"],
  ["男肖", "鼠、牛、虎、龙、马、猴、狗"],
  ["女肖", "兔、蛇、羊、鸡、猪"],
  ["天肖", "牛、兔、龙、马、猴、猪"],
  ["地肖", "鼠、虎、蛇、羊、鸡、狗"],
  ["单笔肖", "鼠、龙、马、蛇、鸡、猪"],
  ["双笔肖", "虎、猴、狗、兔、羊、牛"],
  ["文肖", "鼠、兔、龙、羊、鸡、猪"],
  ["武肖", "牛、虎、马、蛇、猴、狗"],
];
for (const [label, body] of props) {
  lines.push(`<tr><td><font color="#3366ff">${ent(label)}</font>${ent(body)}</td></tr>`);
}
lines.push(
  `<tr><td><font color="#3366ff">${ent("春肖")}</font>${ent("虎、兔、龙　")}<font color="#3366ff">${ent("夏肖")}</font>${ent("蛇、马、羊　")}<font color="#3366ff">${ent("秋肖")}</font>${ent("猴、鸡、狗　")}<font color="#3366ff">${ent("冬肖")}</font>${ent("猪、鼠、牛")}</td></tr>`
);
lines.push(
  `<tr><td><font color="#3366ff">${ent("温馨提示")}</font>${ent("以上内容仅供娱乐参考，请理性对待。")}</td></tr>`
);
lines.push("</table></div></body></html>");

const out = join(__dirname, "xinshuitie28.html");
const text = lines.join("\n") + "\n";
writeFileSync(out, text, { encoding: "ascii" });
console.log("Wrote", out, "length", text.length);
