<?php

/***********************************************
* File      :   utils.php
* Project   :   Z-Push
* Descr     :   
*
* Created   :   03.04.2008
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

// saves information about folder data for a specific device
function _saveFolderData($devid, $folders) {
    if (!is_array($folders) || empty ($folders))
        return false;

    $unique_folders = array ();

    foreach ($folders as $folder) {
        if (!isset($folder->type))
            continue;

        // don't save folder-ids for emails
        if ($folder->type == SYNC_FOLDER_TYPE_INBOX)
            continue;

        // no folder from that type    or the default folder        
        if (!array_key_exists($folder->type, $unique_folders) || $folder->parentid == 0) {
            $unique_folders[$folder->type] = $folder->serverid;
        }
    }

    // Treo does initial sync for calendar and contacts too, so we need to fake 
    // these folders if they are not supported by the backend
    if (!array_key_exists(SYNC_FOLDER_TYPE_APPOINTMENT, $unique_folders))     
        $unique_folders[SYNC_FOLDER_TYPE_APPOINTMENT] = SYNC_FOLDER_TYPE_DUMMY;
    if (!array_key_exists(SYNC_FOLDER_TYPE_CONTACT, $unique_folders))         
        $unique_folders[SYNC_FOLDER_TYPE_CONTACT] = SYNC_FOLDER_TYPE_DUMMY;

    if (!file_put_contents(STATE_PATH."/".$devid."/compat-$devid", serialize($unique_folders))) {
        debugLog("_saveFolderData: Data could not be saved!");
    }
}

// returns information about folder data for a specific device    
function _getFolderID($devid, $class) {
    $filename = STATE_PATH."/".$devid."/compat-$devid";

    if (file_exists($filename)) {
        $arr = unserialize(file_get_contents($filename));

        if ($class == "Calendar")
            return $arr[SYNC_FOLDER_TYPE_APPOINTMENT];
        if ($class == "Contacts")
            return $arr[SYNC_FOLDER_TYPE_CONTACT];

    }

    return false;
}

/**
 * Function which converts a hex entryid to a binary entryid.
 * @param string @data the hexadecimal string
 */
function hex2bin($data)
{
    $len = byte_strlen($data);
    $newdata = "";

    for ($i = 0;$i < $len;$i += 2) {
        $newdata .= pack("C", hexdec(byte_substr($data, $i, 2)));
    }
    return $newdata;
}

