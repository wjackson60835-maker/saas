<?php
date_default_timezone_set('Asia/Shanghai'); 
$current_hour = date('H'); 
 
$start_hour = 20;
$end_hour = 23;
if ($current_hour < $start_hour || $current_hour > $end_hour) {

   echo "当前时间段未到采集时候";
   exit;
}
$api = '/ajax/zoqishu.php';
$resource = file_get_contents( $api );  
$data = json_decode( $resource , 1 );
$qishu = "2026".$data['qishu'];
$qishuyi=$qishu;
$qishuer=$qishuyi -1 ;
$qishusan=$qishuyi -2 ;
$qishusi=$qishuyi -3 ;
$qishuwu=$qishuyi -4 ;
$qishuliu=$qishuyi -5 ;
$host = 'localhost';
$dbname = 'idzcucrw_1550lh';
$user = 'idzcucrw_1550lh';
$pass = 'kaifa491550lh';
 

// 创建数据库连接
$conn = new mysqli($host, $user, $pass, $dbname);
 
// 检查连接
if ($conn->connect_error) {
    die("连接失败: " . $conn->connect_error);
}

$query = "SELECT * FROM ay_kjdata WHERE type='1'  and number='$qishuer'";
$query2 = "SELECT * FROM ay_kjdata WHERE type='1'  and number='$qishusan'";
$query3 = "SELECT * FROM ay_kjdata WHERE type='1'  and number='$qishusi'";
$query4 = "SELECT * FROM ay_kjdata WHERE type='1'  and number='$qishuwu'";
$query5 = "SELECT * FROM ay_kjdata WHERE type='1'  and number='$qishuliu'";
// 执行查询
$result = $conn->query($query);
 $result2 = $conn->query($query2);
 $result3 = $conn->query($query3);
 $result4 = $conn->query($query4);
 $result5 = $conn->query($query5);
