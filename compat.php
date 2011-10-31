<?php
/***********************************************
* File      :   compat.php
* Project   :   Z-Push
* Descr     :   Help function for files
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

if (!function_exists("file_put_contents")) {
    function file_put_contents($n,$d) {
        $f=@fopen($n,"w");
        if (!$f) {
            return false;
        } else {
            fwrite($f,$d);
            fclose($f);
            return true;
        }
    }
}

// dw2412 should help all using either php-cgi or not apache server at all.
// This doesn't mean that other servers are supported officially - it just should help
// lowering the question rate ;-)

if (!function_exists("apache_request_headers")) {
	function apache_request_headers() {
		$headers = array();
		foreach ($_SERVER as $k => $v) {
			if (substr($k, 0, 5) == "HTTP_") {
				$k = str_replace('_', ' ', substr($k, 5));
				$k = str_replace(' ', '-', ucwords(strtolower($k)));
				$headers[$k] = $v;
			}
		}
		return $headers;
	}
}

?>