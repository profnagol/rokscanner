<?php

date_default_timezone_set('Europe/Paris');
error_reporting(E_ALL);
ini_set('display_errors', true);
$basedir = realpath(dirname(__FILE__));
$basedir = str_replace('\\', '/', $basedir);
$basedir = str_replace('//', '/', $basedir);

require_once $basedir . '/rokscan.class.php';

$args = array();

if(count($argv)>=2) {
	foreach($argv as $v) {
		if(preg_match('`^\-+([^=]+)=?([^=]+)?$`', $v, $reg)) {
			if(isset($reg[2])) {
				$args[$reg[1]] = $reg[2];
			} else {
				$args[$reg[1]] = 1;
			}
		}
	}
}

//default values
$pass = 1;
$howmany = 600; //might be a lot but keep in mind if we want to get most of the smaller accounts we might need to go that far
$print = false;
$onlyid = false;
$builduidnamedb = false;
$showlog = false; //great for debugging useless for normal user

if(count($args)>0) {
	if(isset($args['print']) && (int)$args['print'] == 1) {
		$print = true;
	}
	if(isset($args['builduidnamedb']) && (int)$args['builduidnamedb'] == 1) {
		$builduidnamedb = true;
	}
	if(isset($args['onlyid']) && (int)$args['onlyid'] == 1) {
		$onlyid = true;
	}
	if(isset($args['showlog'])) {
		$showlog = true;
	}
	if(isset($args['2pass'])) {
		$pass = 2;
		$target = $args['2pass'];
		if(isset($args['justfirstpass'])) {
			$mod=1;
		}
		if(isset($args['justsecondpass'])) {
			$mod=2;
		}
		if(isset($args['allpasses'])) {
			$mod=3;
		}
	}
	if(isset($args['howmany'])) {
		$howmany = (int)$args['howmany'];
	}
}


if($pass == 1) {
	$rokscan = new rokscan();
	$rokscan->scan($howmany, $print, $onlyid, $builduidnamedb);
} else {
	if($mod == 2) {
		$rokscan = new rokscan(true);
	} else {
		$rokscan = new rokscan();
	}
	$rokscan->multipassscan($howmany, $print, $onlyid, $builduidnamedb, $target, $mod);
}

unset($rokscan);

if($showlog) {
	ADB::showLog();
}