function utf8_to_backendcharset($string, $option = "")
{
	// if the store supports unicode return the string without converting it
	if (defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

    if (function_exists("iconv")){
        return @iconv("UTF-8", BACKEND_CHARSET . $option, $string);
    }else{
        return utf8_decode($string); // no euro support here
    }
}

function backendcharset_to_utf8($string, $option = "")
{
	// if the store supports unicode return the string without converting it
	if (defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

    if (function_exists("iconv")){
        return @iconv(BACKEND_CHARSET, "UTF-8" . $option, $string);
    }else{
        return utf8_encode($string); // no euro support here
    }
}

function w2u($string) { return backendcharset_to_utf8($string); }
function u2w($string) { return utf8_to_backendcharset($string); }

function w2ui($string) { return backendcharset_to_utf8($string, "//TRANSLIT"); }
function u2wi($string) { return utf8_to_backendcharset($string, "//TRANSLIT"); }

/**
 * Truncate an UTF-8 encoded sting correctly
 * 
 * If it's not possible to truncate properly, an empty string is returned 
 *
 * @param string $string - the string
 * @param string $length - position where string should be cut
 * @return string truncated string
 */ 
function utf8_truncate($string, $length) {
    if (byte_strlen($string) <= $length) 
        return $string;

    while ($length >= 0) {
        if ((ord($string[$length]) < 0x80) || (ord($string[$length]) >= 0xC0))
            return byte_substr($string, 0, $length);

        $length--;
    }
    return "";
}


/**
 * Build an address string from the components
 *
 * @param string $street - the street
 * @param string $zip - the zip code
 * @param string $city - the city
 * @param string $state - the state
 * @param string $country - the country
 * @return string the address string or null
 */
function buildAddressString($street, $zip, $city, $state, $country) {
    $out = "";

    if (isset($country) && $street != "") $out = $country;

    $zcs = "";
    if (isset($zip) && $zip != "") $zcs = $zip;
    if (isset($city) && $city != "") $zcs .= (($zcs)?" ":"") . $city;
    if (isset($state) && $state != "") $zcs .= (($zcs)?" ":"") . $state;
    if ($zcs) $out = $zcs . "\r\n" . $out;

    if (isset($street) && $street != "") $out = $street . (($out)?"\r\n\r\n". $out: "") ;

    return ($out)?$out:null;
}

/**
 * Checks if the PHP-MAPI extension is available and in a requested version
 *
 * @param string $version - the version to be checked ("6.30.10-18495", parts or build number)
 * @return boolean installed version is superior to the checked strin
 */
function checkMapiExtVersion($version = "") {
    // compare build number if requested
    if (preg_match('/^\d+$/',$version) && byte_strlen($version) > 3) {
        $vs = preg_split('/-/', phpversion("mapi"));
        return ($version <= $vs[1]); 
    }

    if (extension_loaded("mapi")){
        if (version_compare(phpversion("mapi"), $version) == -1){
            return false;
        }
    }
    else
        return false;

    return true;
}

function base64uri_decode($uri) {
    $uri = base64_decode($uri);
    $lenDevID = ord($uri{4});
    $lenPolKey = ord($uri{4+(1+$lenDevID)});
    $lenDevType = ord($uri{4+(1+$lenDevID)+(1+$lenPolKey)});
    $arr_ret = unpack("CProtVer/CCommand/vLocale/CDevIDLen/H".($lenDevID*2)."DevID/CPolKeyLen".($lenPolKey == 4 ? "/VPolKey" : "")."/CDevTypeLen/A".($lenDevType)."DevType",$uri);
    $pos = (7+$lenDevType+$lenPolKey+$lenDevID);
    $uri = byte_substr($uri,$pos);
    while (byte_strlen($uri) > 0) {
		$lenToken = ord($uri{1});
		switch (ord($uri{0})) {
		    case 0 : $type = "AttachmentName"; break;
		    case 1 : $type = "CollectionId"; break;    //accroding to spec  20090712
		    case 2 : $type = "CollectionName"; break;  //accroding to spec  20090712
		    case 3 : $type = "ItemId"; break;
		    case 4 : $type = "LongId"; break;
		    case 5 : $type = "ParentId"; break;        //accroding to spec  20090712
		    case 6 : $type = "Occurrence"; break;
		    case 7 : $type = "Options"; break;
		    case 8 : $type = "User"; break;
		    default : $type = "unknown".ord($uri{0}); break;
		}
		$value = unpack("CType/CLength/A".$lenToken."Value",$uri);
		$arr_ret[$type] = $value['Value'];
		$pos = 2+$lenToken;
		$uri = byte_substr($uri,$pos);
    }
    
    return $arr_ret;
}

/**
 * Read the correct message body 
 *
 * @param ressource $msg - the message
**/
function eml_ReadMessage($msg) {
    global $protocolversion;
    $rtf = mapi_message_openproperty($msg, PR_RTF_COMPRESSED);
    if (!$rtf) {
		$body = mapi_message_openproperty($msg, PR_BODY);
		$content = "text/plain";
    } else {
        $rtf = preg_replace("/(\n.*)/m","",mapi_decompressrtf($rtf));
        if (strpos($rtf,"\\fromtext") != false || !($protocolversion >= 2.5)) {
		    $body = mapi_message_openproperty($msg, PR_BODY);
		    $content = "text/plain";
		} else {
		    $body = mapi_message_openproperty($msg, PR_HTML);
		    $content = "text/html";
		}
    }
    if (mb_detect_encoding($body) != "UTF-8") 
		$body = w2ui( $body );
    return array('body' => $body,'content' => $content);
}

// START ADDED dw2412 EML Attachment
function buildEMLAttachment($attach) {
    $msgembedded = mapi_attach_openobj($attach);
    $msgprops = mapi_getprops($msgembedded,array(PR_MESSAGE_CLASS,PR_CLIENT_SUBMIT_TIME,PR_DISPLAY_TO,PR_SUBJECT,PR_SENT_REPRESENTING_NAME,PR_SENT_REPRESENTING_EMAIL_ADDRESS));
    $msgembeddedrcpttable = mapi_message_getrecipienttable($msgembedded);
    $msgto = $msgprops[PR_DISPLAY_TO];
    if($msgembeddedrcpttable) {
		$msgembeddedrecipients = mapi_table_queryrows($msgembeddedrcpttable, array(PR_ADDRTYPE, PR_ENTRYID, PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS, PR_RECIPIENT_TYPE, PR_RECIPIENT_FLAGS, PR_PROPOSEDNEWTIME, PR_PROPOSENEWTIME_START, PR_PROPOSENEWTIME_END, PR_RECIPIENT_TRACKSTATUS), 0, 99999999);
		foreach($msgembeddedrecipients as $rcpt) {
		    if ($rcpt[PR_DISPLAY_NAME] == $msgprops[PR_DISPLAY_TO]) {
			    $msgto = $rcpt[PR_DISPLAY_NAME];
			    if (isset($rcpt[PR_EMAIL_ADDRESS]) &&
	    		    $rcpt[PR_EMAIL_ADDRESS] != $msgprops[PR_DISPLAY_TO]) $msgto .= " <".$rcpt[PR_EMAIL_ADDRESS].">";
		        break;
	    	}
		}
    }
    $msgsubject = $msgprops[PR_SUBJECT];
    $msgfrom = $msgprops[PR_SENT_REPRESENTING_NAME];
    if (isset($msgprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS]) &&
        $msgprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS] != $msgprops[PR_SENT_REPRESENTING_NAME]) $msgfrom .= " <".$msgprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS].">";
    $msgtime = $msgprops[PR_CLIENT_SUBMIT_TIME];
    $msgembeddedbody = eml_ReadMessage($msgembedded);
    $msgembeddedattachtable = mapi_message_getattachmenttable($msgembedded);
    $msgembeddedattachtablerows = mapi_table_queryallrows($msgembeddedattachtable, array(PR_ATTACH_NUM, PR_ATTACH_METHOD));
    if ($msgembeddedattachtablerows) {
		$boundary = '=_zpush_static';
		$headercontenttype = "multipart/mixed";
		$msgembeddedbody['body'] = 	"Unfortunately your mobile is not able to handle MIME Messages\n".
									"--".$boundary."\n".
									"Content-Type: ".$msgembeddedbody['content']."; charset=utf-8\n".
									"Content-Transfer-Encoding: quoted-printable\n\n".
									$msgembeddedbody['body']."\n";
		foreach ($msgembeddedattachtablerows as $msgembeddedattachtablerow) {
    	    $msgembeddedattach = mapi_message_openattach($msgembedded, $msgembeddedattachtablerow[PR_ATTACH_NUM]);
	    	if(!$msgembeddedattach) {
	        	debugLog("Unable to open attachment number $attachnum");
		    } else {
		    	$msgembeddedattachprops = mapi_getprops($msgembeddedattach, array(PR_ATTACH_MIME_TAG, PR_ATTACH_LONG_FILENAME,PR_ATTACH_FILENAME,PR_DISPLAY_NAME));
            	if (isset($msgembeddedattachprops[PR_ATTACH_LONG_FILENAME])) 
	        	    $attachfilename = w2u($msgembeddedattachprops[PR_ATTACH_LONG_FILENAME]);
	        	else if (isset($msgembeddedattachprops[PR_ATTACH_FILENAME]))
				    $attachfilename = w2u($msgembeddedattachprops[PR_ATTACH_FILENAME]);
				else if (isset($msgembeddedattachprops[PR_DISPLAY_NAME]))
				    $attachfilename = w2u($msgembeddedattachprops[PR_DISPLAY_NAME]);
				else
				    $attachfilename = w2u("untitled");
	        	if ($msgembeddedattachtablerow[PR_ATTACH_METHOD] == ATTACH_EMBEDDED_MSG) 
        		    $attachfilename .= w2u(".eml");
				$msgembeddedbody['body'] .= "--".$boundary."\n".
							    		    "Content-Type: ".$msgembeddedattachprops[PR_ATTACH_MIME_TAG].";\n".
										    " name=\"".$attachfilename."\"\n".
					    					"Content-Transfer-Encoding: base64\n".
										    "Content-Disposition: attachment;\n".
										    " filename=\"".$attachfilename."\"\n\n";
				$msgembeddedattachstream = mapi_openpropertytostream($msgembeddedattach, PR_ATTACH_DATA_BIN);
	    		$msgembeddedattachment = "";
    			while(1) {
        		    $msgembeddedattachdata = mapi_stream_read($msgembeddedattachstream, 4096);
	        	    if(byte_strlen($msgembeddedattachdata) == 0)
				        break;
				    $msgembeddedattachment .= $msgembeddedattachdata;
				}
				$msgembeddedbody['body'] .= chunk_split(base64_encode($msgembeddedattachment))."\n";
				unset($msgembeddedattachment);
			}
		}
		$msgembeddedbody['body'] .= "--".$boundary."--\n";
    } else {
		$headercontenttype = $msgembeddedbody['content']."; charset=utf-8";
		$boundary = '';
    }
    $msgembeddedheader = "Subject: ".$msgsubject."\n".
    			         "From: ".$msgfrom."\n".
						 "To: ".$msgto."\n".
						 "Date: ".gmstrftime("%a, %d %b %Y %T +0000",$msgprops[PR_CLIENT_SUBMIT_TIME])."\n".
						 "MIME-Version: 1.0\n".
						 "Content-Type: ".$headercontenttype.";\n".
						 ($boundary ? " boundary=\"".$boundary."\"\n" : "").
						 "\n";
    $stream = mapi_stream_create();
    mapi_stream_setsize($stream,byte_strlen($msgembeddedheader.$msgembeddedbody['body']));
    mapi_stream_write($stream,$msgembeddedheader.$msgembeddedbody['body']);
    mapi_stream_seek($stream,0,STREAM_SEEK_SET);
    return $stream;
}
// END ADDED dw2412 EML Attachment

 
/**
 * Parses and returns an ecoded vCal-Uid from an 
 * OL compatible GlobalObjectID
 *
 * @param string $olUid - an OL compatible GlobalObjectID
 * @return string the vCal-Uid if available in the olUid, else the original olUid as HEX
 */
