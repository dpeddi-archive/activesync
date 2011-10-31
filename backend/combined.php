<?php
/***********************************************
* File      :   combined.php
* Project   :   Z-Push
* Descr     :   This backend is for combining
*               backends.
*
* Created   :   01.03.2008
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

//the ExportHierarchyChangesCombined class is returned from GetExporter for hierarchy changes.
//it combines the hierarchy changes from all backends and prepends all folderids with the backendid

class ExportHierarchyChangesCombined{
	var $_backend;
	var $_syncstates;
	var $_exporters;
	var $_importer;
	var $_importwraps;
	function ExportHierarchyChangesCombined(&$backend) {
		debugLog('ExportHierarchyChangesCombined constructed');
		$this->_backend =& $backend;
	}
	
	function Config(&$importer, $folderid, $restrict, $syncstate, $flags, $truncation, $bodypreference, $optionbodypreference, $mimesupport=false) {
		debugLog('ExportHierarchyChangesCombined::Config(...)');
		if($folderid){
			return false;
		}
		$this->_importer =& $importer;
		$this->_syncstates = unserialize($syncstate);

		if(!is_array($this->_syncstates)){
			$this->_syncstates = array();
		}
		foreach($this->_backend->_backends as $i => $b){
			if(isset($this->_syncstates[$i])){
				$state = $this->_syncstates[$i];
			} else {
				$state = '';
			}

			if(!isset($this->_importwraps[$i])){
				$this->_importwraps[$i] = new ImportHierarchyChangesCombinedWrap($i, $this->_backend ,$importer);
			}

			$this->_exporters[$i] = $this->_backend->_backends[$i]->GetExporter();
			$this->_exporters[$i]->Config($this->_importwraps[$i], $folderid, $restrict, $state, $flags, $truncation, $bodypreference, $optionbodypreference, $mimesupport);
		}
		debugLog('ExportHierarchyChangesCombined::Config complete');
	}

	function GetChangeCount() {
		debugLog('ExportHierarchyChangesCombined::GetChangeCount()');
		$c = 0;
		foreach($this->_exporters as $i => $e){
			$c += $this->_exporters[$i]->GetChangeCount();
		}
		return $c;
	}

	function Synchronize() {
		debugLog('ExportHierarchyChangesCombined::Synchronize()');
		foreach($this->_exporters as $i => $e){
			if(!empty($this->_backend->_config['backends'][$i]['subfolder']) && !isset($this->_syncstates[$i])){
				// first sync and subfolder backend
				$f = new SyncFolder();
				$id = $i.$this->_backend->_config['delimiter'].'0';
				if (($fid = array_search($id,$this->_backend->_folders)) === false) {
				    $fid = $this->_backend->_folderid();
				    $this->_backend->_folders[$fid] = $id;
				} 
				$f->serverid = $fid;
				$f->parentid = '0';
				$f->displayname = $this->_backend->_config['backends'][$i]['subfolder'];
				$f->type = SYNC_FOLDER_TYPE_OTHER;
				$this->_importer->ImportFolderChange($f);
			}
			while(is_array($this->_exporters[$i]->Synchronize()));
		}
		file_put_contents(STATE_DIR . '/' . strtolower($this->_backend->_devid). '/combined_folders_'. $this->_backend->_user, serialize($this->_backend->_folders));
		return true;
	}

	function GetState() {
		debugLog('ExportHierarchyChangesCombined::GetState()');
		foreach($this->_exporters as $i => $e){
			$this->_syncstates[$i] = $this->_exporters[$i]->GetState();
		}
		return serialize($this->_syncstates);
	}
};

//the ImportHierarchyChangesCombined class is returned from GetHierarchyImporter.
//it forwards all hierarchy changes to the right backend

class ImportHierarchyChangesCombined{
	var $_backend;
	var $_syncstates = array();

	function ImportHierarchyChangesCombined(&$backend) {
		$this->_backend =& $backend;
	}

	function Config($state) {
		debugLog('ImportHierarchyChangesCombined::Config(...)');
		$this->_syncstates = unserialize($state);
		if (!is_array($this->_syncstates))
			$this->_syncstates = array();
	}

	function ImportFolderChange($id, $parent, $displayname, $type) {
		debugLog('ImportHierarchyChangesCombined::ImportFolderChange('.$id.', '.$parent.', '.$displayname.', '.$type.')');

		if ($parent == '0') {
			if ($id) {
				$backendid = $this->_backend->GetBackendId($id);
			} else {
				$backendid = $this->_backend->_config['rootcreatefolderbackend'];
			}
		} else {
			$backendid = $this->_backend->GetBackendId($parent);
			$parent = $this->_backend->GetBackendFolder($parent);
		}

		if (!empty($this->_backend->_config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->_backend->_config['delimiter'].'0'){
			return false; //we can not change a static subfolder
		}

		if ($id != false){
			if($backendid != $this->_backend->GetBackendId($id))
				return false;//we can not move a folder from 1 backend to an other backend
			$id = $this->_backend->GetBackendFolder($id);
		}

		$importer = $this->_backends[$backendid]->GetHierarchyImporter();

		if (isset($this->_syncstates[$backendid])) {
			$state = $this->_syncstates[$backendid];
		} else {
			$state = '';
		}

		$importer->Config($state);
		$res = $importer->ImportFolderChange($id, $parent, $displayname, $type);
		$this->_syncstates[$backendid] = $importer->GetState();
		return $backendid.$this->_backend->_config['delimiter'].$res;
	}

	function ImportFolderDeletion($id, $parent) {
		debugLog('ImportHierarchyChangesCombined::ImportFolderDeletion('.$id.', '.$parent.')');

		$backendid = $this->_backend->GetBackendId($id);
		if (!empty($this->_backend->_config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->_backend->_config['delimiter'].'0'){
			return false; //we can not change a static subfolder
		}

		$backend = $this->_backend->GetBackend($id);
		$id = $this->_backend->GetBackendFolder($id);

		if ($parent != '0')
			$parent = $this->_backend->GetBackendFolder($parent);

		$importer = $backend->GetHierarchyImporter();

		if (isset($this->_syncstates[$backendid])) {
			$state = $this->_syncstates[$backendid];
		} else {
			$state = '';
		}

		$importer->Config($state);
		$res = $importer->ImportFolderDeletion($id, $parent);
		$this->_syncstates[$backendid] = $importer->GetState();
		return $res;
	}
	function GetState(){
		return serialize($this->_syncstates);
	}
};

//the ImportHierarchyChangesCombinedWrap class wraps the importer given in ExportHierarchyChangesCombined::Config.
//it prepends the backendid to all folderids and checks foldertypes.

class ImportHierarchyChangesCombinedWrap {
	var $_ihc;
	var $_backend;
	var $_backendid;

	function ImportHierarchyChangesCombinedWrap($backendid, &$backend, &$ihc) {
		debugLog('ImportHierarchyChangesCombinedWrap::ImportHierarchyChangesCombinedWrap('.$backendid.',...)');
		$this->_backendid = $backendid;
		$this->_backend =& $backend;
		$this->_ihc = &$ihc;
	}

	function ImportFolderChange($folder) {
		$id = $this->_backendid.$this->_backend->_config['delimiter'].$folder->serverid;
		if (($fid = array_search($id,$this->_backend->_folders)) === false) {
		    $fid = $this->_backend->_folderid();
		    $this->_backend->_folders[$fid] = $id;
		} 
		$folder->serverid = $fid;
		if ($folder->parentid != '0' || !empty($this->_backend->_config['backends'][$this->_backendid]['subfolder'])) {
			$pid = $this->_backendid.$this->_backend->_config['delimiter'].$folder->parentid;
			if (($pfid = array_search($pid,$this->_backend->_folders)) === false) {
			    $pfid = $this->_backend->_folderid();
			    $this->_backend->_folders[$pfid] = $pid;
	    	} 
			$folder->parentid = $pfid;
		}
		if (isset($this->_backend->_config['folderbackend'][$folder->type]) && $this->_backend->_config['folderbackend'][$folder->type] != $this->_backendid) {
			if (in_array($folder->type, array(SYNC_FOLDER_TYPE_INBOX, SYNC_FOLDER_TYPE_DRAFTS, SYNC_FOLDER_TYPE_WASTEBASKET, SYNC_FOLDER_TYPE_SENTMAIL, SYNC_FOLDER_TYPE_OUTBOX))) {
				debugLog('converting folder type to other: '.$folder->displayname.' ('.$folder->serverid.')');
				$folder->type = SYNC_FOLDER_TYPE_OTHER;
			} else {
				debugLog('not ussing folder: '.$folder->displayname.' ('.$folder->serverid.')');
				return true;
			}
		}
		debugLog('ImportHierarchyChangesCombinedWrap::ImportFolderChange('.$folder->serverid.')');
		return $this->_ihc->ImportFolderChange($folder);
	}

	function ImportFolderDeletion($id) {
		debugLog('ImportHierarchyChangesCombinedWrap::ImportFolderDeletion('.$id.')');
		return $this->_ihc->ImportFolderDeletion($this->_backendid.$this->_delimiter.$id);
	}
};

//the ImportContentsChangesCombinedWrap class wraps the importer given in GetContentsImporter.
//it allows to check and change the folderid on ImportMessageMove.

class ImportContentsChangesCombinedWrap{
	var $_icc;
	var $_backend;
	var $_folderid;

	function ImportContentsChangesCombinedWrap($folderid, &$backend, &$icc){
		debugLog('ImportContentsChangesCombinedWrap::ImportContentsChangesCombinedWrap('.$folderid.',...)');
		$this->_folderid = $folderid;
		$this->_backend = &$backend;
		$this->_icc = &$icc;
	}

	function Config($state, $flags = 0) {
		return $this->_icc->Config($state, $flags);
	}
	function ImportMessageChange($id, $message){
#		debugLog('DB message vardump: '.var_export($message, 1));
		return $this->_icc->ImportMessageChange($id, $message);
	}
	function ImportMessageDeletion($id) {
		return $this->_icc->ImportMessageDeletion($id);
	}
	function ImportMessageReadFlag($id, $flags){
		return $this->_icc->ImportMessageReadFlag($id, $flags);
	}

	function ImportMessageFlag($id, $flags){
		return $this->_icc->ImportMessageFlag($id, $flags);
	}

	function ImportMessageMove($id, $newfolder) {
		if ($this->_backend->GetBackendId($this->_folderid) != $this->_backend->GetBackendId($newfolder)) {
			//can not move messages between backends
			return false;
		}
		return $this->_icc->ImportMessageMove($id, $this->_backend->GetBackendFolder($newfolder));
	}
	function getState(){
		return $this->_icc->getState();
	}
};

class BackendCombined {
	var $_config;
	var $_backends;
	var $_user;
	var $_devid;
	var $_loggedin=false;

	function BackendCombined(){
		$this->_config = unserialize(BACKENDCOMBINED_CONFIG);
		foreach ($this->_config['backends'] as $i => $b){
			$this->_backends[$i] = new $b['name']();
		}
		debugLog('Combined '.count($this->_backends). ' backends loaded.');
	}

	// try to logon on each backend
	function Logon($username, $domain, $password) {
		debugLog('Combined::Logon('.$username.', '.$domain.',***)');

		if (!is_array($this->_backends)) {
			return false;
		}

		foreach ($this->_backends as $i => $b) {
			$u = $username;
			$d = $domain;
			$p = $password;
			if (isset($this->_config['backends'][$i]['users'])) {
				if (!isset($this->_config['backends'][$i]['users'][$username])) {
					unset($this->_backends[$i]);
					continue;
				}
				if (isset($this->_config['backends'][$i]['users'][$username]['username']))
					$u = $this->_config['backends'][$i]['users'][$username]['username'];
				if (isset($this->_config['backends'][$i]['users'][$username]['password']))
					$p = $this->_config['backends'][$i]['users'][$username]['password'];
				if (isset($this->_config['backends'][$i]['users'][$username]['domain']))
					$d = $this->_config['backends'][$i]['users'][$username]['domain'];
			}
			if ($this->_backends[$i]->Logon($u, $d, $p) == false) {
				debugLog('Combined login failed on'. $this->_config['backends'][$i]['name']);
				return false;
			}
		}
		$this->_loggedin = true;
		debugLog('Combined login success');
		return true;
	}

	//try to setup each backend
	function Setup($user, $devid, $protocolversion){
		debugLog('Combined::Setup('.$user.', '.$devid.', '.$protocolversion.')');
		$this->_user = $user;
		$this->_devid = $devid;
		$this->_device_filename = STATE_PATH . '/' . strtolower($devid) . '/device_info_'.$user;
		debugLog("HERE ".$this->_device_filename);

		if (!is_array($this->_backends)) {
			return false;
		}

		foreach ($this->_backends as $i => $b){
			$u = $user;
			if (isset($this->_config['backends'][$i]['users']) && isset($this->_config['backends'][$i]['users'][$user]['username'])) {
					$u = $this->_config['backends'][$i]['users'][$user]['username'];
			}
			if ($this->_backends[$i]->Setup($u, $devid, $protocolversion) == false) {
				debugLog('Combined::Setup failed');
				return false;
			}
		}

		// FolderID Cache
		$dir = opendir(STATE_PATH. "/" .strtolower($this->_devid));
	    if (!$dir) {
		    debugLog("Combined Backend: created folder for device ".strtolower($this->_devid));
		    if (mkdir(STATE_PATH. "/" .strtolower($this->_devid), 0744) === false) 
			debugLog("Combined Backend: failed to create folder ".strtolower($this->_devid));
		}
		$filename = STATE_DIR . '/' . strtolower($this->_devid). '/combined_folders_'. $this->_user;
		$this->_folders = false;
		if (file_exists($filename)) {
		    if (($this->_folders = file_get_contents(STATE_DIR . '/' . strtolower($this->_devid). '/combined_folders_'. $this->_user)) !== false) {
				$this->_folders = unserialize($this->_folders);
		    } else {
	    		$this->_folders = array();
				$this->_folders['0'] = ''; // init the root...
				$this->_folders[0] = ''; // init the root...
		    }
		} else {
		    $this->_folders = array();
		    $this->_folders['0'] = ''; // init the root...
    		$this->_folders[0] = ''; // init the root...
		}
		debugLog('Combined::Setup success');
		return true;
	}

	function Logoff() {
		foreach ($this->_backends as $i => $b) {
			$this->_backends[$i]->Logoff();
		}
		return true;
	}

	// get the contents importer from the folder in a backend
	// the importer is wrapped to check foldernames in the ImportMessageMove function
	function GetContentsImporter($folderid){
		debugLog('Combined::GetContentsImporter('.$folderid.')');
		$backend = $this->GetBackend($folderid);
		if ($backend === false)
			return false;
		$importer = $backend->GetContentsImporter($this->GetBackendFolder($folderid));
		if($importer) {
			return new ImportContentsChangesCombinedWrap($folderid, $this, $importer);
		}
		return false;
	}

	//return our own hierarchy importer which send each change to the right backend
	function GetHierarchyImporter(){
		debugLog('Combined::GetHierarchyImporter()');
		return new ImportHierarchyChangesCombined($this);
	}

	//get hierarchy from all backends combined
	function GetHierarchy(){
		debugLog('Combined::GetHierarchy()');
		$ha = array();
		foreach ($this->_backends as $i => $b){
			if(!empty($this->_config['backends'][$i]['subfolder'])){
				$f = new SyncFolder();
				$id = $i.$this->_config['delimiter'].'0';
				if (($fid = array_search($id,$this->_folders)) === false) {
				    $fid = $this->_folderid();
				    $this->_folders[$fid] = $id;
				} 
				$f->serverid = $fid;
				$f->parentid = '0';
				$f->displayname = $this->_config['backends'][$i]['subfolder'];
				$f->type = SYNC_FOLDER_TYPE_OTHER;
				$ha[] = $f;
			}
			$h = $this->_backends[$i]->GetHierarchy();
			if(is_array($h)){
				foreach($h as $j => $f){
					$id = $i.$this->_config['delimiter'].$h[$j]->serverid;
					if (($fid = array_search($id,$this->_folders)) === false) {
					    $fid = $this->_folderid();
					    $this->_folders[$fid] = $id;
					} 
					$h[$j]->serverid = $fid;
					if($h[$j]->parentid != '0' || !empty($this->_config['backends'][$i]['subfolder'])){
						$h[$j]->parentid = $i.$this->_config['delimiter'].$h[$j]->parentid;
					}
					if(isset($this->_config['folderbackend'][$h[$j]->type]) && $this->_config['folderbackend'][$h[$j]->type] != $i){
						$h[$j]->type = SYNC_FOLDER_TYPE_OTHER;
					}
				}
				$ha = array_merge($ha, $h);
			}
		}
		file_put_contents(STATE_DIR . '/' . strtolower($this->_devid). '/combined_folders_'. $this->_user, serialize($this->_folders));
		return $ha;
	}

	//return exporter from right backend for contents exporter and our own exporter for hierarchy exporter
	function GetExporter($folderid = false){
		debugLog('Combined::GetExporter('.$folderid.')');
		if($folderid){
			$backend = $this->GetBackend($folderid);
			if($backend == false)
				return false;
			return $backend->GetExporter($this->GetBackendFolder($folderid));
		}
		return new ExportHierarchyChangesCombined($this);
	}

	//if the wastebasket is set to one backend, return the wastebasket of that backend
	//else return the first waste basket we can find
	function GetWasteBasket(){
		debugLog('Combined::GetWasteBasket()');
		if (isset($this->_config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET])) {
			$wb = $this->_backends[$this->_config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET]]->GetWasteBasket();
			if ($wb) {
				return $this->_config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET].$this->_config['delimiter'].$wb;
			}
			return false;
		}
		foreach ($this->_backends as $i => $b) {
			$w = $this->_backends[$i]->GetWasteBasket();
			if ($w) {
				return $i.$this->_config['delimiter'].$w;
			}
		}
		return false;
	}

	//forward to right backend
//	function ItemOperationsFetchMailbox($searchlongid,$bodypreference){
//		debugLog('Combined::ItemOperationsFetchMailbox('.$searchlongid.', '.serialize($bodypreference).')');
//		$backend = $this->GetBackend($folderid);
//		if($backend == false)
//			return false;
//		return $backend->Fetch($this->GetBackendFolder($folderid), $id);
//	}
//			    $msg = $backend->ItemOperationsFetchMailbox($value['searchlongid'], $value['bodypreference']);
//			} else if(isset($value["airsyncbasefilereference"])) {
//			    $msg = $backend->ItemOperationsGetAttachmentData($value["airsyncbasefilereference"]);

	//forward to right backend
	function Fetch($folderid, $id, $bodypreference = false, $optionbodypreference = false, $mimesupport=0){
		debugLog('Combined::Fetch('.$folderid.', '.$id.', '.serialize($bodypreference).')');
		$backend = $this->GetBackend($folderid);
		if ($backend == false)
			return false;
		return $backend->Fetch($this->GetBackendFolder($folderid), $id, $bodypreference, $optionbodypreference, $mimesupport);
	}

	//there is no way to tell which backend the attachment is from, so we try them all
	function GetAttachmentData($attname){
		debugLog('Combined::GetAttachmentData('.$attname.')');
		foreach ($this->_backends as $i => $b){
			if($this->_backends[$i]->GetAttachmentData($attname) == true){
				return true;
			}
		}
		return false;
	}

	function ItemOperationsGetAttachmentData($attname){
		debugLog('Combined::ItemOperationsGetAttachmentData('.$attname.')');
		foreach ($this->_backends as $i => $b){
			if($val = $this->_backends[$i]->ItemOperationsGetAttachmentData($attname)){
				if (isset($val->_data) && $val->_data!="") return $val;
			}
		}
		return false;
	}
	
	//send mail with the first backend returning true
//	function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
    function SendMail($rfc822, $smartdata=array(), $protocolversion = false) {
		$smartdata['folderid'] = $this->GetBackendFolder($smartdata['folderid']);
		foreach ($this->_backends as $i => $b){
//			if($this->_backends[$i]->SendMail($rfc822, $forward, $reply, $parent) == true){
			if($this->_backends[$i]->SendMail($rfc822, $smartdata, $protocolversion) == true){
				return true;
			}
		}
		return false;
	}

	function MeetingResponse($requestid, $folderid, $error, &$calendarid) {
		$backend = $this->GetBackend($folderid);
		if($backend === false)
			return false;
		return $backend->MeetingResponse($requestid, $this->GetBackendFolder($folderid), $error, $calendarid);
	}

	function GetBackend($folderid){
		$folderid = $this->_folders[$folderid];
		$pos = strpos($folderid, $this->_config['delimiter']);
		if ($pos === false)
			return false;
		$id = substr($folderid, 0,$pos);
		debugLog('GetBackend=>: ID: ' . $id, true);
		if (!isset($this->_backends[$id])) {
			debugLog('Backend not found!');
			return false;
		} else {
			debugLog('Backend Found!');
		}
		return $this->_backends[$id];
	}

	function GetBackendFolder($folderid){
		$folderid = $this->_folders[$folderid];
		$pos = strpos($folderid, $this->_config['delimiter']);
		if ($pos === false)
			return false;
		return substr($folderid,$pos + strlen($this->_config['delimiter']));
	}

	function GetBackendId($folderid){
		$folderid = $this->_folders[$folderid];
		$pos = strpos($folderid, $this->_config['delimiter']);
		if ($pos === false)
			return false;
		return substr($folderid,0,$pos);
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

    // START ADDED dw2412 Settings Support
    function setSettings($request,$devid) {
		if (isset($request["oof"])) {
		    if ($request["oof"]["oofstate"] == 1) {
				// in case oof should be switched on do it here
				// store somehow your oofmessage in case your system supports. 
				// response["oof"]["status"] = true per default and should be false in case 
				// the oof message could not be set
				$response["oof"]["status"] = true; 
		    } else {
				// in case oof should be switched off do it here
				$response["oof"]["status"] = true; 
	    	}
		}
		if (isset($request["deviceinformation"])) {
			// in case you'd like to store device informations do it here. 
    	    $response["deviceinformation"]["status"] = true;
		}
		if (isset($request["devicepassword"])) {
		    // in case you'd like to store device informations do it here. 
    	    $response["devicepassword"]["status"] = true;
		}

		return $response;
    }

    function getSettings($request,$devid) {
		if (isset($request["userinformation"])) {
		    $response["userinformation"]["status"] = true;
	    	$response["userinformation"]["emailaddresses"][] = $userdetails["emailaddress"];
		}
		if (isset($request["oof"])) {
	    	if ($props != false) {
				$response["oof"]["status"] 	= 1;
				// return oof messsage and where it should apply here
				$response["oof"]["oofstate"]	= 0;

				$oofmsg["appliesto"]		= SYNC_SETTINGS_APPLIESTOINTERNAL;
				$oofmsg["replymessage"] 	= w2u("");
				$oofmsg["enabled"]		= 0;
				$oofmsg["bodytype"] 		= $request["oof"]["bodytype"];

		        $response["oof"]["oofmsgs"][]	= $oofmsg;
			    // $this->settings["outofoffice"]["subject"] = windows1252_to_utf8(isset($props[PR_EC_OUTOFOFFICE_SUBJECT]) ? $props[PR_EC_OUTOFOFFICE_SUBJECT] : "");
		    } else {
				$response["oof"]["status"] 	= 0;
	 	    }
		}
		return $response;
    }
    // END ADDED dw2412 Settings Support

    function _folderid() {
/*		ksort($this->_folders);
		end($this->_folders);
		if (key($this->_folders)+1 == 1) {
			return sprintf("1%09d",key($this->_folders)+1);
		}
		return key($this->_folders)+1;
*/
        return sprintf( '%04x%04x%04x%04x%04x%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }
};

?>
