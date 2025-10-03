<?php

require __DIR__ . '/vendor/autoload.php';

use thiagoalessio\TesseractOCR\TesseractOCR;

require_once $basedir . '/adb.class.php';

class rokscan extends ADB {
	public $tesseract;
	public $uidnamedb;
	public $log;
	public function __construct($skipadb=false, $host='127.0.0.1', $port='7555', $bin_path = 'deps/platform-tools/') {
		if(!$skipadb) {
			echo "\n".'Initializing ADB'."\n";
			parent::__construct($host, $port, $bin_path);
		}
		echo "\n".'Initializing Tesseract'."\n";
		$this->tesseract = new TesseractOCR();
		$this->tesseract->tessdataDir($GLOBALS['basedir'] . '/deps/tessdata');
		$this->tesseract->userWords($GLOBALS['basedir'] . '/deps/user-words.txt');
		echo "\n".'Initializing uidnamedb'."\n";
		if(!file_exists(dirname(__FILE__) . '/uidnamedb.txt')) {
			touch(dirname(__FILE__) . '/uidnamedb.txt');
		}
		$uidnamedbfile = file_get_contents(dirname(__FILE__) . '/uidnamedb.txt');
		$uidnamelines = explode("\n", $uidnamedbfile);
		$this->uidnamedb = array();
		foreach($uidnamelines as $l) {
			$parts = explode('==', $l);
			if(count($parts) == 2) {
				$this->uidnamedb[$parts[0]] = $parts[1];
			}
		}
		$this->log = '';
	}
	public function __destruct() {
		file_put_contents(dirname(__FILE__) . '/logs/' . date('YmdHi') . 'log.txt', $this->log);
		parent::__destruct();
	}

	public function verifyres() {
		//$this->_setScreenSize('1280x720');
		//$this->_setScreenSize('1600x900');
		$cur = $this->_getScreenSize();
		print_r($cur);
	}

	public function multipassscan($number=600, $print=false, $onlyid=false, $builduidnamedb=true, $target=1, $mod=3) {
		if($target == 1) {
			$target = uniqid();
		}
		$result = array();
		$ids = array(); //control variable to disallow the possibility to have multiple time the same id in the results although it will tolerate it if we get id=0
		$skip = false; //skip will be used incase we have unscannable items, which happens with people disapearing from KD but still listed as active -- only used in pass 1
		
		//pass 1 scan the KD and save screenshots to target*
		if($mod == 1 || $mod == 3) {
			echo 'Pass 1 scan the KD and save screenshots to target (' . $target . ')'."\n";
			for($i=0; $i<$number; $i++) {
				echo 'Progression: ' . (($i * 100) / $number) . '%'."\n";
				$line = array();
				sleep(1);
				$line = $this->openuser($i, $onlyid, $skip, $target);
				if($skip) {
					$i--; //do not consume one turn on a skip
					$skip = false;
				}
				if($line !== false) {
					$this->closeuser();
					sleep(1);
				} else {
					$skip = true;
				}
			}
		}
		if($mod == 2 || $mod == 3) {
			//pass2 read screenshots and produce result
			echo 'Pass 2 read screenshots from target and produce result'."\n";
			//in pass 2 we override number as we already know how many people we can produce result for
			$newnumber = count(glob($GLOBALS['basedir'] . '/temp_images/' . $target . '/*'));
			//but only if newnumber is less than number because we should allow someone to just test the first 10 if he wants
			if($newnumber <= $number) {
				$number = $newnumber;
			}
			echo 'Pass 2 new number is ' . $number ."\n";
			for($i=0; $i<$number; $i++) {
				echo 'Progression: ' . (($i * 100) / $number) . '%'."\n";
				$line = array();
				$line = $this->openlocaluser($i, $onlyid, $target);
				if(isset($line['id']) && (!in_array($line['id'], $ids) || (int)$line['id'] == 0)) {
					array_push($ids, $line['id']);
					array_push($result, $line);
				}
			}
			//builduidnamedb as a way to correct id sometimes when we cannot get a good result from tesseract
			//should only be ran when number is high enough to have data
			if($builduidnamedb && $number>=300) {
				$this->builduidnamedb($result);
			}
			//produce file
			$this->producefile($print, $target, $number, $result);
		}
	}

