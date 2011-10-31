<?php
/***********************************************
* File      :   diffbackend.php
* Project   :   Z-Push
* Descr     :   We do a standard differential
*               change detection by sorting both
*               lists of items by their unique id,
*               and then traversing both arrays
*               of items at once. Changes can be
*               detected by comparing items at
*               the same position in both arrays.
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once('proto.php');
include_once('backend.php');

function GetDiff(array $old_in, array $new_in)
{
    $changes = $old = array();

    // create associative array of old items with id as key
    foreach($old_in as &$item)
    {
        $old[$item['id']] =& $item;
    }

    // iterate through new items to identify new or changed items
    foreach($new_in as &$item)
    {
        $id = $item['id'];
        $change = array(
            'id' => $id,
        );

        if (!isset($old[$id]))
        {
            // Message in new seems to be new (add)
            $change['type'] = 'change';
            $change['flags'] = SYNC_NEWMESSAGE;
            $changes[] = $change;
        }
        else
        {
            $old_item =& $old[$id];

            // Both messages are still available, compare flags and mod
            if(isset($old_item["flags"]) && isset($item["flags"]) && $old_item["flags"] != $item["flags"]) {
                // Flags changed
                $change['type'] = 'flags';
                $change['flags'] = $item['flags'];
                $changes[] = $change;
            }

            if(isset($old_item['olflags']) && isset($item['olflags']) && $old_item['olflags'] != $item['olflags']) {
                // Outlook Flags changed
                $change['type'] = 'olflags';
                $change['olflags'] = $item['olflags'];
                $changes[] = $change;
            }

            if($old_item['mod'] != $item['mod']) {
                $change['type'] = 'change';
                $changes[] = $change;
            }

            // unset in $old, so $old contains only the deleted items
            unset($old[$id]);
        }
    }

    // now $old contains only deleted items
    foreach($old as $id => &$item)
    {
        // Message in state seems to have disappeared (delete)
        $changes[] = array(
            'type' => 'delete',
            'id'   => $id,
        );
    }

    return $changes;
}

class DiffState {
    var $_syncstate;

    // Update the state to reflect changes
    function updateState($type, $change) {
        // Change can be a change or an add
        if($type == "change") {
            for($i=0; $i < count($this->_syncstate); $i++) {
                if($this->_syncstate[$i]["id"] == $change["id"]) {
                    $this->_syncstate[$i] = $change;
                    return;
                }
            }
            // Not found, add as new
            $this->_syncstate[] = $change;
        } else {
            for($i=0; $i < count($this->_syncstate); $i++) {
                // Search for the entry for this item
                if($this->_syncstate[$i]["id"] == $change["id"]) {
                    if($type == "flags") {
                        // Update flags
                        $this->_syncstate[$i]["flags"] = $change["flags"];
                    } elseif($type == "olflags") {
                        // Update Outlook flags
                        $this->_syncstate[$i]["olflags"] = $change["olflags"];
                    } else if($type == "delete") {
                        // Delete item
                        array_splice($this->_syncstate, $i, 1);
                    }
                    return;
                }
            }
        }
    }

    // Returns TRUE if the given ID conflicts with the given operation. This is only true in the following situations:
    //
    // - Changed here and changed there
    // - Changed here and deleted there
    // - Deleted here and changed there
    //
    // Any other combination of operations can be done (e.g. change flags & move or move & delete)
    function isConflict($type, $folderid, $id) {
        $stat = $this->_backend->StatMessage($folderid, $id);

        if(!$stat) {
            // Message is gone
            if($type == "change")
                return true; // deleted here, but changed there
            else
                return false; // all other remote changes still result in a delete (no conflict)
        }

        foreach($this->_syncstate as $state) {
            if($state["id"] == $id) {
                $oldstat = $state;
                break;
            }
        }

        if(!isset($oldstat)) {
            // New message, can never conflict
            return false;
        }

        if($state["mod"] != $oldstat["mod"]) {
            // Changed here
            if($type == "delete" || $type == "change")
                return true; // changed here, but deleted there -> conflict, or changed here and changed there -> conflict
            else
                return false; // changed here, and other remote changes (move or flags)
        }
    }

    function GetState() {
        return serialize($this->_syncstate);
    }

}

class ImportContentsChangesDiff extends DiffState {
    var $_user;
    var $_folderid;

    function ImportContentsChangesDiff($backend, $folderid) {
        $this->_backend = $backend;
        $this->_folderid = $folderid;
    }

    function Config($state, $flags = 0) {
        $this->_syncstate = unserialize($state);
        $this->_flags = $flags;
    }

    function ImportMessageChange($id, $message) {
        //do nothing if it is in a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY)
            return false;

        if($id) {
            // See if there's a conflict
            $conflict = $this->isConflict("change", $this->_folderid, $id);

            // Update client state if this is an update
            $change = array();
            $change["id"] = $id;
            $change["mod"] = 0; // dummy, will be updated later if the change succeeds
            $change["parent"] = $this->_folderid;
            $change["flags"] = (isset($message->read)) ? $message->read : 0;
            $change["olflags"] = (isset($message->poommailflag->flagstatus)) ? $message->poommailflag->flagstatus : 0;
            $this->updateState("change", $change);

            if($conflict && $this->_flags == SYNC_CONFLICT_OVERWRITE_PIM)
                return true;
        }

        $stat = $this->_backend->ChangeMessage($this->_folderid, $id, $message);
        if(!is_array($stat))
            return $stat;

        // Record the state of the message
        $this->updateState("change", $stat);

        return $stat["id"];
    }

    // Import a deletion. This may conflict if the local object has been modified.
    function ImportMessageDeletion($id) {
        //do nothing if it is in a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY)
            return true;

        // See if there's a conflict
        $conflict = $this->isConflict("delete", $this->_folderid, $id);

        // Update client state
        $change = array();
        $change["id"] = $id;
        $this->updateState("delete", $change);

        // If there is a conflict, and the server 'wins', then return OK without performing the change
        // this will cause the exporter to 'see' the overriding item as a change, and send it back to the PIM
        if($conflict && $this->_flags == SYNC_CONFLICT_OVERWRITE_PIM)
            return true;

        $this->_backend->DeleteMessage($this->_folderid, $id);

        return true;
    }

    // Import a change in 'read' flags .. This can never conflict
    function ImportMessageReadFlag($id, $flags) {
        //do nothing if it is a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY)
            return true;

        // Update client state
        $change = array();
        $change["id"] = $id;
        $change["flags"] = $flags;
        $this->updateState("flags", $change);

        $this->_backend->SetReadFlag($this->_folderid, $id, $flags);

        return true;
    }

    function ImportMessageMove($id, $newfolder) {
        //do nothing if it is a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY || $newfolder == SYNC_FOLDER_TYPE_DUMMY)
            return true;

        // Update client state
        $change = array();
        $change["id"] = $id;
        $change["mod"] = 0; // dummy, will be updated later if the change succeeds
        $change["parent"] = $this->_folderid;
        $change["flags"] = (isset($message->read)) ? $message->read : 0;
        $change["olflags"] = 0;
        $this->updateState("change", $change);

        return $this->_backend->MoveMessage($this->_folderid, $id, $newfolder);
    }

    // Outlook Supports flagging messages - Imap afaik not. Simply return true in this case not to break sync...
    function ImportMessageFlag($id, $flag) {
        //do nothing if it is a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY)
            return true;

        // Update client state
        $change = array();
        $change["id"] = $id;
        $change["olflags"] = isset ($flag->flagstatus) ? $flag->flagstatus : 0;
        $this->updateState("olflags", $change);

        return $this->_backend->ChangeMessageFlag($this->_folderid, $id, $flag);

    }


};

class ImportHierarchyChangesDiff extends DiffState {
    var $_user;

    function ImportHierarchyChangesDiff($backend) {
        $this->_backend = $backend;
    }

    function Config($state) {
        $this->_syncstate = unserialize($state);
    }

    function ImportFolderChange($id, $parent, $displayname, $type) {
        //do nothing if it is a dummy folder
        if ($parent == SYNC_FOLDER_TYPE_DUMMY)
            return false;

        if($id) {
            $change = array();
            $change["id"] = $id;
            $change["mod"] = $displayname;
            $change["parent"] = $parent;
            $change["flags"] = 0;
            $this->updateState("change", $change);
        }

        $stat = $this->_backend->ChangeFolder($parent, $id, $displayname, $type);

        if($stat)
            $this->updateState("change", $stat);

        return $stat["id"];
    }

    function ImportFolderDeletion($id, $parent) {
        //do nothing if it is a dummy folder
        if ($parent == SYNC_FOLDER_TYPE_DUMMY)
            return false;

        $change = array();
        $change["id"] = $id;

        $this->updateState("delete", $change);

        $this->_backend->DeleteFolder($parent, $id);

        return true;
    }
};

class ExportChangesDiff extends DiffState {
    var $_importer;
    var $_folderid;
    var $_restrict;
    var $_flags;
    var $_user;

    function ExportChangesDiff($backend, $folderid) {
        $this->_backend = $backend;
        $this->_folderid = $folderid;
    }

    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function Config(&$importer, $folderid, $restrict, $syncstate, $flags, $truncation, $bodypreference, $optionbodypreference, $mimesupport=0) {
        $this->_importer = &$importer;
        $this->_restrict = $restrict;
        $this->_syncstate = unserialize($syncstate);
        $this->_flags = $flags;
        $this->_truncation = $truncation;
		$this->_bodypreference = $bodypreference;
		$this->_optionbodypreference = $optionbodypreference;
		$this->_mimesupport = $mimesupport;

        $this->_changes = array();
        $this->_step = 0;

		debugLog("DiffBackend::Config mimesupport is: ". $this->_mimesupport);
        $cutoffdate = $this->getCutOffDate($restrict);

        if($this->_folderid) {
            // Get the changes since the last sync
            debugLog("Initializing message diff engine");

            if(!isset($this->_syncstate) || !$this->_syncstate)
                $this->_syncstate = array();

            debugLog(count($this->_syncstate) . " messages in state");

            //do nothing if it is a dummy folder
            if ($this->_folderid != SYNC_FOLDER_TYPE_DUMMY) {

                // on ping: check if backend supports alternative PING mechanism & use it
                if ($folderid === false && $this->_flags == BACKEND_DISCARD_DATA && $this->_backend->AlterPing()) {
                    $this->_changes = $this->_backend->AlterPingChanges($this->_folderid, $this->_syncstate);
                }
                else {
                    // Get our lists - syncstate (old)  and msglist (new)
                    $msglist = $this->_backend->GetMessageList($this->_folderid, $cutoffdate);
                    if($msglist === false)
                        return false;

                    $this->_changes = GetDiff($this->_syncstate, $msglist);
                }
            }

            debugLog("Found " . count($this->_changes) . " message changes");
        } else {
            debugLog("Initializing folder diff engine");

            $folderlist = $this->_backend->GetFolderList();
            if($folderlist === false)
                return false;

            if(!isset($this->_syncstate) || !$this->_syncstate)
                $this->_syncstate = array();

//	    debugLog(print_r($this->_syncstate,true));
//	    debugLog(print_r($folderlist,true));
            $this->_changes = GetDiff($this->_syncstate, $folderlist);

            debugLog("Found " . count($this->_changes) . " folder changes");
        }
    }

    function GetChangeCount() {
        return count($this->_changes);
    }

    function Synchronize() {
        $progress = array();

		debugLog("DiffBackend::Synchronize mimesupport is: ". $this->_mimesupport);
        // Get one of our stored changes and send it to the importer, store the new state if
        // it succeeds
        if($this->_folderid == false) {
            if($this->_step < count($this->_changes)) {
                $change = $this->_changes[$this->_step];

                switch($change["type"]) {
                    case "change":
                        $folder = $this->_backend->GetFolder($change["id"]);
                        $stat = $this->_backend->StatFolder($change["id"]);
                        if (!$folder || !$stat) error_log(__METHOD__."() FATAL !folder || !stat");

                        if(!$folder)
                            return;

                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportFolderChange($folder))
                            $this->updateState("change", $stat);
                        break;
                    case "delete":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportFolderDeletion($change["id"]))
                            $this->updateState("delete", $change);
                        break;
                }

                $this->_step++;

                $progress = array();
                $progress["steps"] = count($this->_changes);
                $progress["progress"] = $this->_step;

                return $progress;
            } else {
                return false;
            }
        }
        else {
            if($this->_step < count($this->_changes)) {
                $change = $this->_changes[$this->_step];

                switch($change["type"]) {
                    case "flags":
                    case "olflags":
                    case "change":
                        $truncsize = $this->getTruncSize($this->_truncation);

                        // Note: because 'parseMessage' and 'statMessage' are two seperate
                        // calls, we have a chance that the message has changed between both
                        // calls. This may cause our algorithm to 'double see' changes.

                        $stat = $this->_backend->StatMessage($this->_folderid, $change["id"]);
                        $message = $this->_backend->GetMessage($this->_folderid, $change["id"], $truncsize,(isset($this->_bodypreference) ? $this->_bodypreference : false),(isset($this->_optionbodypreference) ? $this->_optionbodypreference : false), $this->_mimesupport);

                        // copy the flag to the message
                        if (!$message || !$stat) error_log(__METHOD__."() FATAL !message || !stat");
                        $message->flags = (isset($change["flags"])) ? $change["flags"] : 0;

                        if($stat && $message) {
                            if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageChange($change["id"], $message) == true) {
                                if ($change["type"] == "change") $this->updateState("change", $stat);
                        	if ($change["type"] == "flags") $this->updateState("flags", $change);
                        	if ($change["type"] == "olflags") $this->updateState("olflags", $change);
			    }
                        }
                        break;
                    case "delete":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageDeletion($change["id"]) == true)
                            $this->updateState("delete", $change);
                        break;
/*                    case "flags":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageReadFlag($change["id"], $change["flags"]) == true)
                            $this->updateState("flags", $change);
                        break;
                    case "olflags":
                        $truncsize = $this->getTruncSize($this->_truncation);

                        // Note: because 'parseMessage' and 'statMessage' are two seperate
                        // calls, we have a chance that the message has changed between both
                        // calls. This may cause our algorithm to 'double see' changes.

                        $stat = $this->_backend->StatMessage($this->_folderid, $change["id"]);
                        $message = $this->_backend->GetMessage($this->_folderid, $change["id"], $truncsize,(isset($this->_bodypreference) ? $this->_bodypreference : false));

                        // copy the flag to the message
                        $message->flags = (isset($change["flags"])) ? $change["flags"] : 0;

                        if($stat && $message) {
                    	    if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageChange($change["id"], $message) == true)
                        	$this->updateState("olflags", $change);
                        }
                        break;
*/                    case "move":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageMove($change["id"], $change["parent"]) == true)
                            $this->updateState("move", $change);
                        break;
                }

                $this->_step++;

                $progress = array();
                $progress["steps"] = count($this->_changes);
                $progress["progress"] = $this->_step;

                return $progress;
            } else {
                return false;
            }
        }
    }

    // -----------------------------------------------------------------------

    function getCutOffDate($restrict) {
        switch($restrict) {
            case SYNC_FILTERTYPE_1DAY:
                $back = 60 * 60 * 24;
                break;
            case SYNC_FILTERTYPE_3DAYS:
                $back = 60 * 60 * 24 * 3;
                break;
            case SYNC_FILTERTYPE_1WEEK:
                $back = 60 * 60 * 24 * 7;
                break;
            case SYNC_FILTERTYPE_2WEEKS:
                $back = 60 * 60 * 24 * 14;
                break;
            case SYNC_FILTERTYPE_1MONTH:
                $back = 60 * 60 * 24 * 31;
                break;
            case SYNC_FILTERTYPE_3MONTHS:
                $back = 60 * 60 * 24 * 31 * 3;
                break;
            case SYNC_FILTERTYPE_6MONTHS:
                $back = 60 * 60 * 24 * 31 * 6;
                break;
            default:
                break;
        }

        if(isset($back)) {
            $date = time() - $back;
            return $date;
        } else
            return 0; // unlimited
    }

    function getTruncSize($truncation) {
        switch($truncation) {
            case SYNC_TRUNCATION_HEADERS:
                return 0;
            case SYNC_TRUNCATION_512B:
                return 512;
            case SYNC_TRUNCATION_1K:
                return 1024;
            case SYNC_TRUNCATION_5K:
                return 5*1024;
            case SYNC_TRUNCATION_ALL:
                return 1024*1024; // We'll limit to 1MB anyway
            default:
                return 1024; // Default to 1Kb
        }
    }

};

