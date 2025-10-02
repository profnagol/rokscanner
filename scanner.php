<?php

date_default_timezone_set('Europe/Paris');
error_reporting(E_ALL);
ini_set('display_errors', true);
$basedir = realpath(dirname(__FILE__));
$basedir = str_replace('\\', '/', $basedir);
$basedir = str_replace('//', '/', $basedir);

require_once $basedir . '/rokscan.class.php';

$rokscan = new rokscan();

$rokscan->scan(600);

unset($rokscan);

ADB::showLog();