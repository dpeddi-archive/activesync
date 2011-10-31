<?php
/***********************************************
* File      :   vcarddir.php
* Project   :   Z-Push
* Descr     :   This backend is for vcard
*               directories.
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once('diffbackend.php');

class BackendiCalDir extends BackendDiff {
    var $_config;
    var $_user;
    var $_devid;
    var $_protocolversion;
    // about fieldmapping:
    // Left side = iCal fieldnames in lowercase
    // Right side = airsync fieldnames in lowercase
    var $_mapping = array(
            'content' => 'body',
            'attributes' => 'attributes',
            'flags' => 'flags',
            'alldayevent' => 'alldayevent',
            'busystatus' => 'busystatus',
            'dtstamp' => 'dtstamp',
            'endtime' => 'endtime',
            'location' => 'location',
            'sensitivity' => 'sensitivity',
            'subject' => 'subject',
            'starttime' => 'starttime',
            'meetingstatus' => 'meetingstatus',
            'reminder' => 'reminder',
            'uid' => 'uid',
            'rec_type' => 'recurrence->type',
            'rec_until' => 'recurrence->until',
            'rec_occurrences' => 'recurrence->occurrences',
            'rec_interval' => 'recurrence->interval',
            'rec_dayofweek' => 'recurrence->dayofweek',
            'rec_dayofmonth' => 'recurrence->dayofmonth',
            'rec_weekofmonth' => 'recurrence->weekofmonth',
            'rec_monthofyear' => 'recurrence->monthofyear',
        );


    function BackendiCalDir(){
        debugLog('iCalDir::BackendiCalDir()');
    }

    function Logon($username, $domain, $password) {
        debugLog('iCalDir::Logon()');
        return true;
    }

    // completing protocol
    function Logoff() {
        debugLog('iCalDir::Logoff()');
        return true;
    }

    function Setup($user, $devid, $protocolversion) {
        debugLog('iCalDir::Setup()');
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;
        $this->_path = str_replace('%u', $this->_user, ICALDIR_DIR);

		// ItemID Cache
    	$dir = opendir(STATE_PATH. "/" .strtolower($this->_devid));
        if(!$dir) {
	    	debugLog("IMAP Backend: creating folder for device ".strtolower($this->_devid));
	    	if (mkdir(STATE_PATH. "/" .strtolower($this->_devid), 0744) === false) 
				debugLog("IMAP Backend: failed to create folder ".strtolower($this->_devid));
		}
		$filename = STATE_DIR . '/' . strtolower($this->_devid). '/ical_items_'. $this->_user;
		$this->_items = false;
		if (file_exists($filename)) {
	    	if (($this->_items = file_get_contents(STATE_DIR . '/' . strtolower($this->_devid). '/ical_items_'. $this->_user)) !== false) {
				$this->_items = unserialize($this->_items);
	    	} else {
	        	$this->_items = array();
		    }
		} else {
	    	$this->_items =  array();
	    }

        return true;
    }

    function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
        debugLog('iCalDir::SendMail()');
        return false;
    }

    function GetWasteBasket() {
        debugLog('iCalDir::GetWasteBasket()');
        return false;
    }

    function _uid() {
	    $id = sprintf( '%04x%04x%04x%04x%04x%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
		return $id;
    }

    function GetMessageList($folderid, $cutoffdate) {
        debugLog('iCalDir::GetMessageList('.$folderid.')');
        $messages = array();

        $dir = opendir($this->_path);
		$mod = false;
        if(!$dir)
            return false;
        while($entry = readdir($dir)) {
            if(is_dir($this->_path .'/'.$entry))
                continue;

            $message = array();
			// put real item id in cache and create unique itemids instead
			if (($entryid = array_search($entry,$this->_items)) === false) {
/*				ksort($this->_items);
				end($this->_items);
				if (key($this->_items)+1 == 1)
			    	$entryid = sprintf("1%09d",key($this->_items)+1);
			    else 
			    	$entryid = key($this->_items)+1;
			    $this->_items[$entryid] = $entry;
			    $mod = true;
*/
				$entryid = $this->_itemid();
			    $this->_items[$entryid] = $entry;
				$mod = true;
			}
            $message["id"] = $entryid;
            $stat = stat($this->_path .'/'.$entry);
            $message["mod"] = $stat["mtime"];
            $message["flags"] = 1; // always 'read'

            $messages[] = $message;
        }

		if ($mod == true)
			file_put_contents(STATE_DIR . '/' . strtolower($this->_devid). '/ical_items_'. $this->_user, serialize($this->_items));

        return $messages;
    }

    function GetFolderList() {
        debugLog('iCalDir::GetFolderList()');
        $contacts = array();
        $folder = $this->StatFolder("root");
        $contacts[] = $folder;

        return $contacts;
    }

    function GetFolder($id) {
        debugLog('iCalDir::GetFolder('.$id.')');
        if($id == "root") {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = ICALDIR_FOLDERNAME;
            $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;

            return $folder;
        } else return false;
    }

    function StatFolder($id) {
        debugLog('iCalDir::StatFolder('.$id.')');
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    function GetAttachmentData($attname) {
        debugLog('iCalDir::GetAttachmentData');
        return false;
    }

    function StatMessage($folderid, $id) {
        debugLog('iCalDir::StatMessage('.$folderid.', '.$this->_items[$id].')');
        if($folderid != "root")
            return false;

        $stat = stat($this->_path . "/" . $this->_items[$id]);

        $message = array();
        $message["mod"] = $stat["mtime"];
        $message["id"] = $id;
        $message["flags"] = 1;

        return $message;
    }

    function _generateuid() {
        return sprintf( '%04x%04x%04x%04x%04x%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }

    function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0) {
        debugLog('iCalDir::GetMessage('.$folderid.', '.$this->_items[$id].', ..)');
        if($folderid != "root")
            return;

        $types = array ('dom' => 'type', 'intl' => 'type', 'postal' => 'type', 'parcel' => 'type', 'home' => 'type', 'work' => 'type',
            'pref' => 'type', 'voice' => 'type', 'fax' => 'type', 'msg' => 'type', 'cell' => 'type', 'pager' => 'type',
            'bbs' => 'type', 'modem' => 'type', 'car' => 'type', 'isdn' => 'type', 'video' => 'type',
            'aol' => 'type', 'applelink' => 'type', 'attmail' => 'type', 'cis' => 'type', 'eworld' => 'type',
            'internet' => 'type', 'ibmmail' => 'type', 'mcimail' => 'type',
            'powershare' => 'type', 'prodigy' => 'type', 'tlx' => 'type', 'x400' => 'type',
            'gif' => 'type', 'cgm' => 'type', 'wmf' => 'type', 'bmp' => 'type', 'met' => 'type', 'pmb' => 'type', 'dib' => 'type',
            'pict' => 'type', 'tiff' => 'type', 'pdf' => 'type', 'ps' => 'type', 'jpeg' => 'type', 'qtime' => 'type',
            'mpeg' => 'type', 'mpeg2' => 'type', 'avi' => 'type',
            'wave' => 'type', 'aiff' => 'type', 'pcm' => 'type',
            'x509' => 'type', 'pgp' => 'type', 'text' => 'value', 'inline' => 'value', 'url' => 'value', 'cid' => 'value', 'content-id' => 'value',
            '7bit' => 'encoding', '8bit' => 'encoding', 'quoted-printable' => 'encoding', 'base64' => 'encoding', 'uid' => 'value',
        );


        // Parse the vcard
        $message = new SyncAppointment();

        $data = file_get_contents($this->_path . "/" . $this->_items[$id]);
        $data = str_replace("\x00", '', $data);
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = preg_replace('/(\n)([ \t])/i', '', $data);
		$data = utf8_decode($data);

        $lines = explode("\n", $data);

        $vcard = array();
        foreach($lines as $line) {
            if (trim($line) == '')
                continue;
            $pos = strpos($line, ':');
            if ($pos === false)
                continue;

            $field = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos+1));

            $fieldparts = preg_split('/(?<!\\\\)(\;)/i', $field, -1, PREG_SPLIT_NO_EMPTY);

            $type = strtolower(array_shift($fieldparts));

            $fieldvalue = array();

            foreach ($fieldparts as $fieldpart) {
                if(preg_match('/([^=]+)=(.+)/', $fieldpart, $matches)){
                    if(!in_array(strtolower($matches[1]),array('value','type','encoding','language')))
                        continue;
                    if(isset($fieldvalue[strtolower($matches[1])]) && is_array($fieldvalue[strtolower($matches[1])])){
                        $fieldvalue[strtolower($matches[1])] = array_merge($fieldvalue[strtolower($matches[1])], preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY));
                    }else{
                        $fieldvalue[strtolower($matches[1])] = preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY);
                    }
                }else{
                    if(!isset($types[strtolower($fieldpart)]))
                        continue;
                    $fieldvalue[$types[strtolower($fieldpart)]][] = $fieldpart;
                }
            }
            //
            switch ($type) {
                case 'categories':
                    //case 'nickname':
                    $val = preg_split('/(?<!\\\\)(\,)/i', $value);
                    $val = array_map("w2ui", $val);
                    break;
                default:
                    $val = preg_split('/(?<!\\\\)(\;)/i', $value);
                    break;
            }
            if(isset($fieldvalue['encoding'][0])){
                switch(strtolower($fieldvalue['encoding'][0])){
                    case 'q':
                    case 'quoted-printable':
                        foreach($val as $i => $v){
                            $val[$i] = quoted_printable_decode($v);
                        }
                        break;
                    case 'b':
                    case 'base64':
                        foreach($val as $i => $v){
                            $val[$i] = base64_decode($v);
                        }
                        break;
                }
            }else{
                foreach($val as $i => $v){
                    $val[$i] = $this->unescape($v);
                }
            }
            $fieldvalue['val'] = $val;
            $vcard[$type][] = $fieldvalue;
        }

        // debugLog('iCalDir::LoadedMessage-source:' . print_r($vcard,1));
        //http://www.zachstronaut.com/posts/2009/02/09/careful-with-php-empty.html

		// We pass all times in GMT to device. Calculation to gmt takes place based on timezone definition in config.php
   		$message->timezone = base64_encode($this->_getSyncBlobFromTZ(array("bias" => 0,
   															 "dstendmonth" => 0,
   															 "dstendday" => 0,
   															 "dstendweek" => 0,
   															 "dstendhour" => 0,
   															 "dstendminute" => 0,
   															 "dstendsecond" => 0,
   															 "dstendmillis" => 0,
   															 "stdbias" => 0,
   															 "dststartmonth" => 0,
   															 "dststartday" => 0,
   															 "dststartweek" => 0,
   															 "dststarthour" => 0,
   															 "dststartminute" => 0,
   															 "dststartsecond" => 0,
   															 "dststartmillis" => 0,
   															 "dstbias" => 0
   															 )));
		$fieldmapping = $this->_mapping;
		foreach ($fieldmapping as $k=>$v) {
	    	switch ($v) {
			// these fields should be filled with 0
    			case 'busystatus' :
	    		case 'sensitivity' :
    			case 'meetingstatus' :
    			case 'alldayevent' :
	    			$message->$fieldmapping[$k]= (!empty($vcard[$k][0]['val'][0]) 	? w2u($vcard[$k][0]['val'][0]) 	: "0");
					break;
				case 'starttime' :
				case 'endtime' :
				case 'dtstamp' :
					if (empty($vcard[$k][0]['val'][0])) {
						$message->$fieldmapping[$k] = NULL;
						break;
					};
					$time = w2u($vcard[$k][0]['val'][0]);
					$time = gmmktime(gmdate("H",$time),gmdate("i",$time),gmdate("s",$time),
									 gmdate("n",$time),gmdate("j",$time),gmdate("Y",$time));
				    $message->$fieldmapping[$k] = $time;
				    break;
				case 'body' :
        			if ($bodypreference == false) {
    				    $message->body = w2u(str_replace("\n","\r\n",str_replace("\r","",$vcard[$k][0]['val'][0])));
					    $message->bodysize = strlen($message->body);
		        	    $message->bodytruncated = 0;
					} else {
					    $message->airsyncbasebody = new SyncAirSyncBaseBody();
					    debugLog("airsyncbasebody!");
					    $message->airsyncbasenativebodytype=1;
					    if (isset($bodypreference[2])) {
				    		debugLog("HTML Body");
				    	    // Send HTML if requested and native type was html
				    		$message->airsyncbasebody->type = 2;
		    				$html = '<html>'.
							    '<head>'.
							    '<meta name="Generator" content="Z-Push">'.
							    '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.
							    '</head>'.
							    '<body>'.
							    str_replace("\n","<BR>",str_replace("\r","<BR>", str_replace("\r\n","<BR>",w2u($vcard[$k][0]['val'][0])))).
							    '</body>'.
					    		'</html>';
	    		    		if (isset($bodypreference[2]["TruncationSize"]) &&
    	    	        	    strlen($html) > $bodypreference[2]["TruncationSize"]) {
	        	        	    $html = utf8_truncate($html,$bodypreference[2]["TruncationSize"]);
				    		    $message->airsyncbasebody->truncated = 1;
							}
							$message->airsyncbasebody->data = $html;
							$message->airsyncbasebody->estimateddatasize = strlen($html);
    				    } else {
				    	    // Send Plaintext as Fallback or if original body is plaintext
		    				debugLog("Plaintext Body");
							$plain = w2u(str_replace("\n","\r\n",str_replace("\r","",$vcard[$k][0]['val'][0])));
							$message->airsyncbasebody->type = 1;
		    				if(isset($bodypreference[1]["TruncationSize"]) &&
		    				    strlen($plain) > $bodypreference[1]["TruncationSize"]) {
		        			    $plain = utf8_truncate($plain, $bodypreference[1]["TruncationSize"]);
				    		    $message->airsyncbasebody->truncated = 1;
	   	    		        }
							$message->airsyncbasebody->estimateddatasize = strlen($plain);
		    				$message->airsyncbasebody->data = $plain;
	    			    }
					    // In case we have nothing for the body, send at least a blank... 
					    // dw2412 but only in case the body is not rtf!
	    			    if ($message->airsyncbasebody->type != 3 && (!isset($message->airsyncbasebody->data) || strlen($message->airsyncbasebody->data) == 0))
    		    			$message->airsyncbasebody->data = " ";
					}
					break;
				default : 
					$vs = explode("->",$v);
					if ($vs[0] == "recurrence" &&
					    !empty($vcard[$k][0]['val'][0])) {
					    if (!isset($message->recurrence)) $message->recurrence = new SyncRecurrence();
						    $message->recurrence->$vs[1] = (!empty($vcard[$k][0]['val'][0]) ? w2u($vcard[$k][0]['val'][0]) : NULL);
					} else {
					    $message->$fieldmapping[$k] = (!empty($vcard[$k][0]['val'][0]) ? w2u($vcard[$k][0]['val'][0]) : NULL);
					}
			}
		}
		if (!isset($message->uid) ||
			strlen($message->uid) == 0) $message->uid = $this->_uid();
		if (isset($message->recurrence)) {
			if (!isset($message->recurrence->interval)) {
				debugLog('iCalDir::GetMessage: Error, recurrence object found but no interval is set. Set it to 1 since it needs to be set');
				$message->recurrence->interval = 1;
			}
		}

        // debugLog('iCalDir::LoadedMessage-imported:' . print_r($message,1));

        return $message;
    }

    function DeleteMessage($folderid, $id) {
        debugLog('iCalDir::DeleteMessage ('.$this->_items[$id].')');
        return unlink($this->_path . '/' . $this->_items[$id]);
    }

    function SetReadFlag($folderid, $id, $flags) {
        debugLog('iCalDir::SetReadFlag');
        return false;
    }