class BackendDiff {
    var $_user;
    var $_devid;
    var $_protocolversion;

    function Logon($username, $domain, $password) {
        return true;
    }

        // completing protocol
    function Logoff() {
        return true;
    }

    function Setup($user, $devid, $protocolversion) {
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

        return true;
    }

    function GetHierarchyImporter() {
        return new ImportHierarchyChangesDiff($this);
    }

    function GetContentsImporter($folderid) {
        return new ImportContentsChangesDiff($this, $folderid);
    }

    function GetExporter($folderid = false) {
        return new ExportChangesDiff($this, $folderid);
    }

    function GetHierarchy() {
        $folders = array();

        $fl = $this->getFolderList();
        foreach($fl as $f){
            $folders[] = $this->GetFolder($f['id']);
        }

        return $folders;
    }

    function Fetch($folderid, $id, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0) {
        return $this->GetMessage($folderid, $id, 1024*1024, $bodypreference, $optionbodypreference, $mimesupport); // Forces entire message (up to 1Mb)
    }

    function GetAttachmentData($attname) {
        return false;
    }

    function SendMail($rfc822, $smartdata=array(), $protocolversion=false) {
        return false;
    }

    function GetWasteBasket() {
        return false;
    }

    function GetMessageList($folderid, $cutoffdate) {
        return array();
    }

