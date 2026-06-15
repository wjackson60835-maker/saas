<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = 'UTF-8') { return preg_match_all('/./us', $s, $m) ? count($m[0]) : 0; }
    function mb_substr($s, $st, $l = null, $e = 'UTF-8') {
        preg_match_all('/./us', $s, $m); $c = $m[0];
        if ($l === null) $l = count($c) - $st;
        return implode('', array_slice($c, $st, $l));
    }
}
require_once dirname(__DIR__) . '/ajax/pingma_bet_parser.php';
require_once dirname(__DIR__) . '/ajax/lhc_lookup.php';

$parsed = pingma_parse_submit_text(file_get_contents(dirname(__DIR__) . '/doc/pingma_full_coverage_test.txt'));
$prepared = array_map('pingma_recalc_bet_amounts', $parsed['bets'] ?? array());
$drawTs = strtotime('2026-06-09 21:30:00');
$stake = array_sum(array_map(static fn($b) => (float)($b['total_amount']??0), $prepared));
$targetNet = round($stake * 0.7, 2);
$targetPayout = round($stake - $targetNet, 2);
echo "Stake {$stake}  target 70% net={$targetNet} maxPayout={$targetPayout}\n";

$num = []; $zod = [];
foreach ($prepared as $bet) {
    ((string)($bet['selection_type']??'')==='zodiac') ? $zod[]=$bet : $num[]=$bet;
}
function fast_eval($num,$zod,$stake,$z,$ts){
    $p=0; foreach($num as $b) $p+=(float)pingma_calc_bet_payout($b,$z,'',$ts)['payout'];
    foreach($zod as $b) $p+=(float)pingma_calc_bet_payout($b,$z,'',$ts)['payout'];
    $p=round($p,2); return ['payout'=>$p,'net'=>round($stake-$p,2),'rate'=>round(100*($stake-$p)/$stake,2)];
}
$w=[]; for($i=1;$i<=49;$i++) $w[str_pad((string)$i,2,'0',STR_PAD_LEFT)]=0.0;
foreach($prepared as $bet){
    $groups=pingma_bet_resolve_groups($bet); $odds=pingma_payout_odds_for_play_type((string)($bet['play_type']??''));
    $unit=(float)($bet['amount_per_group']??0)*$odds; if($unit<=0||!$groups) continue;
    $stype=(string)($bet['selection_type']??'number');
    foreach($groups as $g){ if(!is_array($g)||!$g) continue;
        if($stype==='zodiac'){ foreach($g as $sx){ foreach(lhc_numbers_for_shengxiao((string)$sx,$drawTs) as $b){
            $n=str_pad((string)((int)$b),2,'0',STR_PAD_LEFT); $w[$n]+=$unit/max(1,count(lhc_numbers_for_shengxiao((string)$sx,$drawTs)));
        }} } else { $sh=$unit/count($g); foreach($g as $x){ $n=pingma_normalize_number_token((string)$x); if($n)$w[$n]+=$sh; }}
    }
}
$sorted=array_keys($w); usort($sorted,fn($a,$b)=>($w[$a]<=>$w[$b])?:strcmp($a,$b));

function search_pool($pool,$num,$zod,$stake,$ts){
    $n=count($pool); if($n<6) return null;
    $idx=range(0,5); $best=null; $bestZ=[];
    while(true){
        $z=[]; foreach($idx as $i) $z[]=$pool[$i]; sort($z,SORT_STRING);
        $ev=fast_eval($num,$zod,$stake,$z,$ts);
        if($best===null||$ev['payout']<$best['payout']){$best=$ev;$bestZ=$z;}
        $i=5; while($i>=0&&$idx[$i]===$n-6+$i) $i--;
        if($i<0) break; $idx[$i]++; for($j=$i+1;$j<6;$j++) $idx[$j]=$idx[$j-1]+1;
    }
    return ['z'=>$bestZ]+$best;
}

foreach([22,24,26,28,30] as $ps){
    $pool=array_slice($sorted,0,$ps);
    $c=1; for($i=0;$i<6;$i++) $c=intdiv($c*($ps-$i),$i+1);
    $t0=microtime(true);
    $r=search_pool($pool,$num,$zod,$stake,$drawTs);
    $sec=round(microtime(true)-$t0,2);
    if(!$r) continue;
    $ok=$r['net']>=$targetNet?'YES':'no';
    echo "pool={$ps} C={$c} {$sec}s z=".implode(',',$r['z'])." payout={$r['payout']} net={$r['net']} rate={$r['rate']}% hit70%={$ok}\n";
}