// [RT-Comment]                                                                                                                                           START
// eigentliche Struktur der Adresse in der vcard Datei
// [RT-Comment]                                                                                                                                            ENDE


    function ChangeMessage($folderid, $id, $message) {
        debugLog('iCalDir::ChangeMessage('.$folderid.', '.$this->_items[$id].', ..)');
//        debugLog('iCalDir::ChangeMessage:' . print_r($message,1));
		
		// Since in >=AS12.1 we have the airsyncbasebody object
		// By doing this hack we can continue using our current functions...
		if (isset($message->airsyncbasebody)) {
		    switch($message->airsyncbasebody->type) {
		        case '3' 	: $message->rtf = $message->airsyncbasebody->data; break;
	    	    case '1' 	: $message->body = $message->airsyncbasebody->data; break;
		    }
		}
		// In case body is sent in rtf, convert it to ascii and use it as message body element so that we
		// can later on write it to file
		if (isset($message->rtf)) {
		    // Nokia MfE 2.9.158 sends contact notes with RTF and Body element. 
		    // The RTF is empty, the body contains the note therefore we need to unpack the rtf 
		    // to see if it is realy empty and in case not, take the appointment body.
		    $rtf_body = new rtf ();
		    $rtf_body->loadrtf(base64_decode($message->rtf));
		    $rtf_body->output("ascii");
		    $rtf_body->parse();
		    if (isset($message->body) &&
		        isset($rtf_body->out) &&
		        $rtf_body->out == "" && $message->body != "") {
		        unset($message->rtf);
		    }
//		    debugLog('iCalDir::RTFDATA:' . $message->rtf);
		    $rtf_body = new rtf ();
		    $rtf_body->loadrtf(base64_decode($message->rtf));
		    $rtf_body->output("ascii");
		    $rtf_body->parse();
//		    debugLog('iCalDir::RTFDATA-parsed:' . $rtf_body->out);
		    //put rtf into body
		    if($rtf_body->out <> "") $message->body=$rtf_body->out;
		}

		$fieldmapping = array_flip($this->_mapping);
        //$message->uid
        //   ist vom iPhone nicht wirklich barauchbar..., vorerst *deaktiviert*


        //$message->timezone:
        //   der hier im $message enthaltene Wert ist utf8 codiert. Wenn man diesen decodiert 
        //   bekommt man eine 172 Byte lange Structur bestehend aus Werten für die Zeitverschiebung
        //   des jeweiligen Eintrages. Im Hex-Editor sind z.B. die ersten 32 Bit das Offset zur UTC
        //   in Minuten, also für unsere Zeitzone wäre das -60 Minuten Weitere Infos dazu sind in 
        //   der Datentypendokumentation der Acticesync Doku

        $data = "BEGIN:itacomCalendarEntry\nVERSION:1.0\nPRODID:MobileSync\nLASTCHANGED:".date('Ymd:His')."\n";
        foreach($fieldmapping as $zpushobj => $icalfield){
		    switch ($zpushobj) {
				default	:
        		    $val = '';
	        	    // PHP split durch PHP.explode ersetzt da die Funktion auslÃ¤uft und ab PHP6 nicht mehr verfÃ¼gbar sein wird!
	        	    $zpushfields = explode('->', $zpushobj);
	        	    if ($zpushfields[0] == "recurrence") {
	            		if(!empty($message->recurrence->$zpushfields[1]))
	                	    $val .= $this->escape($message->recurrence->$zpushfields[1]);
		            	$val.=';';
	        	    } else {
		        		foreach($zpushfields as $zpushfield) {
	            		    if(!empty($message->$zpushfield))
		                		$val .= $this->escape($message->$zpushfield);
    	        		    $val.=';';
		        		}
	        	    }
	        	    if(empty($val))
	            		continue;
		       	    $val = substr($val,0,-1);
				    $data .= strtoupper($icalfield);
	        	    if(strlen($val)>50) {
    	        		$data .= ":\n\t".substr(chunk_split($val, 50, "\n\t"), 0, -1);
	        	    } else {
	            		$data .= ':'.$val."\n";
	        	    }
			}
        }
		if (!isset($fieldmapping['uid'])) {
			$uid = $this->_uid();
			$data .= "UID:".$uid."\n";
		}
        $data .= "END:itacomCalendarEntry";
//        debugLog('iCalDir::DATA:' . print_r($data,1));
		$data=utf8_encode($data);

        if(!$id){
            // jeder Termin müsste eine Startzeit haben, auch sollten relativ wenig Termine zur elben zeit beginnen
            // dadurch sollten die ID's/Dateinamen ziemlich einzigartig bleiben, so das es nicht nötig sein sollte "_i" hinten an die Dateinmaen zu packen
            $name=$message->starttime;
            $entry = $name.'.iCal';
            $i = 0;
            while(file_exists($this->_path.'/'.$entry)){
                $i++;
                $entry = $name.'_'.$i.'.iCal';
            }
    	    file_put_contents($this->_path.'/'.$entry, $data);
/*			ksort($this->_items);
			end($this->_items);
			if (key($this->_items)+1 == 1) {
				$id = sprintf("1%09d",key($this->_items)+1);
			} else {
				$id = key($this->_items)+1;
			}
*/
			$id = $this->_itemid();
			$this->_items[$id] = $entry;
			file_put_contents(STATE_DIR . '/' . strtolower($this->_devid). '/ical_items_'. $this->_user, serialize($this->_items));
        } else {
    	    file_put_contents($this->_path.'/'.$this->_items[$id], $data);
        }

        return $this->StatMessage($folderid, $id);
    }

    function MoveMessage($folderid, $id, $newfolderid) {
        debugLog('iCalDir::MoveMessage');
        return false;
    }

    // -----------------------------------

    function escape($data){
        debugLog('iCalDir::escape:input:' . $data );
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->escape($val);
            }
            return $data;
        }
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $data);
        debugLog('iCalDir::escape:output:' . u2wi($data) );
        return u2wi($data);
    }

    function unescape($data){
        $data = str_replace(array('\\\\', '\\;', '\\,', '\\n','\\N'),array('\\', ';', ',', "\n", "\n"),$data);
        return $data;
    }

    // Unpack timezone info from Sync
    function _getTZFromSyncBlob($data) {
        $tz = unpack(    "lbias/a64name/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
                        "lstdbias/a64name/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis/" .
                        "ldstbias", $data);

        return $tz;
    }

    // Pack timezone info for Sync
    function _getSyncBlobFromTZ($tz) {
        $packed = pack("la64vvvvvvvv" . "la64vvvvvvvv" . "l",
                $tz["bias"], "", 0, $tz["dstendmonth"], $tz["dstendday"], $tz["dstendweek"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"], $tz["dstendmillis"],
                $tz["stdbias"], "", 0, $tz["dststartmonth"], $tz["dststartday"], $tz["dststartweek"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"], $tz["dststartmillis"],
                $tz["dstbias"]);

        return $packed;
    }

    function _itemid() {
        return sprintf( '%04x%04x%04x%04x%04x%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }



};
?>