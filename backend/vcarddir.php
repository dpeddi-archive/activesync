<?php
/***********************************************
* File      :   vcarddir.php
* Project   :   Z-Push
* Descr     :   This backend is for vcard
*               directories.
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once('diffbackend.php');
include_once('z_RTF.php');

class BackendVCDir extends BackendDiff {
    var $_config;
    var $_user;
    var $_devid;
    var $_protocolversion;
    var $_path;

    function Setup($user, $devid, $protocolversion) {
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;
        $this->_path = $this->getPath();

		// ItemID Cache
    	$dir = opendir(STATE_PATH. "/" .strtolower($this->_devid));
        if(!$dir) {
	    	debugLog("IMAP Backend: creating folder for device ".strtolower($this->_devid));
	    	if (mkdir(STATE_PATH. "/" .strtolower($this->_devid), 0744) === false) 
				debugLog("IMAP Backend: failed to create folder ".strtolower($this->_devid));
		}
		$filename = STATE_DIR . '/' . strtolower($this->_devid). '/vcard_items_'. $this->_user;
		$this->_items = false;
		if (file_exists($filename)) {
	    	if (($this->_items = file_get_contents(STATE_DIR . '/' . strtolower($this->_devid). '/vcard_items_'. $this->_user)) !== false) {
				$this->_items = unserialize($this->_items);
	    	} else {
	        	$this->_items = array();
		    }
		} else {
	    	$this->_items =  array();
	    }

        return true;
    }

    function SendMail($rfc822, $smartdata=array(), $protocolversion = false) {
        return false;
    }

    function GetWasteBasket() {
        return false;
    }

    function GetMessageList($folderid, $cutoffdate) {
        debugLog('VCDir::GetMessageList('.$folderid.')');
        $messages = array();

        $dir = opendir($this->_path);
		$mod = false;
        if(!$dir)
            return false;

        while($entry = readdir($dir)) {
            if(is_dir($this->_path .'/'.$entry))
                continue;

			// put real imap id in cache and create unique folderid instead
			if (($entryid = array_search($entry,$this->_items)) === false) {
				ksort($this->_items);
				end($this->_items);
				if (key($this->_items)+1 == 1)
			    	$entryid = sprintf("1%09d",key($this->_items)+1);
			    else 
			    	$entryid = key($this->_items)+1;
			    $this->_items[$entryid] = $entry;
				$mod = true;
			}
            $message = array();
            $message["id"] = $entryid;
            $stat = stat($this->_path .'/'.$entry);
            $message["mod"] = $stat["mtime"];
            $message["flags"] = 1; // always 'read'

            $messages[] = $message;
        }

		if ($mod == true)
			file_put_contents(STATE_DIR . '/' . strtolower($this->_devid). '/vcard_items_'. $this->_user, serialize($this->_items));

        return $messages;
    }

    function GetFolderList() {
        debugLog('VCDir::GetFolderList()');
        $contacts = array();
        $folder = $this->StatFolder("root");
        $contacts[] = $folder;

        return $contacts;
    }

    function GetFolder($id) {
        debugLog('VCDir::GetFolder('.$id.')');
        if($id == "root") {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = "Contacts";
            $folder->type = SYNC_FOLDER_TYPE_CONTACT;

            return $folder;
        } else return false;
    }

    function StatFolder($id) {
        debugLog('VCDir::StatFolder('.$id.')');
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    function GetAttachmentData($attname) {
        return false;
    }

    function StatMessage($folderid, $id) {
        debugLog('VCDir::StatMessage('.$folderid.', '.$this->_items[$id].')');
        if($folderid != "root")
            return false;

        $stat = stat($this->_path . "/" . $this->_items[$id]);

        $message = array();
        $message["mod"] = $stat["mtime"];
        $message["id"] = $id;
        $message["flags"] = 1;

        return $message;
    }

    function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0) {
        debugLog('VCDir::GetMessage('.$folderid.', '.$this->_items[$id].', ..)');
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
            '7bit' => 'encoding', '8bit' => 'encoding', 'quoted-printable' => 'encoding', 'base64' => 'encoding',
        );


        // Parse the vcard
        $message = new SyncContact();

        $data = file_get_contents($this->_path . "/" . $this->_items[$id]);
        $data = str_replace("\x00", '', $data);
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = preg_replace('/(\n)([ \t])/i', '', $data);

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

        if(isset($vcard['email'][0]['val'][0]))
            $message->email1address = $vcard['email'][0]['val'][0];
        if(isset($vcard['email'][1]['val'][0]))
            $message->email2address = $vcard['email'][1]['val'][0];
        if(isset($vcard['email'][2]['val'][0]))
            $message->email3address = $vcard['email'][2]['val'][0];

        if(isset($vcard['tel'])){
            foreach($vcard['tel'] as $tel) {
                if(!isset($tel['type'])){
                    $tel['type'] = array();
                }
                if(in_array('car', $tel['type'])){
                    $message->carphonenumber = $tel['val'][0];
                }elseif(in_array('pager', $tel['type'])){
                    $message->pagernumber = $tel['val'][0];
                }elseif(in_array('cell', $tel['type'])){
                    $message->mobilephonenumber = $tel['val'][0];
                }elseif(in_array('home', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->homefaxnumber = $tel['val'][0];
                    }elseif(empty($message->homephonenumber)){
                        $message->homephonenumber = $tel['val'][0];
                    }else{
                        $message->home2phonenumber = $tel['val'][0];
                    }
                }elseif(in_array('work', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->businessfaxnumber = $tel['val'][0];
                    }elseif(empty($message->businessphonenumber)){
                        $message->businessphonenumber = $tel['val'][0];
                    }else{
                        $message->business2phonenumber = $tel['val'][0];
                    }
                }elseif(empty($message->homephonenumber)){
                    $message->homephonenumber = $tel['val'][0];
                }elseif(empty($message->home2phonenumber)){
                    $message->home2phonenumber = $tel['val'][0];
                }else{
                    $message->radiophonenumber = $tel['val'][0];
                }
            }
        }
        //;;street;city;state;postalcode;country
        if(isset($vcard['adr'])){
            foreach($vcard['adr'] as $adr) {
                if(empty($adr['type'])){
                    $a = 'other';
                }elseif(in_array('home', $adr['type'])){
                    $a = 'home';
                }elseif(in_array('work', $adr['type'])){
                    $a = 'business';
                }else{
                    $a = 'other';
                }
                if(!empty($adr['val'][2])){
                    $b=$a.'street';
                    $message->$b = w2ui($adr['val'][2]);
                }
                if(!empty($adr['val'][3])){
                    $b=$a.'city';
                    $message->$b = w2ui($adr['val'][3]);
                }
                if(!empty($adr['val'][4])){
                    $b=$a.'state';
                    $message->$b = w2ui($adr['val'][4]);
                }
                if(!empty($adr['val'][5])){
                    $b=$a.'postalcode';
                    $message->$b = w2ui($adr['val'][5]);
                }
                if(!empty($adr['val'][6])){
                    $b=$a.'country';
                    $message->$b = w2ui($adr['val'][6]);
                }
            }
        }

        if(!empty($vcard['fn'][0]['val'][0]))
            $message->fileas = w2ui($vcard['fn'][0]['val'][0]);
        if(!empty($vcard['n'][0]['val'][0]))
            $message->lastname = w2ui($vcard['n'][0]['val'][0]);
        if(!empty($vcard['n'][0]['val'][1]))
            $message->firstname = w2ui($vcard['n'][0]['val'][1]);
        if(!empty($vcard['n'][0]['val'][2]))
            $message->middlename = w2ui($vcard['n'][0]['val'][2]);
        if(!empty($vcard['n'][0]['val'][3]))
            $message->title = w2ui($vcard['n'][0]['val'][3]);
        if(!empty($vcard['n'][0]['val'][4]))
            $message->suffix = w2ui($vcard['n'][0]['val'][4]);
        if(!empty($vcard['bday'][0]['val'][0])){
            $tz = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $message->birthday = strtotime($vcard['bday'][0]['val'][0]);
            date_default_timezone_set($tz);
        }
        if(!empty($vcard['org'][0]['val'][0]))
            $message->companyname = w2ui($vcard['org'][0]['val'][0]);
	    if(!empty($vcard['note'][0]['val'][0])){
	    	if ($bodypreference === false) {
    	        $message->body = w2ui($vcard['note'][0]['val'][0]);
        	    $message->bodysize = strlen($vcard['note'][0]['val'][0]);
            	$message->bodytruncated = 0;
	        } else {
	    	    if (isset($bodypreference[1]) && !isset($bodypreference[1]["TruncationSize"])) 
	    		    $bodypreference[1]["TruncationSize"] = 1024*1024;
				if (isset($bodypreference[2]) && !isset($bodypreference[2]["TruncationSize"])) 
				    $bodypreference[2]["TruncationSize"] = 1024*1024;
				if (isset($bodypreference[3]) && !isset($bodypreference[3]["TruncationSize"]))
				    $bodypreference[3]["TruncationSize"] = 1024*1024;
				if (isset($bodypreference[4]) && !isset($bodypreference[4]["TruncationSize"]))
			    	$bodypreference[4]["TruncationSize"] = 1024*1024;
				$message->airsyncbasebody = new SyncAirSyncBaseBody();
				debugLog("airsyncbasebody!");
				$body="";
				$plain = $vcard['note'][0]['val'][0];
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
							str_replace("\n","<BR>",str_replace("\r","<BR>", str_replace("\r\n","<BR>",w2u($plain)))).
							'</body>'.
							'</html>';
		    	    if(isset($bodypreference[2]["TruncationSize"]) &&
	    	            strlen($html) > $bodypreference[2]["TruncationSize"]) {
		                $html = utf8_truncate($html,$bodypreference[2]["TruncationSize"]);
				        $message->airsyncbasebody->truncated = 1;
				    }
				    $message->airsyncbasebody->data = $html;
				    $message->airsyncbasebody->estimateddatasize = strlen($html);
		    	} else {
					    // Send Plaintext as Fallback or if original body is plaintext
				    debugLog("Plaintext Body");
					$plain = $vcard['note'][0]['val'][0];
				    $plain = w2u(str_replace("\n","\r\n",str_replace("\r","",$plain)));
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
		    	if ($message->airsyncbasebody->type != 3 && 
		    		(!isset($message->airsyncbasebody->data) || strlen($message->airsyncbasebody->data) == 0))
		       	    $message->airsyncbasebody->data = " ";
		    }
	    }
        if(!empty($vcard['role'][0]['val'][0]))
            $message->jobtitle = w2ui($vcard['role'][0]['val'][0]);//$vcard['title'][0]['val'][0]
        if(!empty($vcard['url'][0]['val'][0]))
            $message->webpage = w2ui($vcard['url'][0]['val'][0]);
        if(!empty($vcard['categories'][0]['val']))
            $message->categories = $vcard['categories'][0]['val'];

        if(!empty($vcard['photo'][0]['val'][0]))
            $message->picture = base64_encode($vcard['photo'][0]['val'][0]);

        return $message;
    }

    function DeleteMessage($folderid, $id) {
        return unlink($this->_path . '/' . $this->_items[$id]);
    }

    function SetReadFlag($folderid, $id, $flags) {
        return false;
    }

    function ChangeMessage($folderid, $id, $message) {
        debugLog('VCDir::ChangeMessage('.$folderid.', '.$this->_items[$id].', ..)');
        $mapping = array(
            'fileas' => 'FN',
            'lastname;firstname;middlename;title;suffix' => 'N',
            'email1address' => 'EMAIL;INTERNET',
            'email2address' => 'EMAIL;INTERNET',
            'email3address' => 'EMAIL;INTERNET',
            'businessphonenumber' => 'TEL;WORK',
            'business2phonenumber' => 'TEL;WORK',
            'businessfaxnumber' => 'TEL;WORK;FAX',
            'homephonenumber' => 'TEL;HOME',
            'home2phonenumber' => 'TEL;HOME',
            'homefaxnumber' => 'TEL;HOME;FAX',
            'mobilephonenumber' => 'TEL;CELL',
            'carphonenumber' => 'TEL;CAR',
            'pagernumber' => 'TEL;PAGER',
            ';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;WORK',
            ';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;HOME',
            ';;otherstreet;othercity;otherstate;otherpostalcode;othercountry' => 'ADR',
            'companyname' => 'ORG',
            'body' => 'NOTE',
            'jobtitle' => 'ROLE',
            'webpage' => 'URL',
        );

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
		    debugLog('vcarddir::RTFDATA:' . $message->rtf);
		    $rtf_body = new rtf ();
		    $rtf_body->loadrtf(base64_decode($message->rtf));
	    	$rtf_body->output("ascii");
		    $rtf_body->parse();
		    debugLog('vcarddir::RTFDATA-parsed:' . $rtf_body->out);
	    	//put rtf into body
		    if($rtf_body->out <> "") $message->body=$rtf_body->out;
		}

        $data = "BEGIN:VCARD\nVERSION:2.1\nPRODID:Z-Push\n";
        foreach($mapping as $k => $v){
            $val = '';
            $ks = split(';', $k);
            foreach($ks as $i){
                if(!empty($message->$i))
                    $val .= $this->escape($message->$i);
                $val.=';';
            }
            if(empty($val))
                continue;
            $val = substr($val,0,-1);
            if(strlen($val)>50){
                $data .= $v.":\n\t".substr(chunk_split($val, 50, "\n\t"), 0, -1);
            }else{
                $data .= $v.':'.$val."\n";
            }
        }
        if(!empty($message->categories))
            $data .= 'CATEGORIES:'.implode(',', $this->escape($message->categories))."\n";
        if(!empty($message->picture))
            $data .= 'PHOTO;ENCODING=BASE64;TYPE=JPEG:'."\n\t".substr(chunk_split($message->picture, 50, "\n\t"), 0, -1);
        if(isset($message->birthday))
            $data .= 'BDAY:'.date('Y-m-d', $message->birthday)."\n";
        $data .= "END:VCARD";

// not supported: anniversary, assistantname, assistnamephonenumber, children, department, officelocation, radiophonenumber, spouse, rtf

        if(!$id){
            if(!empty($message->fileas)){
                $name = u2wi($message->fileas);
            }elseif(!empty($message->lastname)){
                $name = $name = u2wi($message->lastname);
            }elseif(!empty($message->firstname)){
                $name = $name = u2wi($message->firstname);
            }elseif(!empty($message->companyname)){
                $name = $name = u2wi($message->companyname);
            }else{
                $name = 'unknown';
            }
            $name = preg_replace('/[^a-z0-9 _-]/i', '', $name);
            $entry = $name.'.vcf';
            $i = 0;
            while(file_exists($this->_path.'/'.$entry)){
                $i++;
                $entry = $name.$i.'.vcf';
            }
    	    file_put_contents($this->_path.'/'.$entry, $data);
			ksort($this->_items);
			end($this->_items);
			if (key($this->_items)+1 == 1)
				$id = sprintf("1%09d",key($this->_items)+1);
			else 
				$id = key($this->_items)+1;
			$this->_items[$id] = $entry;
			file_put_contents(STATE_DIR . '/' . strtolower($this->_devid). '/vcard_items_'. $this->_user, serialize($this->_items));
        } else {
    	    file_put_contents($this->_path.'/'.$this->_items[$id], $data);
        }
        return $this->StatMessage($folderid, $id);
    }

    function MoveMessage($folderid, $id, $newfolderid) {
        return false;
    }

    // -----------------------------------

    function getPath() {
        return str_replace('%u', $this->_user, VCARDDIR_DIR);
    }

    function escape($data){
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->escape($val);
            }
            return $data;
        }
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $data);
        return u2wi($data);
    }

    function unescape($data){
        $data = str_replace(array('\\\\', '\\;', '\\,', '\\n','\\N'),array('\\', ';', ',', "\n", "\n"),$data);
        return $data;
    }
};
?>