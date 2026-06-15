<?php
require_once __DIR__ . '/lhc_lookup.php';
$config = require '../config/database.php';
$host = $config["database"]['host'];
$dbname = $config["database"]['dbname'];
$user = $config["database"]['user'];
$pass = $config["database"]['passwd'];

function GetBoseName2($number,$type){
	$arr['hm redBoClass']='01,02,07,08,12,13,18,19,23,24,29,30,34,35,40,45,46';
	$arr['hm greenBoClass']='05,06,11,16,17,21,22,27,28,32,33,38,39,43,44,49';
	$arr['hm blueBoClass']='03,04,09,10,14,15,20,25,26,31,36,37,41,42,47,48';
	foreach($arr as $key=>$val){
		$a=explode(',',$val);
		foreach($a as $k=>$v){
			if($v==$number){
				$name=$key;
				break;
			}
		}
	}
	if($type){
		return $name;
	}else{

	}
}

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
$nowtime="1672497100";
$zhidingshijian="1672583300";
$kaijiangshuju="select * from ay_kjdata where  time < '$nowtime' and type = 1 order by number desc limit 365";
$kjdata = $pdo->query($kaijiangshuju);

$tableNames = array();
 

while ($row = $kjdata->fetch(PDO::FETCH_NUM)) {
      $haoma=$row[4];
	  $qishu=$row[3];
      $haoma = explode(',', $haoma);
      $date = date('Y-m-d', $row[2]); 
$haoma1=$haoma[0];
$haoma2=$haoma[1];
$haoma3=$haoma[2];
$haoma4=$haoma[3];
$haoma5=$haoma[4];
$haoma6=$haoma[5];
$haoma7=$haoma[6];

$__sx_ts = (int) $row[2];
$shengxiao1=lhc_shengxiao_for_number($haoma1,$__sx_ts);$shuying1=GetshuxingName($haoma1,1);
$shengxiao2=lhc_shengxiao_for_number($haoma2,$__sx_ts);$shuying2=GetshuxingName($haoma2,1);
$shengxiao3=lhc_shengxiao_for_number($haoma3,$__sx_ts);$shuying3=GetshuxingName($haoma3,1);
$shengxiao4=lhc_shengxiao_for_number($haoma4,$__sx_ts);$shuying4=GetshuxingName($haoma4,1);
$shengxiao5=lhc_shengxiao_for_number($haoma5,$__sx_ts);$shuying5=GetshuxingName($haoma5,1);
$shengxiao6=lhc_shengxiao_for_number($haoma6,$__sx_ts);$shuying6=GetshuxingName($haoma6,1);
$shengxiao7=lhc_shengxiao_for_number($haoma7,$__sx_ts);$shuying7=GetshuxingName($haoma7,1);
$dangqi="第".$kjdatas['number']."期"."  ".date('Y-m-d', $kjdatas['time'])."   ".$xingqi;

$ys1 = GetBoseName2($haoma1,1);
$ys2 = GetBoseName2($haoma2,1);
$ys3 = GetBoseName2($haoma3,1);
$ys4 = GetBoseName2($haoma4,1);
$ys5 = GetBoseName2($haoma5,1);
$ys6 = GetBoseName2($haoma6,1);
$ys7 = GetBoseName2($haoma7,1);

	 $tableNames[] ='{"qishu":"'.$qishu.'","date":"'.$date.'","haoma1":"'.$haoma[0].'","haoma2":"'.$haoma[1].'","haoma3":"'.$haoma[2].'","haoma4":"'.$haoma[3].'","haoma5":"'.$haoma[4].'","haoma6":"'.$haoma[5].'","haoma7":"'.$haoma[6].'","shengxiao1":"'.$shengxiao1.'","shengxiao2":"'.$shengxiao2.'","shengxiao3":"'.$shengxiao3.'","shengxiao4":"'.$shengxiao4.'","shengxiao5":"'.$shengxiao5.'","shengxiao6":"'.$shengxiao6.'","shengxiao7":"'.$shengxiao7.'","shuying1":"'.$shuying1.'","shuying2":"'.$shuying2.'","shuying3":"'.$shuying3.'","shuying4":"'.$shuying4.'","shuying5":"'.$shuying5.'","shuying6":"'.$shuying6.'","shuying7":"'.$shuying7.'","ys1":"'.$ys1.'","ys2":"'.$ys2.'","ys3":"'.$ys3.'","ys4":"'.$ys4.'","ys5":"'.$ys5.'","ys6":"'.$ys6.'","ys7":"'.$ys7.'}';
}
 
header('Content-type: application/json');
$zuhe=str_replace('"{\"', '{"', json_encode($tableNames));
$zuhe=str_replace('\"', '"', $zuhe);
$zuhe=str_replace('}"', '"}', $zuhe);
echo $zuhe;
?>