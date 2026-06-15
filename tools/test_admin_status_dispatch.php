<?php
$_GET['action'] = 'admin_status';
$_SERVER['REQUEST_METHOD'] = 'POST';
chdir(dirname(__DIR__));
require 'ajax/collect/api.php';
