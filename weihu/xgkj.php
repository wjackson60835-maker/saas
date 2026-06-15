<?php
date_default_timezone_set('Asia/Shanghai'); 
$current_hour = date('H'); 
 
$start_hour = 20;
$end_hour = 23;
 
if ($current_hour < $start_hour || $current_hour > $end_hour) {
  
   echo "当前时间段未到采集时候";
   exit;
}
$url = "https://a6tkapi1.com/gallerynew/h5/index/lastLotteryRecord?lotteryType=1&lotteryPage=1"; 

$json = file_get_contents( $url ); 

$object = json_decode($json);

$originalDataList = $object->data->originalDataList;
$period = $object->period;

$haoma=$originalDataList[0].",".$originalDataList[1].",".$originalDataList[2].",".$originalDataList[3].",".$originalDataList[4].",".$originalDataList[5].",".$originalDataList[6];

$length = strlen($haoma);
if($length !=20){ exit();}
$array = json_decode($json, true);

$qishu = "2026".$array['data']['period'];
$xiayiqi="2026".$array['data']['nextLotteryNumber'];
$xiayiqikaijiangshijian=$array['data']['nextLotteryTime']." 21:30";
$timestamp2 = strtotime($xiayiqikaijiangshijian);
$kaijiangshijian=$array['data']['lotteryTime']." 21:30";
$timestamp = strtotime($kaijiangshijian);

$host = 'localhost';
$dbname = 'idzcucrw_1550lh';
$user = 'idzcucrw_1550lh';
$pass = 'kaifa491550lh';
 
$mysqli = new mysqli($host, $user, $pass, $dbname);
 
if ($mysqli->connect_error) {
    die("连接失败: " . $mysqli->connect_error);
}
 
$result = $mysqli->query("SELECT COUNT(*) FROM ay_xgkjdata WHERE type='1'  and data='$haoma'");
$row = $result->fetch_row();
$tongji=$row[0];

if($tongji == 1) { 
     echo "chunzai";
}else{
	
	$sqls = "INSERT INTO ay_xgkjdata (type,time, number,data,gid,nyr) VALUES ('1','$timestamp','$qishu','$haoma','1','$kaijiangshijian')";
 

if ($mysqli->query($sqls) === TRUE) {
    echo "新记录已成功插入。";
} else {
    echo "Error: " . $sqls . "<br>" . $mysqli->error;
}
	
}

$results = $mysqli->query("SELECT COUNT(*) FROM ay_xgkjdata_time WHERE type='1'  and actionNo='$xiayiqi'");
$rows = $results->fetch_row();
$tongjis=$rows[0];

if($tongjis == 1) { 
     echo "chunzai";
}else{
	
	$sqlss = "INSERT INTO ay_xgkjdata_time (type,actionNo, lhcTime,nyr) VALUES ('1','$xiayiqi','$timestamp2','$xiayiqikaijiangshijian')";
 

if ($mysqli->query($sqlss) === TRUE) {
    echo "新记录已成功插入。";
} else {
    echo "Error: " . $sqlss . "<br>" . $mysqli->error;
}
	
}

$mysqli->close();
?>