// 检查结果
if ($result) {
    // 处理结果
    while ($row = $result->fetch_assoc()) {
        // 这里处理每一行数据
$qishuyi=substr($qishuyi, -3);
$qishu1=substr($row['number'], -3);
$numbers = $row['data']; 
$stringNumber = (string)$numbers;
$haoma1 = substr($stringNumber, 0, 2);
$haoma2 = substr($stringNumber, 3, 2);
$haoma3 = substr($stringNumber, 6, 2);
$haoma4 = substr($stringNumber, 9, 2);
$haoma5 = substr($stringNumber, 12, 2);
$haoma6 = substr($stringNumber, 15, 2);
$haoma7 = substr($stringNumber, 18, 2);
$html='var apiurl="https://bbs.kaixin49.com:4433/wanfa";
const jsonData = [
    {"qishu":"'. $qishuyi . '","nian":"2026","hao1":"00","hao2":"00","hao3":"00","hao4":"00","hao5":"00","hao6":"00","hao7":"00"},
    {"qishu":"'. $qishu1 . '","nian":"2026","hao1":"'. $haoma1 . '","hao2":"'. $haoma2 . '","hao3":"'. $haoma3 . '","hao4":"'. $haoma4 . '","hao5":"'. $haoma5 . '","hao6":"'. $haoma6 . '","hao7":"'. $haoma7 . '"},';
    }
	
//disantiao
   
    // 释放结果
    $result->free();
}

if ($result2) {
    // 处理结果
    while ($row2 = $result2->fetch_assoc()) {
        // 这里处理每一行数据
$qishu1=substr($row2['number'], -3);
$numbers = $row2['data']; 
$stringNumber = (string)$numbers;
$haoma1 = substr($stringNumber, 0, 2);
$haoma2 = substr($stringNumber, 3, 2);
$haoma3 = substr($stringNumber, 6, 2);
$haoma4 = substr($stringNumber, 9, 2);
$haoma5 = substr($stringNumber, 12, 2);
$haoma6 = substr($stringNumber, 15, 2);
$haoma7 = substr($stringNumber, 18, 2);
$html2='
    {"qishu":"'. $qishu1 . '","nian":"2026","hao1":"'. $haoma1 . '","hao2":"'. $haoma2 . '","hao3":"'. $haoma3 . '","hao4":"'. $haoma4 . '","hao5":"'. $haoma5 . '","hao6":"'. $haoma6 . '","hao7":"'. $haoma7 . '"},';
    }
	
//disantiao
   
    // 释放结果
    $result2->free();
}

if ($result3) {
    // 处理结果
    while ($row3 = $result3->fetch_assoc()) {
        // 这里处理每一行数据
$qishu1=substr($row3['number'], -3);
$numbers = $row3['data']; 
$stringNumber = (string)$numbers;
$haoma1 = substr($stringNumber, 0, 2);
$haoma2 = substr($stringNumber, 3, 2);
$haoma3 = substr($stringNumber, 6, 2);
$haoma4 = substr($stringNumber, 9, 2);
$haoma5 = substr($stringNumber, 12, 2);
$haoma6 = substr($stringNumber, 15, 2);
$haoma7 = substr($stringNumber, 18, 2);
$html3='
    {"qishu":"'. $qishu1 . '","nian":"2026","hao1":"'. $haoma1 . '","hao2":"'. $haoma2 . '","hao3":"'. $haoma3 . '","hao4":"'. $haoma4 . '","hao5":"'. $haoma5 . '","hao6":"'. $haoma6 . '","hao7":"'. $haoma7 . '"},';
    }
	
//disantiao
   
    // 释放结果
    $result3->free();
}


if ($result4) {
    // 处理结果
    while ($row4 = $result4->fetch_assoc()) {
        // 这里处理每一行数据
$qishu1=substr($row4['number'], -3);
$numbers = $row4['data']; 
$stringNumber = (string)$numbers;
$haoma1 = substr($stringNumber, 0, 2);
$haoma2 = substr($stringNumber, 3, 2);
$haoma3 = substr($stringNumber, 6, 2);
$haoma4 = substr($stringNumber, 9, 2);
$haoma5 = substr($stringNumber, 12, 2);
$haoma6 = substr($stringNumber, 15, 2);
$haoma7 = substr($stringNumber, 18, 2);
$html4='
    {"qishu":"'. $qishu1 . '","nian":"2026","hao1":"'. $haoma1 . '","hao2":"'. $haoma2 . '","hao3":"'. $haoma3 . '","hao4":"'. $haoma4 . '","hao5":"'. $haoma5 . '","hao6":"'. $haoma6 . '","hao7":"'. $haoma7 . '"},';
    }
	
//disantiao
   
    // 释放结果
    $result4->free();
} 


if ($result5) {
    // 处理结果
    while ($row5 = $result5->fetch_assoc()) {
        // 这里处理每一行数据
$qishu1=substr($row5['number'], -3);
$numbers = $row5['data']; 
$stringNumber = (string)$numbers;
$haoma1 = substr($stringNumber, 0, 2);
$haoma2 = substr($stringNumber, 3, 2);
$haoma3 = substr($stringNumber, 6, 2);
$haoma4 = substr($stringNumber, 9, 2);
$haoma5 = substr($stringNumber, 12, 2);
$haoma6 = substr($stringNumber, 15, 2);
$haoma7 = substr($stringNumber, 18, 2);
$html5='
    {"qishu":"'. $qishu1 . '","nian":"2026","hao1":"'. $haoma1 . '","hao2":"'. $haoma2 . '","hao3":"'. $haoma3 . '","hao4":"'. $haoma4 . '","hao5":"'. $haoma5 . '","hao6":"'. $haoma6 . '","hao7":"'. $haoma7 . '"} ];';
    }
	
//disantiao
   
    // 释放结果
    $result5->free();
}
// 关闭连接
$conn->close();

$zuheshuchu=$html.$html2.$html3.$html4.$html5;
$filePath = "data.js"; // 指定文件路径和文件名
 
// 将变量$a的内容写入文件
file_put_contents($filePath, $zuheshuchu);
?>