function getICalUidFromOLUid($olUid){
    $icalUid = strtoupper(bin2hex($olUid));
    if(($pos = stripos($olUid,"vCal-Uid"))) {
    	$length = unpack("V", byte_substr($olUid, $pos-4,4));
    	$icalUid = byte_substr($olUid, $pos+12, $length[1] -14);
    }
    return $icalUid;
}

/**
 * Checks the given UID if it is an OL compatible GlobalObjectID
 * If not, the given UID is encoded inside the GlobalObjectID
 *
 * @param string $icalUid - an appointment uid as HEX
 * @return string an OL compatible GlobalObjectID
 *
 */
function getOLUidFromICalUid($icalUid) {
	if (byte_strlen($icalUid) <= 64) {
		$len = 13 + byte_strlen($icalUid);
		$OLUid = pack("V", $len);
		$OLUid .= "vCal-Uid";
		$OLUid .= pack("V", 1);
		$OLUid .= $icalUid;
		return hex2bin("040000008200E00074C5B7101A82E0080000000000000000000000000000000000000000". bin2hex($OLUid). "00");
	}
	else
		return hex2bin($icalUid);
} 

/**
 * Extracts the basedate of the GlobalObjectID and the RecurStartTime 
 *
 * @param string $goid - OL compatible GlobalObjectID
 * @param long $recurStartTime - RecurStartTime 
 * @return long basedate 
 *
 */