	public function scan($number=600, $print=false, $onlyid=false, $builduidnamedb=true) {
		$result = array();
		$ids = array(); //control variable to disallow the possibility to have multiple time the same id in the results although it will tolerate it if we get id=0
		$skip = false; //skip will be used incase we have unscannable items, which happens with people disapearing from KD but still listed as active
		for($i=0; $i<$number; $i++) {
			echo 'Progression: ' . (($i * 100) / $number) . '%'."\n";
			$line = array();
			sleep(1);
			$line = $this->openuser($i, $onlyid, $skip);
			if($skip) {
				$i--; //do not consume one turn on a skip
				$skip = false;
			}
			if($line !== false) {
				$this->closeuser();
				sleep(1);
				if(!in_array($line['id'], $ids) || (int)$line['id'] == 0) {
					array_push($ids, $line['id']);
					array_push($result, $line);
				}
			} else {
				$skip = true;
			}
		}
		//builduidnamedb as a way to correct id sometimes when we cannot get a good result from tesseract
		//should only be ran when number is high enough to have data
		if($builduidnamedb && $number>300) {
			$this->builduidnamedb($result);
		}
		//produce file
		$this->producefile($print, 1, $number, $result);
	}

	private function builduidnamedb($result) {
		echo "\n".'Producing uidnamedb'."\n";
		$tmptxt = '';
		foreach($result as $r) {
			$tmptxt .= $r['name'] . '==' . (int)$r['id'] . "\n";
		}
		file_put_contents(dirname(__FILE__) . '/uidnamedb.txt', $tmptxt);
	}

	private function producefile($print=false, $target=1, $number=600, $result=array()) {
		if($target == 1) {
			$target = uniqid();
		}
		if(count($result) == 0) {
			echo "\n".'No data to work with'."\n";
		} else {
			if($print === FALSE) {
				echo 'Producing file'."\n";
				$content = 'ID,Name,Power,Killpoints,Deads,T1 Kills,T2 Kills,T3 Kills,T4 Kills,T5 Kills,Total Kills,T45 Kills,Ranged,Rss Gathered,Rss Assistance,Helps,Alliance'."\n";
				foreach($result as $r) {
					$content .= $r['id'] . ',' . $r['name'] . ',' . $r['power'] . ',' . $r['kps'] . ',';
					$content .= (isset($r['deads']))?$r['deads']:0;
					$content .= ',';
					$content .= (isset($r['T1']))?$r['T1']:0;
					$content .= ',';
					$content .= (isset($r['T2']))?$r['T2']:0;
					$content .= ',';
					$content .= (isset($r['T3']))?$r['T3']:0;
					$content .= ',';
					$content .= (isset($r['T4']))?$r['T4']:0;
					$content .= ',';
					$content .= (isset($r['T5']))?$r['T5']:0;
					$content .= ',';
					$content .= (isset($r['Total']))?$r['Total kills']:0;
					$content .= ',';
					$content .= (isset($r['T45']))?$r['T45']:0;
					$content .= ',';
					$content .= (isset($r['ranged']))?$r['ranged']:0;
					$content .= ',';
					$content .= (isset($r['rssgather']))?$r['rssgather']:0;
					$content .= ',';
					$content .= (isset($r['rssassist']))?$r['rssassist']:0;
					$content .= ',';
					$content .= (isset($r['helps']))?$r['helps']:0;
					$content .= ',';
					$content .= $r['alliance'] . "\n";
				}
				file_put_contents($GLOBALS['basedir'] . '/TOP' . $number . '-' . date('Y') . '-' . date('m') . '-' . date('d') . '-1303-[' . $target . '].csv', $content);
				echo "\n".'Finished'."\n";
			} else {
				print_r($result);
				echo "\n".'Finished'."\n";
			}
		}
	}

	private function processtesseract($im, $coords) {
		try {
			$id = imagecrop($im, $coords);
			ob_start();
			imagepng($id, null, 0);
			$id_size = ob_get_length();
			$id_data = ob_get_contents();
			ob_end_clean();
			$this->tesseract->imageData($id_data, $id_size);
			$text = $this->tesseract->run();
			return $text;
		} catch(Exception $err) {
			echo $err->getMessage();
		}
		return '0';
	}

	public function randomcoords($start, $margin) {
		return rand($start-$margin, $start+$margin);
	}

