<?php
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$domainName = $_SERVER['HTTP_HOST'];
$zuhe= $protocol . '://' . $domainName;
$url = $zuhe."/ajax/getcode.php"; 
$html = file_get_contents($url);
$data = json_decode($html, true); 
echo $data['qishu'];

$numbers = range(0, 4); 
shuffle($numbers); 
$result = array_slice($numbers, 0, 3); 
echo implode(',', $result); 

// 创建 SQL 查询语句
$sql = "INSERT INTO pc_data_time (type, actionNo,lhcTime) VALUES ('2','','$timestamp')";
 
// 执行 SQL 查询并获取结果
if ($conn->query($sql) === TRUE) {
    echo "新记录已成功插入。";
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}
 
// 关闭与数据库的连接
$conn->close();