function extractBaseDate($goid, $recurStartTime) {
	$hexbase = byte_substr(bin2hex($goid), 32, 8);
	$day = hexdec(byte_substr($hexbase, 6, 2));
	$month = hexdec(byte_substr($hexbase, 4, 2));
	$year = hexdec(byte_substr($hexbase, 0, 4));
 
	if ($day && $month && $year) {
		$h = $recurStartTime >> 12;
		$m = ($recurStartTime - $h * 4096) >> 6;
		$s = $recurStartTime - $h * 4096 - $m * 64;
       	return gmmktime($h, $m, $s, $month, $day, $year);
	}
	else
	    return false;
}

// stripos is only available since php5 - just for compatibility reasons we have this function below...
if (!function_exists("stripos")) {
        function stripos($string1, $string2) {
    		return strpos(strtolower($string1),strtolower($string2));
	}
}

/**
 * Return the number of bytes of a string, independent of mbstring.func_overload
 * AND the availability of mbstring
 *
 * @param string $str
 * @return int
 */
function byte_strlen($str) {
	return MBSTRING_OVERLOAD & 2 ? mb_strlen($str,'ascii') : strlen($str);
}

/**
 * mbstring.func_overload safe substr
 *
 * @param string $data
 * @param int $offset
 * @param int $len
 * @return string
 */