	private function rgb2hsl($r, $g, $b) {
		$var_R = ($r / 255);
		$var_G = ($g / 255);
		$var_B = ($b / 255);

		$var_Min = min($var_R, $var_G, $var_B);
		$var_Max = max($var_R, $var_G, $var_B);
		$del_Max = $var_Max - $var_Min;

		$v = $var_Max;

		if ($del_Max == 0) {
			$h = 0;
			$s = 0;
		} else {
			$s = $del_Max / $var_Max;
			$del_R = ( ( ( $var_Max - $var_R ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
			$del_G = ( ( ( $var_Max - $var_G ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
			$del_B = ( ( ( $var_Max - $var_B ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;

			if      ($var_R == $var_Max) $h = $del_B - $del_G;
			else if ($var_G == $var_Max) $h = ( 1 / 3 ) + $del_R - $del_B;
			else if ($var_B == $var_Max) $h = ( 2 / 3 ) + $del_G - $del_R;

			if ($h < 0) $h++;
			if ($h > 1) $h--;
		}

		return array($h, $s, $v);
	}

	function hsl2rgb($h, $s, $v) {
		if($s == 0) {
			$r = $g = $B = $v * 255;
		} else {
			$var_H = $h * 6;
			$var_i = floor( $var_H );
			$var_1 = $v * ( 1 - $s );
			$var_2 = $v * ( 1 - $s * ( $var_H - $var_i ) );
			$var_3 = $v * ( 1 - $s * (1 - ( $var_H - $var_i ) ) );

			if       ($var_i == 0) { $var_R = $v     ; $var_G = $var_3  ; $var_B = $var_1 ; }
			else if  ($var_i == 1) { $var_R = $var_2 ; $var_G = $v      ; $var_B = $var_1 ; }
			else if  ($var_i == 2) { $var_R = $var_1 ; $var_G = $v      ; $var_B = $var_3 ; }
			else if  ($var_i == 3) { $var_R = $var_1 ; $var_G = $var_2  ; $var_B = $v     ; }
			else if  ($var_i == 4) { $var_R = $var_3 ; $var_G = $var_1  ; $var_B = $v     ; }
			else                   { $var_R = $v     ; $var_G = $var_1  ; $var_B = $var_2 ; }

			$r = $var_R * 255;
			$g = $var_G * 255;
			$B = $var_B * 255;
		}    
		return array($r, $g, $B);
	}

	private function imageSaturation(&$image, $saturationPercentage) {
		$width = imagesx($image);
		$height = imagesy($image);

		for($x = 0; $x < $width; $x++) {
			for($y = 0; $y < $height; $y++) {
				$rgb = imagecolorat($image, $x, $y);
				$r = ($rgb >> 16) & 0xFF;
				$g = ($rgb >> 8) & 0xFF;
				$b = $rgb & 0xFF;            
				$alpha = ($rgb & 0x7F000000) >> 24;
				list($h, $s, $l) = $this->rgb2hsl($r, $g, $b);         
				$s = $s * (100 + $saturationPercentage ) /100;
				if($s > 1) $s = 1;
				list($r, $g, $b) = $this->hsl2rgb($h, $s, $l);            
				imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, round($r), round($g), round($b), $alpha));
			}
		}
	}

	public function openlocaluser($i=0, $onlyid=false, $target=NULL) {
		//process mainscreen
		$name = file_get_contents($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/name.txt');
		list($id,$power,$kps,$alliance) = $this->processmainscreen($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/mainscreen.png', $name);

		if($onlyid === FALSE) {
			//process kp screen
			list($T1,$T2,$T3,$T4,$T5,$ranged) = $this->processkpscreen($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/kpscreen.png', $name);

			$calculatedkp = (round($T1 *  0.2) + ($T2 * 2) + ($T3 * 4) + ($T4 * 10) + ($T5 * 20));
			if($calculatedkp != $kps) {
				echo "\n" . 'There is a problem with KPs here, main KP screen is ' . $kps . ' but detail and formula give ' . $calculatedkp . "\n";
			}

			//process more screen
			list($deads,$rssgather,$rssassist,$helps) = $this->processmorestatsscreen($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/morestats.png', $name);

			return array(
				'id' => $id,
				'name' => $name,
				'power' => $power,
				'kps' => $kps, //should be round($T1 *  0.2) + ($T2 * 2) + ($T3 * 4) + ($T4 * 10) + ($T5 * 20)
				'deads' => $deads,
				'T1' => $T1,
				'T2' => $T2,
				'T3' => $T3,
				'T4' => $T4,
				'T5' => $T5,
				'Total kills' => $kps,
				'T45' => ($T4 * 10) + ($T5 * 20),
				'ranged' => $ranged,
				'rssgather' => $rssgather,
				'rssassist' => $rssassist,
				'helps' => $helps,
				'alliance' => $alliance,
			);
		} else {
			return array(
				'id' => $id,
				'name' => $name,
				'power' => $power,
				'kps' => $kps,
				'alliance' => $alliance,
			);
		}
	}

	public function openuser($i=0, $onlyid=false, $skip=false, $target=false) {

		$startX = 401;
		$startY = 349;
		$step = 121;
		$marginX = 20;
		$marginY = 38;
		$fixedY = 741;

		//screenshot the list
		$clickable = true;
		usleep(rand(300000, 500000));
		$this->screenshotandretrieve('list.png');
		usleep(rand(700000, 900000));
		//extract future click
		$im = imagecreatefrompng($GLOBALS['basedir'] . '/temp_images/list.png');
		$listextract1 = $this->processtesseract($im, ['x'=>403, 'y'=>688, 'width'=>513, 'height'=>89]);
		
		if($i<=3) {
			$x = $this->randomcoords($startX, $marginX);
			$y = $this->randomcoords($startY+($i*$step), $marginY);
			if($skip) {
				$y += 123;
			}
			ADB::addLog('Openuser $i = ' . $i . ', X= ' . $x . ', Y= ' . $y);
			$this->_tap($x, $y);
		} else {
			//After 4 scans the height becomes fixed as clicling fires scrolling
			$x = $this->randomcoords($startX, $marginX);
			$y = $this->randomcoords($fixedY, $marginY);
			if($skip) {
				$y += 123;
			}
			ADB::addLog('Openuser $i = ' . $i . ', X= ' . $x . ', Y= ' . $y);
			$this->_tap($x, $y);
		}

		//screenshot the list
		usleep(rand(300000, 500000));
		$this->screenshotandretrieve('list.png');
		usleep(rand(700000, 900000));
		//extract future click
		$im = imagecreatefrompng($GLOBALS['basedir'] . '/temp_images/list.png');
		$listextract2 = $this->processtesseract($im, ['x'=>403, 'y'=>688, 'width'=>513, 'height'=>89]);

		if($listextract1 == $listextract2 && $listextract1 != '0') {
			$clickable = false;
			$this->doLog('[error] not clickable listextract1 = ' . $listextract1 . ', listextract2 = ' . $listextract2 . "\n");
		}

		if($clickable) {
			//process mainscreen
			usleep(rand(300000, 500000));
			$this->screenshotandretrieve('mainscreen.png');
			usleep(rand(700000, 900000));

			if($target !== false) {
				if(!file_exists($GLOBALS['basedir'] . '/temp_images/' . $target) || !file_exists($GLOBALS['basedir'] . '/temp_images/' . $target . '/'. $i)) {
					mkdir($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i, 0770, true);
				}
				copy($GLOBALS['basedir'] . '/temp_images/mainscreen.png', $GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/mainscreen.png');
				$this->namecopy();
				$name = exec('powershell Get-Clipboard');
				file_put_contents($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/name.txt', $name);
			} else {
				$name = file_get_contents($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/name.txt');
				list($id,$power,$kps,$alliance) = $this->processmainscreen($GLOBALS['basedir'] . '/temp_images/mainscreen.png', $name);
				
			}
			if($onlyid === FALSE) {
				//process kp screen
				$this->openkpstats();

				usleep(rand(300000, 500000));
				$this->screenshotandretrieve('kpscreen.png');
				usleep(rand(700000, 900000));

				if($target !== false) {
					if(!file_exists($GLOBALS['basedir'] . '/temp_images/' . $target) || !file_exists($GLOBALS['basedir'] . '/temp_images/' . $target . '/'. $i)) {
						mkdir($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i, 0770, true);
					}
					copy($GLOBALS['basedir'] . '/temp_images/kpscreen.png', $GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/kpscreen.png');
				} else {
					list($T1,$T2,$T3,$T4,$T5,$ranged) = $this->processkpscreen($GLOBALS['basedir'] . '/temp_images/kpscreen.png', $name);

					$calculatedkp = (round($T1 *  0.2) + ($T2 * 2) + ($T3 * 4) + ($T4 * 10) + ($T5 * 20));
					if($calculatedkp != $kps) {
						echo "\n" . 'There is a problem with KPs here, main KP screen is ' . $kps . ' but detail and formula give ' . $calculatedkp . "\n";
					}
				}

				//process more screen
				$this->openmorestats();

				usleep(rand(300000, 500000));
				$this->screenshotandretrieve('morestats.png');
				usleep(rand(700000, 900000));

				$this->closemorestats();

				if($target !== false) {
					if(!file_exists($GLOBALS['basedir'] . '/temp_images/' . $target) || !file_exists($GLOBALS['basedir'] . '/temp_images/' . $target . '/'. $i)) {
						mkdir($GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i, 0770, true);
					}
					copy($GLOBALS['basedir'] . '/temp_images/morestats.png', $GLOBALS['basedir'] . '/temp_images/' . $target . '/' . $i . '/morestats.png');
				} else {
					list($deads,$rssgather,$rssassist,$helps) = $this->processmorestatsscreen($GLOBALS['basedir'] . '/temp_images/morestats.png', $name);
				}

				if($target !== false) {
					return true;
				} else {
					return array(
						'id' => $id,
						'name' => $name,
						'power' => $power,
						'kps' => $kps, //should be round($T1 *  0.2) + ($T2 * 2) + ($T3 * 4) + ($T4 * 10) + ($T5 * 20)
						'deads' => $deads,
						'T1' => $T1,
						'T2' => $T2,
						'T3' => $T3,
						'T4' => $T4,
						'T5' => $T5,
						'Total kills' => $kps,
						'T45' => ($T4 * 10) + ($T5 * 20),
						'ranged' => $ranged,
						'rssgather' => $rssgather,
						'rssassist' => $rssassist,
						'helps' => $helps,
						'alliance' => $alliance,
					);
				}
			} else {
				if($target !== false) {
					return true;
				} else {
					return array(
						'id' => $id,
						'name' => $name,
						'power' => $power,
						'kps' => $kps,
						'alliance' => $alliance,
					);
				}
			}
		} else {
			return false;
		}
	}

	private function processmainscreen($image, $name) {
		$im = imagecreatefrompng($image);
		$this->imageSaturation($im, -20); //desaturate colors to make OCR's job easier
//~ echo 'idttext' . "\n";
		$idttext = $this->processtesseract($im, ['x'=>705, 'y'=>212, 'width'=>391, 'height'=>42]);
		$idpreg = preg_replace('`[^\(]+\([^:]+:([^\)]+)\)?`', '$1', $idttext);
		$id = str_replace(')', '', $this->formatnumber($idpreg));

		if((int)$id == 0) {
			//let's do a fallback and check if we already have an id for that name in the database
			$this->doLog('[error] name = ' . $name . ', idtext = ' . $idttext . ', idpreg = ' . $idpreg . ', id = ' . $id . "\n");
			if(isset($this->uidnamedb[$name])) {
				$id = $this->uidnamedb[$name];
				$this->doLog('[fallback] name = ' . $name . ', id = ' . $id . "\n");
			}
			$this->doLog('[fallbackerror] name = ' . $name . ', id = 0 ' . "\n");
		} else {
			$this->doLog('[good] name = ' . $name . ', idtext = ' . $idttext . ', idpreg = ' . $idpreg . ', id = ' . $id . "\n");
		}
//~ echo 'power' . "\n";
		$power = $this->formatnumber($this->processtesseract($im, ['x'=>1053, 'y'=>390, 'width'=>271, 'height'=>35]));
		if($power == 0) {
			$this->doLog('[error] Power shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
//~ echo 'kps' . "\n";
		$kps = $this->formatnumber($this->processtesseract($im, ['x'=>1434, 'y'=>390, 'width'=>230, 'height'=>36]));
		if($kps == 0) {
			$this->doLog('[error] Kps shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
//~ echo 'alliance' . "\n";
		//something tesseract never get right
		$alliance = mb_ereg_replace('oe', 'œ', $this->processtesseract($im, ['x'=>707, 'y'=>390, 'width'=>330, 'height'=>38]));
		
		return array(
			$id,
			$power,
			$kps,
			$alliance
		);
	}

	private function processkpscreen($image, $name) {
		$im     = imagecreatefrompng($image);
		
		//$this->imageSaturation($im, -80); //saturate colors to make OCR's job easier
echo 'T1' . "\n";
		$T1 = $this->formatnumber($this->processtesseract($im, ['x'=>1101, 'y'=>506, 'width'=>275, 'height'=>32]));
		if($T1 == 0) {
			$this->doLog('[error] T1 shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'T2' . "\n";
		$T2 = $this->formatnumber($this->processtesseract($im, ['x'=>1101, 'y'=>559, 'width'=>275, 'height'=>32]));
		if($T2 == 0) {
			$this->doLog('[error] T2 shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'T3' . "\n";
		$T3 = $this->formatnumber($this->processtesseract($im, ['x'=>1101, 'y'=>612, 'width'=>275, 'height'=>32]));
		if($T3 == 0) {
			$this->doLog('[error] T3 shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'T4' . "\n";
		$T4 = $this->formatnumber($this->processtesseract($im, ['x'=>1101, 'y'=>666, 'width'=>275, 'height'=>32]));
		if($T4 == 0) {
			$this->doLog('[error] T4 shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'T5' . "\n";
		$T5 = $this->formatnumber($this->processtesseract($im, ['x'=>1101, 'y'=>720, 'width'=>275, 'height'=>32]));
		if($T5 == 0) {
			$this->doLog('[error] T5 shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'ranged' . "\n";
		$ranged = $this->processtesseract($im, ['x'=>1613, 'y'=>840, 'width'=>160, 'height'=>35]);
		if($ranged == 0) {
			$this->doLog('[error] Ranged shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
		
		return array(
			$T1,
			$T2,
			$T3,
			$T4,
			$T5,
			$ranged
		);
	}

	private function processmorestatsscreen($image, $name) {
		$im     = imagecreatefrompng($image);
		//$this->imageSaturation($im, -80); //saturate colors to make OCR's job easier
echo 'deads' . "\n";
		$deads = $this->formatnumber($this->processtesseract($im, ['x'=>1339, 'y'=>550, 'width'=>245, 'height'=>42]));
		if($deads == 0) {
			$this->doLog('[error] Deads shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'rssgather' . "\n";
		$rssgather = $this->formatnumber($this->processtesseract($im, ['x'=>1339, 'y'=>764, 'width'=>245, 'height'=>42]));
		if($rssgather == 0) {
			$this->doLog('[error] rssgather shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'rssassist' . "\n";
		$rssassist = $this->formatnumber($this->processtesseract($im, ['x'=>1339, 'y'=>834, 'width'=>245, 'height'=>42]));
		if($rssassist == 0) {
			$this->doLog('[error] rssassist shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
echo 'helps' . "\n";
		$helps = $this->formatnumber($this->processtesseract($im, ['x'=>1339, 'y'=>906, 'width'=>245, 'height'=>42]));
		if($helps == 0) {
			$this->doLog('[error] helps shouldn\'t be 0 for ' . $name . ' ...' . "\n");
		}
		return array(
			$deads,
			$rssgather,
			$rssassist,
			$helps
		);
	}

	public function doLog($txt) {
		$this->log .= $txt;
	}

	public function formatnumber($num) {
		return (int)str_replace(' ', '', str_replace(',', '', $num));
	}

	public function namecopy() {
		$x = $this->randomcoords(744, 10);
		$y = $this->randomcoords(276, 10);
		ADB::addLog('Clicking to fire name copy = X= ' . $x . ', Y= ' . $y);
		$this->_tap($x, $y);
	}

	public function openkpstats() {
		$x = $this->randomcoords(1408, 10);
		$y = $this->randomcoords(366, 10);
		ADB::addLog('Clicking to fire kp stats = X= ' . $x . ', Y= ' . $y);
		$this->_tap($x, $y);
	}

	public function openmorestats() {
		$x = $this->randomcoords(278, 10);
		$y = $this->randomcoords(880, 10);
		ADB::addLog('Clicking to open more stats = X= ' . $x . ', Y= ' . $y);
		$this->_tap($x, $y);
	}

	public function closemorestats() {
		$x = $this->randomcoords(1676, 6);
		$y = $this->randomcoords(64, 6);
		ADB::addLog('Clicking to close more stats = X= ' . $x . ', Y= ' . $y);
		$this->_tap($x, $y);
	}

	public function closeuser() {
		$x = $this->randomcoords(1746, 6);
		$y = $this->randomcoords(105, 6);
		ADB::addLog('Clicking to close user = X= ' . $x . ', Y= ' . $y);
		
		$this->_tap($x, $y);
		usleep(rand(300000, 500000));
	}

	public function screenshotandretrieve($name='capture.png') {
		$this->_ScreenshotPNG();
		$this->_dlScreenshotPNG('temp_images/', $name);
		$this->_rmScreenshotPNG();
	}

}