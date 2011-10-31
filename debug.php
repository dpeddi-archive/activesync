<?php
/***********************************************
* File      :   debug.php
* Project   :   Z-Push
* Descr     :   Debuging functions
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

global $debugstr;

function debug($str) {
    global $debugstr;
    $debugstr .= "$str\n";
}

function getDebugInfo() {
    global $debugstr;

    return $debugstr;
}

function debugLog($message) {
    global $devid;

    // global log
    if ((@$fp = fopen(STATE_DIR . "/debug.txt","a"))) {
	@$date = strftime("%x %X");
	@fwrite($fp, "$date [". getmypid() ."] $message\n");
        @fclose($fp);
    }
    // logging by device
    if (isset($devid) && strlen($devid) > 0 &&
	($fn = STATE_DIR . "/". strtolower($devid). "/debug.txt") &&
	file_exists($fn)) {
    	@$fp = fopen($fn,"a");
    	@$date = strftime("%x %X");
	@fwrite($fp, "$date [". getmypid() ."] $message\n");
	@fclose($fp);
    }
}

function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    debugLog("$errfile:$errline $errstr ($errno)");
}

error_reporting(E_ALL & ~E_NOTICE);
set_error_handler("zarafa_error_handler",E_ALL & ~E_NOTICE);

?>