function byte_substr(&$data,$offset,$len=null) {
	if ($len == null) 
		return MBSTRING_OVERLOAD & 2 ? mb_substr($data,$offset,byte_strlen($data),'ascii') : substr($data,$offset);
	return MBSTRING_OVERLOAD & 2 ? mb_substr($data,$offset,$len,'ascii') : substr($data,$offset,$len);
}

// START ADDED dw2412 just to find out if include file is available (didn't find a better place to have this 
// nice function reachable to check if common classes not already integrated in Zarafa Server exists)
function include_file_exists($filename) {
     $path = explode(":", ini_get('include_path'));
     foreach($path as $value) {
        if (file_exists($value.$filename)) return true;
     }
     
     return false;
}
// END ADDED dw2412 just to find out if include file is available

// START ADDED dw2412 ResolveRecipient Support
function generateMergedFB($starttime, $endtime, $entries) {
	$mergedfb = '';
	for ($i = 0; $i < ($endtime-$starttime)/60/30; $i++) {
		$mergedfb .= '0';
	}
	if (isset($entries) && sizeof($entries) > 0) {
		foreach ($entries as $entry) {
			for ($i=(($entry['start'] - $starttime) / 60 / 30); $i<(($entry['end'] - $starttime) / 60 / 30); $i++) {
				$mergedfb{$i} = $entry['status'];
			}
		}
	}
	return $mergedfb;
}

function parseVFB($lines, &$line_no) {
	for (;$line_no<sizeof($lines);$line_no++) {
		$line = $lines[$line_no];
		if (($i = strpos($line,":"))) {
			$field = substr($line,0,$i);
			$value = substr($line,$i+1);
			switch (strtolower($field)) {
				case 'begin'	: 
					$line_no++;
					$vfb_loc[strtolower($value)] = parseVFB($lines,$line_no);
					break;
				case 'dtend' : 
				case 'dtstart' : 
				case 'dtstamp' : 
					$vfb_loc[strtolower($field)] = parseVFB_date($value); 
					break;
				case 'freebusy' : 
		           	if (($i = strpos($value,"/"))) {
				        $val['starttime'] = parseVFB_date(substr($value,0,$i));
				        $val['endtime']   = parseVFB_date(substr($value,$i+1));
		           	}
					$vfb_loc[strtolower($field)][] = $val; 
					break;
				case 'end'		: 
					return $vfb_loc;
				default			: 
					$vfb_loc[strtolower($field)] = $value;
			}
		}
	}
	return $vfb_loc;
}

function parseVFB_date($tstring) {
	if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z/", $tstring, $matches)) {
		if ($matches[1] >= 2038){
			$matches[1] = 2038;
			$matches[2] = 1;
			$matches[3] = 18;
			$matches[4] = $matches[5] = $matches[6] = 0;
		}
		return gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
	}
	return false;
}
// END ADDED dw2412 ResolveRecipient Support

