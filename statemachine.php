<?php
/***********************************************
* File      :   statemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*               Each differential mechanism can
*               store its own state information,
*               which is stored through the
*               state machine. SyncKey's are
*               of the  form {UUID}N, in which
*               UUID is allocated during the
*               first sync, and N is incremented
*               for each request to 'getNewSyncKey'.
*               A sync state is simple an opaque
*               string value that can differ
*               for each backend used - normally
*               a list of items as the backend has
*               sent them to the PIM. The backend
*               can then use this backend
*               information to compute the increments
*               with current data.
*
*               Old sync states are not deleted
*               until a sync state is requested.
*               At that moment, the PIM is
*               apparently requesting an update
*               since sync key X, so any sync
*               states before X are already on
*               the PIM, and can therefore be
*               removed. This algorithm is
*               automatically enforced by the
*               StateMachine class.
*
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/


class StateMachine {
    var $_devid;
    var $oldsynccache;
    var $newsynccache;
    
    // Gets the sync state for a specified sync key. Requesting a sync key also implies
    // that previous sync states for this sync key series are no longer needed, and the
    // state machine will tidy up these files.
    function StateMachine($devid, $user) {
		$this->_devid = strtolower($devid);
		$this->_user = strtolower($user);
		debugLog ("Statemachine _devid initialized with ".$this->_devid." for user ".$user);
        $dir = opendir(STATE_PATH. "/" .$this->_devid);
        if(!$dir) {
	    	debugLog("StateMachine: created folder for device ".$this->_devid);
	 		if (mkdir(STATE_PATH. "/" .$this->_devid, 0744) === false) 
				debugLog("StateMachine: failed to create folder ".$this->_devid);
		}
    }

    function getSyncState($synckey) {

        // No sync state for sync key '0'
        if($synckey == "0" || $synckey == "SMS0" || $synckey == "s0" || $synckey == "mi0") {
	    	debugLog("GetSyncState: Sync key 0 detected");
            return "";
		}

        // Check if synckey is allowed
        if(!preg_match('/^(s|mi|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches)) {
		    debugLog("GetSyncState: Sync key invalid formatted (".$syckey.")");
            return -9;
        }

        // Remember synckey GUID and ID
		$key = $matches[1];
        $guid = $matches[2];
        $n = $matches[3];

        // Cleanup all older syncstates
        // dw2412 removed. Doing this in request.php depending on synckeys we get from device (by this we know device 
        // has got a cretain key!)

        // Read current sync state
        $filename = STATE_PATH . "/". $this->_devid . "/$synckey";

	// For SMS Sync take on first sync the Main Sync Key from the folder in question
        if (!file_exists($filename) &&
            $key=="SMS") {
            debugLog("GetSyncState: Initial SMS Sync since sms state not existing");
            return "";
//          debugLog("GetSyncState: Initial SMS Sync, take state from the main folder file");
//          $filename = STATE_PATH . "/" .$this->_devid . '/{'.$guid.'}'.$n;
        }

        if(file_exists($filename)) {
	    	$content = file_get_contents($filename);
	    	debugLog("GetSyncState: File $filename read");
			debugLog("GetSyncState: Size of syncstate is ".strlen($content));
//			if (strlen($content) > 8) {
//				debugLog("GetSyncState: Size of syncstate is > 8, create backup.");
//				file_put_contents($filename."get.bak", $content);
//			}
            return $content;
        } else {
	    	debugLog("GetSyncState: File $filename not existing");
    	    return -9;
    	}
    }

    // we need to seperate the cleanup from the get since with AS14 cached synckeys being used. Synckeys
    // should only be unlinked in case the got confirmed from device to keep the sync alive.
    function cleanOldSyncState($synckey) {
        if($synckey == "0" || $synckey == "SMS0" || $synckey == "s0" || $synckey == "mi0" ) {
	    	debugLog("cleanOldSyncState: Sync key 0 detected");
            return true;
		}

        // Check if synckey is allowed
        if(!preg_match('/^(s|mi|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches)) {
		    debugLog("cleanOldSyncState: Sync key invalid formatted");
            return -9;
        }

        // Remember synckey GUID and ID
		$key = $matches[1];
        $guid = $matches[2];
        $n = $matches[3];

        $dir = opendir(STATE_PATH."/".$this->_devid);
        if(!$dir) {
		    debugLog("cleanOldSyncState: Sync key folder not existing");
            return -12;
		}

        // Cleanup all older syncstates
    	while($entry = readdir($dir)) {
    	    if(preg_match('/^(s|mi|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $entry, $matches)) {
                if($matches[1] == $key && $matches[2] == $guid && $matches[3] < ($n-1)) {
	    	    debugLog("GetSyncState: Removing old Sync Key ".STATE_PATH . "/". $this->_devid . "/$entry");
            	    unlink(STATE_PATH . "/".$this->_devid . "/$entry");
            	}
    	    }
		}

		return true;
    }

	// Function is used to remove SMS Sync State files in case SMS sync is disabled again on client
    function removeSyncState($synckey) {
        if($synckey == "0" || $synckey == "SMS0" || $synckey == "s0" || $synckey == "mi0" ) {
	    	debugLog("removeSyncState: Sync key 0 detected");
            return true;
		}

        // Check if synckey is allowed
        if(!preg_match('/^(s|mi|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches)) {
		    debugLog("removeSyncState: Sync key invalid formatted");
            return -9;
        }

        // Remember synckey GUID and ID
		$key = $matches[1];
        $guid = $matches[2];
        $n = $matches[3];

        $dir = opendir(STATE_PATH."/".$this->_devid);
        if(!$dir) {
		    debugLog("removeSMSSyncState: Sync key folder not existing");
            return -12;
		}

        // Cleanup all older syncstates
    	while($entry = readdir($dir)) {
    	    if(preg_match('/^(s|mi|SMS){0,1}\{([0-9A-Za-z-]+)\}([0-9]+)$/', $entry, $matches)) {
                if($matches[1] == $key && $matches[2] == $guid) {
	    	    debugLog("removeSyncState: Removing old Sync Key ".STATE_PATH . "/". $this->_devid . "/$entry");
            	    unlink(STATE_PATH . "/".$this->_devid . "/$entry");
            	}
    	    }
		}

		return true;
    }


    // Gets the new sync key for a specified sync key. You must save the new sync state
    // under this sync key when done sync'ing (by calling setSyncState);
    function getNewSyncKey($synckey) {
        if(!isset($synckey) || $synckey == "0") {
            return "{" . $this->uuid() . "}" . "1";
        } else {
            if(preg_match('/^(s|mi|SMS){0,1}\{([a-zA-Z0-9-]+)\}([0-9]+)$/', $synckey, $matches)) {
                $n = $matches[3];
                $n++;
                return "{" . $matches[2] . "}" . $n;
            } else return false;
        }
    }

    // Writes the sync state to a new synckey
    function setSyncState($synckey, $syncstate) {
        // Check if synckey is allowed
		debugLog("setSyncState: Try writing to file ".STATE_PATH . "/". $this->_devid . "/$synckey");
		debugLog("setSyncState: Size of syncstate is ".strlen($syncstate));
//		if (strlen($syncstate) > 8) {
//			debugLog("SetSyncState: Size of syncstate is > 8, create backup.");
//			file_put_contents(STATE_PATH . "/". $this->_devid . "/$synckey"."set.bak", $syncstate);
//            if(preg_match('/^(s|SMS){0,1}\{([a-zA-Z0-9-]+)\}([0-9]+)$/', $synckey, $matches)) {
//                $n = $matches[3];
//                $n--;
//                $oldkey="{" . $matches[2] . "}" . $n;
//                file_put_contents(STATE_PATH . "/". $this->_devid . "/$oldkey"."set.bak",
//                	file_get_contents(STATE_PATH . "/". $this->_devid . "/$oldkey"));
//        	};
//		}

        if(!preg_match('/^(s|mi|SMS){0,1}\{[0-9A-Za-z-]+\}[0-9]+$/', $synckey)) {
		    debugLog("setSyncState: Format not match!");
            return false;
        }

        return file_put_contents(STATE_PATH . "/". $this->_devid . "/$synckey", $syncstate);
    }

    // Writes the sync state to a new synckey
    function setSyncCache($cachestate) {
		global $cachestatus;
		$this->newsynccache = unserialize($cachestate);
		$cachestatus = SYNCCACHE_UNCHANGED;
		if (is_array($this->newsynccache) && is_array($this->oldsynccache)) {
		    if (isset($this->oldsynccache["collections"]) && isset($this->newsynccache["collections"]) &&
				is_array($this->oldsynccache["collections"]) && is_array($this->newsynccache["collections"])) {
				$this->_compareCacheRecursive($this->oldsynccache["collections"],$this->newsynccache["collections"],$cachestatus);
				$this->_compareCacheRecursive($this->newsynccache["collections"],$this->oldsynccache["collections"],$cachestatus);
		    } else $cachestatus = SYNCCACHE_CHANGED;
		} else $cachestatus = SYNCCACHE_CHANGED;
		return file_put_contents(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid."_".$this->_user, $cachestate);
    }

    function _compareCacheRecursive($old, $new, &$cachestatus) {
		$cachestatus = SYNCCACHE_UNCHANGED;
		foreach ($old as $key=>$value) {
		    if ($cachestatus == SYNCCACHE_CHANGED) return;
		    if (isset($new[$key])) {
				if (is_array($old[$key]) && is_array($old[$key])) {
				    $this->_compareCacheRecursive($old[$key],$new[$key],$cachestatus);
				} else {
				    $diff = array_diff_assoc($old, $new);
				    if (is_array($diff) && count($diff) > 0) {
						$cachestatus = SYNCCACHE_CHANGED;
						return;
				    }
				}
	    	} else {
				$cachestatus = SYNCCACHE_CHANGED;
		    }
		}
    }

    function getSyncCache() {
        
        if(file_exists(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid."_".$this->_user)) {
    	    $content = file_get_contents(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid."_".$this->_user);
    	    $this->oldsynccache=unserialize($content);
            return $content;
        } else if(file_exists(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid)) {
// just for compatibility to take old cache and apply it for new format
    	    $content = file_get_contents(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid);
			if (file_put_contents(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid."_".$this->_user, $content)) 
				unlink(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid);
    	    $this->oldsynccache=unserialize($content);
            return $content;
        } else return false;
    }


    function deleteSyncCache() {
    	// Remove the cache in case full sync is requested with a synckey 0. 
    	if(file_exists(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid))
    	    unlink(STATE_PATH . "/". $this->_devid . "/cache_".$this->_devid);
    }

    function updateSyncCacheFolder(&$cache, $serverid, $parentid, $displayname, $type) {
		debugLog((!isset($cache['folders'][$serverid]) ? "Adding" : "Updating")." SyncCache Folder ".$serverid." Parent: ".$parentid." Name: ".$displayname." Type: ". $type);
        if (isset($parentid))    $cache['folders'][$serverid]['parentid'] = $parentid;
        if (isset($displayname)) $cache['folders'][$serverid]['displayname'] = $displayname;
		switch ($type) {
		    case 7	: // These are Task classes
		    case 15	: $cache['folders'][$serverid]['class'] = "Tasks"; break;
		    case 8	: // These are calendar classes
		    case 13	: $cache['folders'][$serverid]['class'] = "Calendar"; break;
		    case 9	: // These are contact classes
		    case 14	: $cache['folders'][$serverid]['class'] = "Contacts"; break;
		    case 17	: 
		    case 10	: $cache['folders'][$serverid]['class'] = "Notes"; break;
		    case 1	: // All other types map to Email
		    case 2	: 
		    case 3	: 
		    case 4	: 
		    case 5	: 
		    case 6	: 
		    case 11	: 
		    case 12	: 
		    case 16	: 
		    case 18	: 
		    default	: $cache['folders'][$serverid]['class'] = "Email";
		}
		$cache['folders'][$serverid]['type'] = $type;
		$cache['folders'][$serverid]['filtertype'] = "0";
		$cache['timestamp'] = time();
	}

    function deleteSyncCacheFolder(&$cache, $serverid) {
		debugLog("Delete SyncCache Folder ".$serverid);
		unset($cache['folders'][$serverid]);
		unset($cache['collections'][$serverid]);
		$cache['timestamp'] = time();
    }
    
    function getProtocolState() {
		if ($this->_devid == "") return false;
	        if (file_exists(STATE_PATH . "/". $this->_devid . "/prot_".$this->_devid))
    	        return file_get_contents(STATE_PATH . "/". $this->_devid . "/prot_".$this->_devid);
	        else return "";
	}
    
    function setProtocolState($protstate) {
		if ($this->_devid == "") return false;
        	return file_put_contents(STATE_PATH . "/". $this->_devid . "/prot_".$this->_devid,$protstate);
	}
    
	function getMsgInfos($hierarchysynckey) {
        if(!isset($hierarchysynckey) || $hierarchysynckey == "0") {
            return array();
        } else {
            if(preg_match('/^(s|mi|SMS){0,1}\{([a-zA-Z0-9-]+)\}([0-9]+)$/', $hierarchysynckey, $matches)) {
                $filename = "mi{" . $matches[2] . "}";
            } else array();
        }
		$filename = STATE_PATH . "/". $this->_devid . "/". $filename;
		$res = unserialize(file_get_contents($filename));
		if ($res === false) return array();
		debugLog("getMsgInfos called with synckey ".$hierarchysynckey);
		return $res;
	}

	function setMsgInfos($hierarchysynckey,$msginfos) {
        if(!isset($hierarchysynckey) || $hierarchysynckey == "0") {
            return array();
        } else {
            if(preg_match('/^(s|mi|SMS){0,1}\{([a-zA-Z0-9-]+)\}([0-9]+)$/', $hierarchysynckey, $matches)) {
                $filename = "mi{" . $matches[2] . "}";
            } else array();
        }
		$filename = STATE_PATH . "/". $this->_devid . "/". $filename;
		debugLog("setMsgInfos called with synckey ".$hierarchysynckey);
		return file_put_contents($filename,serialize($msginfos));
	}

    function uuid() {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }
};

?>
