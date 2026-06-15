<?php
date_default_timezone_set('Asia/Shanghai'); 
$current_hour = date('H'); 
 
$start_hour = 21;
$end_hour = 22;
 
if ($current_hour < $start_hour || $current_hour > $end_hour) {
    
   echo "当前时间段未到采集时候";
   exit;
}
// $url = "https://a6tkapi1.com/gallerynew/h5/index/lastLotteryRecord?lotteryType=5&lotteryPage=1"; 
$url = "https://kj6.kkj.app:1888/data/v_am.json"; 

$jsonData = file_get_contents( $url ); 

$dataArray = json_decode($jsonData, true);

if (json_last_error() === JSON_ERROR_NONE) {

    $numbers = [];

    foreach ($dataArray['Data'] as $item) {
        $numbers[] = $item['number'];
		$qiValue = $dataArray['Qi'];
    }

   $haomaquan=implode(",", $numbers);
} 
$qishu = "2026".$qiValue;
$haoma=$haomaquan;
$length = strlen($haoma);
$kaijiangshijian=date("Y-m-d 21:30:00");
$timestamp = strtotime($kaijiangshijian);
if($length !=20){ exit();}



$host = 'localhost';
$dbname = 'idzcucrw_1550lh';
$user = 'idzcucrw_1550lh';
$pass = 'kaifa491550lh';
 
// 创建连接
$mysqli = new mysqli($host, $user, $pass, $dbname);
 
// 检查连接
if ($mysqli->connect_error) {
    die("连接失败: " . $mysqli->connect_error);
}
 
$result = $mysqli->query("SELECT COUNT(*) FROM ay_xakjdata WHERE type='1'  and data='$haoma'");
$row = $result->fetch_row();
$tongji=$row[0];

if($tongji == 1) { 
     echo "chunzai";
}else{
	
	$sqls = "INSERT INTO ay_xakjdata (type,time, number,data,gid,nyr) VALUES ('1','$timestamp','$qishu','$haoma','1','$kaijiangshijian')";
 

if ($mysqli->query($sqls) === TRUE) {
    echo "新记录已成功插入。";
} else {
    echo "Error: " . $sqls . "<br>" . $mysqli->error;
}
	
}

$mysqli->close();
?>