function InternalSMTPClient($from,$to,$cc,$bcc,$content) {
	$addpath = "";
	if (strlen($to) > 0) $addpath .= $to.", ";
	if (strlen($cc) > 0) $addpath .= $cc.", ";
	if (strlen($bcc) > 0) $addpath .= $bcc.", ";
	$addrs = array();
	$namefield="";
	$addrfield="";
	for($i=0;$i < strlen($addpath);$i++) {
		switch ($addpath{$i}) {
			case "\"" : // parse namefield
				$namefield="";
				for($i++;$i < strlen($addpath); $i++) {
					if ($addpath{$i} == "\\") {
       					$namefield .= $addpath{$i};
						$i++;
						$namefield .= $addpath{$i};
					} else 
					if ($addpath{$i} == "\"") 
						break;
					else 
						$namefield .= $addpath{$i};
				}
				break;
			case "<" : 
				$addrfield="";
				for($i++;$i < strlen($addpath); $i++) {
					if ($addpath{$i} == ">") 
						break;
					else 
						$addrfield .= $addpath{$i};
				}
				break;
			case "," : 
				if ($addrfield != "") {
					$addr = array ("FullName" => $namefield, "addr" => $addrfield);
					array_push($addrs,$addr);
				}
				$namefield="";
				$addrfield="";
				break;
			}
	}
	if ($addrfield != "") {
		$addr = array ("FullName" => $namefield, "addr" => $addrfield);
		array_push($addrs,$addr);
	}
	if (sizeof($addrs) == 0) {
		debugLog('Error: No eMail Recipients!');
		return false;
	}

	// Initiate connection with the SMTP server
	if (!($handle = fsockopen(INTERNAL_SMTPCLIENT_SERVERNAME,INTERNAL_SMTPCLIENT_SERVERPORT))) {
		debugLog('Error: ' . $errstr . ' (' . $errno . ')');
		return false;
	}

	if (substr(PHP_OS, 0, 3) != 'WIN') {
		socket_set_timeout($handle, INTERNAL_SMTPCLIENT_CONNECTTIMEOUT, 0);
	}

	while ($line = fgets($handle, 515)) {
		if (substr($line,3,1) == ' ') break;
	}

	if (substr(PHP_OS, 0, 3) != 'WIN') {
		socket_set_timeout($handle, INTERNAL_SMTPCLIENT_SOCKETTIMEOUT, 0);
	}
	fputs($handle, "EHLO ".INTERNAL_SMTPCLIENT_MAILDOMAIN."\n");

	while ($line = fgets($handle, 515)) {
		if (substr($line,3,1) == ' ') {
			if (substr($line,0,3) != '250') {
				debugLog('Error: EHLO not accepted from server!');
				return false;
			}
		}
	}

	// SMTP authorization
	if (INTERNAL_SMTPCLIENT_USERNAME != '' && INTERNAL_SMTPCLIENT_PASSWORD != '') {
		fputs($handle, "AUTH LOGIN\n");
		while ($line = fgets($handle, 515)) {
			if (substr($line,3,1) == ' ') {
				if (substr($line,0,3) != '334') {
					debugLog('Error: AUTH LOGIN not accepted from server!');
					return false;
				}
				break;
			}
		}
		fputs($handle, base64_encode(INTERNAL_SMTPCLIENT_USERNAME)."\n");
		while ($line = fgets($handle, 515)) {
			if (substr($line,3,1) == ' ') {
				if (substr($line,0,3) != '334') {
					debugLog('Error: Username not accepted by server!');
					return false;
				}
				break;
			}
		}
		fputs($handle, base64_encode(INTERNAL_SMTPCLIENT_PASSWORD)."\n");
		while ($line = fgets($handle, 515)) {
			if (substr($line,3,1) == ' ') {
				if (substr($line,0,3) != '235') {
					debugLog('Error: Password not accepted by server!');
					return false;
				}
				break;
			}
		}
	}

	// Send out the e-mail
	fputs($handle, "MAIL FROM: ".$from."\n");
	while ($line = fgets($handle, 515)) {
		if (substr($line,3,1) == ' ') {
			if (substr($line,0,3) != '250') {
				debugLog('Error: MAIL FROM not accepted by server!');
				return false;
			}
		}
	}

	foreach ($addrs as $value) {
		if ($value['addr'] != "") {
			fputs($handle, "RCPT TO: ".$value['addr']."\n");
			while ($line = fgets($handle, 515)) {
				if (substr($line,3,1) == ' ') {
					if (substr($line,0,3) != '250' && substr($line,0,3) != '251') {
						debugLog('Error: RCPT TO not accepted by server!');
						return false;
					}
				}
			}
		}
	}

	fputs($handle, "DATA\n");
	while ($line = fgets($handle, 515)) {
		if (substr($line,3,1) == ' ') {
			if (substr($line,0,3) != '354') {
				debugLog('Error: DATA Command not accepted by server!');
				return false;
			}
		}
	}
	fputs($handle, $content. "\n");
	fputs($handle, ".\n");
	while ($line = fgets($handle, 515)) {
		if (substr($line,3,1) == ' ') {
			if (substr($line,0,3) != '250') {
				debugLog('Error: DATA Content not accepted by server!');
				return false;
			}
		}
	}
	// Close connection to SMTP server
	fputs($handle, "QUIT\n");
	while ($line = fgets($handle, 515)) {
		if (substr($line,3,1) == ' ') {
			if (substr($line,0,3) != '221') {
				debugLog('Error: QUIT not accepted by server!');
				return false;
			}
		}
	}
	fclose ($handle);
	return true;
}

?>
