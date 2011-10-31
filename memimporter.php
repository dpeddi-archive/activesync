<?php
/***********************************************
* File      :   memimporter.php
* Project   :   Z-Push
* Descr     :   Classes that collect changes
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

class ImportContentsChangesMem extends ImportContentsChanges {
    var $_changes;
    var $_deletions;

    function ImportContentsChangesMem() {
        $this->_changes = array();
        $this->_deletions = array();
        $this->_md5tosrvid = array();
    }
    
    function ImportMessageChange($id, $message) {
		// SMS Initial Sync deduplication of items
		if (isset($message->messageclass) &&
			strtolower($message->messageclass) == 'ipm.note.mobile.sms') {
//			debugLog("ImportMessageChange Message: ".print_r($message,true));
			$md5msg = array('datereceived' 		=> (isset($message->datereceived) 			? strval($message->datereceived) 			: ''),
							'importance' 		=> (isset($message->importance) 			? strval($message->importance) 				: ''),
							'messageclass' 		=> (isset($message->messageclass) 			? strval($message->messageclass) 			: ''),
							'to' 				=> (isset($message->to) 					? strval($message->to)						: ''),
							'cc' 				=> (isset($message->cc) 					? strval($message->cc)						: ''),
							'from' 				=> (isset($message->from) 					? strval($message->from)					: ''),
							'internetcpid' 		=> (isset($message->internetcpid) 			? strval($message->internetcpid) 			: ''),
	//						'conversationid' 	=> (isset($appdata->conversationid) 		? bin2hex($appdata->conversationid) 	: ''),  
	//						'conversationindex'	=> (isset($appdata->conversationindex) 		? bin2hex($appdata->conversationindex)	: ''),
							'body' 				=> (isset($message->airsyncbasebody->data)	? strval($message->airsyncbasebody->data)	: ''),
			);
			$this->_md5tosrvid[md5(serialize($md5msg))] = array('serverid' 			=> $id,
																'conversationid' 	=> $message->conversationid,
																'conversationindex' => $message->conversationindex,
																);
		}
        $this->_changes[] = $id; 
        return true;
    }

    function ImportMessageDeletion($id) { 
        $this->_deletions[] = $id;
        return true;
    }

    function ImportMessageReadFlag($message) { return true; }

    function ImportMessageMove($message) { return true; }

	function isDuplicate($md5) {
		if (!isset($this->_md5tosrvid[$md5]))
			return false;
		else
			return $this->_md5tosrvid[$md5];
	}

    function isChanged($id) {
        return in_array($id, $this->_changes);
    }

    function isDeleted($id) {
        return in_array($id, $this->_deletions);
    }

};

// This simply collects all changes so that they can be retrieved later, for
// statistics gathering for example
class ImportHierarchyChangesMem extends ImportHierarchyChanges {
    var $changed;
    var $deleted;
    var $count;
    
    function ImportHierarchyChangesMem() {
        $this->changed = array();
        $this->deleted = array();
        $this->count = 0;
        
        return true;
    }
    
    function ImportFolderChange($folder) {
        array_push($this->changed, $folder);
        
        $this->count++;

        return true;
    }

    function ImportFolderDeletion($id) {
        array_push($this->deleted, $id);
        
        $this->count++;
        
        return true;
    }
};

?>