    function StatMessage($folderid, $id) {
        return false;
    }

    function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0) {
        return false;
    }

    function DeleteMessage($folderid, $id) {
        return false;
    }

    function SetReadFlag($folderid, $id, $flags) {
        return false;
    }

    function ChangeMessageFlag($folderid, $id, $flags) {
    	return false;
    }

    function ChangeMessage($folderid, $id, $message) {
        return false;
    }

    function MoveMessage($folderid, $id, $newfolderid) {
        return false;
    }

    function MeetingResponse($requestid, $folderid, $error, &$calendarid) {
        return false;
    }

    function getTruncSize($truncation) {
        switch($truncation) {
            case SYNC_TRUNCATION_HEADERS:
                return 0;
            case SYNC_TRUNCATION_512B:
                return 512;
            case SYNC_TRUNCATION_1K:
                return 1024;
            case SYNC_TRUNCATION_5K:
                return 5*1024;
            case SYNC_TRUNCATION_ALL:
                return 1024*1024; // We'll limit to 1MB anyway
            default:
                return 1024; // Default to 1Kb
        }
    }

    /**
     * Returns array of items which contain searched for information
     *
     * @param array $searchquery
     * @param string $searchname
     *
     * @return array
     */
    function getSearchResults($searchquery,$searchname) {
        return false;
    }

	/**
     * Checks if the sent policykey matches the latest policykey on the server
     *
	 * @param string $policykey
     * @param string $devid
     *
	 * @return status flag
     */
	function CheckPolicy($policykey, $devid) {
        global $user, $auth_pw;

        $status = SYNC_PROVISION_STATUS_SUCCESS;

        $user_policykey = $this->getPolicyKey($user, $auth_pw, $devid);

        if ($user_policykey != $policykey) {
        	$status = SYNC_PROVISION_STATUS_POLKEYMISM;
        }

        if (!$policykey) $policykey = $user_policykey;
        return $status;
	}

    /**
     * Return a policy key for given user with a given device id.
     * If there is no combination user-deviceid available, a new key
     * should be generated.
     *
     * @param string $user
     * @param string $pass
     * @param string $devid
     *
     * @return unknown
     */
    function getPolicyKey($user, $pass, $devid) {
		if($this->_loggedin === false) {
			debugLog("logon failed for user $user");
			return false;
        }
		$this->_device_filename = STATE_PATH . '/' . strtolower($devid) . '/device_info_'.$user;

		if (file_exists($this->_device_filename)) {
			$this->_device_info = unserialize(file_get_contents($this->_device_filename));
			if (isset($this->_device_info['policy_key'])) {
				return $this->_device_info['policy_key'];
			} else {
				return $this->setPolicyKey(0, $devid);
			}
		} else {
			return $this->setPolicyKey(0, $devid);
		}
        return false;
    }

    /**
     * Generate a random policy key. Right now it's a 10-digit number.
     *
     * @return unknown
     */
	function generatePolicyKey() {
//		AS14 transmit Policy Key in URI on MS Phones.
//		In the base64 encoded binary string only 4 Bytes being reserved for
//		policy key and works in signed mode... Thats why we need here the max...
//		return mt_rand(1000000000, 9999999999);
		return mt_rand(1000000000, 2147483647);
	}

    /**
     * Set a new policy key for the given device id.
     *
     * @param string $policykey
     * @param string $devid
     * @return unknown
     */
    function setPolicyKey($policykey, $devid) {
		$this->_device_filename = STATE_PATH . '/' . strtolower($devid) . '/device_info_'.$this->_user;
		// create device directory, if not yet existing
		if (!file_exists(dirname($this->_device_filename)))
		{
			mkdir(dirname($this->_device_filename),0700,true);
		}

    	if($this->_loggedin !== false) {
    		if (!$policykey)
    			$policykey = $this->generatePolicyKey();
    		$this->_device_info['policy_key'] = $policykey;
    		file_put_contents($this->_device_filename,serialize($this->_device_info));
    		return $policykey;
    	}
	    return false;
    }

    /**
     * Return a device wipe status
     *
     * @param string $user
     * @param string $pass
     * @param string $devid
     * @return int
     */
    function getDeviceRWStatus($user, $pass, $devid) {
    	return false;
    }

    /**
     * Set a new rw status for the device
     *
     * @param string $user
     * @param string $pass
     * @param string $devid
     * @param string $status
     *
     * @return boolean
     */
    function setDeviceRWStatus($user, $pass, $devid, $status) {
        return false;
    }

    function AlterPing() {
        return false;
    }

    function AlterPingChanges($folderid, &$syncstate) {
        return array();
    }
}
?>
