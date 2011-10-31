<?php
/***********************************************
* File      :   request.php
* Project   :   Z-Push
* Descr     :   This file contains the actual
*               request handling routines.
*               The request handlers are optimised
*               so that as little as possible
*               data is kept-in-memory, and all
*               output data is directly streamed
*               to the client, while also streaming
*               input data from the client.
*
* Created   :   01.10.2007
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once("proto.php");
include_once("wbxml.php");
include_once("statemachine.php");
include_once("backend/backend.php");
include_once("memimporter.php");
include_once("streamimporter.php");
include_once("zpushdtd.php");
include_once("zpushdefs.php");
include_once("include/utils.php");

function GetObjectClassFromFolderClass($folderclass)
{
    $classes = array ( "Email" => "syncmail", "Contacts" => "synccontact", "Calendar" => "syncappointment", "Tasks" => "synctask", "Notes" => "syncnote", "SMS" => "syncsms");

    return $classes[$folderclass];
}

function HandleMoveItems($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
	global $user;

    $statemachine = new StateMachine($devid,$user);
//    $SyncCache = unserialize($statemachine->getSyncCache());

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MOVE_MOVES))
        return false;

    $moves = array();
    while($decoder->getElementStartTag(SYNC_MOVE_MOVE)) {
        $move = array();
        if($decoder->getElementStartTag(SYNC_MOVE_SRCMSGID)) {
            $move["srcmsgid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_SRCFLDID)) {
            $move["srcfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_DSTFLDID)) {
            $move["dstfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        array_push($moves, $move);

        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_MOVE_MOVES);

    foreach($moves as $move) {
        $encoder->startTag(SYNC_MOVE_RESPONSE);
        $encoder->startTag(SYNC_MOVE_SRCMSGID);
        $encoder->content($move["srcmsgid"]);
        $encoder->endTag();

        $importer = $backend->GetContentsImporter($move["srcfldid"]);
        $result = $importer->ImportMessageMove($move["srcmsgid"], $move["dstfldid"]);
        // We discard the importer state for now.

        $encoder->startTag(SYNC_MOVE_STATUS);
        $encoder->content($result ? 3 : 1);
        $encoder->endTag();

        $encoder->startTag(SYNC_MOVE_DSTMSGID);
        $encoder->content(is_string($result)?$result:$move["srcmsgid"]);
        $encoder->endTag();
        $encoder->endTag();
    }

    $encoder->endTag();

    return true;
}

function HandleNotify($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_NOTIFY))
        return false;

    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_DEVICEINFO))
        return false;

    if(!$decoder->getElementEndTag())
        return false;

    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_AIRNOTIFY_NOTIFY);
    {
        $encoder->startTag(SYNC_AIRNOTIFY_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_AIRNOTIFY_VALIDCARRIERPROFILES);
        $encoder->endTag();
    }

    $encoder->endTag();

    return true;

}

// Handle GetHierarchy method - simply returns current hierarchy of all folders
function HandleGetHierarchy($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $output;

    // Input is ignored, no data is sent by the PIM
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $folders = $backend->GetHierarchy();

    if(!$folders)
        return false;

    // save folder-ids for fourther syncing
    _saveFolderData($devid, $folders);

    $encoder->StartWBXML();
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERS);

    foreach ($folders as $folder) {
        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDER);
        $folder->encode($encoder);
        $encoder->endTag();
    }

    $encoder->endTag();
    return true;
}

function _ErrorHandleFolderSync($errorcode) {
    global $zpushdtd;
    global $output;
    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->StartWBXML();
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
    $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
    $encoder->content($errorcode);
    $encoder->endTag();
    $encoder->endTag();
}
// Handles a 'FolderSync' method - receives folder updates, and sends reply with
// folder changes on the server
function HandleFolderSync($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    global $useragent;
	global $user;

    // Maps serverid -> clientid for items that are received from the PIM
    $map = array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    // Parse input

    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC))
        return false;

    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;

    $synckey = $decoder->getElementContent();

    if(!$decoder->getElementEndTag())
        return false;

    // First, get the syncstate that is associated with this synckey
    $statemachine = new StateMachine($devid,$user);

    // The state machine will discard any sync states before this one, as they are no
    // longer required
    $syncstate = $statemachine->getSyncState($synckey);
    // Get Foldercache
    $SyncCache = unserialize($statemachine->getSyncCache());
    if (isset($SyncCache['folders']) &&
		is_array($SyncCache['folders'])) {
		foreach ($SyncCache['folders'] as $key=>$value) {
	    	if (!isset($value['class'])) {
				$statemachine->deleteSyncCache();
				_ErrorHandleFolderSync("9");
	    		return true;
	    	}
	   		$exporter = $backend->GetExporter($key);
	    	if (isset($exporter->exporter) && $exporter->exporter === false) unset($SyncCache['folders'][$key]);
		}
    }

    // additional information about already seen folders
    if ($synckey != "0")
		$seenfolders = $statemachine->getSyncState("s".$synckey);

    // if we have any error with one of the requests bail out here!
    if (($synckey != "0" && 
	 	is_numeric($seenfolders) &&
	 	$seenfolders<0) ||
		(is_numeric($syncstate) &&
	 	$syncstate<0)) { // if we get a numeric syncstate back it means we have an error...
		debugLog("GetSyncState ERROR (Seenfolders: ".abs($seenfolders).", Syncstate: ".abs($syncstate).")");
		if ($seenfolders < 0) $status = abs($seenfolders);
		if ($syncstate < 0) $status = abs($syncstate);
		// Output our WBXML reply now
		_ErrorHandleFolderSync(abs($status));
        return true;
    } else {
		$foldercache = unserialize($statemachine->getSyncCache());
		// Clear the foldercache in SyncCache in case the SyncKey = 0
		if ($synckey == "0") {
	    // $statemachine->deleteSyncCache();
	    	unset($foldercache['folders']);
	    	debugLog("Clean the folders in foldercache");
		}
		debugLog("GetSyncState OK");
    }

    if ($synckey == "0" && 
	 	(!isset($seenfolders) ||
	 	(is_numeric($seenfolders) &&
	 	$seenfolders<0))) $seenfolders = false;
    $seenfolders = unserialize($seenfolders);
    if (!$seenfolders) $seenfolders = array();

   	if (!$foldercache ||
		!is_array($foldercache)) $foldercache = array();

    // lets clean the old state files away...
    if ($synckey != "0") {
		$statemachine->cleanOldSyncState("s".$synckey);
		if (($delstatus = $statemachine->cleanOldSyncState($synckey)) !== true) {
		    _ErrorHandleFolderSync(abs($delstatus));
    	    return true;
        }
    };

    // We will be saving the sync state under 'newsynckey'
    $newsynckey = $statemachine->getNewSyncKey($synckey);
    $changes = false;

    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_CHANGES)) {
        // Ignore <Count> if present
        if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_COUNT)) {
            $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        // Process the changes (either <Add>, <Modify>, or <Remove>)
        $element = $decoder->getElement();

        if($element[EN_TYPE] != EN_TYPE_STARTTAG)
            return false;

        while(1) {
            $folder = new SyncFolder();
            if(!$folder->decode($decoder))
                break;

            // Configure importer with last state
            $importer = $backend->GetHierarchyImporter();
            $importer->Config($syncstate);

            switch($element[EN_TAG]) {
                case SYNC_ADD:
                case SYNC_MODIFY:
                    $serverid = $importer->ImportFolderChange($folder);
					$statemachine->updateSyncCacheFolder($foldercache, $serverid, $folder->parentid, $folder->displayname, $folder->type);

                    // add folder to the serverflags
                    $seenfolders[] = $serverid;
                    $changes = true;
                    break;
                case SYNC_REMOVE:
                    $serverid = $importer->ImportFolderDeletion($folder);
                    // remove folder from the folderflags array
                    $changes = true;
                    if (($sid = array_search($serverid, $seenfolders)) !== false) {
                        unset($seenfolders[$sid]);
                        $seenfolders = array_values($seenfolders);    
                    }
		 			$statemachine->deleteSyncCacheFolder($foldercache,$serverid);
                    break;
            }

            if($serverid)
                $map[$serverid] = $folder->clientid;
        }

        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    // We have processed incoming foldersync requests, now send the PIM
    // our changes

    // The MemImporter caches all imports in-memory, so we can send a change count
    // before sending the actual data. As the amount of data done in this operation
    // is rather low, this is not memory problem. Note that this is not done when
    // sync'ing messages - we let the exporter write directly to WBXML.
    $importer = new ImportHierarchyChangesMem($encoder);

    // Request changes from backend, they will be sent to the MemImporter passed as the first
    // argument, which stores them in $importer. Returns the new sync state for this exporter.
    $exporter = $backend->GetExporter();

    $exporter->Config($importer, false, false, $syncstate, 0, 0, false, false);

    while(is_array($exporter->Synchronize()));

    // Output our WBXML reply now
    $encoder->StartWBXML();

    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
    {
        $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
        $encoder->content((($changes || $importer->count > 0)?$newsynckey:$synckey));
		$foldercache['hierarchy']['synckey'] = (($changes || $importer->count > 0)?$newsynckey:$synckey);
        $encoder->endTag();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_CHANGES);
        {
	    	// remove unnecessary updates where details between cache and real folder are equal
    	    if(count($importer->changed) > 0) {
                foreach($importer->changed as $key=>$folder) {
		    		if (isset($folder->serverid) && in_array($folder->serverid, $seenfolders) &&
						isset($foldercache['folders'][$folder->serverid]) &&
						$foldercache['folders'][$folder->serverid]['parentid'] == $folder->parentid &&
						$foldercache['folders'][$folder->serverid]['displayname'] == $folder->displayname &&
						$foldercache['folders'][$folder->serverid]['type'] == $folder->type) {
		                debugLog("Ignoring ".$folder->serverid." from importer->changed because it is folder update requests!");
    					unset($importer->changed[$key]);
    					$importer->count--;
		    		}
        		}
        	}
	    	// remove unnecessary deletes where folders never got sent to the device
    	    if(count($importer->deleted) > 0) {
                foreach($importer->deleted as $key=>$folder) {
                    if (($sid = array_search($folder, $seenfolders)) === false) {
                		debugLog("Removing $folder from importer->deleted because sid $sid (not in seenfolders)!");
    					unset($importer->deleted[$key]);
    					$importer->count--;
    		    	}
    			}
    	    }
            $encoder->startTag(SYNC_FOLDERHIERARCHY_COUNT);
    	    $encoder->content($importer->count);
            $encoder->endTag();

	    	if(count($importer->changed) > 0) {
				foreach($importer->changed as $folder) {
            		if (isset($folder->serverid) && in_array($folder->serverid, $seenfolders)){
                    	$encoder->startTag(SYNC_FOLDERHIERARCHY_UPDATE);
            		} else {
                    	$seenfolders[] = $folder->serverid;
                    	$encoder->startTag(SYNC_FOLDERHIERARCHY_ADD);
            		}
					$statemachine->updateSyncCacheFolder($foldercache, $folder->serverid, $folder->parentid, $folder->displayname, $folder->type);
                	$folder->encode($encoder);
                	$encoder->endTag();
		    	}
			}
            if(count($importer->deleted) > 0) {
                foreach($importer->deleted as $folder) {
                    if (($sid = array_search($folder, $seenfolders)) !== false) {
                    	$encoder->startTag(SYNC_FOLDERHIERARCHY_REMOVE);
                        $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                        $encoder->content($folder);
                        $encoder->endTag();
                    	$encoder->endTag();

                    // remove folder from the folderflags array
                    	unset($seenfolders[$sid]);
						$statemachine->deleteSyncCacheFolder($foldercache,$folder);
                        $seenfolders = array_values($seenfolders);    
                    } else {
                		debugLog("Don't send $folder because sid $sid (not in seenfolders)!");
                    }
                }
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the sync state for the next time
    $syncstate = $exporter->GetState();
    $statemachine->setSyncState($newsynckey, $syncstate);
    $statemachine->setSyncState("s".$newsynckey, serialize($seenfolders));

    // Remove collections from foldercache for that no folder exists
    if (isset($foldercache['collections']))
	foreach ($foldercache['collections'] as $key => $value) {
	    if (!isset($foldercache['folders'][$key])) unset($foldercache['collections'][$key]);
	}
    $statemachine->setSyncCache(serialize($foldercache));

    return true;
}

function _HandleSyncError($errorcode, $limit = false) {
    global $zpushdtd;
    global $output;

    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->StartWBXML();
    $encoder->startTag(SYNC_SYNCHRONIZE);
    $encoder->startTag(SYNC_STATUS);
    $encoder->content($errorcode);
    $encoder->endTag();
    if ($limit !== false) {
		$encoder->startTag(SYNC_LIMIT);
		$encoder->content($limit);
		$encoder->endTag();
    }
    $encoder->endTag();
}

function HandleSync($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;
    global $user, $auth_pw;
	global $sessionstarttime;

    // Contains all containers requested
    $collections = array();

    // Init WBXML decoder
    $decoder = new WBXMLDecoder($input, $zpushdtd);

    // Init state machine
    $statemachine = new StateMachine($devid,$user);

    // Start decode
    $shortsyncreq = false;
    $dataimported = false;
    $dataavailable = false;
    $partial = false;
    $maxcacheage = 960; // 15 Minutes + 1 to store it long enough for Device being connected to ActiveSync PC.

	$SyncStatus = SYNC_STATUS_SUCCESS; // AS14 over all SyncStatus

    if(!$decoder->getElementStartTag(SYNC_SYNCHRONIZE)) {
		if ($protocolversion >= 12.1) {
	    	if (!($SyncCache = unserialize($statemachine->getSyncCache())) ||
				!isset($SyncCache['collections'])) {
				_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
				debugLog("HandleSync: Empty Sync request and no SyncCache or SyncCache without collections. ".
						"(SyncCache[lastuntil]+".$maxcacheage."=".($SyncCache['lastuntil']+$maxcacheage).", ".
						"Time now".(time()).", ".
						"SyncCache[collections]=".(isset($SyncCache['collections']) ? "Yes" : "No" ).", ".
						"SyncCache array=".(is_array($SyncCache) ? "Yes" : "No" ).") ".
						"(STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
				return true;
		    } else {
				if (sizeof($SyncCache['confirmed_synckeys']) > 0) {
					debugLog("HandleSync: We have unconfirmed sync keys but during short request. Enforce full Sync Request (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
					_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
					return true;
		 		}
				$shortsyncreq = true;
				$SyncCache['timestamp'] = time();
				$statemachine->setSyncCache(serialize($SyncCache));
				debugLog("HandleSync: Empty Sync request and taken info from SyncCache.");
				$collections = array();
				foreach ($SyncCache['collections'] as $key=>$value) {
					$collection = $value;
					$collection['collectionid'] = $key;
					if (isset($collection['synckey'])) {
        				$collection['onlyoptionbodypreference'] = $protocolversion >= 14.0 && (!isset($collection["BodyPreference"][1]) && 
																							   !isset($collection["BodyPreference"][2]) && 
																							   !isset($collection["BodyPreference"][3]) && 
																							   !isset($collection["BodyPreference"][4]));
						$collection['syncstate'] = $statemachine->getSyncState($collection['synckey']);
						if (isset($collection['optionfoldertype'])) 
							$collection[$collection['optionfoldertype'].'syncstate'] = $statemachine->getSyncState($collection['optionfoldertype'].$collection['synckey']);
						if ($collection['synckey'] == "0") {
							$msginfos[$key] = array();
						} else {
							$msginfos[$key] = unserialize($statemachine->getSyncState("mi".$collection['synckey']));
						}
						array_push($collections,$collection);
					}
				}
				if (count($collections) == 0) {
					debugLog("HandleSync: Don't have any collections. Enforce full request. (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
					_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
					return true;
				}
			}
		} else {
			_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
			debugLog("HandleSync: Empty Sync request and protocolversion < 12.1 (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
			return true;
		}
	} else {
		if (!isset($SyncCache)) 
			$SyncCache = unserialize($statemachine->getSyncCache());
		// Just to update the timestamp...
		$SyncCache['timestamp'] = time();

		// Check if time of last sync is too long ago (but only in case we don't expect a full request!)
		$statemachine->setSyncCache(serialize($SyncCache));
		$SyncCache['wait'] = false;
    	$SyncCache['hbinterval'] = false;

		while(($synctag = ($decoder->getElementStartTag(SYNC_MAXITEMS) ? SYNC_MAXITEMS : 
						  ($decoder->getElementStartTag(SYNC_FOLDERS) ? SYNC_FOLDERS :
						  ($decoder->getElementStartTag(SYNC_PARTIAL) ? SYNC_PARTIAL :
						  ($decoder->getElementStartTag(SYNC_WAIT) ? SYNC_WAIT :
						  ($decoder->getElementStartTag(SYNC_HEARTBEATINTERVAL) ? SYNC_HEARTBEATINTERVAL :
						  -1)))))) != -1 ) {
			switch($synctag) {
				case SYNC_HEARTBEATINTERVAL :
			        if ($SyncCache['hbinterval'] = $decoder->getElementContent()) {
    					$decoder->getElementEndTag();
		    	    }
				    debugLog('HandleSync: Got Heartbeat Interval Sync ('.$SyncCache['hbinterval'].' Seconds)');
					if ($SyncCache['hbinterval'] > (REAL_SCRIPT_TIMEOUT-600)) {
				    	_HandleSyncError(SYNC_STATUS_INVALID_WAIT_HEARTBEATINTERVAL,(REAL_SCRIPT_TIMEOUT-600));
				   		debugLog('HandleSync: HeartbeatInterval larger than '.(REAL_SCRIPT_TIMEOUT-600).' Seconds. This violates the protocol spec. (STATUS = ".SYNC_STATUS_INVALID_WAIT_HEARTBEATINTERVAL.", LIMIT = '.(REAL_SCRIPT_TIMEOUT-600).')');
					    return true;
					}
			    	break;
				case SYNC_WAIT :
		    	    if ($SyncCache['wait'] = $decoder->getElementContent()) {
						$decoder->getElementEndTag();
		    	    }
			    	debugLog('HandleSync: Got Wait Sync ('.$SyncCache['wait'].' Minutes)');
					if ($SyncCache['wait'] > ((REAL_SCRIPT_TIMEOUT-600)/60)) {
					    _HandleSyncError(SYNC_STATUS_INVALID_WAIT_HEARTBEATINTERVAL,((REAL_SCRIPT_TIMEOUT-600)/60));
					    debugLog('HandleSync: Wait larger than '.((REAL_SCRIPT_TIMEOUT-600)/60).' Minutes. This violates the protocol spec. (STATUS = ".SYNC_STATUS_INVALID_WAIT_HEARTBEATINTERVAL.", LIMIT = '.((REAL_SCRIPT_TIMEOUT-600)/60).')');
					    return true;
					}
			    	break;
				case SYNC_PARTIAL :
			    	if($decoder->getElementContent(SYNC_PARTIAL))
    					$decoder->getElementEndTag();
					$partial = true;
					break;
				case SYNC_MAXITEMS :
//					_HandleSyncError("12");
//					return true;
// Sending Max Items outside a collection is invalid according to specs...
			    	$default_maxitems = $decoder->getElementContent();
			   		if(!$decoder->getElementEndTag())
						return false; 
					break;
				case SYNC_FOLDERS :
		   		    $dataimported = false;

				    while($decoder->getElementStartTag(SYNC_FOLDER)) {
		   				$collection = array();
						// Intializing the collection 
		           		$collection['clientids'] = array();
			           	$collection['fetchids'] = array();
						$msginfo = array();
						// set default truncation value
		        		$collection['truncation'] = SYNC_TRUNCATION_ALL;
				        // set default conflict behavior from config if the device doesn't send a conflict resolution parameter
						$collection['conflict'] = SYNC_CONFLICT_DEFAULT;
						$collection['onlyoptionbodypreference'] = false;

			    		while (($foldertag = ($decoder->getElementStartTag(SYNC_FOLDERTYPE)  		? SYNC_FOLDERTYPE		:
			    							 ($decoder->getElementStartTag(SYNC_SYNCKEY)  			? SYNC_SYNCKEY 			:
			 								 ($decoder->getElementStartTag(SYNC_FOLDERID)	  		? SYNC_FOLDERID 		:
			 								 ($decoder->getElementStartTag(SYNC_MAXITEMS)	  		? SYNC_MAXITEMS 		:
			 								 ($decoder->getElementStartTag(SYNC_SUPPORTED)	  		? SYNC_SUPPORTED	 	:
			 								 ($decoder->getElementStartTag(SYNC_CONVERSATIONMODE)	? SYNC_CONVERSATIONMODE :
		 	 								 ($decoder->getElementStartTag(SYNC_DELETESASMOVES)		? SYNC_DELETESASMOVES 	:
			 								 ($decoder->getElementStartTag(SYNC_GETCHANGES)			? SYNC_GETCHANGES 		:
			 								 ($decoder->getElementStartTag(SYNC_OPTIONS)			? SYNC_OPTIONS	 		:
			 								 ($decoder->getElementStartTag(SYNC_PERFORM)			? SYNC_PERFORM	 		:
			 								 -1))))))))))) != -1) {
				    	    switch ($foldertag) {
						    	case SYNC_SYNCKEY :
						    		$collection["synckey"] = $decoder->getElementContent();
							        if(!$decoder->getElementEndTag())
							            return false;
							        // Get our sync state for this collection
					    	    	$collection["syncstate"] = $statemachine->getSyncState($collection["synckey"]);
									if($collection['synckey'] != "0") {
										$msginfo = unserialize($statemachine->getSyncState("mi".$collection['synckey']));
									}
									if (($delstatus = $statemachine->cleanOldSyncState($collection["synckey"])) !== true) {
										_HandleSyncError(abs($delstatus));
									    return true;
									};
									$statemachine->cleanOldSyncState("mi".$collection["synckey"]);
									if (is_numeric($collection['syncstate']) && 
									   	$collection['syncstate'] < 0 && strlen($collection['syncstate']) < 8) {
							    	    debugLog("HandleSync: GetSyncState - Got an error in HandleSync ( STATUS = ".SYNC_STATUS_INVALID_SYNCKEY.")");
										_HandleSyncError(SYNC_STATUS_INVALID_SYNCKEY);
									    return false;
									}
									// Reset the msginfos for the collectionid if set and synckey is 0
									if ($collection['synckey'] == '0' && 
										isset($msginfo)) {
										debugLog("HandleSync: SyncKey 0 detected and msginfos contains information for the collection - resetting msginfos");
										unset($msginfo);
									}
							        break;
				    			case SYNC_FOLDERID :
									$collection["collectionid"] = $decoder->getElementContent();
						    	    if(!$decoder->getElementEndTag())
							            return false;
									if ($collection['onlyoptionbodypreference'] == false &&
										isset($SyncCache['collections'][$collection["collectionid"]]["BodyPreference"])) 
										$collection['onlyoptionbodypreference'] = $protocolversion >= 14.0 && ( !isset($SyncCache['collections'][$collection["collectionid"]]["BodyPreference"][1]) && 
		       																	 			   					!isset($SyncCache['collections'][$collection["collectionid"]]["BodyPreference"][2]) && 
		       																	 			   					!isset($SyncCache['collections'][$collection["collectionid"]]["BodyPreference"][3]) && 
		       																	 			   					!isset($SyncCache['collections'][$collection["collectionid"]]["BodyPreference"][4]) );
							        break;
						    	case SYNC_FOLDERTYPE : 
									$collection["class"] = $decoder->getElementContent();
									debugLog("HandleSync: Sync folder:{$collection["class"]}");
					        		if(!$decoder->getElementEndTag())
						            	return false;
							        break;
						    	case SYNC_MAXITEMS :  
									$collection["maxitems"] = $decoder->getElementContent();
							        if(!$decoder->getElementEndTag())
						        		return false;
						    	    break;
						    	case SYNC_CONVERSATIONMODE :  
									if(($collection["conversationmode"] = $decoder->getElementContent()) !== false) {
									    if(!$decoder->getElementEndTag())
								    	   	return false;
								    } else {
							  		    $collection["conversationmode"] = true;
								    }
								   	break;
			    			    case SYNC_SUPPORTED : 
			    			        while(1) {
				            			$el = $decoder->getElement();
				            		    if($el[EN_TYPE] == EN_TYPE_ENDTAG)
			    		            	   	break;
			        				}
				        			break;
				    		    case SYNC_DELETESASMOVES : 
				    		    	if (($collection["deletesasmoves"] = $decoder->getElementContent()) !== false) {
					    			    if(!$decoder->getElementEndTag()) {
			        	    				return false;
			            			    };
									} else {
				            		    $collection["deletesasmoves"] = true;
									}
									break;
			    			    case SYNC_GETCHANGES : 
				        			if (($collection["getchanges"] = $decoder->getElementContent()) !== false) {
			    		   			    if(!$decoder->getElementEndTag()) {
				            				return false;
				           			    };
									} else {
					    	  		    $collection["getchanges"] = true;
									}
									break;
					    		case SYNC_OPTIONS :
							        while(($syncoptionstag = ($decoder->getElementStartTag(SYNC_FOLDERTYPE) ? SYNC_FOLDERTYPE :
							        						 ($decoder->getElementStartTag(SYNC_FILTERTYPE) ? SYNC_FILTERTYPE :
					    		    						 ($decoder->getElementStartTag(SYNC_TRUNCATION) ? SYNC_TRUNCATION :
					        								 ($decoder->getElementStartTag(SYNC_RTFTRUNCATION) ? SYNC_RTFTRUNCATION :
					        								 ($decoder->getElementStartTag(SYNC_MIMESUPPORT) ? SYNC_MIMESUPPORT :
							        						 ($decoder->getElementStartTag(SYNC_MIMETRUNCATION) ? SYNC_MIMETRUNCATION :
							        						 ($decoder->getElementStartTag(SYNC_CONFLICT) ? SYNC_CONFLICT :
					    		    						 ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE) ? SYNC_AIRSYNCBASE_BODYPREFERENCE :
					        								 -1))))))))) != -1) {
								    	// dw2412 in as14 this is used to sent SMS type messages
								    	switch ($syncoptionstag) {
								    		case SYNC_FOLDERTYPE :
						    	       	   	    $collection['optionfoldertype'] = $decoder->getElementContent();
		    		    			       	    if(!$decoder->getElementEndTag())
		    		    	    		   	    	return false;
												// In case there is no optionfoldertype set in our cache, remove all old 
												// optionfoldertype sync keys in case any exist
												if (!isset($SyncCache['collections'][$collection['collectionid']]['optionfoldertype'])) {
													$statemachine->removeSyncState($collection['optionfoldertype'].$collection["synckey"]);
												}
												$collection[$collection['optionfoldertype'].'syncstate'] = $statemachine->getSyncState($collection['optionfoldertype'].$collection['synckey']);
												if (($delstatus = $statemachine->cleanOldSyncState($collection['optionfoldertype'].$collection["synckey"])) !== true) {
													_HandleSyncError(abs($delstatus));
												   	return true;
												};
				    		    	       	    break;
							            	case SYNC_FILTERTYPE :
								           	    if (isset($collection['optionfoldertype'])) 
				    	    		       			$collection[$collection['optionfoldertype']]["filtertype"] = $decoder->getElementContent();
					    				    	else
				            	   					$collection["filtertype"] = $decoder->getElementContent();
					            	    		if(!$decoder->getElementEndTag())
							        	           	return false;
							            		break;
							    	        case SYNC_TRUNCATION :
							        	  	    if (isset($collection['optionfoldertype'])) 
						    	       				$collection[$collection['optionfoldertype']]["truncation"] = $decoder->getElementContent();
									    	    else
								            		$collection["truncation"] = $decoder->getElementContent();
							    	      	    if(!$decoder->getElementEndTag())
							        	           	return false;
					    		   				break;
						                	case SYNC_RTFTRUNCATION :
							           	    	if (isset($collection['optionfoldertype'])) 
						   		        			$collection[$collection['optionfoldertype']]["rtftruncation"] = $decoder->getElementContent();
							    			    else
						    	    	       		$collection["rtftruncation"] = $decoder->getElementContent();
				        	    	    	    if(!$decoder->getElementEndTag())
				            	    	    	   	return false;
					               				break;
						               		case SYNC_MIMESUPPORT :
					    		        	    if (isset($collection['optionfoldertype'])) 
				    	    		       			$collection[$collection['optionfoldertype']]["mimesupport"] = $decoder->getElementContent();
					    				    	else
				            	   					$collection["mimesupport"] = $decoder->getElementContent();
					            	       		if(!$decoder->getElementEndTag())
						            	           	return false;
					    	           			break;
						               		case SYNC_MIMETRUNCATION :
					    		        	    if (isset($collection['optionfoldertype'])) 
						    	           			$collection[$collection['optionfoldertype']]["mimetruncation"] = $decoder->getElementContent();
												else
				    	    	    	   			$collection["mimetruncation"] = $decoder->getElementContent();
				            		    	   	if(!$decoder->getElementEndTag())
				        	        	    	   	return false;
												break;
								            case SYNC_CONFLICT :
					    		        	    if (isset($collection['optionfoldertype'])) 
				        		 	      			$collection[$collection['optionfoldertype']]["conflict"] = $decoder->getElementContent();
						    				    else
				   	            					$collection["conflict"] = $decoder->getElementContent();
					               	    		if(!$decoder->getElementEndTag())
						                       		return false;
						                       	break;
											// START ADDED dw2412 V12.0 Sync Support
											case SYNC_AIRSYNCBASE_BODYPREFERENCE :
										        if (!isset($bodypreference)) $bodypreference=array();
						       		    	    while(($bodypreferencefield = ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE) ? SYNC_AIRSYNCBASE_TYPE : 
						       		    	    							  ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE) ? SYNC_AIRSYNCBASE_TRUNCATIONSIZE :
						       		    	    							  ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW) ? SYNC_AIRSYNCBASE_PREVIEW :
						       		    	    							  ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE) ? SYNC_AIRSYNCBASE_ALLORNONE :
						       		    	    							  -1))))) != -1) {
				    		       					switch($bodypreferencefield) {
				    		       						case SYNC_AIRSYNCBASE_TYPE :
					        		    		    		$bodypreference["Type"] = $decoder->getElementContent();
						    			        	    	if(!$decoder->getElementEndTag())
				   			            	        	    	return false;
				   			            	        	    break;
						    	                    	case SYNC_AIRSYNCBASE_TRUNCATIONSIZE :
						    		    	    	    	$bodypreference["TruncationSize"] = $decoder->getElementContent();
											            	if(!$decoder->getElementEndTag())
			        					   	            		return false;
			        					   	            	break;
					                    		    	case SYNC_AIRSYNCBASE_PREVIEW :
							            	    		    $bodypreference["Preview"] = $decoder->getElementContent();
						   					                if(!$decoder->getElementEndTag())
			    			    		   	            		return false;
			    	    				   	            	break;
		                        		                case SYNC_AIRSYNCBASE_ALLORNONE :
						            				        $bodypreference["AllOrNone"] = $decoder->getElementContent();
				   				            			    if(!$decoder->getElementEndTag())
					    	        	   	        			return false;
						    	        	   	        	break;
				    			        	   	    }
												}
				       	   			        	$decoder->getElementEndTag();
									 			if (isset($collection['optionfoldertype'])) 
					           						$collection[$collection['optionfoldertype']]["BodyPreference"][$bodypreference["Type"]] = $bodypreference;
		   	    		    		    		else
													$collection["BodyPreference"][$bodypreference["Type"]] = $bodypreference;
												if ($collection['onlyoptionbodypreference'] == false)
			        								$collection['onlyoptionbodypreference'] = $protocolversion >= 14.0 && (!isset($collection["BodyPreference"][1]) && 
		    	    																			   						   !isset($collection["BodyPreference"][2]) && 
		    	    																			   						   !isset($collection["BodyPreference"][3]) && 
		    	    																			   						   !isset($collection["BodyPreference"][4]));
							               		break;
		    	   							// END ADDED dw2412 V12.0 Sync Support
				       					}
						            }
					               	$decoder->getElementEndTag();
			                      	break;
								case SYNC_PERFORM :
							        // compatibility mode - get folderid from the state directory
						    	    if (!isset($collection["collectionid"])) {
					    	    	    $collection["collectionid"] = _getFolderID($devid, $collection["class"]);
						        	}

									// Start error checking
									// Since we're not working sequential with the fields we need to do error checking prior actual perform can take place.
									// If needed elements are missing we will return error Status to the client
									if ($collection["collectionid"] == "" ||
										$collection["collectionid"] == false) {
										_HandleSyncError(SYNC_STATUS_INVALID_SYNCKEY);
										debugLog("HandleSync: Should do a perform but don't have a collectionid, sending status ".SYNC_STATUS_INVALID_SYNCKEY." to recover from this ( STATUS = ".SYNC_STATUS_INVALID_SYNCKEY.")");
									    return true;
									}

                                    if (!isset($collection["synckey"])) {
										_HandleSyncError(SYNC_STATUS_PROTOCOL_ERROR);
										debugLog("HandleSync: Should do a perform in collection ".$collection["collectionid"]." without any synckey, sending status ".SYNC_STATUS_PROTOCOL_ERROR." to recover from this ( STATUS = ".SYNC_STATUS_PROTOCOL_ERROR.")");
									    return true;
                                    }

									if ($protocolversion >= 12.1 &&
									   	!isset($collection["class"]) &&
									   	isset($collection["collectionid"])) {
							    		if (isset($SyncCache['folders'][$collection["collectionid"]]["class"])) {
											$collection["class"] = $SyncCache['folders'][$collection["collectionid"]]["class"];
											debugLog("HandleSync: Sync folder:{$collection["class"]}");
									    } else {
											_HandleSyncError(SYNC_STATUS_FOLDER_HIERARCHY_CHANGED);
											debugLog("HandleSync: No Class even in cache, sending status ".SYNC_STATUS_FOLDER_HIERARCHY_CHANGED." to recover from this ( STATUS = ".SYNC_STATUS_FOLDER_HIERARCHY_CHANGED.")");
									       	return true;
								    	}
									};
									// End error checking, everything seems to be ok until this point. Doing the requested SYNC_PERFORM

									// Configure importer with last state
								    $importer[$collection["collectionid"]] = $backend->GetContentsImporter($collection["collectionid"]);
									$filtertype = (isset($collection["filtertype"]) ? $collection["filtertype"] : 
													 	 (isset($SyncCache['collections'][$collection["collectionid"]]["filtertype"]) ? $SyncCache['collections'][$collection["collectionid"]]["filtertype"] 
													 	 																			  : 0)
												  ); 
									$mclass = (isset($collection["class"]) ? $collection["class"] : 
											    	(isset($SyncCache['collections'][$collection["collectionid"]]["class"]) ? $SyncCache['collections'][$collection["collectionid"]]["class"]: 
											    		false)
												  		);
							    	$bodypreference = (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : 
											    (isset($SyncCache['collections'][$collection["collectionid"]]["BodyPreference"]) ? $SyncCache['collections'][$collection["collectionid"]]["BodyPreference"]: 
												    false)
													);
									if (isset($collection["optionfoldertype"])) {
										$optionfiltertype = (isset($collection[$collection['optionfoldertype']]['filtertype']) ? $collection[$collection['optionfoldertype']]['filtertype'] : 
													 	 (isset($SyncCache['collections'][$collection['collectionid']][$collection['optionfoldertype']]['filtertype']) ? $SyncCache['collections'][$collection['collectionid']][$collection['optionfoldertype']]['filtertype']
													 	 																								  : 0)
													  );
							    		$optionbodypreference = (isset($collection[$collection["optionfoldertype"]]["BodyPreference"]) ? $collection[$collection["optionfoldertype"]]["BodyPreference"] : 
											    (isset($SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["BodyPreference"]) ? $SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["BodyPreference"]: 
												    false)
													);
								    	$importer[$collection['optionfoldertype'].$collection["collectionid"]] = $backend->GetContentsImporter($collection["collectionid"]);
					        	   		$importer[$collection['optionfoldertype'].$collection["collectionid"]]->Config($collection[$collection['optionfoldertype'].'syncstate'], $collection["conflict"], $collection['optionfoldertype'], $optionfiltertype, false, $optionbodypreference);
									} else {
										$optionbodypreference = false;
									}
									debugLog("HandleSync: FilterTypes Perform: ".$filtertype. " ".$optionfiltertype);
									if ($collection['onlyoptionbodypreference'] === false)
						        	   	$importer[$collection["collectionid"]]->Config($collection['syncstate'], $collection["conflict"], $mclass, $filtertype, $bodypreference, false);

					    	        $nchanges = 0;
						            while(($performtag = ($decoder->getElementStartTag(SYNC_ADD) ? SYNC_ADD :
						            					 ($decoder->getElementStartTag(SYNC_MODIFY) ? SYNC_MODIFY :
						            					 ($decoder->getElementStartTag(SYNC_REMOVE) ? SYNC_REMOVE :
						            					 ($decoder->getElementStartTag(SYNC_FETCH) ? SYNC_FETCH :
						            					 -1))))) != -1 ) {
						                $nchanges++;

						    			// dw2412 in as14 this is used to sent SMS type messages
					    	        	$foldertype = false;
				       			       	$serverid = false;
				   	        	        $clientid = false;
					    	            while (($addmodifyfetchtag = ($decoder->getElementStartTag(SYNC_FOLDERTYPE) ? SYNC_FOLDERTYPE : 
					    	            							 ($decoder->getElementStartTag(SYNC_SERVERENTRYID) ? SYNC_SERVERENTRYID :
					    	            							 ($decoder->getElementStartTag(SYNC_CLIENTENTRYID) ? SYNC_CLIENTENTRYID :
					    	            							 ($decoder->getElementStartTag(SYNC_DATA) ? SYNC_DATA :
					    	            							 -1))))) != -1) {
											switch($addmodifyfetchtag) {
												case SYNC_FOLDERTYPE : 
						    	    	            $foldertype = $decoder->getElementContent();
						        	    	        if(!$decoder->getElementEndTag()) // end foldertype
						            	    	   		return false;
						            	    	   	break;
												case SYNC_SERVERENTRYID :
								                    $serverid = $decoder->getElementContent();
							        	        	if(!$decoder->getElementEndTag()) // end serverid
								                		return false;
													break;
												case SYNC_CLIENTENTRYID :
						    	                    $clientid = $decoder->getElementContent();
						                          	if(!$decoder->getElementEndTag()) // end clientid
						    		               		return false;
													break;
												case SYNC_DATA :
									            	// Get application data if available
													if (!isset($collection["class"])) {
														debugLog("HandleSync: No Class found for collection ".$collection["collectionid"]);
														if(isset($SyncCache["collections"][$collection["collectionid"]]["class"])) {
															debugLog("HandleSync: SyncCache search results in ".$SyncCache["collections"][$collection["collectionid"]]["class"]);
															$collection["class"] = $SyncCache["collections"][$collection["collectionid"]]["class"];
														} else {
															debugLog("HandleSync: SyncCache search results in nothing :-(");
														}
													}
										            switch($collection["class"]) {
									    	           	case "Email":
									        	            if ($foldertype) {
									        	             	$appdata = new SyncSMS();
															} else {
									        	              	$appdata = new SyncMail();
									        	            }
									            	       	$appdata->decode($decoder);
									                      	break;
										            	case "Contacts":
									    	               	$appdata = new SyncContact($protocolversion);
								    	    	           	$appdata->decode($decoder);
								        	    	       	break;
									            	    case "Calendar":
									                      	$appdata = new SyncAppointment();
								    	              	   	$appdata->decode($decoder);
									    	               	break;
									        	        case "Tasks":
								    	        	       	$appdata = new SyncTask();
								        	        	   	$appdata->decode($decoder);
								            	         	break;
									                  	case "Notes":
									                      	$appdata = new SyncNote();
										                   	$appdata->decode($decoder);
									    	               	break;
								    	    	    }
										            if(!$decoder->getElementEndTag()) // end applicationdata
										               	return false;
													break;
											}
										}
									    switch($performtag) {
										    case SYNC_MODIFY:
									       		if(isset($appdata)) {
										          	if ($appdata->_setchange == true || 
										           	   	($appdata->_setread == false &&
								    	          	   	$appdata->_setflag == false &&
								    	          	   	$appdata->_setcategories == false)) {
								    	          	   	if (isset($collection['optionfoldertype']) &&
								    	          	   		$foldertype == $collection['optionfoldertype']) {
								                   	   		$collection['changeids'][$serverid]['optionfoldertype'] = $foldertype;
								                   	   		if (!isset($msginfo[$serverid])) 
								                   	   		    $collection[$collection['optionfoldertype'].'changeids'][$serverid]['status'] = SYNC_STATUS_OBJECT_NOT_FOUND;
								                   	   		else {
								                   	   			$importer[$collection['optionfoldertype'].$collection["collectionid"]]->ImportMessageChange($serverid, $appdata);
								                   	   		    $collection[$collection['optionfoldertype'].'changeids'][$serverid]['status'] = SYNC_STATUS_SUCCESS;
								                   	   		}
														} else {
								                   	   		if (!isset($msginfo[$serverid])) 
								                   	   		    $collection['changeids'][$serverid]['status'] = SYNC_STATUS_OBJECT_NOT_FOUND;
								                   	   		else {
									                   	   		$importer[$collection["collectionid"]]->ImportMessageChange($serverid, $appdata);
								                   	   		    $collection['changeids'][$serverid]['status'] = SYNC_STATUS_SUCCESS;
								                   	   		}
								                   	   	}
								                   	} else {
								                 		if ($appdata->_setflag == true) {
								    	  	        	   	if (isset($collection['optionfoldertype']) &&
								    	          	   			$foldertype == $collection['optionfoldertype']) {
									        	       	   		$collection[$collection['optionfoldertype']."flagids"][$serverid]['data'] = $appdata->poommailflag;
									                   	   		$collection['changeids'][$serverid]['optionfoldertype'] = $foldertype;
									                   	   		if (!isset($msginfo[$serverid])) 
									                   	   		    $collection[$collection['optionfoldertype'].'changeids'][$serverid]['status'] = SYNC_STATUS_OBJECT_NOT_FOUND;
									                   	   		else {
										    						$collection[$collection['optionfoldertype']."flagids"][$serverid]['status'] = $importer[$collection['optionfoldertype'].$collection["collectionid"]]->ImportMessageFlag($serverid, $appdata->poommailflag);
									                   	   		    $collection[$collection['optionfoldertype'].'changeids'][$serverid]['status'] = SYNC_STATUS_SUCCESS;
									                   	   		}
								    	  	        	   	} else {
									        	       	   		$collection["flagids"][$serverid]['data'] = $appdata->poommailflag;
									                   	   		if (!isset($msginfo[$serverid])) 
									                   	   		    $collection['changeids'][$serverid]['status'] = SYNC_STATUS_OBJECT_NOT_FOUND;
									                   	   		else {
										    						$collection["flagids"][$serverid]['status'] = $importer[$collection["collectionid"]]->ImportMessageFlag($serverid, $appdata->poommailflag);
									                   	   		    $collection['changeids'][$serverid]['status'] = SYNC_STATUS_SUCCESS;
									                   	   		}
									    					}
								        	    		}
									            		if ($appdata->_setread == true) {
								    	        	  	   	if (isset($collection['optionfoldertype']) &&
								    	          	   			$foldertype == $collection['optionfoldertype']) {
								    		               		$collection[$collection['optionfoldertype']."readids"][$serverid]['data'] = $appdata->read;
									                   	   		$collection['changeids'][$serverid]['optionfoldertype'] = $foldertype;
									                   	   		if (!isset($msginfo[$serverid])) 
									                   	   		    $collection[$collection['optionfoldertype'].'changeids'][$serverid]['status'] = SYNC_STATUS_OBJECT_NOT_FOUND;
									                   	   		else {
									        		       			$collection[$collection['optionfoldertype']."readids"][$serverid]['status'] = $importer[$collection['optionfoldertype'].$collection["collectionid"]]->ImportMessageReadFlag($serverid, $appdata->read);
									                   	   		    $collection[$collection['optionfoldertype'].'changeids'][$serverid]['status'] = SYNC_STATUS_SUCCESS;
									                   	   		}
								    	        	  	   	} else {
								    		               		$collection["readids"][$serverid]['data'] = $appdata->read;
									                   	   		if (!isset($msginfo[$serverid])) 
									                   	   		    $collection['changeids'][$serverid]['status'] = SYNC_STATUS_OBJECT_NOT_FOUND;
									                   	   		else {
									        		       			$collection["readids"][$serverid]['status'] = $importer[$collection["collectionid"]]->ImportMessageReadFlag($serverid, $appdata->read);
									                   	   		    $collection['changeids'][$serverid]['status'] = SYNC_STATUS_SUCCESS;
									                   	   		}
															}
								                		}
													}
													$collection["importedchanges"] = true;
									            }
									            break;
								            case SYNC_ADD:
							                  	if(isset($appdata)) {
													if (isset($collection['optionfoldertype']) &&
								    	          	   	$foldertype == $collection['optionfoldertype'])
					    			               		$id = $importer[$collection['optionfoldertype'].$collection["collectionid"]]->ImportMessageChange(false, $appdata);
													else
					    			               		$id = $importer[$collection["collectionid"]]->ImportMessageChange(false, $appdata);
							        	            if($clientid && $id) {
						    	        	      		$collection["clientids"][$clientid]['serverid'] = $id;
					    	    	              		if ($foldertype) {
			        			    	           		    $collection["clientids"][$clientid]['optionfoldertype'] = $foldertype;
															$md5msg = array('datereceived' 		=> (isset($appdata->datereceived) 			? strval($appdata->datereceived) 			: ''),
											 								'importance' 		=> (isset($appdata->importance) 			? strval($appdata->importance) 				: ''),
																			'messageclass' 		=> (isset($appdata->messageclass) 			? strval($appdata->messageclass) 			: ''),
																			'to' 				=> (isset($appdata->to) 					? strval($appdata->to)						: ''),
																			'cc' 				=> (isset($appdata->cc) 					? strval($appdata->cc)						: ''),
																			'from' 				=> (isset($appdata->from) 					? strval($appdata->from)					: ''),
																			'internetcpid' 		=> (isset($appdata->internetcpid) 			? strval($appdata->internetcpid) 			: ''),
												//							'conversationid' 	=> (isset($appdata->conversationid) 		? bin2hex($appdata->conversationid) 	: ''),  
												//							'conversationindex'	=> (isset($appdata->conversationindex) 		? bin2hex($appdata->conversationindex)	: ''),
																			'body' 				=> (isset($appdata->body)				 	? strval($appdata->body)					: ''),
																			);
															$md5flags = array('flagstatus' 		=> (isset($appdata->poommailflag->flagstatus) 		? strval($appdata->poommailflag->flagstatus) 		: ''),
																			  'flagtype'		=> (isset($appdata->poommailflag->flagtype) 		? strval($appdata->poommailflag->flagtype) 			: ''),
																			  'startdate'		=> (isset($appdata->poommailflag->startdate) 		? strval($appdata->poommailflag->startdate) 		: ''),
																			  'utcstartdate'	=> (isset($appdata->poommailflag->utcstartdate) 	? strval($appdata->poommailflag->utcstartdate)		: ''),
																			  'duedate'			=> (isset($appdata->poommailflag->duedate) 			? strval($appdata->poommailflag->duedate)			: ''),
																			  'utcduedate'		=> (isset($appdata->poommailflag->utcduedate) 		? strval($appdata->poommailflag->utcduedate) 		: ''),
																			  'datecomplete'	=> (isset($appdata->poommailflag->datecompleted) 	? strval($appdata->poommailflag->datecompleted) 	: ''),
																			  'reminderset' 	=> (isset($appdata->poommailflag->reminderset) 		? strval($appdata->poommailflag->reminderset) 		: ''),
																			  'subject'			=> (isset($appdata->poommailflag->subject) 			? strval($appdata->poommailflag->subject)			: ''),
																			  'ordinaldate'		=> (isset($appdata->poommailflag->ordinaldate) 		? strval($appdata->poommailflag->ordinaldate) 		: ''),
																			  'subordinaldate'	=> (isset($appdata->poommailflag->subordinaldate) 	? strval($appdata->poommailflag->subordinaldate) 	: ''),
																			  'completetime'	=> (isset($appdata->poommailflag->completetime) 	? strval($appdata->poommailflag->completetime) 		: ''),
																			  );
															$msginf['md5msg'] = md5(serialize($md5msg)); 
															$msginf['md5flags'] = md5(serialize($md5flags));
															$msginf['read'] = (isset($appdata->read) ? $appdata->read : '');
															$msginf['class'] = "syncsms";
															unset($md5msg);
															unset($md5flags);
															$msginfo[$id['sourcekey']] = $msginf;
															debugLog("HandleSync: Generated msginfos for ".$id['sourcekey']." with following values: ".print_r($msginf,true));
															unset($msginf);
														} else {
															$msginfo[$id] = array('md5msg' => 0, 'read' => '', 'md5flags' => '', 'class' => strtolower(get_class($appdata)));
															debugLog("HandleSync: Generated msginfos for ".$id." with following values: ".print_r($msginf,true));
														}
							            	          	$collection["importedchanges"] = true;
													}
							    	        	}
							        	    	break;
											case SYNC_REMOVE:
					    	    	          	if(isset($collection["deletesasmoves"])) {
						    		                $folderid = $backend->GetWasteBasket();
				        	    		   	        if($folderid) {
														if (isset($collection['optionfoldertype']) &&
								    	          	   		$foldertype == $collection['optionfoldertype'])
					    	                       	    	$importer[$collection['optionfoldertype'].$collection["collectionid"]]->ImportMessageMove($serverid, $folderid);
														else
					    	                       	    	$importer[$collection["collectionid"]]->ImportMessageMove($serverid, $folderid);
						                	           	$collection["importedchanges"] = true;
			    			                           	break;
			        			                    } else {
			        			                    	debugLog("HandleSync: SYNC_REMOVE failed because there is no waste basket returned!");
			        			                    }
			                		        	}
				            		            if (isset($importer[$collection["collectionid"]]))
													if (isset($collection['optionfoldertype']) &&
								    	          	   	$foldertype == $collection['optionfoldertype'])
					            		                $importer[$collection['optionfoldertype'].$collection["collectionid"]]->ImportMessageDeletion($serverid);
													else
					            		                $importer[$collection["collectionid"]]->ImportMessageDeletion($serverid);
				            		            else
			        			                   	debugLog("HandleSync: SYNC_REMOVE failed because there is no importer for collection");
					                    	    $collection["importedchanges"] = true;
												if (isset($collection['changeids'][$serverid]))
													$collection['changeids'][$serverid]['status'] = SYNC_STATUS_OBJECT_NOT_FOUND;
												unset($msginfo[$serverid]);
					   	                    	break;
						                    case SYNC_FETCH:
			    			                   	array_push($collection["fetchids"], $serverid);
					                    	    $collection["importedchanges"] = true;
				        		               	break;
										}

						                if(!$decoder->getElementEndTag()) // end add/remove/modify/fetch
				    		                return false;
				    	        	}

					                debugLog("HandleSync: Processed $nchanges incoming changes");

				    	        	// Save the updated state, which is used for the exporter later
									if (isset($importer[$collection["collectionid"]]))
				            		   	$collection['syncstate'] = $importer[$collection["collectionid"]]->getState();
									if (isset($collection['optionfoldertype']) &&
										isset($importer[$collection['optionfoldertype'].$collection["collectionid"]]))
				            		   	$collection[$collection['optionfoldertype'].'syncstate'] = $importer[$collection['optionfoldertype'].$collection["collectionid"]]->getState();
						    		if (isset($collection["importedchanges"]) &&
						    			$collection["importedchanges"] == true) 
					    				$dataimported = true;

				        		    if(!$decoder->getElementEndTag()) // end SYNC_PERFORM
				        	    		return false;
									break;
				   	    	};
				   		};

		    			if(!$decoder->getElementEndTag()) // end collection
		        	    	return false;

						if (isset($msginfo))
							$statemachine->setSyncState('mi'.$collection['synckey'],serialize($msginfo));
			    		array_push($collections, $collection);
						if (isset($collection['collectionid'])) {
							$msginfos[$collection['collectionid']] = (isset($msginfo) ? $msginfo : array());
					    	if (isset($collection['class'])) 			$SyncCache['collections'][$collection['collectionid']]['class'] = $collection['class'];
					    	if (isset($collection['maxitems'])) 		$SyncCache['collections'][$collection['collectionid']]['maxitems'] = $collection['maxitems'];
					    	if (isset($collection['deletesasmoves'])) 	$SyncCache['collections'][$collection['collectionid']]['deletesasmoves'] = $collection['deletesasmoves'];
					    	if (isset($collection['conversationmode'])) $SyncCache['collections'][$collection['collectionid']]['conversationmode'] = $collection['conversationmode'];
					    	if (isset($collection['filtertype'])) 		$SyncCache['collections'][$collection['collectionid']]['filtertype'] = $collection['filtertype'];
					    	if (isset($collection['truncation'])) 		$SyncCache['collections'][$collection['collectionid']]['truncation'] = $collection['truncation'];
					    	if (isset($collection['rtftruncation']))  	$SyncCache['collections'][$collection['collectionid']]['rtftruncation'] = $collection['rtftruncation'];
					   	 	if (isset($collection['mimesupport'])) 		$SyncCache['collections'][$collection['collectionid']]['mimesupport'] = $collection['mimesupport'];
					    	if (isset($collection['mimetruncation'])) 	$SyncCache['collections'][$collection['collectionid']]['mimetruncation'] = $collection['mimetruncation'];
					    	if (isset($collection['conflict']))	  		$SyncCache['collections'][$collection['collectionid']]['conflict'] = $collection['conflict'];
				    		if (isset($collection['BodyPreference'])) 	$SyncCache['collections'][$collection['collectionid']]['BodyPreference'] = $collection['BodyPreference'];
				    		if (isset($collection['optionfoldertype'])) {
				    			if (isset($collection[$collection['optionfoldertype']]['filtertype'])) 		 $SyncCache['collections'][$collection['collectionid']][$collection['optionfoldertype']]['filtertype'] = $collection[$collection['optionfoldertype']]['filtertype'];
				    			if (isset($collection[$collection['optionfoldertype']]['BodyPreference'])) 	 $SyncCache['collections'][$collection['collectionid']][$collection['optionfoldertype']]['BodyPreference'] = $collection[$collection['optionfoldertype']]['BodyPreference'];
				    			$SyncCache['collections'][$collection['collectionid']]['optionfoldertype'] = $collection['optionfoldertype'];
				    		}
				    		elseif (isset($SyncCache['collections'][$collection['collectionid']]['optionfoldertype'])) {
				    				$optionfoldertype = $SyncCache['collections'][$collection['collectionid']]['optionfoldertype'];
				    				if (isset($SyncCache['collections'][$collection['collectionid']][$optionfoldertype])) {
					    				unset($SyncCache['collections'][$collection['collectionid']][$optionfoldertype]);
					    				unset($SyncCache['collections'][$collection['collectionid']]['optionfoldertype']);
					    			}
							}
						};
				   	}
			    	if (!$decoder->getElementEndTag() ) // end collections
						return false;
					break;
			}
		}

		if (!isset($collections)) {
		   	debugLog("HandleSync:  HERE S ". (isset($SyncCache['lastuntil']) ? strftime("%x %X",$SyncCache['lastuntil']+$maxcacheage) : "NO LASTUNTIL!"));
			$found = false;
			foreach($SyncCache['collections'] as $value) {
				if (isset($value['synckey'])) {
					$found = true;
					break;
		   		}
	       	}
			if ($found == false) {
		   		$SyncCache['lastuntil'] = time();
    	   		$statemachine->setSyncCache(serialize($SyncCache));
    	   		_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
		   		debugLog("HandleSync: No Collections with SyncKeys. Enforce Full Sync Request (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
				return true;
			}
		}

		// Fill up collections with values from cache in case they're missing
		foreach ($collections as $key=>$values) {
			if (!isset($values["class"]) &&
				isset($SyncCache['folders'][$values["collectionid"]]["class"])) 
				$collections[$key]["class"] = $SyncCache['folders'][$values["collectionid"]]["class"];
			if (!isset($values["filtertype"]) &&
				isset($SyncCache['collections'][$values["collectionid"]]["filtertype"])) 
				$collections[$key]["filtertype"] = $SyncCache['collections'][$values["collectionid"]]["filtertype"];
			if (!isset($values["mimesupport"]) &&
				isset($SyncCache['collections'][$values["collectionid"]]["mimesupport"])) 
				$collections[$key]["mimesupport"] = $SyncCache['collections'][$values["collectionid"]]["mimesupport"];
			if (!isset($values["BodyPreference"]) &&
				isset($SyncCache['collections'][$values["collectionid"]]["BodyPreference"])) 
				$collections[$key]["BodyPreference"] = $SyncCache['collections'][$values["collectionid"]]["BodyPreference"];
			if (isset($value['optionfoldertype'])) {
				if (!isset($values[$value['optionfoldertype']]["filtertype"]) &&
					isset($SyncCache['collections'][$values["collectionid"]][$value['optionfoldertype']]["filtertype"])) 
					$collections[$key][$value['optionfoldertype']]["filtertype"] = $SyncCache['collections'][$values["collectionid"]][$value['optionfoldertype']]["filtertype"];
				if (!isset($values[$value['optionfoldertype']]["BodyPreference"]) &&
					isset($SyncCache['collections'][$values["collectionid"]][$value['optionfoldertype']]["BodyPreference"])) 
					$collections[$key][$value['optionfoldertype']]["BodyPreference"] = $SyncCache['collections'][$values["collectionid"]][$value['optionfoldertype']]["BodyPreference"];
			}
	        $collections[$key]['onlyoptionbodypreference'] = $protocolversion >= 14.0 && (!isset($SyncCache['collections'][$values["collectionid"]]["BodyPreference"][1]) && 
    	    																		   	  !isset($SyncCache['collections'][$values["collectionid"]]["BodyPreference"][2]) && 
	        													 		 			   	  !isset($SyncCache['collections'][$values["collectionid"]]["BodyPreference"][3]) && 
    	    													 		 			   	  !isset($SyncCache['collections'][$values["collectionid"]]["BodyPreference"][4]));
			// Set the maxitems (windowsize) to either what is being declared in cache or to 100 if nothing can be found in cache.
			// 100 is according to spec the default if nothing is being sent by the client
		    if (!isset($values["maxitems"])) 
				$collections[$key]["maxitems"] = (isset($SyncCache['collections'][$values["collectionid"]]['maxitems']) ? 
						    $SyncCache['collections'][$values["collectionid"]]['maxitems'] : 100);
			// in case the maxitems (windowsize) is above 512 or 0 it should be interpreted as 512 according to specs.
			if ($collections[$key]["maxitems"] > 512 ||
				$collections[$key]["maxitems"] == 0) $collections[$key]["maxitems"]=512;

	    	if (isset($values['synckey']) && 
				$values['synckey'] == '0' && 
				isset($SyncCache['collections'][$values["collectionid"]]['synckey']) && 
				$SyncCache['collections'][$values["collectionid"]]['synckey'] != '0') {
				unset($SyncCache['collections'][$values["collectionid"]]['synckey']);
		    }
		}

		// Give up in case we don't have a synched hierarchy synckey!
		if (!isset($SyncCache['hierarchy']['synckey'])) {
	    	_HandleSyncError(SYNC_STATUS_FOLDER_HIERARCHY_CHANGED);
		    debugLog("HandleSync: HandleSync Error No Hierarchy SyncKey in SyncCache... Invalidate! (STATUS = ".SYNC_STATUS_FOLDER_HIERARCHY_CHANGED.")");
		    return true;
		}

		// Just in case some client runs amok. This HB Interval & Wait in one request is not allowed by definition
		if ($SyncCache['hbinterval'] !== false &&
		    $SyncCache['wait'] !== false) {
		    _HandleSyncError(SYNC_STATUS_PROTOCOL_ERROR);
		    debugLog("HandleSync: HandleSync got Found HeartbeatInterval and Wait in request. This violates the protocol spec. (STATUS = ".SYNC_STATUS_PROTOCOL_ERROR.")");
		    return true;
		}

		// Partial sync but with Folders and Options so we need to set collections
		$foundsynckey = false;

		if ($partial === true) {
		    debugLog("HandleSync: Partial Sync");

		    $TempSyncCache = unserialize($statemachine->getSyncCache());

			// Removing all from TempSyncCache that we already got information on
			$CollectionsUnchanged = 0;
			$CollectionKeys = 0;
			$ConfirmedKeys = 0;
			foreach ($collections as $key=>$value) {
				// Discover if any collection got really changed
			    $v1 = $collections[$key];
			    if (isset($v1['collectionid'])) unset($v1['collectionid']);
			    if (isset($v1['clientids'])) unset($v1['clientids']);
			    if (isset($v1['fetchids'])) unset($v1['fetchids']);
			    if (isset($v1['getchanges'])) unset($v1['getchanges']);
			    if (isset($v1['changeids'])) unset($v1['changeids']);
			    if (isset($v1['onlyoptionbodypreference'])) unset($v1['onlyoptionbodypreference']);
			    if (isset($v1['syncstate'])) unset($v1['syncstate']);
			    if (isset($v1['optionfoldertype'])) {
				    if (isset($v1[$v1['optionfoldertype'].'syncstate'])) unset($v1[$v1['optionfoldertype'].'syncstate']);
                }
                $v2 = $TempSyncCache['collections'][$value['collectionid']];
				ksort($v1);
				if (isset($v1['BodyPreference'])) {
					ksort($v1['BodyPreference']);
					foreach($v1['BodyPreference'] as $key=>$v) {
						ksort($v1['BodyPreference'][$key]);
					}
				}
				if (isset($v1['optionfoldertype'])) {
					ksort($v1[$v1['optionfoldertype']]);
					if (isset($v1[$v1['optionfoldertype']]['BodyPreference'])) {
						ksort($v1[$v1['optionfoldertype']]['BodyPreference']);
						foreach($v1[$v1['optionfoldertype']]['BodyPreference'] as $key=>$v) {
							ksort($v1[$v1['optionfoldertype']]['BodyPreference'][$key]);
						}
					}
				}
				if (isset($v1['BodyPreference'])) ksort($v1['BodyPreference']);
				ksort($v2);
				if (isset($v2['BodyPreference'])) {
					ksort($v2['BodyPreference']);
					foreach($v2['BodyPreference'] as $key=>$v) {
						ksort($v2['BodyPreference'][$key]);
					}
				}
				if (isset($v2['optionfoldertype'])) {
					ksort($v2[$v2['optionfoldertype']]);
					if (isset($v2[$v2['optionfoldertype']]['BodyPreference'])) {
						ksort($v2[$v2['optionfoldertype']]['BodyPreference']);
						foreach($v2[$v2['optionfoldertype']]['BodyPreference'] as $key=>$v) {
							ksort($v2[$v2['optionfoldertype']]['BodyPreference'][$key]);
						}
					}
				}
				if (md5(serialize($v1)) == md5(serialize($v2))) 
					$CollectionsUnchanged++;

				if ((isset($v2['optionfoldertype']) &&
					!isset($v1['optionfoldertype'])) ||
					(isset($v1['optionfoldertype']) &&
					!isset($v2['optionfoldertype']))) {
					$SyncStatus = SYNC_STATUS_REQUEST_INCOMPLETE;
				}

				unset($v1);
				unset($v2);

				// Unset Collection in TempSyncCache in case we already have it in our collections
				if(isset($TempSyncCache['collections'][$value['collectionid']])) {
					debugLog("HandleSync: Removing ".$value['collectionid']." from TempSyncCache");
					unset($TempSyncCache['collections'][$value['collectionid']]);
				}

				// Remove keys from confirmed synckeys array and count them
				if (isset($value['synckey'])) {
				    $foundsynckey = true;
					if (isset($SyncCache['confirmed_synckeys'][$value['synckey']])) {
						debugLog('HandleSync: Removed '.$SyncCache['confirmed_synckeys'][$value['synckey']].' from confirmed_synckeys array');
						unset($SyncCache['confirmed_synckeys'][$value['synckey']]);
						$ConfirmedKeys++;
					}
				}

				// Count all current Collections with SyncKey set
				if (isset($value['synckey'])) $CollectionKeys++;
			}

			$CacheKeys = 0;
			foreach ($SyncCache['collections'] as $value) {
				// Count all cached Collections with SyncKey set
				if (isset($value['synckey'])) $CacheKeys++;
			}

			debugLog("HandleSync: CollectionKeys vs SyncCacheKeys vs Unchanged Collections vs ConfirmedKeys: ".$CollectionKeys. " / " .$CacheKeys . " / " . $CollectionsUnchanged . " / " .$ConfirmedKeys);
			debugLog("HandleSync: Wait Cache / TempCache: ".$SyncCache['wait']. " / ". $TempSyncCache['wait']);
			debugLog("HandleSync: Heartbeat Cache / TempCache: ".$SyncCache['hbinterval']. " / ". $TempSyncCache['hbinterval']);
			debugLog("HandleSync: Time now is <= SyncCache lastuntil (".time()." - ".$SyncCache['lastuntil']." = ".(time()-$SyncCache['lastuntil']).")");
			debugLog("HandleSync: Last HB Sync started vs Last Sync normal end ".$SyncCache['lasthbsyncstarted']." / ".$SyncCache['lastsyncendnormal'].")");

			if (isset($SyncCache['lasthbsyncstarted']) && 
				$SyncCache['lasthbsyncstarted'] > $SyncCache['lastsyncendnormal']) {
 				debugLog("HandleSync: lasthbsyncstarted is larger than lastsyncendnormal. Request a full request now (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
				_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
				return true;
			}

			if (isset($SyncCache['lastuntil']) &&
				isset($SyncCache['lasthbsyncstarted']) &&
				isset($SyncCache['lastsyncendnormal']) &&
				$SyncCache['lasthbsyncstarted'] > $SyncCache['lastsyncendnormal'] &&
				time() < $SyncCache['lastuntil']) {
 				debugLog("HandleSync: Current Time is lower than lastuntil. Request a full request now (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
				_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
				return true;
			}

			// If there are no changes within partial sync, send status 13 since sending partial elements without any changes is suspicius 
			// (Could be a remove folder from sync...)
			// Logic is: 
			// Collection SyncKeys are being send by device
			// No SyncKeys got confirmed
			// Collections in request are equal with Collections in Cache
			// Current Heartbeat/Wait is still running
			// No new Heartbeat/Wait Value is being sent
			if ($CollectionKeys > 0 &&
				$ConfirmedKeys == 0 &&
				$CollectionsUnchanged == $CollectionKeys &&
				time() <= $SyncCache['lastuntil'] &&
				($SyncCache['wait'] == false && $SyncCache['hbinterval'] == false)) {
 				debugLog("HandleSync: Partial Request with completely unchanged collections. Request a full request now (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
				_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
				return true;
			}

			// Updating Collections with all necessary informations that we don't have informations for but with a synckey in foldercache
		    foreach ($TempSyncCache['collections'] as $key=>$value) {
				if (isset($value['synckey'])) {
		    	    $collection = $value;
		    	    $collection['collectionid'] = $key;
	    		    if (isset($default_maxitems)) 	$collection["maxitems"] = $default_maxitems;
        			$collection['onlyoptionbodypreference'] = $protocolversion >= 14.0 && (!isset($collection["BodyPreference"][1]) && 
        							  											 		   !isset($collection["BodyPreference"][2]) && 
        															 		 			   !isset($collection["BodyPreference"][3]) && 
        															 		 			   !isset($collection["BodyPreference"][4]));
					$collection['syncstate'] = $statemachine->getSyncState($collection["synckey"]);
					if (isset($collection['optionfoldertype'])) {
						$collection[$collection['optionfoldertype'].'syncstate'] = $statemachine->getSyncState($collection['optionfoldertype'].$collection["synckey"]);
					}
					if ($collection['synckey'] == "0") {
						debugLog('HandleSync: Here4 : Setting $msginfos['.$collection['collectionid'].'] to array()');
						$msginfos[$collection['collectionid']] = array();
					} else {
						if (!isset($msginfos[$collection['collectionid']]))
							$msginfos[$collection['collectionid']] = unserialize($statemachine->getSyncState("mi".$collection['synckey']));
					}
					if (isset($SyncCache['confirmed_synckeys'][$collection["synckey"]]) && 
						(strlen($collection['syncstate']) == 0 || bin2hex(substr($collection['syncstate'],4,4)) == "00000000")) {
						debugLog("HandleSync: InitialSync determined for collection. No need to confirm this key! ".$collection["synckey"]);
						unset($SyncCache['confirmed_synckeys'][$collection["synckey"]]);
					}
					if (($collection['onlyoptionbodypreference'] === false && $collection['syncstate'] < 0 && strlen($collection['syncstate']) < 8) ||
						(isset($collection['optionfoldertype']) && $collection[$collection['optionfoldertype'].'syncstate'] < 0 && $collection[$collection['optionfoldertype'].'syncstate'] < 8)) {
					    _HandleSyncError("3");
						debugLog("HandleSync: GetSyncState ERROR (Syncstate: ".abs($collection['syncstate']).") strlen=".strlen($collection['syncstate']));
						return true;
					}

				    debugLog("HandleSync: Using SyncCache State for ".$TempSyncCache['folders'][$key]['displayname']);
				    array_push($collections, $collection);
				}
		    }
		    unset($TempSyncCache);
		} else {
			// We got a full sync so we don't need to look after any confirmed synckey in array since device never the less only knows keys that it send now
			$SyncCache['confirmed_synckeys'] = array();
			// Reset the lastuntil heartbeat/wait time to time now since this is the new base for the heartbeat
			$SyncCache['lastuntil'] = time();
			// No Partial Sync so in this case we have to remove all synckeys to prevent syncs in these collections.
			foreach ($SyncCache['collections'] as $key=>$value) {
				debugLog("HandleSync: Not a partial sync. Removing SyncCache[synckey] from collection ".$key);
				unset($SyncCache['collections'][$key]['synckey']);
			}
		}

		// Update the SyncCache with values from current collections
		foreach($collections as $key=>$value) {
			if (isset($value['collectionid'])) {
			    if (isset($value['synckey'])) {
		    	    debugLog("HandleSync: Adding SyncCache[synckey] from collection ".$value['collectionid']);
			        $SyncCache['collections'][$value['collectionid']]['synckey'] = $value['synckey'];
			    }
			    if (isset($value["class"])) 			$SyncCache['collections'][$value["collectionid"]]["class"] = $value["class"];
			    if (isset($value["maxitems"])) 			$SyncCache['collections'][$value["collectionid"]]["maxitems"] = $value["maxitems"];
			    if (isset($value["deletesasmoves"])) 	$SyncCache['collections'][$value["collectionid"]]["deletesasmoves"] = $value["deletesasmoves"];
			    if (isset($value["conversationmode"])) 	$SyncCache['collections'][$value["collectionid"]]["conversationmode"] = $value["conversationmode"];
				if (isset($value["filtertype"])) 		$SyncCache['collections'][$value["collectionid"]]["filtertype"] = $value["filtertype"];
				if (isset($value["truncation"])) 		$SyncCache['collections'][$value["collectionid"]]["truncation"] = $value["truncation"];
			    if (isset($value["rtftruncation"])) 	$SyncCache['collections'][$value["collectionid"]]["rtftruncation"] = $value["rtftruncation"];
				if (isset($value["mimesupport"])) 		$SyncCache['collections'][$value["collectionid"]]["mimesupport"] = $value["mimesupport"];
				if (isset($value["mimetruncation"])) 	$SyncCache['collections'][$value["collectionid"]]["mimetruncation"] = $value["mimetruncation"];
				if (isset($value["conflict"])) 			$SyncCache['collections'][$value["collectionid"]]["conflict"] = $value["conflict"];
				if (isset($value["BodyPreference"])) 	$SyncCache['collections'][$value["collectionid"]]["BodyPreference"] = $value["BodyPreference"];
			       	if (isset($value['optionfoldertype'])) {
			       		if (isset($value[$value['optionfoldertype']]["filtertype"]))  		$SyncCache['collections'][$value["collectionid"]][$value['optionfoldertype']]["filtertype"] = $value[$value['optionfoldertype']]["filtertype"];
			       		if (isset($value[$value['optionfoldertype']]["BodyPreference"]))  	$SyncCache['collections'][$value["collectionid"]][$value['optionfoldertype']]["BodyPreference"] = $value[$value['optionfoldertype']]["BodyPreference"];
		    	   		$SyncCache['collections'][$value["collectionid"]]['optionfoldertype'] = $value['optionfoldertype'];
		        }
	   		} else
				debugLog("HandleSync: Collection without collectionid found: ".print_r($value,true));
		}

		// End Update the synckeys in SyncCache

		if(!$decoder->getElementEndTag()) // end sync
	        return false;

		// In case some synckeys didn't get confirmed by device we issue a full sync
		if (isset($SyncCache['confirmed_synckeys']) &&
			sizeof($SyncCache['confirmed_synckeys']) > 0) {
			debugLog("Confirmed Synckeys contains: ".print_r($SyncCache['confirmed_synckeys'],true));
			unset($SyncCache['confirmed_synckeys']);
    		$statemachine->setSyncCache(serialize($SyncCache));
			debugLog("HandleSync: Some SyncKeys didn't get confirmed. To ensure sync integrity we request a full request now (STATUS = ".SYNC_STATUS_REQUEST_INCOMPLETE.")");
			_HandleSyncError(SYNC_STATUS_REQUEST_INCOMPLETE);
			return true;
		} else {
			debugLog("HandleSync: All SyncKeys got confirmed. We continue here...");
    		$statemachine->setSyncCache(serialize($SyncCache));
		}

		$i=0;
		$statemachine->setSyncState('mi'.$collection['synckey'],(isset($msginfos[$collection['collectionid']]) ? serialize($msginfos[$collection['collectionid']]) : serialize(array())));
		foreach($collections as $key=>$value) {
			if (isset($value['synckey'])) $i++;
		}
		if ($i==0) {
			debugLog("HandleSync: We don't have any synckeys in collection. Request a full request now (STATUS = ".SYNC_STATUS_PROTOCOL_ERROR.")");
			_HandleSyncError(SYNC_STATUS_PROTOCOL_ERROR);
			return true;
		}
	};

	debugLog("HandleSync: SyncStatus is ".$SyncStatus." hbinterval is ".$SyncCache['hbinterval']);
    if ($protocolversion >= 12.1 &&
		$SyncStatus == 1 &&
		$dataimported == false &&
		($SyncCache['wait'] !== false ||
	 	 $SyncCache['hbinterval'] !== false ||
	 	 $shortsyncreq === true)) {
		$dataavailable = false;
		$timeout = 5;
		if (isset($SyncCache['wait']) &&
			$SyncCache['wait'] !== false) $until = time()+(($SyncCache['wait']*60));
		else if (isset($SyncCache['hbinterval']) &&
			$SyncCache['hbinterval'] !== false) $until = time()+($SyncCache['hbinterval']);
		else $until = time()+10;
		debugLog("HandleSync: Looking for changes for ".($until - time())." seconds");
		$SyncCache['lastuntil'] = $until;
		$SyncCache['lasthbsyncstarted'] = time();
   		$statemachine->setSyncCache(serialize($SyncCache));
		// Reading current state of the hierarchy state for determining changes during heartbeat/wait
        $hierarchystate = $statemachine->getSyncState($SyncCache['hierarchy']['synckey']);
		$hbrunavrgduration = 0;
		$hbrunmaxduration = 0;
		while ((time()+$hbrunavrgduration)<($until-$hbrunmaxduration)) {
			$hbrunstarttime = microtime(true);
	    	// we try to find changes as long as time is lower than wait time 
	    	// In case something changed in SyncCache regarding the folder hierarchy exit this function
    		$TempSyncCache = unserialize($statemachine->getSyncCache());
	   		if ($TempSyncCache === false) {
				debugLog("HandleSync: TempSyncCache could not be read and decoded, exiting here.");
   				return true;
	   		}
	   		if ($TempSyncCache['timestamp'] > $SyncCache['timestamp']) {
				debugLog("HandleSync: Changes in cache determined during Sync Wait/Heartbeat, exiting here.");
   				return true;
	   		}
   			if (PROVISIONING === true) {
				$rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);
				if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
    	    		//return 12 because it forces folder sync
	   				_HandleSyncError(SYNC_STATUS_FOLDER_HIERARCHY_CHANGED);
	   				return true;
				}
    		}
    	    if(count($collections) == 0) {
       			$error = 1;
	       		break;
   	    	}
    	    for($i=0;$i<count($collections);$i++) {
        		$collection = $collections[$i];

				$class = ($collection['onlyoptionbodypreference'] === false ? $collection["class"] : $collection["optionfoldertype"]);

				if ($class == "SMS" && !isset($collection['nextsmssync'])) $collection['nextsmssync'] = 0;

				unset($state);
		    	unset($exporter);

				if ($class != "SMS" ||
					($class == "SMS" && $collection['nextsmssync'] < time())) {
					// Checking SMS Folders only once per 5 minutes for changes
					if ($class == "SMS") {
						$collections[$i]['nextsmssync'] = time()+300;
						debugLog ("HandleSync: SMS Items now being synceed");
					}
	    		    $state = $collection['syncstate'];
					$filtertype = (isset($collection["filtertype"]) ? $collection["filtertype"] : 
									 	 (isset($SyncCache['collections'][$collection["collectionid"]]["filtertype"]) ? $SyncCache['collections'][$collection["collectionid"]]["filtertype"] 
									 	 																			  : 0)
								  ); 
					$optionfiltertype = (isset($collection["optionfoldertype"]) ? 
							    			(isset($collection[$collection["optionfoldertype"]]["filtertype"]) ? $collection[$collection["optionfoldertype"]]["filtertype"] :
							    				(isset($SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["filtertype"]) ? $SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["filtertype"] 
							    																															  : 0)
							    			)
							    												: 0
							    		);

	        		$collection['onlyoptionbodypreference'] = $protocolversion >= 14.0 && (!isset($collection["BodyPreference"][1]) && 
    	    						  											 		   !isset($collection["BodyPreference"][2]) && 
        															 		 			   !isset($collection["BodyPreference"][3]) && 
        															 		 			   !isset($collection["BodyPreference"][4]));

					if ($collection['onlyoptionbodypreference'] === false) {
	        		    $waitimporter = false;
	        		    $exporter = $backend->GetExporter($collection["collectionid"]);
		        	    $ret = $exporter->Config($waitimporter, $class, $filtertype, $state, BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false),(isset($collection["optionfoldertype"]) ? $collection[$collection["optionfoldertype"]]["BodyPreference"] : false));

		                // stop heartbeat if exporter can not be configured (e.g. after Zarafa-server restart)
	    	    	    if ($ret === false ) {
    	    	    		debugLog("HandleSync: Sync Wait/Heartbeat error: Exporter can not be configured. Waiting 30 seconds before sync is retried.");
							debugLog($collection["collectionid"]);
            				sleep(30);
	        		    }

	    	    	    $changecount = $exporter->GetChangeCount();

	        	        if (($changecount > 0)) {
    	        			debugLog("HandleSync: Found ".$changecount." change(s) in folder ".$SyncCache['folders'][$collection["collectionid"]]['displayname']);
	    	        		$dataavailable = true;
    	    				$collections[$i]["getchanges"] = true;
	        		    }

		        	    // Discard any data
	    	    	    while(is_array($exporter->Synchronize()));
						usleep(500000);
					}

					if (isset($collection['optionfoldertype'])) {
	        		    $waitimporter = false;
		    		    $state = $collection[$collection['optionfoldertype'].'syncstate'];
	        		    $exporter = $backend->GetExporter($collection["collectionid"]);
		        	    $ret = $exporter->Config($waitimporter, $collection['optionfoldertype'], $optionfiltertype, $state, BACKEND_DISCARD_DATA, 0, false, $collection[$collection["optionfoldertype"]]["BodyPreference"]);

		                // stop heartbeat if exporter can not be configured (e.g. after Zarafa-server restart)
	    	    	    if ($ret === false ) {
    	    	    		debugLog("HandleSync: Sync Wait/Heartbeat error: Exporter can not be configured. Waiting 30 seconds before sync is retried.");
							debugLog("HandleSync: optionfoldertype: ".$collection["collectionid"]);
            				sleep(30);
	        		    }

	    	    	    $changecount = $exporter->GetChangeCount();

	        	        if (($changecount > 0)) {
    	        			debugLog("HandleSync: Found ".$changecount." change(s) for optionfoldertype in folder ".$SyncCache['folders'][$collection["collectionid"]]['displayname']);
	    	        		$dataavailable = true;
    	    				$collections[$i]["getchanges"] = true;
	        		    }

		        	    // Discard any data
	    	    	    while(is_array($exporter->Synchronize()));
						usleep(500000);
					}
   				}
   			}

    	    if($dataavailable) {
    			debugLog("HandleSync: Found change");
        		break;
    	    }

		    // Check for folder Updates
		    $hierarchychanged = false;
		    if ($hierarchystate >= 0 &&
        		!(strlen($hierarchystate) == 0) && 
				!(bin2hex(substr($hierarchystate,4,4)) == "00000000")) {
				unset($exporter);
				$exporter = $backend->GetExporter();
				$waitimporter = false;
				$exporter->Config($waitimporter, false, false, $hierarchystate, BACKEND_DISCARD_DATA, 0, false, false);
				if ($exporter->GetChangeCount() > 0) {
				    $hierarchychanged = true;
				}

    			while(is_array($exporter->Synchronize()));

				if ($hierarchychanged) {
	           	    debugLog("HandleSync: Found hierarchy changes during Wait/Heartbeat Interval... Sending status ".SYNC_STATUS_FOLDER_HIERARCHY_CHANGED." to get changes (STATUS = ".SYNC_STATUS_FOLDER_HIERARCHY_CHANGED.")");
				    _HandleSyncError(SYNC_STATUS_FOLDER_HIERARCHY_CHANGED);
				    return true;
				}
		    } else {
            	debugLog("HandleSync: Error in Syncstate during Wait/Heartbeat Interval... Sending status ".SYNC_STATUS_FOLDER_HIERARCHY_CHANGED." to enforce hierarchy sync (STATUS = ".SYNC_STATUS_FOLDER_HIERARCHY_CHANGED.")");
				_HandleSyncError(SYNC_STATUS_FOLDER_HIERARCHY_CHANGED);
				return true;
		    }
		    // 5 seconds sleep to keep the load low...
		    sleep ($timeout);
			$hbrunthisduration = (microtime(true) - $hbrunstarttime);
			if ($hbrunavrgduration > 0)
				$hbrunavrgduration = ($hbrunavrgduration + $hbrunthisduration) / 2;
			else
				$hbrunavrgduration = $hbrunthisduration;
			if ($hbrunthisduration>$hbrunmaxduration) $hbrunmaxduration = $hbrunthisduration;
		};
		debugLog("HandleSync: Max Heartbeat run duration is ".$hbrunmaxduration);
 		debugLog("HandleSync: Average Heartbeat run duration is ".$hbrunavrgduration);

		// Even in case we found a change, better check that no other Sync already started... If so,
		// we exit here and let the other process do the export.
		$TempSyncCache = unserialize($statemachine->getSyncCache());
    	if ($TempSyncCache['timestamp'] > $SyncCache['timestamp']) {
	    	debugLog("HandleSync: Changes in cache determined during Sync Wait/Heartbeat, exiting here.");
    	    return true;
    	}
	}

    // Do a short answer to allow short sync requests
    debugLog("HandleSync: dataavailable: ".($dataavailable == true ? "Yes" : "No")." dataimported: ".($dataimported == true ? "Yes" : "No"));
    if ($protocolversion >= 12.1 &&
		$SyncStatus == SYNC_STATUS_SUCCESS &&
		$dataavailable == false &&
		$dataimported == false &&
		($SyncCache['wait'] !== false ||
		 $SyncCache['hbinterval'] !== false)) {
		debugLog("HandleSync: Doing a short reply since no data is available, no data was imported and syncstatus is ".$SyncStatus);
		$SyncCache['lastsyncendnormal'] = time();
    	$statemachine->setSyncCache(serialize($SyncCache));

		debugLog("HandleSync: Sync runtime = ".(microtime(true) - $sessionstarttime));
		return true;
    }

	// So there was either a change in heartbeat or a normal sync request.
	// Lets go through all collections and set getchanges to true in case collection has a valid synckey and it is not set at all in collection 
	// since if omitted it should be true according to spec
	// Since we don't want to get continuesly new sync keys for collections without changes we only set it to true in case a real change is being there...
	debugLog ("Looking for collections not having the getChanges option being set");
	foreach ($collections as $key=>$collection) {
		if (isset($collection['synckey']) && $collection['synckey'] != '0' &&
			!isset($collection['getchanges'])) {
	   		$state = $collection['syncstate'];
			$filtertype = (isset($collection["filtertype"]) ? $collection["filtertype"] : 
							 	 (isset($SyncCache['collections'][$collection["collectionid"]]["filtertype"]) ? $SyncCache['collections'][$collection["collectionid"]]["filtertype"] 
						 	 																			  : 0)
						  ); 
			$optionfiltertype = (isset($collection["optionfoldertype"]) ? 
					    			(isset($collection[$collection["optionfoldertype"]]["filtertype"]) ? $collection[$collection["optionfoldertype"]]["filtertype"] :
					    				(isset($SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["filtertype"]) ? $SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["filtertype"] 
					    																															  : 0)
					    			)
					    												: 0
					    		);

       		$collection['onlyoptionbodypreference'] = $protocolversion >= 14.0 && (!isset($collection["BodyPreference"][1]) && 
   	   						  											 		   !isset($collection["BodyPreference"][2]) && 
    														 		 			   !isset($collection["BodyPreference"][3]) && 
    														 		 			   !isset($collection["BodyPreference"][4]));
			if ($collection['onlyoptionbodypreference'] === false) {
       		    $waitimporter = false;
       		    $exporter = $backend->GetExporter($collection['collectionid']);

	       	    $ret = $exporter->Config($waitimporter, $collection['class'], $filtertype, $state, BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false),(isset($collection["optionfoldertype"]) ? $collection[$collection["optionfoldertype"]]["BodyPreference"] : false));

        	    $changecount = $exporter->GetChangeCount();

       	        if (($changecount > 0)) {
            		$dataavailable = true;
   	   				$collections[$key]['getchanges'] = true;
       		    }
       			debugLog("HandleSync: Found ".$changecount." change(s) for foldertype in folder ".$SyncCache['folders'][$collection["collectionid"]]['displayname']);

	       	    // Discard any data
        	    while(is_array($exporter->Synchronize()));
			}
			// just take care about the optionfoldertype in case the main folder class a no changes...
			if (isset($collection['optionfoldertype']) &&
				!isset($collections[$key]['getchanges'])) {
      		    $waitimporter = false;
	  		    $state = $collection[$collection['optionfoldertype'].'syncstate'];
      		    $exporter = $backend->GetExporter($collection['collectionid']);
	      	    $ret = $exporter->Config($waitimporter, $collection['optionfoldertype'], $optionfiltertype, $state, BACKEND_DISCARD_DATA, 0, false, $collection[$collection["optionfoldertype"]]["BodyPreference"]);

        	    $changecount = $exporter->GetChangeCount();
       			debugLog("HandleSync: Found ".$changecount." change(s) for optionfoldertype in folder ".$SyncCache['folders'][$collection["collectionid"]]['displayname']);

       	        if (($changecount > 0)) {
	        		$dataavailable = true;
 	   				$collections[$key]["getchanges"] = true;
       		    }
        	    // Discard any data
   	    	    while(is_array($exporter->Synchronize()));
			}
		}
   	}

    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->startWBXML();

	$answerstarttime = microtime(true);
    $encoder->startTag(SYNC_SYNCHRONIZE);
    {
		if ($protocolversion >= 14.0) {
			$encoder->startTag(SYNC_STATUS);
			$encoder->content($SyncStatus);
			$encoder->endTag();
		}
        $encoder->startTag(SYNC_FOLDERS);
        {
            foreach($collections as $collection) {
			    // START ADDED dw2412 Protocol Version 12 Support
			    if (isset($collection["BodyPreference"])) $encoder->_bodypreference = $collection["BodyPreference"];
			    // END ADDED dw2412 Protocol Version 12 Support

                // Get a new sync key to output to the client if any changes have been requested or have been sent
                if (isset($collection["importedchanges"]) || (isset($collection["getchanges"]) && $collection["getchanges"] != 0) || $collection["synckey"] == "0") {
                    $collection["newsynckey"] = $statemachine->getNewSyncKey($collection["synckey"]);
					debugLog("HandleSync: New Synckey generated because importedchanges: ".isset($collection["importedchanges"]). " getchanges: ". isset($collection["getchanges"]) . " initialsync: " . ($collection["synckey"] == "0"));
				}

				$folderstatus=1;
				// dw2412 ensure that no older exporter definition exists and could be used
				// figthing against that some folder get content of another folder...
				if (isset($exporter)) unset($exporter);
				if (isset($optionexporter)) unset($optionexporter);
                if ((isset($collection["getchanges"]) &&
                	 $collection["getchanges"] != 0)) {
                    // Try to get the exporter. In case it is not possible (i.e. folder removed) set
                    // status according. 
                    $exporter = $backend->GetExporter($collection["collectionid"]);
		    		debugLog("HandleSync: Exporter Value: ".is_object($exporter). " " .(isset($exporter->exporter) ? $exporter->exporter : ""));
		    		if (isset($collection['optionfoldertype'])){
						$optionexporter = $backend->GetExporter($collection["collectionid"]);
		    			debugLog("HandleSync: OptionExporter Value: ".is_object($optionexporter). " " .(isset($optionexporter->exporter) ? $optionexporter->exporter : ""));
		    		}
            	    if ((isset($exporter->exporter) && $exporter->exporter === false) ||
            	    	(isset($optionexporter->exporter) && $optionexporter->exporter === false)) {
            			$folderstatus = SYNC_STATUS_OBJECT_NOT_FOUND;
            	    }
                };

                $encoder->startTag(SYNC_FOLDER);
    			// FolderType/Class is only being returned by AS up to 12.0. 
				// In 12.1 it could break the sync.
				if (isset($collection["class"]) &&
				    $protocolversion <= 12.0) {
            	    $encoder->startTag(SYNC_FOLDERTYPE);
            	    $encoder->content($collection["class"]);
            	    $encoder->endTag();
                }

                $encoder->startTag(SYNC_SYNCKEY);

                if(isset($collection["newsynckey"]))
                    $encoder->content($collection["newsynckey"]);
                else
                    $encoder->content($collection["synckey"]);

                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERID);
                $encoder->content($collection["collectionid"]);
                $encoder->endTag();

                $encoder->startTag(SYNC_STATUS);
                $encoder->content($folderstatus);
                $encoder->endTag();

                //check the mimesupport because we need it for advanced emails
                $mimesupport = isset($collection['mimesupport']) ? $collection['mimesupport'] : 0;

                // Output server IDs for new items we received from the PDA
                if(isset($collection["clientids"]) || (isset($collection["fetchids"]) && count($collection["fetchids"]) > 0) || isset($collection["changeids"])) {
                    $encoder->startTag(SYNC_REPLIES);
/*					if (isset($collection["changeids"])) {
	                    foreach($collection["changeids"] as $serverid => $servervals) {
    	                    $encoder->startTag(SYNC_MODIFY);
							if (isset($servervals['optionfoldertype'])) {
		    	          	    $encoder->startTag(SYNC_FOLDERTYPE);
							    $encoder->content($collection['optionfoldertype']);
			    				$encoder->endTag();
							}
	                        $encoder->startTag(SYNC_SERVERENTRYID);
    	                    $encoder->content($serverid);
        	                $encoder->endTag();
            	            $encoder->startTag(SYNC_STATUS);
                	        $encoder->content($servervals['status']);
                    	    $encoder->endTag();
                        	$encoder->endTag();
	                    }
					}
*/    	            foreach($collection["clientids"] as $clientid => $servervals) {
                        $encoder->startTag(SYNC_ADD);
						if (isset($clientid['optionfoldertype']) && is_array($servervals['serverid'])) {
		              	    $encoder->startTag(SYNC_FOLDERTYPE);
						    $encoder->content($collection['optionfoldertype']);
			    			$encoder->endTag();
						}
                        $encoder->startTag(SYNC_CLIENTENTRYID);
                        $encoder->content($clientid);
                        $encoder->endTag();
						if(is_array($servervals['serverid'])) {
                    	    $encoder->startTag(SYNC_SERVERENTRYID);
                    	    $encoder->content($servervals['serverid']['sourcekey']);
                    	    $encoder->endTag();
                        } else {
                    	    $encoder->startTag(SYNC_SERVERENTRYID);
                    	    $encoder->content($servervals['serverid']);
                    	    $encoder->endTag();
                        }
                        $encoder->startTag(SYNC_STATUS);
                        $encoder->content(1);
                        $encoder->endTag();
						if (is_array($servervals['serverid'])) {
                    	    $encoder->startTag(SYNC_DATA);
                    	    $encoder->startTag(SYNC_POOMMAIL2_CONVERSATIONID);
                    	    $encoder->contentopaque($servervals['serverid']['convid']);
                    	    $encoder->endTag();
                    	    $encoder->startTag(SYNC_POOMMAIL2_CONVERSATIONINDEX);
                    	    $encoder->contentopaque($servervals['serverid']['convidx']);
                    	    $encoder->endTag();
                    	    $encoder->endTag();
						}
                        $encoder->endTag();
                    }
                    foreach($collection["fetchids"] as $id) {
						// CHANGED dw2412 to support bodypreference
                        $data = $backend->Fetch($collection["collectionid"], $id, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false), (isset($collection["optionfoldertype"]) ? $collection[$collection["optionfoldertype"]]["BodyPreference"] : false), $mimesupport);
                        if($data !== false) {
                            $encoder->startTag(SYNC_FETCH);
                            $encoder->startTag(SYNC_SERVERENTRYID);
                            $encoder->content($id);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_STATUS);
                            $encoder->content(1);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_DATA);
                            $data->encode($encoder);
                            $encoder->endTag();
                            $encoder->endTag();
                        } else {
                            debugLog("HandleSync: unable to fetch $id");
                        }
                    }
                    $encoder->endTag();
                }

                if ((isset($collection["getchanges"]) &&
                	 $collection["getchanges"] != 0) ||
                	isset($collection["readids"]) ||
                	isset($collection["flagids"]) ||
                	(isset($collection['optionfoldertype']) &&
	               	 (isset($collection[$collection['optionfoldertype']."readids"]) ||
                 	  isset($collection[$collection['optionfoldertype']."flagids"])))) {
                    // Use the state from the importer, as changes may have already happened

					$filtertype = (isset($collection["filtertype"]) ? $collection["filtertype"] : 
									 	 (isset($SyncCache['collections'][$collection["collectionid"]]["filtertype"]) ? $SyncCache['collections'][$collection["collectionid"]]["filtertype"]
							    																						: 0)
							  		);
					$optionfiltertype = (isset($collection["optionfoldertype"]) ? 
							    			(isset($collection[$collection["optionfoldertype"]]["filtertype"]) ? $collection[$collection["optionfoldertype"]]["filtertype"] :
							    				(isset($SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["filtertype"]) ? $SyncCache['collections'][$collection["collectionid"]][$collection["optionfoldertype"]]["filtertype"] 
							    																																: 0)
							    			)
							    			: 0
							    		);

					debugLog("HandleSync: FilterType GetChanges : ".$filtertype. " ".$optionfiltertype);
					$changecount = 0;

					if ($collection['onlyoptionbodypreference'] === false) {
    	            	$exporter = $backend->GetExporter($collection["collectionid"]);
						debugLog("HandleSync: Messageclass for Export: ".$collection["class"]);

	            		$exporter->Config($importer[$collection["collectionid"]], $collection["class"], $filtertype, $collection['syncstate'], 0, $collection["truncation"],$collection["BodyPreference"], false, (isset($collection["mimesupport"]) ? $collection['mimesupport'] : 0));

    	            	$changecount = $exporter->GetChangeCount();
    	            }

					// Optionfoldertype
					if (isset($collection['optionfoldertype'])) {
            	    	$optionexporter = $backend->GetExporter($collection["collectionid"]);
						debugLog("HandleSync: Messageclass for Export: ".$collection["optionfoldertype"]);
            			$optionexporter->Config($importer[$collection['optionfoldertype'].$collection["collectionid"]], $collection['optionfoldertype'], $optionfiltertype, $collection[$collection['optionfoldertype'].'syncstate'], 0, 9, false, $collection[$collection["optionfoldertype"]]["BodyPreference"], (isset($collection["mimesupport"]) ? $collection['mimesupport'] : 0));

	                	$changecount = $changecount + $optionexporter->GetChangeCount();
					}

                    debugLog("HandleSync: Changecount vs maxitems: ".$changecount." ".$collection["maxitems"]);
            		if($changecount > $collection["maxitems"]) {
                		$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
            		}

            		// Output message changes per folder
            		$encoder->startTag(SYNC_PERFORM);

	                $n = 0;

	                // Stream the changes to the PDA
	
					if ($collection['onlyoptionbodypreference'] === false) {
						$ids = array("readids" => (isset($collection["readids"]) ? $collection["readids"]: array()),
								     "flagids" => (isset($collection["flagids"]) ? $collection["flagids"]: array()));
						$importer[$collection["collectionid"]] = new ImportContentsChangesStream($encoder, GetObjectClassFromFolderClass($collection["class"]), $ids, $msginfos[$collection["collectionid"]]);

		                while(1) {
    		               	$progress = $exporter->Synchronize();
	        	           	if(!is_array($progress))
	            	           	break;
	                	   	if ($importer[$collection["collectionid"]]->_lastObjectStatus == 1) 
	                   			$n++;
							debugLog("HandleSync: _lastObjectStatus = ".$importer[$collection["collectionid"]]->_lastObjectStatus);

    	                   	if ($n >= $collection["maxitems"]) {
	    	               		debugLog("HandleSync: Exported maxItems of messages: ". $collection["maxitems"] . " - more available");
	        	           		break;
	                   		}
		                }
						$msginfos[$collection["collectionid"]] = $importer[$collection["collectionid"]]->_msginfos;
					}

					if (isset($collection['optionfoldertype'])) {
						$ids = array("readids" => (isset($collection[$collection['optionfoldertype']."readids"]) ? $collection[$collection['optionfoldertype']."readids"]: array()),
								     "flagids" => (isset($collection[$collection['optionfoldertype']."flagids"]) ? $collection[$collection['optionfoldertype']."flagids"]: array()));
						$importer[$collection['optionfoldertype'].$collection["collectionid"]] = new ImportContentsChangesStream($encoder, GetObjectClassFromFolderClass($collection["optionfoldertype"]), $ids, $msginfos[$collection["collectionid"]]);

		                while(1) {
    		               	$progress = $optionexporter->Synchronize();
	    	               	if(!is_array($progress))
	        	               	break;
	            	       	if ($importer[$collection['optionfoldertype'].$collection["collectionid"]]->_lastObjectStatus == 1) 
	                	   		$n++;
							debugLog("HandleSync: _lastObjectStatus = ".$importer[$collection['optionfoldertype'].$collection["collectionid"]]->_lastObjectStatus);

	                       	if ($n >= $collection["maxitems"]) {
		                   		debugLog("HandleSync: Exported maxItems of messages: ". $collection["maxitems"] . " - more available");
	    	               		break;
	        	           	}
	            	    }
						$msginfos[$collection["collectionid"]] = $importer[$collection['optionfoldertype'].$collection["collectionid"]]->_msginfos;
					}
// START HACK: CURRENT ICS EXPORTER DOES NOT PROVIDE READ STATE AND FLAG UPDATES IF SEND FROM DEVICE. THIS WE DO HERE JUST BECAUSE OF THIS!
					if ($collection['onlyoptionbodypreference'] === false) {
						$array_rf = array_unique(
										array_merge(
											array_keys((isset($importer[$collection["collectionid"]]) ? $importer[$collection["collectionid"]]->_readids : array())),
										    array_keys((isset($importer[$collection["collectionid"]]) ? $importer[$collection["collectionid"]]->_flagids : array()))
										    ));
			    		debugLog("HandleSync: After Exporting Changes we still have following array_rf in importer: ".print_r($array_rf,true));
						$class = GetObjectClassFromFolderClass($collection["class"]);
						foreach ($array_rf as $rfid) {
					        $encoder->startTag(SYNC_MODIFY);
							if (!isset($msginfos[$collection["collectionid"]][$rfid])) {
								unset($importer[$collection["collectionid"]]->_readids[$rfid]);
								unset($importer[$collection["collectionid"]]->_flagids[$rfid]);
							    $encoder->startTag(SYNC_SERVERENTRYID);
							    	$encoder->content($rfid);
							    $encoder->endTag();
            	            	$encoder->startTag(SYNC_STATUS);
									$encoder->content(SYNC_STATUS_OBJECT_NOT_FOUND);
                    	    	$encoder->endTag();
                    	    	$encoder->endTag();
								continue;
						    }
							if ($msginfos[$collection["collectionid"]][$rfid]['class'] != $class) continue;
						    $encoder->startTag(SYNC_SERVERENTRYID);
						    	$encoder->content($rfid);
						    $encoder->endTag();

						    $encoder->startTag(SYNC_DATA);
				    			if (isset($importer[$collection["collectionid"]]->_readids[$rfid]) &&
				        			$importer[$collection["collectionid"]]->_readids[$rfid]['status'] == true) {
									$encoder->startTag(SYNC_POOMMAIL_READ);
					    			$encoder->content($importer[$collection["collectionid"]]->_readids[$rfid]['data']);
						    		$encoder->endTag();
									unset($importer[$collection["collectionid"]]->_readids[$rfid]);
							    }
							    if (isset($importer[$collection["collectionid"]]->_flagids[$rfid]) &&
				    		    	$importer[$collection["collectionid"]]->_flagids[$rfid]['status'] == true) {
									if (!isset($importer[$collection["collectionid"]]->_flagids[$rfid]['data']->flagstatus)||
										$importer[$collection["collectionid"]]->_flagids[$rfid]['data']->flagstatus == 0 || 
										$importer[$collection["collectionid"]]->_flagids[$rfid]['data']->flagstatus == "") {
							    		$encoder->startTag(SYNC_POOMMAIL_FLAG,false,true);
									} else {
									    $encoder->startTag(SYNC_POOMMAIL_FLAG);
						        	    $importer[$collection["collectionid"]]->_flagids[$rfid]['data']->encode($importer[$collection["collectionid"]]->_encoder);
							   		    $encoder->endTag();
									}
									unset($importer[$collection["collectionid"]]->_flagids[$rfid]);
							    }
						    $encoder->endTag();
					    	$encoder->endTag();
						}
						unset($array_rf);
						$array_rf = array_keys(
										array_merge(
											(isset($importer[$collection["collectionid"]]) ? $importer[$collection["collectionid"]]->_readids : array()),
											(isset($importer[$collection["collectionid"]]) ? $importer[$collection["collectionid"]]->_flagids : array())
									 ));
					    debugLog("HandleSync: After manual export of read and flag changes we still have following array_rf in importer: ".print_r($array_rf,true));
						unset($array_rf);
					};
					if (isset($collection["optionfoldertype"])) {
						$array_rf = array_unique(
										array_merge(
										    array_keys(isset($importer[$collection["optionfoldertype"].$collection["collectionid"]]) ? $importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids : array()),
										    array_keys(isset($importer[$collection["optionfoldertype"].$collection["collectionid"]]) ? $importer[$collection["optionfoldertype"].$collection["collectionid"]]->_readids : array())
										    ));
				    	debugLog("HandleSync: After Exporting Changes we still have following array_rf in importer for optionfoldertype: ".print_r($array_rf,true));
						$class = GetObjectClassFromFolderClass($collection["optionfoldertype"]);
						foreach ($array_rf as $rfid) {
					        $encoder->startTag(SYNC_MODIFY);

						    $encoder->startTag(SYNC_FOLDERTYPE);
						    $encoder->content($collection["optionfoldertype"]);
				    	    $encoder->endTag();
							if (!isset($msginfos[$collection["collectionid"]][$rfid])) {
								unset($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_readids[$rfid]);
								unset($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]);
							    $encoder->startTag(SYNC_SERVERENTRYID);
							    	$encoder->content($rfid);
							    $encoder->endTag();
            	            	$encoder->startTag(SYNC_STATUS);
									$encoder->content(SYNC_STATUS_OBJECT_NOT_FOUND);
                    	    	$encoder->endTag();
                    	    	$encoder->endTag();
								continue;
						    }
							if ($msginfos[$collection["collectionid"]][$rfid]['class'] != $class) continue;
						    $encoder->startTag(SYNC_SERVERENTRYID);
						    	$encoder->content($rfid);
						    $encoder->endTag();
						    $encoder->startTag(SYNC_DATA);
					    		if (isset($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_readids[$rfid]) &&
					        		$importer[$collection["optionfoldertype"].$collection["collectionid"]]->_readids[$rfid]['status'] == true) {
									$encoder->startTag(SYNC_POOMMAIL_READ);
					    			$encoder->content($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_readids[$rfid]['data']);
						    		$encoder->endTag();
									unset($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_readids[$rfid]);
							    }
							    if (isset($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]) &&
					    	    	$importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]['status'] == true) {
									if (!isset($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]['data']->flagstatus)||
										$importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]['data']->flagstatus == 0 || 
										$importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]['data']->flagstatus == "") {
								    	$encoder->startTag(SYNC_POOMMAIL_FLAG,false,true);
									} else {
								    	$encoder->startTag(SYNC_POOMMAIL_FLAG);
						        	    $importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]['data']->encode($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_encoder);
							   		    $encoder->endTag();
									}
									unset($importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids[$rfid]);
							    }
						    $encoder->endTag();
					    	$encoder->endTag();
						}
						unset($array_rf);
						$array_rf = array_keys(
										array_merge(
											$importer[$collection["optionfoldertype"].$collection["collectionid"]]->_readids,
											$importer[$collection["optionfoldertype"].$collection["collectionid"]]->_flagids
									 ));
					    debugLog("HandleSync: After manual export of read and flag changes we still have following array_rf in importer for optionfoldertype: ".print_r($array_rf,true));
						unset($array_rf);
					}

// END HACK: CURRENT ICS EXPORTER DOES NOT PROVIDE READ STATE AND FLAG UPDATES IF SEND FROM DEVICE. THIS WE DO HERE JUST BECAUSE OF THIS!
		           	$encoder->endTag();
				}
		        $encoder->endTag();
	            // Save the sync state for the next time
    	        if(isset($collection["newsynckey"])) {
					unset($state);
					unset($optionstate);
		            if (isset($exporter) && $exporter)
	    	            $state = $exporter->GetState();

		            // nothing exported, but possible imported
		            else if (isset($importer[$collection["collectionid"]]) && $importer[$collection["collectionid"]])
	    	            $state = $importer[$collection["collectionid"]]->GetState();

		            // if a new request without state information (hierarchy) save an empty state
		            else if ($collection["synckey"] == "0")
	    	            $state = "";

		            if (isset($optionexporter) && $optionexporter)
	    	            $optionstate = $optionexporter->GetState();

		            // nothing exported, but possible imported
		            else if (isset($collection['optionfoldertype']))
	    	            $optionstate = $importer[$collection['optionfoldertype'].$collection["collectionid"]]->GetState();

		            // if a new request without state information (hierarchy) save an empty state
		            else if ($collection["synckey"] == "0")
	    	            $state = "";

		            if (isset($state)) 
		              	$statemachine->setSyncState($collection["newsynckey"], $state);
	    	        else debugLog("HandleSync: error saving " . $collection["newsynckey"] . " - no state information available");

		            if (isset($optionstate) && isset($collection['optionfoldertype'])) 
		              	$statemachine->setSyncState($collection['optionfoldertype'].$collection["newsynckey"], $optionstate);
	    	        else if(isset($collection['optionfoldertype'])) debugLog("HandleSync: error saving " . $collection['optionfoldertype'].$collection["newsynckey"] . " - no state information available");

	    	        if (trim($collection['newsynckey']) != trim($collection['synckey'])) {
    		        	debugLog("HandleSync: Current Synckey: ".$collection['synckey']." New Synckey: ".$collection['newsynckey']);
    		        	$SyncCache['confirmed_synckeys'][$collection['newsynckey']] = true;
						$statemachine->setSyncState('mi'.$collection['newsynckey'],(isset($msginfos[$collection['collectionid']]) ? serialize($msginfos[$collection['collectionid']]) : serialize(array())));
    	    	    }
	            }
	        	if (isset($collection['collectionid'])) {
			    	if (isset($collection['newsynckey']))
						$SyncCache['collections'][$collection['collectionid']]['synckey'] = $collection['newsynckey'];
				    else
						$SyncCache['collections'][$collection['collectionid']]['synckey'] = $collection['synckey'];
				    if (isset($collection['class'])) 			$SyncCache['collections'][$collection['collectionid']]['class'] 			= $collection['class'];
				    if (isset($collection['maxitems'])) 		$SyncCache['collections'][$collection['collectionid']]['maxitems'] 			= $collection['maxitems'];
			    	if (isset($collection['deletesasmoves']))	$SyncCache['collections'][$collection['collectionid']]['deletesasmoves'] 	= $collection['deletesasmoves'];
			    	if (isset($collection['conversationmode']))	$SyncCache['collections'][$collection['collectionid']]['conversationmode'] 	= $collection['conversationmode'];
					if (isset($SyncCache['collections'][$collection['collectionid']]['getchanges'])) unset($SyncCache['collections'][$collection['collectionid']]['getchanges']);
				    if (isset($collection['filtertype'])) 		$SyncCache['collections'][$collection['collectionid']]['filtertype']		= $collection['filtertype'];
				    if (isset($collection['truncation'])) 		$SyncCache['collections'][$collection['collectionid']]['truncation'] 		= $collection['truncation'];
				    if (isset($collection['rtftruncation'])) 	$SyncCache['collections'][$collection['collectionid']]['rtftruncation'] 	= $collection['rtftruncation'];
				    if (isset($collection['mimesupport'])) 		$SyncCache['collections'][$collection['collectionid']]['mimesupport'] 		= $collection['mimesupport'];
				    if (isset($collection['mimetruncation'])) 	$SyncCache['collections'][$collection['collectionid']]['mimetruncation'] 	= $collection['mimetruncation'];
				    if (isset($collection['conflict'])) 		$SyncCache['collections'][$collection['collectionid']]['conflict'] 			= $collection['conflict'];
				    if (isset($collection['BodyPreference'])) 	$SyncCache['collections'][$collection['collectionid']]['BodyPreference'] 	= $collection['BodyPreference'];
				    if (isset($collection['optionfoldertype'])) {
				    	$SyncCache['collections'][$collection['collectionid']]['optionfoldertype'] 	= $collection['optionfoldertype'];
					    if (isset($collection[$collection['optionfoldertype']]['filtertype'])) 			$SyncCache['collections'][$collection['collectionid']][$collection['optionfoldertype']]['filtertype']		= $collection[$collection['optionfoldertype']]['filtertype'];
					    if (isset($collection[$collection['optionfoldertype']]['BodyPreference'])) 		$SyncCache['collections'][$collection['collectionid']][$collection['optionfoldertype']]['BodyPreference']	= $collection[$collection['optionfoldertype']]['BodyPreference'];
				    }
				};
	        }
	    }
        $encoder->endTag();
    }
    $encoder->endTag();
	debugLog("HandleSync: Answer prepare duration run ".(microtime(true) - $answerstarttime));

    $TempSyncCache = unserialize($statemachine->getSyncCache());
    if (isset($SyncCache['timestamp']) &&
		$TempSyncCache['timestamp'] > $SyncCache['timestamp']) {
		debugLog("HandleSync: Changes in cache determined during Sync Wait/Heartbeat, exiting here. SyncCache not updated!");
		debugLog("HandleSync: Sync runtime = ".(microtime(true) - $sessionstarttime));
    	return true;
    } else {
		$SyncCache['lastsyncendnormal'] = time();
		$statemachine->setSyncCache(serialize($SyncCache));
		debugLog("HandleSync: Sync runtime = ".(microtime(true) - $sessionstarttime));
    }

    return true;
}

function HandleGetItemEstimate($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;
	global $user;

    $collections = array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    // Init state machine
    $statemachine = new StateMachine($devid,$user);

    $SyncCache = unserialize($statemachine->getSyncCache());
    
    // Check the validity of the sync cache. If state is errornous set the syncstatus to 2 as retval for client
    $syncstatus=1;

    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
        return false;

    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
        return false;

    while($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
        $collection = array();

		unset($class);
		unset($filtertype);
		unset($synckey);
		$conversationmode = false;
		while (($type = ($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE)  ? SYNC_GETITEMESTIMATE_FOLDERTYPE 	:
			    		($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)	? SYNC_GETITEMESTIMATE_FOLDERID 	:
						($decoder->getElementStartTag(SYNC_FILTERTYPE)	  				? SYNC_FILTERTYPE 					:
						($decoder->getElementStartTag(SYNC_SYNCKEY)	  					? SYNC_SYNCKEY 						:
						($decoder->getElementStartTag(SYNC_CONVERSATIONMODE)	 		? SYNC_CONVERSATIONMODE 			:
						($decoder->getElementStartTag(SYNC_OPTIONS)						? SYNC_OPTIONS 						:
						-1))))))) != -1) {
		    switch ($type) {
				case SYNC_GETITEMESTIMATE_FOLDERTYPE :  
					$class = $decoder->getElementContent();
				    if(!$decoder->getElementEndTag())
			    	    return false;
				    break;
				case SYNC_GETITEMESTIMATE_FOLDERID :  
					$collectionid = $decoder->getElementContent();
				    if(!$decoder->getElementEndTag())
				        return false;
				    break;
				case SYNC_FILTERTYPE : 
					$filtertype = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
				case SYNC_SYNCKEY : 
					$synckey = $decoder->getElementContent();
			        if(!$decoder->getElementEndTag())
			            return false;
			        break;
				case SYNC_CONVERSATIONMODE : 
					if(($conversationmode = $decoder->getElementContent()) !== false) {
		    	    if(!$decoder->getElementEndTag())
			        	return false;
			        } else {
			    	    $conversationmode = true;
			        }
			        break;
				case SYNC_OPTIONS :
					unset($options);
					while (($typeoptions = 	($decoder->getElementStartTag(SYNC_FOLDERTYPE)	? SYNC_FOLDERTYPE 	:
											($decoder->getElementStartTag(SYNC_MAXITEMS)	? SYNC_MAXITEMS 	:
											($decoder->getElementStartTag(SYNC_FILTERTYPE)	? SYNC_FILTERTYPE 	:
											-1)))) != -1) {
						switch ($typeoptions) {
						    case SYNC_FOLDERTYPE : 
								$options['foldertype'] = $decoder->getElementContent();
						        if(!$decoder->getElementEndTag())
						            return false;
						        break;
						    case SYNC_MAXITEMS : 
								if (isset($options['foldertype']))
									$options[$options['foldertype']]['maxitems'] = $decoder->getElementContent();
								else
									$options['maxitems'] = $decoder->getElementContent();
						        if(!$decoder->getElementEndTag())
						            return false;
						        break;
						    case SYNC_FILTERTYPE : 
								if (isset($options['foldertype']))
									$options[$options['foldertype']]['filtertype'] = $decoder->getElementContent();
								else
									$options['filtertype'] = $decoder->getElementContent();
						        if(!$decoder->getElementEndTag())
						            return false;
						        break;
						};
				    };
					if(!$decoder->getElementEndTag()) // END Options
						return false;
		    };
		};
		if(!$decoder->getElementEndTag()) // END Folder
           	return false;


        // compatibility mode - get folderid from the state directory
        if (!isset($collectionid)) {
            $collectionid = _getFolderID($devid, $class);
        }

		if ($protocolversion >= 12.1 && !isset($class)) {
		    $class = $SyncCache['folders'][$collectionid]['class'];
		} else if ($protocolversion >= 12.1)  {
		    $SyncCache['folders'][$collectionid]['class'] = $class;
		}
		if ($protocolversion >= 12.1 && !isset($options['filtertype']) && isset($SyncCache['collections'][$collectionid]['filtertype'])) {
		    debugLog("filtertype not set! SyncCache Result ".$SyncCache['collections'][$collectionid]['filtertype']);
		    $options['filtertype'] = $SyncCache['collections'][$collectionid]['filtertype'];
		} else if ($protocolversion >= 12.1 && isset($options['filtertype']))  {
		    $SyncCache['collections'][$collectionid]['filtertype'] = $options['filtertype'];
		}
		if ($protocolversion >= 12.1 && isset($options['foldertype']) && !isset($options[$options['foldertype']]['filtertype']) && isset($SyncCache['collections'][$collectionid][$options['foldertype']]['filtertype'])) {
		    debugLog("filtertype not set! SyncCache Result ".$SyncCache['collections'][$collectionid][$options['foldertype']]['filtertype']);
		    $options[$options['foldertype']]['filtertype'] = $SyncCache['collections'][$collectionid][$options['foldertype']]['filtertype'];
		} else if ($protocolversion >= 12.1 && isset($options['foldertype']) && isset($options[$options['foldertype']]['filtertype']))  {
		    $SyncCache['collections'][$collectionid][$options['foldertype']]['filtertype'] = $options[$options['foldertype']]['filtertype'];
		}
		if ($protocolversion >= 12.1 && !isset($synckey) && isset($SyncCache['collections'][$collectionid]['synckey'])) {
		    $synckey = $SyncCache['collections'][$collectionid]['synckey'];
		} else if ($protocolversion >= 12.1 && isset($synckey)) {
		    $SyncCache['collections'][$collectionid]['synckey'] = $synckey;
		}
		if ($protocolversion >= 12.1 && !isset($conversationmode) && isset($SyncCache['collections'][$collectionid]['conversationmode'])) {
		    $conversationmode = $SyncCache['collections'][$collectionid]['conversationmode'];
		} else if ($protocolversion >= 12.1 && isset($conversationmode)) {
		    $SyncCache['collections'][$collectionid]['conversationmode'] = $conversationmode;
		}


        $collection = array();
        $collection["synckey"] = $synckey;
    	$collection["class"] = $class;
		if (isset($filtertype))
	        $collection["filtertype"] = $filtertype;
        $collection["collectionid"] = $collectionid;
		$collection["options"] = $options;

        array_push($collections, $collection);
    }

    if (!$decoder->getElementEndTag()) // END Folders
		return false;

	if(!$decoder->getElementEndTag()) // END GetItemEstimate
		return false;

    $encoder->startWBXML();

    $encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
    {
        foreach($collections as $collection) {
            $encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
            {
                $importer = new ImportContentsChangesMem();

                $statemachine = new StateMachine($devid,$user);

				$syncstatus = 1;
				$changecount = 0;
				$optionchangecount = 0;

				if (isset($collection["options"]["filtertype"]) ||
					isset($collection["filtertype"])) {
		            $syncstate = $statemachine->getSyncState($collection["synckey"]);
					$statemachine->cleanOldSyncState($collection["synckey"]);

					if (is_numeric($syncstate) &&
					    $syncstate < 0 &&
					    strlen($syncstate) < 8) {
					    debugLog("GetSyncState: Got an error in HandleGetItemEstimate");
					    $syncstate = false;
					    if ($collection["synckey"] != '0') $syncstatus = 2;
					    else $syncstatus=4;
					} 

	                $exporter = $backend->GetExporter($collection["collectionid"]);
	                $exporter->Config($importer, $collection["class"], (isset($collection["options"]["filtertype"]) ? $collection["options"]["filtertype"]: $collection["filtertype"]), $syncstate, 0, 0, false, false);

					$changecount = $exporter->GetChangeCount();
					if ($changecount === false) {
					    $syncstatus=2;
					}
				}
				// we currently add the optionfoldertype changecount to collection changecount since it is not better documented in specs!
				// todo: validate with future open protocol specification!
				if (isset($collection['options']['foldertype'])) {
					$optionsyncstatus = 1;
		            $optionsyncstate = $statemachine->getSyncState($collection["synckey"]);
					$statemachine->cleanOldSyncState($collection['options']['foldertype'].$collection["synckey"]);
					if (is_numeric($optionsyncstate) &&
					    $optionsyncstate < 0 &&
					    strlen($optionsyncstate) < 8) {
					    debugLog("GetSyncState: Got an error in HandleGetItemEstimate");
					    $optionsyncstate = false;
					    if ($collection["synckey"] != '0') $optionsyncstatus = 2;
				    	else $optionsyncstatus=4;
					}

	                $optionexporter = $backend->GetExporter($collection["collectionid"]);
    	            $optionexporter->Config($importer, $collection['options']['foldertype'], $collection['options'][$collection['options']['foldertype']]['filtertype'], $optionsyncstate, 0, 0, false, false);
					$optionchangecount = $optionexporter->GetChangeCount();
					if ($optionchangecount === false) {
					    $optionsyncstatus=2;
					} else {
						$changecount = $changecount + $optionchangecount;
					}
					if ($syncstatus == 1 && $optionsyncstatus != 1) {
						$syncstatus = $optionsyncstatus;
					}
				}

				$statemachine->cleanOldSyncState("mi".$collection["synckey"]);

                $encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
   	            $encoder->content($syncstatus);
                $encoder->endTag();

				$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
				{
				    if ($protocolversion <= 12.0) {
				        $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
						debugLog("Collection Class is ".$collection["class"]);
	                	$encoder->content($collection["class"]);
	            		$encoder->endTag();
				    };

                    $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                    $encoder->content($collection["collectionid"]);
                    $encoder->endTag();

                    $encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);
                    $encoder->content($changecount);
                    $encoder->endTag();
                }
                $encoder->endTag();

            }
            $encoder->endTag();
        }
    }
    $encoder->endTag();
    $TempSyncCache = unserialize($statemachine->getSyncCache());
    if (isset($SyncCache['timestamp']) &&
		$TempSyncCache['timestamp'] > $SyncCache['timestamp']) {
		debugLog("HandleSync: Changes in cache determined during Sync Wait/Heartbeat, exiting here. SyncCache not updated!");
    	return true;
    } else {
		$statemachine->setSyncCache(serialize($SyncCache));
    }

    return true;
}

function HandleGetAttachment($backend, $protocolversion) {
    $attname = $_GET["AttachmentName"];

    if(!isset($attname))
        return false;

    header("Content-Type: application/octet-stream");

    $backend->GetAttachmentData($attname);

    return true;
}

function _HandlePingError($errorcode, $limit = false) {
    global $zpushdtd;
    global $output;

    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->StartWBXML();
    $encoder->startTag(SYNC_PING_PING);
    $encoder->startTag(SYNC_PING_STATUS);
    $encoder->content($errorcode);
    $encoder->endTag();
    if ($limit !== false) {
		$encoder->startTag(SYNC_PING_LIFETIME);
		$encoder->content($limit);
		$encoder->endTag();
    }
    $encoder->endTag();
}

function HandlePing($backend, $devid) {
    global $zpushdtd, $input, $output;
    global $user, $auth_pw;
    $timeout = 10;

    debugLog("Ping received");

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $collections = array();
    $lifetime = 0;
	$timestamp = time();

    // Get previous defaults if they exist
    $file = STATE_PATH . "/" . strtolower($devid) . "/". $devid;
    if (file_exists($file)) {
        $ping = unserialize(file_get_contents($file));
        $collections = $ping["collections"];
        $lifetime = $ping["lifetime"];
    	file_put_contents(STATE_PATH . "/" . strtolower($devid). "/" . $devid, serialize(array("lifetime" => $lifetime, "timestamp" => time(), "collections" => $collections)));
    }

    if($decoder->getElementStartTag(SYNC_PING_PING)) {
        debugLog("Ping init");
        if($decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
            $lifetime = $decoder->getElementContent();
            $decoder->getElementEndTag();
        }

        if($decoder->getElementStartTag(SYNC_PING_FOLDERS)) {
            // avoid ping init if not necessary
            $saved_collections = $collections;

            $collections = array();

            while($decoder->getElementStartTag(SYNC_PING_FOLDER)) {
                $collection = array();

				// moxier tasks for apple sends both values in a different order. thats why it was changed here to do it in flexible way
                while (($type = ($decoder->getElementStartTag(SYNC_PING_SERVERENTRYID) ? SYNC_PING_SERVERENTRYID :
				                ($decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)    ? SYNC_PING_FOLDERTYPE :
				                -1))) != -1) {
					switch ($type) {
						case SYNC_PING_SERVERENTRYID :
							if (($collection["serverid"] = $decoder->getElementContent()) != false) {
						        if(!$decoder->getElementEndTag())
				    		        return false;
							}
							break;
						case SYNC_PING_FOLDERTYPE :
							if (($collection["class"] = $decoder->getElementContent()) != false) {
						        if(!$decoder->getElementEndTag())
						            return false;
							}
							break;
					}
                }

				// if we don't find either class or serverid send back error 3 to ask device for a full request
				if (!isset($collection["class"]) ||
					!isset($collection["serverid"])) {
					_HandlePingError("3");
					return false;
				}
                $decoder->getElementEndTag();

                // initialize empty state
                $collection["state"] = "";

                // try to find old state in saved states
                foreach ($saved_collections as $saved_col) {
                    if ($saved_col["serverid"] == $collection["serverid"] && $saved_col["class"] == $collection["class"]) {
                        $collection["state"] = $saved_col["state"];
                        debugLog("reusing saved state for ". $collection["class"]);
                        break;
                    }
                }

                if ($collection["state"] == "")
                    debugLog("empty state for ". $collection["class"]);

                // Create start state for this collection
                $exporter = $backend->GetExporter($collection["serverid"]);
                $importer = false;
                $exporter->Config($importer, false, false, $collection["state"], BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false), (isset($collection["optionfoldertype"]) ? $collection[$collection["optionfoldertype"]]["BodyPreference"] : false));
                while(is_array($exporter->Synchronize()));
                $collection["state"] = $exporter->GetState();
                array_push($collections, $collection);
            }

            if(!$decoder->getElementEndTag())
                return false;
        }

        if(!$decoder->getElementEndTag())
            return false;
    }

    $changes = array();
    $dataavailable = false;

    if ($lifetime < 60) {
		_HandlePingError("5","60");
		debugLog("Lifetime lower than 60 Seconds. This violates the protocol spec. (STATUS = 5, LIMIT min = 60)");
		return true;
    }
    if ($lifetime > (REAL_SCRIPT_TIMEOUT-600)) {
		_HandlePingError("5",(REAL_SCRIPT_TIMEOUT-600));
		debugLog("Lifetime larger than ".(REAL_SCRIPT_TIMEOUT-600)." Seconds. This violates the protocol spec. (STATUS = 5, LIMIT max = ".(REAL_SCRIPT_TIMEOUT-600).")");
		return true;
    }

    debugLog("Waiting for changes... (lifetime $lifetime)");
    // Wait for something to happen
	$pingrunavrgduration = 0;
	$pingrunmaxduration = 0;
	$until = time() + $lifetime;
    $n=0;
    while ((time()+$pingrunavrgduration) < ($until-$pingrunmaxduration)) {
		$n++;
		//check if there is a new ping request running...
		$pingrunstarttime = microtime(true);
 		if (file_exists($file)) {
        	$ping = unserialize(file_get_contents($file));
			if ($ping['timestamp'] > $timestamp) {
				debugLog("Another Ping is running. Tell the device that we don't have changes (just in case connection is still alive) and return.");
			    $encoder->StartWBXML();
			    $encoder->startTag(SYNC_PING_PING);
		        $encoder->startTag(SYNC_PING_STATUS);
            	$encoder->content("1");
		        $encoder->endTag();
        		$encoder->endTag();
				return true;
			}
        }

        //check the remote wipe status
        if (PROVISIONING === true) {
		    $rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);
		    if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
	    	    //return 7 because it forces folder sync
	        	$pingstatus = 7;
		        break;
		    }
        }

        if(count($collections) == 0) {
            $error = 1;
            break;
        }

        for($i=0;$i<count($collections);$i++) {
            $collection = $collections[$i];

            $exporter = $backend->GetExporter($collection["serverid"]);
            $state = $collection["state"];
            $importer = false;
            $ret = $exporter->Config($importer, false, false, $state, BACKEND_DISCARD_DATA, 0, (isset($collection["BodyPreference"]) ? $collection["BodyPreference"] : false), (isset($collection["optionfoldertype"]) ? $collection[$collection["optionfoldertype"]]["BodyPreference"] : false), false);

            // stop ping if exporter can not be configured (e.g. after Zarafa-server restart)
            if ($ret === false ) {
                // force "ping" to stop
                $n = $lifetime / $timeout;
                debugLog("Ping error: Exporter can not be configured. Waiting 30 seconds before ping is retried.");
                sleep(30);
                break;
            }

            $changecount = $exporter->GetChangeCount();

            if($changecount > 0) {
                $dataavailable = true;
                $changes[$collection["serverid"]] = $changecount;
            }

            // Discard any data
            while(is_array($exporter->Synchronize()));

            // Record state for next Ping
            $collections[$i]["state"] = $exporter->GetState();
        }

        if($dataavailable) {
            debugLog("Found change");
            break;
        }

        sleep($timeout);
		$pingrunthisduration = (microtime(true) - $pingrunstarttime);
		if ($pingrunavrgduration > 0)
			$pingrunavrgduration = ($pingrunavrgduration + $pingrunthisduration) / 2;
		else
			$pingrunavrgduration = $pingrunthisduration;
		if ($pingrunthisduration>$pingrunmaxduration) $pingrunmaxduration = $pingrunthisduration;
    }
	debugLog("HandlePing: Max Ping run duration is ".$pingrunmaxduration);
	debugLog("HandlePing: Average Ping run duration is ".$pingrunavrgduration);

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_PING_PING);
    {
        $encoder->startTag(SYNC_PING_STATUS);
        if(isset($error))
            $encoder->content(3);
        elseif (isset($pingstatus))
            $encoder->content($pingstatus);
        else
            $encoder->content(count($changes) > 0 ? 2 : 1);
        $encoder->endTag();

        $encoder->startTag(SYNC_PING_FOLDERS);
        foreach($collections as $collection) {
            if(isset($changes[$collection["serverid"]])) {
                $encoder->startTag(SYNC_PING_FOLDER);
                $encoder->content($collection["serverid"]);
                $encoder->endTag();
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the ping request state for this device
    file_put_contents(STATE_PATH . "/" . strtolower($devid). "/" . $devid, serialize(array("lifetime" => $lifetime, "timestamp" => $timestamp, "collections" => $collections)));

    return true;
}

function HandleSendMail($backend, $protocolversion) {
    // All that happens here is that we receive an rfc822 message on stdin
    // and just forward it to the backend. We provide no output except for
    // an OK http reply
    global $zpushdtd;
    global $input, $output;

    $data['task'] = 'new';
	// dw2412 Backend should return proper status.
	// For Protocolversion <AS14 everything that is not true results in 400 Bad Request header
	// For Protocolversion >=AS14
	// 115 = SendQuotaExceeded
	// 116 = MessageRecipientUnresolved
	// 117 = MessageReplyNotAllowed
	// 118 = MessagePreviouslySent
	// 119 = MessageHasNoRecipient
	// 120 = MailSubmissionFailed
	// 121 = MessageReplyFailed
    $result = 1;
	// With AS14.0 it is possible to get messages from device in WBXML encoded form.
    if($protocolversion >= 14.0) {
		$decoder = new WBXMLDecoder($input, $zpushdtd);
		// Since HTC sends AS14.0 Protocol Version but behaves in sendmail as if it is <AS14.0 Protocol in use I compare WBXML Protocol Version and in case it is higher than 0x03 use the
		// old sendmail protocol algorythm
		if ($decoder->version > 0x03) {
            $rfc822 = $decoder->_inputRaw.readStream($input);
			$result = $backend->SendMail($rfc822, $data, $protocolversion);
			// dw2412: we return Bad Request in case mail could not be send
			// TODO: Maybe another status will mention that mail could not be send.
	    	if ($result !== true) {
			    header("HTTP/1.1 400 Bad Request");
    		}
		} else {
			$encoder = new WBXMLEncoder($output, $zpushdtd);

			$mime=false;
    	    if(!$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SENDMAIL)) 
			    $result = 102;

			while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SAVEINSENTITEMS) 	? SYNC_COMPOSEMAIL_SAVEINSENTITEMS :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_CLIENTID) 		? SYNC_COMPOSEMAIL_CLIENTID :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_MIME)			? SYNC_COMPOSEMAIL_MIME :
							-1)))) != -1 &&
							$result == 1) {
			    switch ($tag) {
					case SYNC_COMPOSEMAIL_SAVEINSENTITEMS : 
						if ($data['saveinsentitems'] = $decoder->getElementContent()) {
							$decoder->getElementEndTag();
						} else {
							$e = $decoder->peek();
							if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
								$decoder->getElementEndTag();
							}
					    	$data['saveinsentitems'] = true;
						}
					    break;
					case SYNC_COMPOSEMAIL_CLIENTID :
    				    $data['clientid'] = $decoder->getElementContent();
	    			    if(!$decoder->getElementEndTag())
							$result = 102;
    				    break;
					case SYNC_COMPOSEMAIL_MIME :
					    $mime = $decoder->getElementContent();
	                    if(!$decoder->getElementEndTag())
					        $result = 102;
	   				    break;
		    	}
			}
			if ($mime === false) 
		   	    $result = 102;
			if (!isset($data['clientid'])) 
			    $result = 103;

	        if(!$decoder->getElementEndTag()) // End Sendmail
			    $result = 102;

			$rfc822 = $mime;
			if ($result == 1)
			    $result = $backend->SendMail($rfc822, $data, $protocolversion);
		    $encoder->startWBXML();
			$encoder->startTag(SYNC_COMPOSEMAIL_SENDMAIL);
			$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
	        $encoder->content(($result === true ? "1" : $result));
	        $encoder->endTag();
    	    $encoder->endTag();
		}
    } else {
        $rfc822 = readStream($input);
		$result = $backend->SendMail($rfc822, $data, $protocolversion);
		// dw2412: we return Bad Request in case mail could not be send
		// TODO: Maybe another status will mention that mail could not be send.
    	if ($result !== true) {
		    header("HTTP/1.1 400 Bad Request");
    	}
    };

    return true;
}

function HandleSmartForward($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    // SmartForward is a normal 'send' except that you should attach the
    // original message which is specified in the URL

    $data['task'] = 'forward';
	// dw2412 Backend should return proper status.
	// For Protocolversion <AS14 everything that is not true results in 400 Bad Request header
	// For Protocolversion >=AS14
	// 115 = SendQuotaExceeded
	// 116 = MessageRecipientUnresolved
	// 117 = MessageReplyNotAllowed
	// 118 = MessagePreviouslySent
	// 119 = MessageHasNoRecipient
	// 120 = MailSubmissionFailed
	// 121 = MessageReplyFailed
    $result = 1;
    if($protocolversion >= 14.0) {
		$decoder = new WBXMLDecoder($input, $zpushdtd);
		// Since HTC sends AS14.0 Protocol Version but behaves in sendmail as if it is <AS14.0 Protocol in use I compare WBXML Protocol Version and in case it is higher than 0x03 use the
		// old sendmail protocol algorythm
		if ($decoder->version > 0x03) {
            $rfc822 = $decoder->_inputRaw.readStream($input);

			if(isset($_GET["LongId"]))
	            $data['longid'] = $_GET["LongId"];
	        else
    	        $data['longid'] = false;

			if(isset($_GET["ItemId"]))
		   	    $data['itemid'] = $_GET["ItemId"];
		    else
			    $data['itemid'] = false;

		    if(isset($_GET["CollectionId"]))
			    $data['folderid'] = $_GET["CollectionId"];
		    else
			    $data['folderid'] = false;

			$result = $backend->SendMail($rfc822, $data, $protocolversion);
			// dw2412: we return Bad Request in case mail could not be send
			// TODO: Maybe another status will mention that mail could not be send.
	    	if ($result !== true) {
			    header("HTTP/1.1 400 Bad Request");
    		}
		} else {
			$encoder = new WBXMLEncoder($output, $zpushdtd);

			$mime = false;
	        if(!$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SMARTFORWARD))
			    $result = 102;
			while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SAVEINSENTITEMS) 	? SYNC_COMPOSEMAIL_SAVEINSENTITEMS :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_CLIENTID) 			? SYNC_COMPOSEMAIL_CLIENTID :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_MIME)				? SYNC_COMPOSEMAIL_MIME :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_REPLACEMIME)			? SYNC_COMPOSEMAIL_REPLACEMIME :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SOURCE)				? SYNC_COMPOSEMAIL_SOURCE :
							-1)))))) != -1 &&
							$result == 1) {
			    switch ($tag) {
					case SYNC_COMPOSEMAIL_SAVEINSENTITEMS : 
						if ($data['saveinsentitems'] = $decoder->getElementContent()) {
							$decoder->getElementEndTag();
						} else {
							$e = $decoder->peek();
							if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
								$decoder->getElementEndTag();
							}
					    	$data['saveinsentitems'] = true;
						}
					    break;
					case SYNC_COMPOSEMAIL_CLIENTID :
		    		    $data['clientid'] = $decoder->getElementContent();
		    		    if(!$decoder->getElementEndTag())
							$result = 102;
		    		    break;
					case SYNC_COMPOSEMAIL_MIME :
					    $mime = $decoder->getElementContent();
	                       if(!$decoder->getElementEndTag())
							$result = 102;
		    		    break;
					case SYNC_COMPOSEMAIL_SOURCE :
					    while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_FOLDERID) 	? SYNC_COMPOSEMAIL_FOLDERID :
										($decoder->getElementStartTag(SYNC_COMPOSEMAIL_ITEMID) 		? SYNC_COMPOSEMAIL_ITEMID :
										($decoder->getElementStartTag(SYNC_COMPOSEMAIL_LONGID)		? SYNC_COMPOSEMAIL_LONGID :
										($decoder->getElementStartTag(SYNC_COMPOSEMAIL_INSTANCEID)	? SYNC_COMPASEMAIL_INSTANCEID :
										-1))))) != -1 &&
									    $result == 1) {
							switch ($tag) {
							    case SYNC_COMPOSEMAIL_FOLDERID :
								    $data['folderid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
										$result = 102;
								    break;
							    case SYNC_COMPOSEMAIL_ITEMID :
								    $data['itemid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
										$result = 102;
								    break;
							    case SYNC_COMPOSEMAIL_LONGID :
								    $data['longid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
										$result = 102;
								    break;
							    case SYNC_COMPOSEMAIL_INSTANCEID :
								    $data['instanceid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
										$result = 102;
								    break;
							}
			    		}
						if ((isset($data['folderid']) && !isset($data['itemid'])) ||
							(!isset($data['folderid']) && isset($data['itemid'])) ||
							(isset($data['longid']) && (isset($data['folderid']) || isset($data['itemid']) || isset($data['instanceid']))))
							$result = 103;
	    			    if(!$decoder->getElementEndTag()) // End Source
							$result = 102;
					    break;
					case SYNC_COMPOSEMAIL_REPLACEMIME :
					    $data['replacemime'] = true;
					    break;
			    }
			}

			if (!isset($data['longid']) &&
				isset($_GET['LongId'])) $data['longid'] = $_GET['LongId'];

			if (!isset($data['itemid']) &&
				isset($_GET['ItemId'])) $data['itemid'] = $_GET['ItemId'];

			if (!isset($data['folderid']) &&
				isset($_GET['CollectionId'])) $data['itemid'] = $_GET['CollectionId'];

			if ($mime === false) 
		        $result = 102;
			if (!isset($data['clientid'])) 
			    $result = 103;

	        if(!$decoder->getElementEndTag()) // End SmartForward
			    $result = 102;

			$rfc822 = $mime;
			if ($result == 1)
			    $result = $backend->SendMail($rfc822, $data, $protocolversion);
    	    $encoder->startWBXML();
			$encoder->startTag(SYNC_COMPOSEMAIL_SMARTFORWARD);
			$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
	        $encoder->content(($result === true ? "1" : $result));
	        $encoder->endTag();
	        $encoder->endTag();
		}
	} else {
		if (isset($_GET['LongId'])) 
			$data['longid'] = $_GET['LongId'];
		else 
			$data['longid'] = false;

		if(isset($_GET["ItemId"]))
	   	    $data['itemid'] = $_GET["ItemId"];
	    else
		    $data['itemid'] = false;

	    if(isset($_GET["CollectionId"]))
		    $data['folderid'] = $_GET["CollectionId"];
	    else
		    $data['folderid'] = false;

        $rfc822 = readStream($input);
		$result = $backend->SendMail($rfc822, $data, $protocolversion);
		// dw2412: we return Bad Request in case mail could not be send
		// TODO: Maybe another status will mention that mail could not be send.
    	if ($result !== true) {
		    header("HTTP/1.1 400 Bad Request");
   		}
	};

    return true;
}

function HandleSmartReply($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    // Smart reply should add the original message to the end of the message body

    // In some way there could be a header in XML and not only in _GET...

    $data['task'] = 'reply';    
    $data['replacemime'] = false;
	// dw2412 Backend should return proper status.
	// For Protocolversion <AS14 everything that is not true results in 400 Bad Request header
	// For Protocolversion >=AS14
	// 115 = SendQuotaExceeded
	// 116 = MessageRecipientUnresolved
	// 117 = MessageReplyNotAllowed
	// 118 = MessagePreviouslySent
	// 119 = MessageHasNoRecipient
	// 120 = MailSubmissionFailed
	// 121 = MessageReplyFailed
    $result = 1;
    if($protocolversion >= 14.0) {
		$decoder = new WBXMLDecoder($input, $zpushdtd);
		// Since HTC sends AS14.0 Protocol Version but behaves in sendmail as if it is <AS14.0 Protocol in use I compare WBXML Protocol Version and in case it is higher than 0x03 use the
		// old sendmail protocol algorythm
		if ($decoder->version > 0x03) {
            $rfc822 = $decoder->_inputRaw.readStream($input);

			if(isset($_GET["LongId"]))
	            $data['longid'] = $_GET["LongId"];
	        else
    	        $data['longid'] = false;

			if(isset($_GET["ItemId"]))
	            $data['itemid'] = $_GET["ItemId"];
	        else
    	        $data['itemid'] = false;

	        if(isset($_GET["CollectionId"]))
			    $data['folderid'] = $_GET["CollectionId"];
	        else
			    $data['folderid'] = false;

			$result = $backend->SendMail($rfc822, $data, $protocolversion);
			// dw2412: we return Bad Request in case mail could not be send
			// TODO: Maybe another status will mention that mail could not be send.
		    if ($result !== true) {
			    header("HTTP/1.1 400 Bad Request");
    		}
		} else {
			$encoder = new WBXMLEncoder($output, $zpushdtd);

			$mime = false;
	        if(!$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SMARTREPLY))
			    $result = 102;
			while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SAVEINSENTITEMS) 	? SYNC_COMPOSEMAIL_SAVEINSENTITEMS :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_CLIENTID) 		? SYNC_COMPOSEMAIL_CLIENTID :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_MIME)			? SYNC_COMPOSEMAIL_MIME :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_REPLACEMIME)		? SYNC_COMPOSEMAIL_REPLACEMIME :
							($decoder->getElementStartTag(SYNC_COMPOSEMAIL_SOURCE)			? SYNC_COMPOSEMAIL_SOURCE :
							-1)))))) != -1 &&
				$result == 1) {
			    switch ($tag) {
					case SYNC_COMPOSEMAIL_SAVEINSENTITEMS : 
						if ($data['saveinsentitems'] = $decoder->getElementContent()) {
							$decoder->getElementEndTag();
						} else {
							$e = $decoder->peek();
							if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
								$decoder->getElementEndTag();
							}
					    	$data['saveinsentitems'] = true;
						}
					    break;
					case SYNC_COMPOSEMAIL_CLIENTID :
		    		    $data['clientid'] = $decoder->getElementContent();
		    		    if(!$decoder->getElementEndTag())
							$result = 102;
		   			    break;
					case SYNC_COMPOSEMAIL_MIME :
					    $mime = $decoder->getElementContent();
		                if(!$decoder->getElementEndTag())
							$result = 102;
		   			    break;
					case SYNC_COMPOSEMAIL_SOURCE :
					    while (($tag = 	($decoder->getElementStartTag(SYNC_COMPOSEMAIL_FOLDERID) 	? SYNC_COMPOSEMAIL_FOLDERID :
										($decoder->getElementStartTag(SYNC_COMPOSEMAIL_ITEMID) 		? SYNC_COMPOSEMAIL_ITEMID :
										($decoder->getElementStartTag(SYNC_COMPOSEMAIL_LONGID)		? SYNC_COMPOSEMAIL_LONGID :
										($decoder->getElementStartTag(SYNC_COMPOSEMAIL_INSTANCEID)	? SYNC_COMPASEMAIL_INSTANCEID :
										-1))))) != -1 &&
									    $result == 1) {
							switch ($tag) {
							    case SYNC_COMPOSEMAIL_FOLDERID :
								    $data['folderid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
			    						$result = 102;
								    break;
							    case SYNC_COMPOSEMAIL_ITEMID :
								    $data['itemid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
			    						$result = 102;
								    break;
							    case SYNC_COMPOSEMAIL_LONGID :
								    $data['longid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
			    						$result = 102;
									    break;
							    case SYNC_COMPOSEMAIL_INSTANCEID :
								    $data['instanceid'] = $decoder->getElementContent();
								    if(!$decoder->getElementEndTag())
			    						$result = 102;
								    break;
							}
					    }
					    if ((isset($data['folderid']) && !isset($data['itemid'])) ||
							(!isset($data['folderid']) && isset($data['itemid'])) ||
							(isset($data['longid']) && (isset($data['folderid']) || isset($data['itemid']) || isset($data['instanceid']))))
							$result = 103;
		   			    if(!$decoder->getElementEndTag()) // End Source
	    					$result = 102;
					    break;
					case SYNC_COMPOSEMAIL_REPLACEMIME :
					    $data['replacemime'] = true;
					    break;
			    }
			}
			if (!isset($data['longid']) &&
				isset($_GET['LongId'])) $data['longid'] = $_GET['LongId'];

			if (!isset($data['itemid']) &&
				isset($_GET['ItemId'])) $data['itemid'] = $_GET['ItemId'];

			if (!isset($data['folderid']) &&
				isset($_GET['CollectionId'])) $data['itemid'] = $_GET['CollectionId'];


			if ($mime === false) 
	    	    $result = 102;

			if (!isset($data['clientid'])) 
		    	$result = 103;

	        if(!$decoder->getElementEndTag()) // End SmartReply
			    $result = 102;

			$rfc822 = $mime;
			if ($result == 1)
		    	$result = $backend->SendMail($rfc822, $data, $protocolversion);
	    	$encoder->startWBXML();
			$encoder->startTag(SYNC_COMPOSEMAIL_SMARTREPLY);
			$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
		    $encoder->content(($result === true ? "1" : $result));
	        $encoder->endTag();
	        $encoder->endTag();
		}
	} else {
		if (isset($_GET['LongId'])) 
			$data['longid'] = $_GET['LongId'];
		else 
			$data['longid'] = false;

		if(isset($_GET["ItemId"]))
            $data['itemid'] = $_GET["ItemId"];
        else
            $data['itemid'] = false;

        if(isset($_GET["CollectionId"]))
	    $data['folderid'] = $_GET["CollectionId"];
        else
	    $data['folderid'] = false;

		$rfc822 = readStream($input);
		$result = $backend->SendMail($rfc822, $data, $protocolversion);
		// dw2412: we return Bad Request in case mail could not be send
		// TODO: Maybe another status will mention that mail could not be send.
    	if ($result !== true) {
		    header("HTTP/1.1 400 Bad Request");
   		}
    }

    return true;
}

function HandleFolderCreate($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
	global $user;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $el = $decoder->getElement();

    if($el[EN_TYPE] != EN_TYPE_STARTTAG)
        return false;

    $create = $update = $delete = false;

    if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERCREATE)
        $create = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERUPDATE)
        $update = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERDELETE)
        $delete = true;

    if(!$create && !$update && !$delete)
        return false;

    // SyncKey
    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;
    $synckey = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    // ServerID
    $serverid = false;
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID)) {
        $serverid = $decoder->getElementContent();
        if(!$decoder->getElementEndTag())
            return false;
    }

    // when creating or updating more information is necessary
    if (!$delete) {
	    // Parent
	    $parentid = false;
	    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_PARENTID)) {
	        $parentid = $decoder->getElementContent();
	        if(!$decoder->getElementEndTag())
	            return false;
	    }
	
	    // Displayname
	    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_DISPLAYNAME))
	        return false;
	    $displayname = $decoder->getElementContent();
	    if(!$decoder->getElementEndTag())
	        return false;
	
	    // Type
	    $type = false;
	    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_TYPE)) {
	        $type = $decoder->getElementContent();
	        if(!$decoder->getElementEndTag())
	            return false;
	    }
    }

    if(!$decoder->getElementEndTag())
        return false;

    // Get state of hierarchy
    $statemachine = new StateMachine($devid,$user);
    $syncstate = $statemachine->getSyncState($synckey);
    if (is_numeric($syncstate) &&
		$syncstate < 0 &&
		strlen($syncstate) < 8) {
		debugLog("GetSyncState: Got an error in HandleGetFolderCreate - syncstate");
		$syncstate = false;
    } 
    $newsynckey = $statemachine->getNewSyncKey($synckey);

    // additional information about already seen folders
    $seenfolders = $statemachine->getSyncState("s".$synckey);
    if ($synckey != "0" &&
		is_numeric($seenfolders) &&
		$seenfolders < 0) {
		debugLog("GetSyncState: Got an error in HandleGetFolderCreate - seenfolders");
		$seenfolders = false;
    } 
    $seenfolders = unserialize($seenfolders);;
    if (!$seenfolders) $seenfolders = array();

    if ($synckey != "0") {
		$statemachine->cleanOldSyncState("s".$synckey);
		$statemachine->cleanOldSyncState($synckey);
    }

    // get the foldercache from synccache
    $foldercache = unserialize($statemachine->getSyncCache());
    if (!$delete && !$create) {
		debugLog("Here1 folder create serverid: ".$serverid." type: ".$type." displayname: ".$displayname." parentid: ".$parentid);
		if (!isset($serverid) || $serverid === false) 
			return false;
		if ($type === false && isset($foldercache['folders'][$serverid]['type'])) 
		    $type = $foldercache['folders'][$serverid]['type'];
		if ($displayname === false && isset($foldercache['folders'][$serverid]['displayname'])) 
		    $displayname = $foldercache['folders'][$serverid]['displayname'];
		if ($parentid === false && isset($foldercache['folders'][$serverid]['parentid'])) 
		    $parentid = $foldercache['folders'][$serverid]['parentid'];
		if ($type === false || $displayname === false || $parentid === false) 
			return false;
		debugLog("Here2 folder create serverid: ".$serverid." type: ".$type." displayname: ".$displayname." parentid: ".$parentid);
    }
    // Configure importer with last state
    $importer = $backend->GetHierarchyImporter();
    $importer->Config($syncstate);

    if (!$delete) {
	    // Send change
	    $serverid = $importer->ImportFolderChange($serverid, $parentid, $displayname, $type);

	    // add the folderinfo to synccache
	    $statemachine->updateSyncCacheFolder($foldercache, $serverid, $parentid, $displayname, $type);

    } else {
    	// delete folder
    	$deletedstat = $importer->ImportFolderDeletion($serverid, 0);
		// remove the folder from synccache
		$statemachine->deleteSyncCacheFolder($foldercache,$serverid);
    }

    $encoder->startWBXML();
    if ($create) {
    	// add folder id to the seen folders
        $seenfolders[] = $serverid;

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERCREATE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content(1);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                $encoder->content($serverid);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
        $encoder->endTag();
    }

    elseif ($update) {

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERUPDATE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content(1);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
    }
    elseif ($delete) {

        $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERDELETE);
        {
            {
                $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                $encoder->content($deletedstat);
                $encoder->endTag();

                $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $encoder->content($newsynckey);
                $encoder->endTag();
            }
            $encoder->endTag();
        }
        
        // remove folder from the folderflags array
        if (($sid = array_search($serverid, $seenfolders)) !== false) {
            unset($seenfolders[$sid]);
            $seenfolders = array_values($seenfolders);
            debugLog("deleted from seenfolders: ". $serverid);    
        }
    }   

    $encoder->endTag();
    // Save the sync state for the next time
    $statemachine->setSyncState($newsynckey, $importer->GetState());
    $statemachine->setSyncState("s".$newsynckey, serialize($seenfolders));
    $statemachine->setSyncCache(serialize($foldercache));
    
    return true;
}

// Handle meetingresponse method
function HandleMeetingResponse($backend, $protocolversion) {
    global $zpushdtd;
    global $output, $input;

    $requests = Array();

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE))
        return false;

    while($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUEST)) {
        $req = Array();

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_USERRESPONSE)) {
            $req["response"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_FOLDERID)) {
            $req["folderid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUESTID)) {
            $req["requestid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if(!$decoder->getElementEndTag())
            return false;

        array_push($requests, $req);
    }

    if(!$decoder->getElementEndTag())
        return false;

    // Start output, simply the error code, plus the ID of the calendar item that was generated by the
    // accept of the meeting response

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE);

    foreach($requests as $req) {
        $calendarid = "";
        $ok = $backend->MeetingResponse($req["requestid"], $req["folderid"], $req["response"], $calendarid);
        $encoder->startTag(SYNC_MEETINGRESPONSE_RESULT);
            $encoder->startTag(SYNC_MEETINGRESPONSE_REQUESTID);
                $encoder->content($req["requestid"]);
            $encoder->endTag();

            $encoder->startTag(SYNC_MEETINGRESPONSE_STATUS);
                $encoder->content($ok ? 1 : 2);
            $encoder->endTag();

            if($ok) {
                $encoder->startTag(SYNC_MEETINGRESPONSE_CALENDARID);
                    $encoder->content($calendarid);
                $encoder->endTag();
            }

        $encoder->endTag();
    }

    $encoder->endTag();

    return true;
}

function HandleFolderUpdate($backend, $devid, $protocolversion) {
    return HandleFolderCreate($backend, $devid, $protocolversion);
}

function HandleFolderDelete($backend, $devid, $protocolversion) {
    return HandleFolderCreate($backend, $devid, $protocolversion);
}

function HandleProvision($backend, $devid, $protocolversion) {
    global $user, $auth_pw, $policykey;

    global $zpushdtd, $policies;
    global $output, $input;

    $status = SYNC_PROVISION_STATUS_SUCCESS;

    $phase2 = true;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_PROVISION_PROVISION))
        return false;

    //handle android remote wipe.
    if ($decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
        if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
            return false;

        $status = $decoder->getElementContent();

        if(!$decoder->getElementEndTag())
            return false;

        if(!$decoder->getElementEndTag())
            return false;
    }

    else {
		if($decoder->getElementStartTag(SYNC_SETTINGS_DEVICEINFORMATION)) {
    		if($decoder->getElementStartTag(SYNC_SETTINGS_SET)) {
				while (($field = ($decoder->getElementStartTag(SYNC_SETTINGS_MODEL) 			 ? SYNC_SETTINGS_MODEL				: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_IMEI) 				 ? SYNC_SETTINGS_IMEI 				: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_FRIENDLYNAME)		 ? SYNC_SETTINGS_FRIENDLYNAME 		: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_OS) 				 ? SYNC_SETTINGS_OS 				: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_OSLANGUAGE) 		 ? SYNC_SETTINGS_OSLANGUAGE 		: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_PHONENUMBER)		 ? SYNC_SETTINGS_PHONENUMBER 		: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_USERAGENT) 		 ? SYNC_SETTINGS_USERAGENT 			: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_MOBILEOPERATOR)	 ? SYNC_SETTINGS_MOBILEOPERATOR 	: 
								 ($decoder->getElementStartTag(SYNC_SETTINGS_ENABLEOUTBOUNDSMS)	 ? SYNC_SETTINGS_ENABLEOUTBOUNDSMS	: 
								 -1)))))))))) != -1) {
    	    	    if (($deviceinfo[$field] = $decoder->getElementContent()) !== false) {
        		        $decoder->getElementEndTag(); // end $field
		    		}
				};
				$request["set"]["deviceinformation"] = $deviceinfo;    
	     		$decoder->getElementEndTag(); // end SYNC_SETTINGS_SET
		    }
	       	$decoder->getElementEndTag(); // end SYNC_SETTINGS_DEVICEINFORMATION
		    if (isset($request["set"])) $result["set"] = $backend->setSettings($request["set"],$devid);
		}

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICIES))
            return false;

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICY))
            return false;

        if(!$decoder->getElementStartTag(SYNC_PROVISION_POLICYTYPE))
            return false;

        $policytype = $decoder->getElementContent();
// START CHANGED dw2412 Support V12.0
		if ($protocolversion >= 12.0) {
    	    if ($policytype != 'MS-EAS-Provisioning-WBXML') {
        	$status = SYNC_PROVISION_STATUS_SERVERERROR;
    	    }
    	} else {
    	    if ($policytype != 'MS-WAP-Provisioning-XML') {
	        	$status = SYNC_PROVISION_STATUS_SERVERERROR;
    	    }
        }
// END CHANGED dw2412 Support V12.0
        if(!$decoder->getElementEndTag()) //policytype
            return false;

        if ($decoder->getElementStartTag(SYNC_PROVISION_POLICYKEY)) {
            $devpolicykey = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;

            if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $status = $decoder->getElementContent();
            //do status handling
            $status = SYNC_PROVISION_STATUS_SUCCESS;

            if(!$decoder->getElementEndTag())
                return false;

            $phase2 = false;
        }

        if(!$decoder->getElementEndTag()) //policy
            return false;

        if(!$decoder->getElementEndTag()) //policies
            return false;

        if ($decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
            if(!$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $status = $decoder->getElementContent();

            if(!$decoder->getElementEndTag())
                return false;

            if(!$decoder->getElementEndTag())
                return false;
        }
    }
    if(!$decoder->getElementEndTag()) //provision
        return false;

    $encoder->StartWBXML();

    //set the new final policy key in the backend
    //in case the send one does not macht the one already in backend. If it matches, we
    //just return the already defined key. (This Helps at least the RoadSync 5.0 Client to sync...
    if ($backend->CheckPolicy($policykey,$devid) == SYNC_PROVISION_STATUS_SUCCESS) {
		debugLog("Policykey is OK! Will not generate a new one!");
	} else {
		if (!$phase2) {
    	    $policykey = $backend->generatePolicyKey();
    	    $backend->setPolicyKey($policykey, $devid);
		} else {
	    // just create a temporary key (i.e. iPhone OS4 Beta does not like policykey 0 in response)
    	    $policykey = $backend->generatePolicyKey();
		}
    }

    $encoder->startTag(SYNC_PROVISION_PROVISION);
    {

		if (isset($request)) {
	        $encoder->startTag(SYNC_SETTINGS_DEVICEINFORMATION);
	        $encoder->startTag(SYNC_SETTINGS_STATUS);
			if (!isset($result["set"]["deviceinformation"]["status"])) {
	        	$encoder->content(0);
		    } else {
	    	    $encoder->content($result["set"]["deviceinformation"]["status"]);
	    	}
	        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
	        $encoder->endTag(); // end SYNC_SETTINGS_DEVICEINFORMATION
		}

        $encoder->startTag(SYNC_PROVISION_STATUS);
            $encoder->content($status);
        $encoder->endTag();

        $encoder->startTag(SYNC_PROVISION_POLICIES);
            $encoder->startTag(SYNC_PROVISION_POLICY);

            $encoder->startTag(SYNC_PROVISION_POLICYTYPE);
                   $encoder->content($policytype);
            $encoder->endTag();

            $encoder->startTag(SYNC_PROVISION_STATUS);
                $encoder->content($status);
            $encoder->endTag();

            $encoder->startTag(SYNC_PROVISION_POLICYKEY);
                   $encoder->content($policykey);
            $encoder->endTag();

            if ($phase2) {
                $encoder->startTag(SYNC_PROVISION_DATA);
                if ($policytype == 'MS-WAP-Provisioning-XML') {
                    $encoder->content('<wap-provisioningdoc><characteristic type="SecurityPolicy"><parm name="4131" value="1"/><parm name="4133" value="1"/></characteristic></wap-provisioningdoc>');
/* dw2412 maybe we can make use of this later on in as2.5 proivsioning.
        	    <characteristic type="Registry">
					// 0 = no frequency 1 = set and take minutes from FrequencyValue
                    <characteristic type="HKLM\Comm\Security\Policy\LASSD\AE\{50C13377-C66D-400C-889E-C316FC4AB374}">
                	    <parm name="AEFrequencyType" value="1"/>
                        <parm name="AEFrequencyValue" value="3"/>
                    </characteristic>
					// Wipe after n unsuccessful password entries.
                	<characteristic type="HKLM\Comm\Security\Policy\LASSD">
                        <parm name="DeviceWipeThreshold" value="6"/>
            		</characteristic>
					// Show password reminder after n attemps
                	<characteristic type="HKLM\Comm\Security\Policy\LASSD">
                        <parm name="CodewordFrequency" value="3"/>
                	</characteristic>
					// if not send there is no PIN required
                    <characteristic type="HKLM\Comm\Security\Policy\LASSD\LAP\lap_pw">
                        <parm name="MinimumPasswordLength" value="5"/>
                    </characteristic>
					// 0 = require alphanum, 1 = require numeric, 2 = anything
                    <characteristic type="HKLM\Comm\Security\Policy\LASSD\LAP\lap_pw">
                        <parm name="PasswordComplexity" value="2"/>
                    </characteristic>
                </characteristic>
*/
                } else if ($policytype == 'MS-EAS-Provisioning-WBXML') {
				    $encoder->startTag('Provision:EASProvisionDoc');
				    $devicepasswordenable = 0;
				    $encoder->startTag('Provision:DevicePasswordEnabled');$encoder->content($devicepasswordenable);$encoder->endTag();
				    if ($devicepasswordenable == 1 || (defined('NOKIA_DETECTED') && NOKIA_DETECTED == true)) {
						$encoder->startTag('Provision:AlphanumericDevicePasswordRequired');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:PasswordRecoveryEnabled');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:MinDevicePasswordLength');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:MaxDevicePasswordFailedAttempts');$encoder->content('5');$encoder->endTag();
						$encoder->startTag('Provision:AllowSimpleDevicePassword');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:DevicePasswordExpiration',false,true); // was 0
						$encoder->startTag('Provision:DevicePasswordHistory');$encoder->content('0');$encoder->endTag();
				    }
				    $encoder->startTag('Provision:DeviceEncryptionEnabled');$encoder->content('0');$encoder->endTag();
				    $encoder->startTag('Provision:AttachmentsEnabled');$encoder->content('1');$encoder->endTag();
				    $encoder->startTag('Provision:MaxInactivityTimeDeviceLock');$encoder->content('9999');$encoder->endTag();
//				    $encoder->startTag('Provision:MaxInactivityTimeDeviceLock');$encoder->content('0');$encoder->endTag();
				    $encoder->startTag('Provision:MaxAttachmentSize');$encoder->content('5000000');$encoder->endTag();
				    if ($protocolversion >= 12.1) {
						$encoder->startTag('Provision:AllowStorageCard');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowCamera');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:RequireDeviceEncryption');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:AllowUnsignedApplications');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowUnsignedInstallationPackages');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:MinDevicePasswordComplexCharacters');$encoder->content('3');$encoder->endTag(); // was 0
						$encoder->startTag('Provision:AllowWiFi');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowTextMessaging');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowPOPIMAPEmail');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowBluetooth');$encoder->content('2');$encoder->endTag();
						$encoder->startTag('Provision:AllowIrDA');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:RequireManualSyncWhenRoaming');$encoder->content('0');$encoder->endTag(); // Set to one in case you'd like to save money...
						$encoder->startTag('Provision:AllowDesktopSync');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:MaxCalendarAgeFilter');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:AllowHTMLEmail');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:MaxEmailAgeFilter');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:MaxEmailBodyTruncationSize');$encoder->content('5000000');$encoder->endTag(); // was 5000000
						$encoder->startTag('Provision:MaxHTMLBodyTruncationSize');$encoder->content('5000000');$encoder->endTag(); // was 5000000
						$encoder->startTag('Provision:RequireSignedSMIMEMessages');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:RequireEncryptedSMIMEMessages');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:RequireSignedSMIMEAlgorithm');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:RequireEncryptedSMIMEAlgorithm');$encoder->content('0');$encoder->endTag();
						$encoder->startTag('Provision:AllowSMIMEEncryptionAlgorithmNegotiation');$encoder->content('2');$encoder->endTag(); // was 1
						$encoder->startTag('Provision:AllowSMIMESoftCerts');$encoder->content('1');$encoder->endTag(); 
						$encoder->startTag('Provision:AllowBrowser');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowConsumerEmail');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowRemoteDesktop');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:AllowInternetSharing');$encoder->content('1');$encoder->endTag();
						$encoder->startTag('Provision:UnapprovedInROMApplicationList',false,true);
//						$encoder->startTag('Provision:ApplicationName');$encoder->content('');$encoder->endTag();
						$encoder->startTag('Provision:ApprovedApplicationList',false,true);
//						$encoder->startTag('Provision:Hash');$encoder->content('');$encoder->endTag();
				    };
				    $encoder->endTag();
				} else {
                    debugLog("Wrong policy type");
                    return false;
                }

                $encoder->endTag();//data
            }
            $encoder->endTag();//policy
        $encoder->endTag(); //policies
    }
    $rwstatus = $backend->getDeviceRWStatus($user, $auth_pw, $devid);


    //wipe data if status is pending or wiped
    if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
        $encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
        $backend->setDeviceRWStatus($user, $auth_pw, $devid, SYNC_PROVISION_RWSTATUS_WIPED);
        //$rwstatus = SYNC_PROVISION_RWSTATUS_WIPED;
    }

    $encoder->endTag();//provision

    return true;
}

function ParseQuery($decoder, $subquery=NULL) {
    $query = array();
    while (($type = ($decoder->getElementStartTag(SYNC_SEARCH_AND)  		? SYNC_SEARCH_AND :
		    ($decoder->getElementStartTag(SYNC_SEARCH_OR)  		? SYNC_SEARCH_OR :
		    ($decoder->getElementStartTag(SYNC_SEARCH_EQUALTO)  	? SYNC_SEARCH_EQUALTO :
		    ($decoder->getElementStartTag(SYNC_SEARCH_LESSTHAN)  	? SYNC_SEARCH_LESSTHAN :
		    ($decoder->getElementStartTag(SYNC_SEARCH_GREATERTHAN)  	? SYNC_SEARCH_GREATERTHAN :
		    ($decoder->getElementStartTag(SYNC_SEARCH_FREETEXT)  	? SYNC_SEARCH_FREETEXT :
		    ($decoder->getElementStartTag(SYNC_FOLDERID)	  	? SYNC_FOLDERID :
		    ($decoder->getElementStartTag(SYNC_FOLDERTYPE)	  	? SYNC_FOLDERTYPE :
		    ($decoder->getElementStartTag(SYNC_DOCUMENTLIBRARY_LINKID) 	? SYNC_DOCUMENTLIBRARY_LINKID :
		    ($decoder->getElementStartTag(SYNC_POOMMAIL_DATERECEIVED)  	? SYNC_POOMMAIL_DATERECEIVED :
		    -1))))))))))) != -1) {
		switch ($type) {
		    case SYNC_SEARCH_AND 		:
		    case SYNC_SEARCH_OR  		:
		    case SYNC_SEARCH_EQUALTO	 	:
		    case SYNC_SEARCH_LESSTHAN 		:
		    case SYNC_SEARCH_GREATERTHAN	:
			    $q["op"] = $type;
			    $q["value"] = ParseQuery($decoder,true);
			    if ($subquery==true) {
					$query["subquery"][] = $q;
			    } else {
					$query[] = $q;
			    }
			    $decoder->getElementEndTag();
			    break;
		    default 			:
			    if (($query[$type] = $decoder->getElementContent()) !== false) {
					$decoder->getElementEndTag();
	    	    } else {
					$decoder->getElementStartTag(SYNC_SEARCH_VALUE);
			        $query[$type] = $decoder->getElementContent();
					switch ($type) {
				    	case SYNC_POOMMAIL_DATERECEIVED :
	    					if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z/", $query[$type], $matches)) {
    	    			    	if ($matches[1] >= 2038){
		            				$matches[1] = 2038;
	    	        				$matches[2] = 1;
        		    				$matches[3] = 18;
            						$matches[4] = $matches[5] = $matches[6] = 0;
        					    }
		        				$query[$type] = gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
		    				}
							break;
					}
					$decoder->getElementEndTag();
			    };
			    break;
		};
    };	
    return $query;	    
}

function HandleSearch($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    global $auth_user,$auth_domain,$auth_pw;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_SEARCH_SEARCH))
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_STORE))
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_NAME))
        return false;
    $searchname = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    if(!$decoder->getElementStartTag(SYNC_SEARCH_QUERY))
        return false;
    //START CHANGED dw2412 V12.0 Support
    switch (strtolower($searchname)) {
		case 'documentlibrary'  : 
			$searchquery['query'] = ParseQuery($decoder);
			break;	
		case 'mailbox'  : 
			$searchquery['query'] = ParseQuery($decoder);
			break;	
		case 'gal'	: 
			$searchquery['query'] = $decoder->getElementContent(); 
			break;
    }
    if(!$decoder->getElementEndTag())
        return false;

    if($decoder->getElementStartTag(SYNC_SEARCH_OPTIONS)) {
		$searchquerydeeptraversal = false;
		$searchqueryrebuildresults = false;
	    $searchschema = false;
		$mimesupport = 0;
	  	while (($searchoptionstag = ($decoder->getElementStartTag(SYNC_SEARCH_RANGE)  				? SYNC_SEARCH_RANGE					:
			    					($decoder->getElementStartTag(SYNC_SEARCH_DEEPTRAVERSAL)		? SYNC_SEARCH_DEEPTRAVERSAL			:
			 						($decoder->getElementStartTag(SYNC_SEARCH_REBUILDRESULTS)		? SYNC_SEARCH_REBUILDRESULTS		:
			 						($decoder->getElementStartTag(SYNC_SEARCH_USERNAME)	  			? SYNC_SEARCH_USERNAME	 			:
			 						($decoder->getElementStartTag(SYNC_SEARCH_PASSWORD)	  			? SYNC_SEARCH_PASSWORD	 			:
			 						($decoder->getElementStartTag(SYNC_SEARCH_SCHEMA)	  			? SYNC_SEARCH_SCHEMA		 		:
			 						($decoder->getElementStartTag(SYNC_MIMESUPPORT)	  				? SYNC_MIMESUPPORT		 			:
			 						($decoder->getElementStartTag(SYNC_MIMETRUNCATION)	  			? SYNC_MIMETRUNCATION	 			:
			 						($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)	? SYNC_AIRSYNCBASE_BODYPREFERENCE	:
									-1)))))))))) != -1) {
 			switch($searchoptionstag) {
 				case SYNC_SEARCH_RANGE :
					if (($searchrange = $decoder->getElementContent()))
						if(!$decoder->getElementEndTag())
							return false;
					break;
    //START ADDED dw2412 V12.0 Support
				case SYNC_SEARCH_DEEPTRAVERSAL :
					if (!($searchdeeptraversal = $decoder->getElementContent()))  
						$searchquerydeeptraversal = true;
            		else
            		    if(!$decoder->getElementEndTag())
                			return false;
            		break;
				case SYNC_SEARCH_REBUILDRESULTS :
					if (!($searchrebuildresults = $decoder->getElementContent()))  
            	    	$searchqueryrebuildresults = true;
            		else
            	    	if(!$decoder->getElementEndTag())
                			return false;
					break;
				case SYNC_SEARCH_USERNAME :
					if (!($searchqueryusername = $decoder->getElementContent()))
						return false;
	            	else
    	        	    if(!$decoder->getElementEndTag())
        		        	return false;
					break;
				case SYNC_SEARCH_PASSWORD :
	                if (!($searchquerypassword = $decoder->getElementContent()))  
	            	    return false;
	            	else
    	        	    if(!$decoder->getElementEndTag())
	    	            	return false;
					break;
				case SYNC_SEARCH_SCHEMA :
	                if (!($searchschema = $decoder->getElementContent()))  
	            	    $searchschema = true;
	            	else
	            	    if(!$decoder->getElementEndTag())
		                	return false;
					break;
           		case SYNC_MIMESUPPORT :
   					$mimesupport = $decoder->getElementContent();
       	       		if(!$decoder->getElementEndTag())
          	           	return false;
           			break;
          		case SYNC_MIMETRUNCATION :
    	   			$mimetruncation = $decoder->getElementContent();
  		    	   	if(!$decoder->getElementEndTag())
        	    	   	return false;
					break;
				case SYNC_AIRSYNCBASE_BODYPREFERENCE :
		        	$bodypreference=array();
   		    	    while(($bodypreferencefield = ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE) ? SYNC_AIRSYNCBASE_TYPE : 
  		    	    							  ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE) ? SYNC_AIRSYNCBASE_TRUNCATIONSIZE :
  		    	    							  ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW) ? SYNC_AIRSYNCBASE_PREVIEW :
   		    	    							  ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE) ? SYNC_AIRSYNCBASE_ALLORNONE :
   		    	    							  -1))))) != -1) {
   						switch($bodypreferencefield) {
	   						case SYNC_AIRSYNCBASE_TYPE :
		    		    		$bodypreference["Type"] = $decoder->getElementContent();
				     	    	if(!$decoder->getElementEndTag())
				        	    	return false;
				        	    break;
				           	case SYNC_AIRSYNCBASE_TRUNCATIONSIZE :
				    	    	$bodypreference["TruncationSize"] = $decoder->getElementContent();
						       	if(!$decoder->getElementEndTag())
				               		return false;
				               	break;
						 	case SYNC_AIRSYNCBASE_PREVIEW :
							    $bodypreference["Preview"] = $decoder->getElementContent();
						        if(!$decoder->getElementEndTag())
				    	       		return false;
				    	       	break;
							case SYNC_AIRSYNCBASE_ALLORNONE :
							    $bodypreference["AllOrNone"] = $decoder->getElementContent();
					   		    if(!$decoder->getElementEndTag())
						    		return false;
							    break;
						}
					    if (isset($bodypreference["Type"]))
							$searchbodypreference[$bodypreference["Type"]] = $bodypreference;
					}
			       	$decoder->getElementEndTag();
                	break;
			};
    //END ADDED dw2412 V12.0 Support
        }
	    if(!$decoder->getElementEndTag()) //options
    	    return false;
	}
    if(!$decoder->getElementEndTag()) //store
        return false;

    if(!$decoder->getElementEndTag()) //search
        return false;


    //START CHANGED dw2412 V12.0 Support
    switch (strtolower($searchname)) {
		case 'documentlibrary'  : 
			if (isset($searchqueryusername)) {
			    if (strpos($searchqueryusername,"\\")) {
					list($searchquery['username']['domain'],$searchquery['username']['username']) = explode("\\",$searchqueryusername);
			    } else {
					$searchquery['username'] = array('domain' => "",'username' => $searchqueryusername);
			    }
			} else {
			    $searchquery['username']['domain'] = $auth_domain;
			    $searchquery['username']['username'] = $auth_user;
			};
           	$searchquery['password'] = (isset($searchquerypassword) ? $searchquerypassword : $auth_pw);
            $searchquery['range'] = $searchrange;
          	break;
		case 'mailbox'  : 
          	$searchquery['rebuildresults'] = $searchqueryrebuildresults;
           	$searchquery['deeptraversal'] =  $searchquerydeeptraversal;
            $searchquery['range'] = $searchrange;
			break;
		case 'gal'  : 
            $searchquery['range'] = $searchrange;
			break;
	}


    //get search results from backend
	$result = $backend->getSearchResults($searchquery,$searchname);
    //END CHANGED dw2412 V12.0 Support

    $encoder->startWBXML();
    // START ADDED dw2412 Protocol Version 12 Support
    if (isset($searchbodypreference)) 
    	$encoder->_bodypreference = $searchbodypreference;
    // END ADDED dw2412 Protocol Version 12 Support

    $encoder->startTag(SYNC_SEARCH_SEARCH);


        $encoder->startTag(SYNC_SEARCH_STATUS);
        $encoder->content($result['global_search_status']);
        $encoder->endTag();

        $encoder->startTag(SYNC_SEARCH_RESPONSE);
            $encoder->startTag(SYNC_SEARCH_STORE);

                $encoder->startTag(SYNC_SEARCH_STATUS);
                $encoder->content($result['status']);
                $encoder->endTag();

				if ($result['status'] == 1) {
				// CHANGED dw2412 AS V12.0 Support (mentain single return way...)
                if (isset($result['rows']) && is_array($result['rows']) && !empty($result['rows'])) {
                    $searchtotal = count($result['rows']);
			    // CHANGED dw2412 AS V12.0 Support (honor the range in request...)
			    $range = explode("-",$searchrange);
			    $returnitems = $range[1] - $range[0];
                $returneditems=0;
		    	$result['rows'] = array_slice($result['rows'],$range[0],$returnitems+1,true);
			    // CHANGED dw2412 AS V12.0 Support (mentain single return way...)
                foreach ($result['rows'] as $u) {

			    // CHANGED dw2412 AS V12.0 Support (honor the range in request...)
               	    if ($returneditems>$returnitems) break; 
               	    $returneditems++;


				    switch (strtolower($searchname)) {
						case 'documentlibrary'  : 
                   		    $encoder->startTag(SYNC_SEARCH_RESULT);
                       		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
							$encoder->startTag(SYNC_DOCUMENTLIBRARY_LINKID);
              		    	$encoder->content($u['linkid']);
							$encoder->endTag();
							$encoder->startTag(SYNC_DOCUMENTLIBRARY_DISPLAYNAME);
                	    	$encoder->content($u['displayname']);
							$encoder->endTag();
							$encoder->startTag(SYNC_DOCUMENTLIBRARY_CREATIONDATE);
               		    	$encoder->content($u['creationdate']);
							$encoder->endTag();
							$encoder->startTag(SYNC_DOCUMENTLIBRARY_LASTMODIFIEDDATE);
               		    	$encoder->content($u['lastmodifieddate']);
							$encoder->endTag();
							$encoder->startTag(SYNC_DOCUMENTLIBRARY_ISHIDDEN);
               		    	$encoder->content($u['ishidden']);
							$encoder->endTag();
	    					$encoder->startTag(SYNC_DOCUMENTLIBRARY_ISFOLDER);
							$encoder->content($u['isfolder']);
							$encoder->endTag();
		    				if ($u['isfolder'] == "0") {
							    $encoder->startTag(SYNC_DOCUMENTLIBRARY_CONTENTLENGTH);
	           		    	    $encoder->content($u['contentlength']);
							    $encoder->endTag();
							    $encoder->startTag(SYNC_DOCUMENTLIBRARY_CONTENTTYPE);
							    $encoder->content($u['contenttype']);
							    $encoder->endTag();
					        }
                       		$encoder->endTag();//result
                   		    $encoder->endTag();//properties
						    break;
						case 'mailbox'  : 
                    	    $encoder->startTag(SYNC_SEARCH_RESULT);
                       		$encoder->startTag(SYNC_FOLDERTYPE);
                       		$encoder->content('Email');
                       		$encoder->endTag();
                       		$encoder->startTag(SYNC_SEARCH_LONGID);
                       		$encoder->content($u['uniqueid']);
                       		$encoder->endTag();
                       		$encoder->startTag(SYNC_FOLDERID);
                       		$encoder->content($u['searchfolderid']);
                       		$encoder->endTag();
                       		$encoder->startTag(SYNC_SEARCH_PROPERTIES);
				    		$msg = $backend->ItemOperationsFetchMailbox($u['uniqueid'], $searchbodypreference, $mimesupport);
						    $msg->encode($encoder);
	           		        $encoder->endTag();//properties
                       	    $encoder->endTag();//result
						    break;
						case 'gal'  : 
                   		    $encoder->startTag(SYNC_SEARCH_RESULT);
                       		$encoder->startTag(SYNC_SEARCH_PROPERTIES);

						    if (isset($u["fullname"]) && $u["fullname"] != "") {
                           		$encoder->startTag(SYNC_GAL_DISPLAYNAME);
                           		$encoder->content($u["fullname"]);
	                       		$encoder->endTag();
						    }

						    if (isset($u["phone"]) && $u["phone"] != "") {
                           		$encoder->startTag(SYNC_GAL_PHONE);
            					$encoder->content($u["phone"]);
                           		$encoder->endTag();
						    }

						    if (isset($u["homephone"]) && $u["homephone"] != "") {
                           		$encoder->startTag(SYNC_GAL_HOMEPHONE);
        	    				$encoder->content($u["homephone"]);
                           		$encoder->endTag();
						    }

						    if (isset($u["mobilephone"]) && $u["mobilephone"] != "") {
                           		$encoder->startTag(SYNC_GAL_MOBILEPHONE);
		           				$encoder->content($u["mobilephone"]);
                           		$encoder->endTag();
						    }

						    if (isset($u["company"]) && $u["company"] != "") {
                           		$encoder->startTag(SYNC_GAL_COMPANY);
        	    				$encoder->content($u["company"]);
                           		$encoder->endTag();
						    }

						    if (isset($u["office"]) && $u["office"] != "") {
                           		$encoder->startTag(SYNC_GAL_OFFICE);
                           		$encoder->content($u["office"]);
                           		$encoder->endTag();
						    }

						    if (isset($u["title"]) && $u["title"] != "") {
                       	        $encoder->startTag(SYNC_GAL_TITLE);
                       	        $encoder->content($u["title"]);
                          		$encoder->endTag();
						    }

						    if (isset($u["username"]) && $u["username"] != "") {
                           		$encoder->startTag(SYNC_GAL_ALIAS);
                           		$encoder->content($u["username"]);
                           		$encoder->endTag();
						    }

                       	    //it's not possible not get first and last name of an user
                       	    //from the gab and user functions, so we just set fullname
                       	    //to lastname and leave firstname empty because nokia needs
                       	    //first and lastname in order to display the search result
                       	    $encoder->startTag(SYNC_GAL_FIRSTNAME);
                       	    $encoder->content("");
                       	    $encoder->endTag();

                       	    $encoder->startTag(SYNC_GAL_LASTNAME);
                      	    $encoder->content($u["fullname"]);
                       	    $encoder->endTag();

                       	    $encoder->startTag(SYNC_GAL_EMAILADDRESS);
                       	    $encoder->content($u["emailaddress"]);
                    	    $encoder->endTag();

                   		    $encoder->endTag();//result
                		    $encoder->endTag();//properties
						    break;
					};
                }
                $searchrange = $range[0]."-".($range[0]+$returneditems-1);
                $encoder->startTag(SYNC_SEARCH_RANGE);
                $encoder->content($searchrange);
                $encoder->endTag();

                $encoder->startTag(SYNC_SEARCH_TOTAL);
                $encoder->content($searchtotal);
                $encoder->endTag();
                }
                };
            $encoder->endTag();//store
        $encoder->endTag();//response
    $encoder->endTag();//search


    return true;
}

// START ADDED dw2412 Settings Support
function HandleSettings($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_SETTINGS_SETTINGS))
        return false;

    $request = array();
    while (($reqtype = ($decoder->getElementStartTag(SYNC_SETTINGS_OOF) 	      		?   SYNC_SETTINGS_OOF               :
				       ($decoder->getElementStartTag(SYNC_SETTINGS_DEVICEINFORMATION)	?   SYNC_SETTINGS_DEVICEINFORMATION :
				       ($decoder->getElementStartTag(SYNC_SETTINGS_USERINFORMATION)  	?   SYNC_SETTINGS_USERINFORMATION   :
		 		       ($decoder->getElementStartTag(SYNC_SETTINGS_DEVICEPASSWORD)   	?   SYNC_SETTINGS_DEVICEPASSWORD    :
				       -1))))) != -1) {
		if($decoder->getElementStartTag(SYNC_SETTINGS_GET)) {
		    if($reqtype == SYNC_SETTINGS_OOF) {
				if(!$decoder->getElementStartTag(SYNC_SETTINGS_BODYTYPE))
    	   	    	return false;
            	$bodytype = $decoder->getElementContent();
            	if(!$decoder->getElementEndTag())
                	return false; // end SYNC_SETTINGS BODYTYPE
            	if(!$decoder->getElementEndTag())
                	return false; // end SYNC_SETTINGS GET
            	if(!$decoder->getElementEndTag())
                	return false; // end SYNC_SETTINGS_OOF
				$request["get"]["oof"]["bodytype"] = $bodytype;
	    	} elseif ($reqtype == SYNC_SETTINGS_USERINFORMATION) {
            	if(!$decoder->getElementEndTag())
                	return false; // end SYNC_SETTINGS GET
				$request["get"]["userinformation"] = array();
        	} else { return false; };
    	} elseif($decoder->getElementStartTag(SYNC_SETTINGS_SET)) {
    	    if($reqtype == SYNC_SETTINGS_OOF) {
    	    	$decoder->getElementStartTag(SYNC_SETTINGS_OOFSTATE);
	        	$oofstate = $decoder->getElementContent();
	        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_OOFSTATE
				$request["set"]["oof"]["oofstate"] = $oofstate;    
    	        if ($oofstate != 0) {
	    		    $decoder->getElementStartTag(SYNC_SETTINGS_OOFMESSAGE);

                    $oofmsgs = array();
				    while (($type = ($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOINTERNAL)        ? SYNC_SETTINGS_APPLIESTOINTERNAL :
							   		($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOEXTERNALKNOWN)   ? SYNC_SETTINGS_APPLIESTOEXTERNALKNOWN :
							    	($decoder->getElementStartTag(SYNC_SETTINGS_APPLIESTOEXTERNALUNKNOWN) ? SYNC_SETTINGS_APPLIESTOEXTERNALUNKNOWN :
							    	-1)))) != -1) {
						$oof = array();
	        			$oof["appliesto"] = $type;
	    	    		$decoder->getElementStartTag(SYNC_SETTINGS_ENABLED);
    	    			$oof["enabled"] = $decoder->getElementContent();
	    	    		$decoder->getElementEndTag(); // end SYNC_SETTINGS_ENABLED
    	    			$decoder->getElementStartTag(SYNC_SETTINGS_REPLYMESSAGE);
	        			$oof["replymessage"] = $decoder->getElementContent();
    		    		$decoder->getElementEndTag(); // end SYNC_SETTINGS_REPLYMESSAGE
	    	    		$decoder->getElementStartTag(SYNC_SETTINGS_BODYTYPE);
        				$oof["bodytype"] = $decoder->getElementContent();
        				$decoder->getElementEndTag(); // end SYNC_SETTINGS_BODYTYPE
						$oofmsgs[]=$oof;
				    }; 
        	    $request["set"]["oof"]["oofmsgs"] = $oofmsgs;    

        	    $decoder->getElementEndTag(); // end SYNC_SETTINGS_OOFMESSAGE
			};
    		$decoder->getElementEndTag(); // end SYNC_SETTINGS_SET
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_OOF
	    } elseif ($reqtype == SYNC_SETTINGS_DEVICEINFORMATION) {
			while (($field = ($decoder->getElementStartTag(SYNC_SETTINGS_MODEL) 			 ? SYNC_SETTINGS_MODEL				: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_IMEI) 				 ? SYNC_SETTINGS_IMEI 				: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_FRIENDLYNAME)		 ? SYNC_SETTINGS_FRIENDLYNAME 		: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_OS) 				 ? SYNC_SETTINGS_OS 				: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_OSLANGUAGE) 		 ? SYNC_SETTINGS_OSLANGUAGE 		: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_PHONENUMBER)		 ? SYNC_SETTINGS_PHONENUMBER 		: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_USERAGENT) 		 ? SYNC_SETTINGS_USERAGENT 			: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_MOBILEOPERATOR)	 ? SYNC_SETTINGS_MOBILEOPERATOR 	: 
							 ($decoder->getElementStartTag(SYNC_SETTINGS_ENABLEOUTBOUNDSMS)	 ? SYNC_SETTINGS_ENABLEOUTBOUNDSMS	: 
							 -1)))))))))) != -1) {
        	    if (($deviceinfo[$field] = $decoder->getElementContent()) !== false) {
        	        $decoder->getElementEndTag(); // end $field
		    	}
			};
			$request["set"]["deviceinformation"] = $deviceinfo;    
     		$decoder->getElementEndTag(); // end SYNC_SETTINGS_SET
        	$decoder->getElementEndTag(); // end SYNC_SETTINGS_DEVICEINFORMATION

	    } elseif ($reqtype == SYNC_SETTINGS_DEVICEPASSWORD) {
			$decoder->getElementStartTag(SYNC_SETTINGS_PASSWORD);
        	if (($password = $decoder->getElementContent()) !== false) $decoder->getElementEndTag(); // end $field
				$request["set"]["devicepassword"] = $password;
    	    } else { return false; };
		} else { return false; };
    }
    $decoder->getElementEndTag(); // end SYNC_SETTINGS_SETTINGS

    if (isset($request["set"])) $result["set"] = $backend->setSettings($request["set"],$devid);
    if (isset($request["get"])) $result["get"] = $backend->getSettings($request["get"],$devid);

    $encoder->startWBXML();
    $encoder->startTag(SYNC_SETTINGS_SETTINGS);
    $encoder->startTag(SYNC_SETTINGS_STATUS);
    $encoder->content(1);
    $encoder->endTag(); // end SYNC_SETTINGS_STATUS
    if (isset($request["set"]["oof"])) {
        $encoder->startTag(SYNC_SETTINGS_OOF);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["oof"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["oof"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_OOF
    };
    if (isset($request["set"]["deviceinformation"])) {
        $encoder->startTag(SYNC_SETTINGS_DEVICEINFORMATION);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
		if (!isset($result["set"]["deviceinformation"]["status"])) {
        	$encoder->content(0);
	    } else {
    	    $encoder->content($result["set"]["deviceinformation"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_DEVICEINFORMATION
    };
    if (isset($request["set"]["devicepassword"])) {
        $encoder->startTag(SYNC_SETTINGS_DEVICEPASSWORD);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
	if (!isset($result["set"]["devicepassword"]["status"])) {
    	    $encoder->content(0);
    	} else {
    	    $encoder->content($result["set"]["devicepassword"]["status"]);
    	}
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->endTag(); // end SYNC_SETTINGS_DEVICEPASSWORD
    };
    if (isset($request["get"]["userinformation"])) {
        $encoder->startTag(SYNC_SETTINGS_USERINFORMATION);
        $encoder->startTag(SYNC_SETTINGS_STATUS);
        $encoder->content($result["get"]["userinformation"]["status"]);
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS
        $encoder->startTag(SYNC_SETTINGS_GET);
        $encoder->startTag(SYNC_SETTINGS_EMAILADDRESSES);
		foreach($result["get"]["userinformation"]["emailaddresses"] as $value) {
	    	$encoder->startTag(SYNC_SETTINGS_SMTPADDRESS);
    	    $encoder->content($value);
    	    $encoder->endTag(); // end SYNC_SETTINGS_SMTPADDRESS
        };
        $encoder->endTag(); // end SYNC_SETTINGS_EMAILADDRESSES
        $encoder->endTag(); // end SYNC_SETTINGS_GET
        $encoder->endTag(); // end SYNC_SETTINGS_USERINFORMATION
    };
    if (isset($request["get"]["oof"])) {
        $encoder->startTag(SYNC_SETTINGS_OOF);

        $encoder->startTag(SYNC_SETTINGS_STATUS);
        $encoder->content(1);
        $encoder->endTag(); // end SYNC_SETTINGS_STATUS

        $encoder->startTag(SYNC_SETTINGS_GET);
        $encoder->startTag(SYNC_SETTINGS_OOFSTATE);
        $encoder->content($result["get"]["oof"]["oofstate"]);
        $encoder->endTag(); // end SYNC_SETTINGS_OOFSTATE
//	This we maybe need later on (OOFSTATE=2). It shows that OOF Messages could be send depending on Time being set in here. 
//	Unfortunately cannot proof it working on my device.
/*      $encoder->startTag(SYNC_SETTINGS_STARTTIME);
        $encoder->content("2007-05-08T10:45:51.250Z");
        $encoder->endTag(); // end SYNC_SETTINGS_STARTTIME
        $encoder->startTag(SYNC_SETTINGS_ENDTIME);
        $encoder->content("2007-05-11T10:45:51.250Z");
        $encoder->endTag(); // end SYNC_SETTINGS_ENDTIME
*/
        foreach($result["get"]["oof"]["oofmsgs"] as $oofentry) {
            $encoder->startTag(SYNC_SETTINGS_OOFMESSAGE);
            $encoder->startTag($oofentry["appliesto"],false,true);
            $encoder->startTag(SYNC_SETTINGS_ENABLED);
            $encoder->content($oofentry["enabled"]);
            $encoder->endTag(); // end SYNC_SETTINGS_ENABLED
    	    $encoder->startTag(SYNC_SETTINGS_REPLYMESSAGE);
            $encoder->content($oofentry["replymessage"]);
            $encoder->endTag(); // end SYNC_SETTINGS_REPLYMESSAGE
            $encoder->startTag(SYNC_SETTINGS_BODYTYPE);
		    switch (strtolower($oofentry["bodytype"])) {
				case "text" : $encoder->content("Text"); break;
				case "HTML" : $encoder->content("HTML"); break;
		    };
            $encoder->endTag(); // end SYNC_SETTINGS_BODYTYPE
            $encoder->endTag(); // end SYNC_SETTINGS_OOFMESSAGE
        };
    
        $encoder->endTag(); // end SYNC_SETTINGS_GET
        $encoder->endTag(); // end SYNC_SETTINGS_OOF
    
    };
    $encoder->endTag(); // end SYNC_SETTINGS_SETTINGS
    
    return true;
}

// END ADDED dw2412 Settings Support

// START ADDED dw2412 ItemOperations Support
function HandleItemOperations($backend, $devid, $protocolversion, $multipart) {
    global $zpushdtd;
    global $input, $output;
    global $auth_user,$auth_domain,$auth_pw;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS))
        return false;

    $request = array();
	$mimesupport = 0;
    while (($reqtype = ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_FETCH)       			?   SYNC_ITEMOPERATIONS_FETCH      	  		:
				       ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENT) 	?   SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENT	:
				       -1))) != -1) {
		if ($reqtype == SYNC_ITEMOPERATIONS_FETCH) {
		    $thisio["type"] = "fetch";
		    while (($reqtag = ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_STORE)      ?   SYNC_ITEMOPERATIONS_STORE  	  	:
						      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_OPTIONS)	?   SYNC_ITEMOPERATIONS_OPTIONS		:
						      ($decoder->getElementStartTag(SYNC_SERVERENTRYID)			 	?   SYNC_SERVERENTRYID				:
						      ($decoder->getElementStartTag(SYNC_FOLDERID)			 		?   SYNC_FOLDERID					:
						      ($decoder->getElementStartTag(SYNC_DOCUMENTLIBRARY_LINKID)	?   SYNC_DOCUMENTLIBRARY_LINKID		:
						      ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_FILEREFERENCE)	?   SYNC_AIRSYNCBASE_FILEREFERENCE	:
						      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_USERNAME)	?   SYNC_ITEMOPERATIONS_USERNAME	:
						      ($decoder->getElementStartTag(SYNC_ITEMOPERATIONS_PASSWORD)	?   SYNC_ITEMOPERATIONS_PASSWORD	:
						      ($decoder->getElementStartTag(SYNC_SEARCH_LONGID)			 	?   SYNC_SEARCH_LONGID				:
				    	      -1)))))))))) != -1) {
	    		if ($reqtag == SYNC_ITEMOPERATIONS_OPTIONS) {
	    		    while (($thisoption = ($decoder->getElementStartTag(SYNC_MIMESUPPORT)					? SYNC_MIMESUPPORT 					:
	    		    					  ($decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE) 	? SYNC_AIRSYNCBASE_BODYPREFERENCE 	:
	    		    					  -1))) != -1) {
						if ($thisoption == SYNC_MIMESUPPORT) {
			        	    $mimesupport = $decoder->getElementContent();
	    			    	$decoder->getElementEndTag();
						} elseif ($thisoption == SYNC_AIRSYNCBASE_BODYPREFERENCE) {
				    	    $bodypreference=array();
	        	        	while(1) {
            	    	    	if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
			                        $bodypreference["Type"] = $decoder->getElementContent();
    				                if(!$decoder->getElementEndTag())
        	                    	    return false;
    	    		    	    }

	            	    	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
			        	        	$bodypreference["TruncationSize"] = $decoder->getElementContent();
			        		        if(!$decoder->getElementEndTag())
            	                	    return false;
	        		    	    }

		                	    if($decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
    		    	                $bodypreference["AllOrNone"] = $decoder->getElementContent();
			        		        if(!$decoder->getElementEndTag())
	        	            		    return false;
	    	    			    }

	            	    	    $e = $decoder->peek();
		            		    if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
    			        			$decoder->getElementEndTag();
									if (!isset($thisio["bodypreference"]["wanted"]))
									    $thisio["bodypreference"]["wanted"] = $bodypreference["Type"];
									if (isset($bodypreference["Type"]))
									    $thisio["bodypreference"][$bodypreference["Type"]] = $bodypreference;
		    		    			break;
	    		    	    	}
                			}
				    	}
					}
				} elseif ($reqtag == SYNC_ITEMOPERATIONS_STORE) {
    	    	    $thisio["store"] = $decoder->getElementContent();
				} elseif ($reqtag == SYNC_ITEMOPERATIONS_USERNAME) {
    	    	    $thisio["username"] = $decoder->getElementContent();
				} elseif ($reqtag == SYNC_ITEMOPERATIONS_PASSWORD) {
    	    	    $thisio["password"] = $decoder->getElementContent();
				} elseif ($reqtag == SYNC_SEARCH_LONGID) {
    	    	    $thisio["searchlongid"] = $decoder->getElementContent();
				} elseif ($reqtag == SYNC_AIRSYNCBASE_FILEREFERENCE) {
				    $thisio["airsyncbasefilereference"] = $decoder->getElementContent();
				} elseif ($reqtag == SYNC_SERVERENTRYID) {
				    $thisio["serverentryid"] = $decoder->getElementContent();
				} elseif ($reqtag == SYNC_FOLDERID) {
				    $thisio["folderid"] = $decoder->getElementContent();
				} elseif ($reqtag == SYNC_DOCUMENTLIBRARY_LINKID) {
				    $thisio["documentlibrarylinkid"] = $decoder->getElementContent();
				} 
    			$e = $decoder->peek();
    	        if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
    			    $decoder->getElementEndTag();
				}
	    	}
		    $itemoperations[] = $thisio;
		    $decoder->getElementEndTag(); // end SYNC_ITEMOPERATIONS_FETCH
		}
    }
    $decoder->getElementEndTag(); // end SYNC_ITEMOPERATIONS_ITEMOPERATIONS

    if ($multipart == true) {
        $encoder->startWBXML(true);
    } else {
        $encoder->startWBXML(false);
    }
    $encoder->startTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS);
    $encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
    $encoder->content(1);
    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
    $encoder->startTag(SYNC_ITEMOPERATIONS_RESPONSE);
    foreach($itemoperations as $value) {
		switch($value["type"]) {
		    case "fetch" :
				switch(strtolower($value["store"])) {
					case "mailbox" :
	    				$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);

						$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
						$encoder->content(1);
						$encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
						if (isset($value["airsyncbasefilereference"])) {
						    $encoder->startTag(SYNC_AIRSYNCBASE_FILEREFERENCE);
						    $encoder->content($value["airsyncbasefilereference"]);
					    	$encoder->endTag(); // end SYNC_SERVERENTRYID
					    	$msg = $backend->ItemOperationsGetAttachmentData($value["airsyncbasefilereference"]);
						} else if (isset($value["searchlongid"])) {
							$encoder->startTag(SYNC_SEARCH_LONGID);
							$encoder->content($value["searchlongid"]);
							$encoder->endTag();
		       		    	$encoder->startTag(SYNC_FOLDERTYPE);
			           	    $encoder->content("Email");
		    	   		    $encoder->endTag();
						    $msg = $backend->ItemOperationsFetchMailbox($value['searchlongid'], $value['bodypreference'], $mimesupport);
						} else {
						    if (isset($value["folderid"])) {
		    		    		$encoder->startTag(SYNC_FOLDERID);
								$encoder->content($value["folderid"]);
			    				$encoder->endTag(); // end SYNC_FOLDERID
					    	}
				    	    if (isset($value["serverentryid"])) {
								$encoder->startTag(SYNC_SERVERENTRYID);
								$encoder->content($value["serverentryid"]);
								$encoder->endTag(); // end SYNC_SERVERENTRYID
						    } 
		       		    	$encoder->startTag(SYNC_FOLDERTYPE);
			           	    $encoder->content("Email");
		    	   		    $encoder->endTag();
						    $msg = $backend->Fetch($value['folderid'], $value['serverentryid'], $value['bodypreference'], false, $mimesupport);
			    	    }
		            	$encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
		        		$msg->encode($encoder);
		            	$encoder->endTag(); // end SYNC_ITEMOPERATIONS_PROPERTIES

						$encoder->endTag(); // end SYNC_ITEMOPERATIONS_FETCH
						break;
				    case "documentlibrary" :
						if (isset($value['username'])) {
					    	if (strpos($value['username'],"\\")) {
								list($cred['username']['domain'],$cred['username']['username']) = explode("\\",$value['username']);
						    } else {
								$cred['username'] = array('domain' => "",'username' => $value['username']);
						    }
						} else {
						    $cred['username']['domain'] = $auth_domain;
						    $cred['username']['username'] = $auth_user;
						}
		   	    		$cred['password'] = (isset($value['password']) ? $value['password'] : $auth_pw);
						$result = $backend->ItemOperationsGetDocumentLibraryLink($value["documentlibrarylinkid"],$cred);
			    		$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);
						$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
						$encoder->content($result['status']);
						// $encoder->content(1);
						$encoder->endTag(); // end SYNC_ITEMOPERATIONS_STATUS
						$encoder->startTag(SYNC_DOCUMENTLIBRARY_LINKID);
						$encoder->content($value["documentlibrarylinkid"]);
						$encoder->endTag(); // end SYNC_DOCUMENTLIBRARY_LINKID
						if ($result['status'] == 1) {
		           		    $encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
		               	    if ($multipart == true) {
		                		$encoder->_bodyparts[] = $result['data'];
		                		$encoder->startTag(SYNC_ITEMOPERATIONS_PART);
		                		$encoder->content("".(sizeof($encoder->_bodyparts))."");
		                		$encoder->endTag();
						    } else {
		        				$encoder->startTag(SYNC_ITEMOPERATIONS_DATA);
								$encoder->content($result['data']);
								$encoder->endTag(); // end SYNC_ITEMOPERATIONS_DATA
		            	    };
		           		    $encoder->startTag(SYNC_ITEMOPERATIONS_VERSION);
		           		    $encoder->content(gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $result['version']));
						    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_VERSION
						    $encoder->endTag(); // end SYNC_ITEMOPERATIONS_PROPERTIES
						} else {
						    $encoder->_bodyparts = array();
						}
						$encoder->endTag(); // end SYNC_ITEMOPERATIONS_FETCH
						break;
				    default :
						debugLog ("Store ".$value["type"]." not supported by HandleItemOperations");
				        break;
				}
				break;
	    	default :
				debugLog ("Operations ".$value["type"]." not supported by HandleItemOperations");
				break;
		}
    }
    $encoder->endTag(); //end SYNC_ITEMOPERATIONS_RESPONSE
    $encoder->endTag(); //end SYNC_ITEMOPERATIONS_ITEMOPERATIONS

    return true;
}

// END ADDED dw2412 ItemOperations Support

// START ADDED dw2412 ValidateCert Support
function HandleValidateCert($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_VALIDATECERT_VALIDATECERT))
        return false;

    while (($field	 = ($decoder->getElementStartTag(SYNC_VALIDATECERT_CERTIFICATES)       			?   SYNC_VALIDATECERT_CERTIFICATES     	:
				       ($decoder->getElementStartTag(SYNC_VALIDATECERT_CERTIFICATECHAIN) 			?   SYNC_VALIDATECERT_CERTIFICATECHAIN	:
				       ($decoder->getElementStartTag(SYNC_VALIDATECERT_CHECKCRL) 					?   SYNC_VALIDATECERT_CHECKCRL			:
				       -1)))) != -1) {
		if ($field == SYNC_VALIDATECERT_CERTIFICATES) {
			while ($decoder->getElementStartTag(SYNC_VALIDATECERT_CERTIFICATE)) {
				$certificates[] = $decoder->getElementContent();
				if (!$decoder->getElementEndTag()) return false;
			}
			if (!$decoder->getElementEndTag()) return false;
		} else if($field == SYNC_VALIDATECERT_CERTIFICATECHAIN) {
			while ($decoder->getElementStartTag(SYNC_VALIDATECERT_CERTIFICATE)) {
				$chain_certificates[] = $decoder->getElementContent();
				if (!$decoder->getElementEndTag()) return false;
			}
			if (!$decoder->getElementEndTag()) return false;
		} else if($field == SYNC_VALIDATECERT_CHECKCRL) {
			$checkcrl = $decoder->getElementContent();
			if (!$decoder->getElementEndTag()) return false;
		}
	}

	if (isset($checkcrl)) debugLog ("validatecert: checkcrl: ".$checkcrl);
	if (isset($chain_certificates)) {
		foreach($chain_certificates as $certificate) {
			debugLog ("validatecert: certificatechain: ".print_r($certificate,true));
		}
	}

	foreach ($certificates as $certificate) {
		$cert_der = base64_decode($certificate);
		$cert_pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($cert_der), 64, "\n")."-----END CERTIFICATE-----\n";
		$cert_fn = VERIFYCERT_TEMP."validatecert".rand(1000,99999).".pem";
		file_put_contents($cert_fn,$cert_pem);
		$now = time();
		if (!($cert_content = openssl_x509_parse($cert_pem)))
			$status = 10;
		else if ($cert_content['validFrom_time_t'] >= $now ||
			$cert_content['validTo_time_t'] <= $now) 
			$status = 7;
		else if (openssl_x509_checkpurpose($cert_pem,X509_PURPOSE_SMIME_SIGN,array(VERIFYCERT_CERTSTORE)) != 1)
			$status = 9;
		else if ($checkcrl == 1) {
			if (isset($cert_content['extensions']['crlDistributionPoints'])) {
				$crlDistributionPoints = explode ("\n",str_replace("\r",'',$cert_content['extensions']['crlDistributionPoints']));
				foreach ($crlDistributionPoints as $entry) {
					$line = explode("URI:",$entry);
					if (isset($line[1]) && substr($line[1],0,5) == "http:")
						$crl_urls[] = $line[1];
				}
			}
			if (isset($cert_content['extensions']['authorityInfoAccess'])) {
				$authorityInfoAccess = explode ("\n",str_replace("\r",'',$cert_content['extensions']['authorityInfoAccess']));
				foreach ($authorityInfoAccess as $entry) {
					$line = explode(" - URI:",$entry);
					if (strtolower(trim($line[0])) == 'ocsp')
						$ocsp_urls[] = $line[1];
					if (strtolower(trim($line[0])) == 'ca issuers') 
						$ca_issuers[] = $line[1];
				}
			}
			$result = preg_split('/[\r\n]/',shell_exec(VERIFYCERT_SSLBIN." x509 -in ".$cert_fn." -issuer_hash -noout") );
			$issuer_cer_name = $result[0].'.0';
			$issuer_crl_name = $result[0].'.r0';
			if (!file_exists(VERIFYCERT_CERTSTORE.$issuer_cer_name)) {
				if (isset($ca_issuers)) {
					foreach($ca_issuers as $ca_issuer) {
						$ca_cert = file_get_contents($ca_issuer);
						if (strpos($ca_cert,'----BEGIN CERTIFICATE-----') == false) {
							$ca_cert = der2pem($ca_cert);
						}
						if (!openssl_x509_parse($ca_cert)) {
							$status=5;
						} else {
							file_put_contents(VERIFYCERT_CERTSTORE.$issuer_cer_name, $ca_cert);
						}
					}
				} else {
					$status = 4;
				}
			}
			if (isset($ocsp_urls)) {
				$command = VERIFYCERT_SSLBIN." ocsp -VAfile ".VERIFYCERT_CERTSTORE.$issuer_cer_name."  -issuer ".VERIFYCERT_CERTSTORE.$issuer_cer_name." -CApath ".VERIFYCERT_CERTSTORE." -no_nonce -cert ".$cert_fn." -url ".$ocsp_urls[0];
				$result = preg_split('/[\r\n]/',shell_exec($command));
				$status = 14;
				foreach ($result as $line) {
					$values = explode(":",$line);
					if (trim($values[0]) == $cert_fn) {
						switch (strtolower(trim($values[1]))) {
							case 'good' : 
								$status = 1; break;
							default : 
								$status = 13;
						};
					}
				}
			} else if (isset($crl_urls)) {
				echo "Verfication using crl!<br>\n";
				$nextupdate = time()-1;
				if (file_exists(VERIFYCERT_CERTSTORE.$issuer_crl_name)) {
					$command = VERIFYCERT_SSLBIN." crl -in ".VERIFYCERT_CERTSTORE.$issuer_crl_name." -nextupdate -noout";
					$result = preg_split('/[\r\n]/',shell_exec($command));
					foreach ($result as $line) {
						$values = explode("=",$line);
						if (strtolower(trim($values[0])) == 'nextupdate') $nextupdate = strtotime($values[1]);
					}
				}
				if (!file_exists(VERIFYCERT_CERTSTORE.$issuer_crl_name) ||
					$nextupdate < time()) {
					if ($nextupdate < time()) echo "CRL File needs update!\n";
					foreach($crl_urls as $crl_url) {
						if (($crl_cert = file_get_contents($crl_url))) {
							if (strstr($crl_cert,'----BEGIN X509 CRL-----') == false) {
								file_put_contents(VERIFYCERT_TEMP.$issuer_crl_name, $crl_cert);
								$command = VERIFYCERT_SSLBIN." crl -in ".VERIFYCERT_TEMP.$issuer_crl_name." -inform der -out ".VERIFYCERT_CERTSTORE.$issuer_crl_name." -outform pem";
								$result = preg_split('/[\r\n]/',shell_exec($command));
								foreach ($result as $line) {
									echo $line."\n";
								}
							} else {
								file_put_contents(VERIFYCERT_CERTSTORE.$issuer_crl_name, $crl_cert);
							}
						} else {
							$status = 14;
						}
					}
				}
				if (file_exists(VERIFYCERT_CERTSTORE.$issuer_crl_name)) {
					$command = VERIFYCERT_SSLBIN." verify -verbose -CApath ".VERIFYCERT_CERTSTORE." -crl_check ".$cert_fn;
					$result = preg_split('/[\r\n]/',shell_exec($command));
					foreach ($result as $line) {
						$values = explode(":",$line);
						if (trim($values[0]) == $cert_fn) {
							switch (strtolower(trim($values[1]))) {
								case 'ok' : 
									$status = 1; break;
								default : 
									$status = 13;
							};
						}
					}
				} else {
					$status = 16;
				}
			} else {
				$status = 16;
			}
		} else {
			$status = 1;
		}
		unlink($cert_fn);
	}

    $encoder->startWBXML();
    $encoder->startTag(SYNC_VALIDATECERT_VALIDATECERT);
    $encoder->startTag(SYNC_VALIDATECERT_STATUS);
    $encoder->content(1);
    $encoder->endTag(); // end SYNC_VALIDATECERT_STATUS
	$encoder->startTag(SYNC_VALIDATECERT_CERTIFICATE);
    $encoder->startTag(SYNC_VALIDATECERT_STATUS);
    $encoder->content($status);
    $encoder->endTag(); // end SYNC_VALIDATECERT_STATUS
    $encoder->endTag(); // end SYNC_VALIDATECERT_CERTIFICATE
    $encoder->endTag(); // end SYNC_VALIDATECERT_VALIDATECERT

	return true;
}
// END ADDED dw2412 ValidateCert Support

// START ADDED dw2412 ResolveRecipient Support
function HandleResolveRecipients($backend, $devid, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

//	define ('OVERRIDE_GZIP',true);

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS))
        return false;

	$status = 1;
    while ($status == 1 &&
    	   ($field	 = ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_TO)       			?   SYNC_RESOLVERECIPIENTS_TO     	:
				       ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_OPTIONS) 			?   SYNC_RESOLVERECIPIENTS_OPTIONS	:
				       -1))) != -1) {
		if ($field == SYNC_RESOLVERECIPIENTS_OPTIONS) {
    		while ($status == 1 &&
    			   ($option	= ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL)    ?   SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL     	:
				       		  ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_MAXCERTIFICATES) 		?	SYNC_RESOLVERECIPIENTS_MAXCERTIFICATES				:
				       		  ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_MAXAMBIGUOUSRECIPIENTS)	?	SYNC_RESOLVERECIPIENTS_MAXAMBIGUOUSRECIPIENTS		:
				       		  ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_AVAILABILITY)			?	SYNC_RESOLVERECIPIENTS_AVAILABILITY					:
				       		  ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_PICTURE)					?	SYNC_RESOLVERECIPIENTS_PICTURE						:
				       -1)))))) != -1) {
				switch ($option) {
					case SYNC_RESOLVERECIPIENTS_AVAILABILITY : 
    					while (($suboption	= ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_STARTTIME)   ?   SYNC_RESOLVERECIPIENTS_STARTTIME     	:
				       						  ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_ENDTIME)		?	SYNC_RESOLVERECIPIENTS_ENDTIME			:
				       				-1))) != -1) {
							$ts = $decoder->getElementContent();
					        if(preg_match("/(\d{4})[^0-9]*(\d{2})[^0-9]*(\d{2})T(\d{2})[^0-9]*(\d{2})[^0-9]*(\d{2})(.\d+)?Z/", $ts, $matches)) {
					            if ($matches[1] >= 2038){
					                $matches[1] = 2038;
					                $matches[2] = 1;
					                $matches[3] = 18;
					                $matches[4] = $matches[5] = $matches[6] = 0;
					            }
					            $ts = gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]);
					        } else {
					        	$ts = 0;
					        }
					        $options[$option][$suboption] = $ts;
							if (!$decoder->getElementEndTag()) 
								$status = 5;
						}
						if (!$decoder->getElementEndTag())
							$status = 5;
						break;
					case SYNC_RESOLVERECIPIENTS_PICTURE : 
    					while (($suboption	= ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_MAXSIZE)   	?   SYNC_RESOLVERECIPIENTS_MAXSIZE     	:
				       						  ($decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_MAXPICTURES)	?	SYNC_RESOLVERECIPIENTS_MAXPICTURES	:
				       				-1))) != -1) {
							$options[$option][$suboption] = $decoder->getElementContent();
							if (!$decoder->getElementEndTag()) 
								$status = 5;
						}
						if (!$decoder->getElementEndTag()) 
							$status = 5;
						break;
					default :
						$options[$option] = $decoder->getElementContent();
						if (!$decoder->getElementEndTag()) 
							$status = 5;
				}
			}
			if (!$decoder->getElementEndTag()) 
				$status = 5;
		} else if($field == SYNC_RESOLVERECIPIENTS_TO) {
			$to[] = $decoder->getElementContent();
			if (!$decoder->getElementEndTag()) 
				$status = 5;
		}
	}

	$results = '';
	foreach($to as $item) {
		if (isset($options[SYNC_RESOLVERECIPIENTS_AVAILABILITY])) {
			$result = $backend->resolveRecipient('availability',$item,array('starttime' => $options[SYNC_RESOLVERECIPIENTS_AVAILABILITY][SYNC_RESOLVERECIPIENTS_STARTTIME],
																  			'endtime' => $options[SYNC_RESOLVERECIPIENTS_AVAILABILITY][SYNC_RESOLVERECIPIENTS_ENDTIME]));
			$results[$item] = $result[$item];
		}
		if (isset($options[SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL])) {
			$result = $backend->resolveRecipient('certificate',$item,array('maxcerts' => $options[SYNC_RESOLVERECIPIENTS_MAXCERTIFICATES],
																		   'maxambigious' => $options[SYNC_RESOLVERECIPIENTS_MAXAMBIGUOUSRECIPIENTS],
																		   )
												);
			$results[$item] = $result[$item];
		}
	}
	debugLog("Results returned".print_r($results,true));

    $encoder->startWBXML();
    $encoder->startTag(SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS);
    $encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
    $encoder->content($status);
    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_STATUS
	foreach ($to as $item) {
		$encoder->startTag(SYNC_RESOLVERECIPIENTS_RESPONSE);
	    $encoder->startTag(SYNC_RESOLVERECIPIENTS_TO);
	    $encoder->content($item);
	    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_TO
	    $encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
	    $encoder->content((sizeof($results[$item]) > 1 ? 2 : (sizeof($results[$item]) == 0 ? 4 : (sizeof($results[$item]) == 1 ? 1 : 3))));
	    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_STATUS
	    $encoder->startTag(SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT);
	    $encoder->content(sizeof($results[$item]));
	    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT
		foreach ($results[$item] as $value) {
		    $encoder->startTag(SYNC_RESOLVERECIPIENTS_RECIPIENT);
			$encoder->startTag(SYNC_RESOLVERECIPIENTS_TYPE);
		    $encoder->content($value['type']);
		    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_TYPE
			$encoder->startTag(SYNC_RESOLVERECIPIENTS_DISPLAYNAME);
		    $encoder->content($value['displayname']);
		    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_DISPLAYNAME
			$encoder->startTag(SYNC_RESOLVERECIPIENTS_EMAILADDRESS);
		    $encoder->content($value['emailaddress']);
		    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_EMAILADDRESS
			if (isset($options[SYNC_RESOLVERECIPIENTS_AVAILABILITY])) {
				$encoder->startTag(SYNC_RESOLVERECIPIENTS_AVAILABILITY);
				$encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
			    $encoder->content(1);
			 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_STATUS
				$encoder->startTag(SYNC_RESOLVERECIPIENTS_MERGEDFREEBUSY);
			    $encoder->content($value['mergedfb']);
			 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_MERGEDFREEBUSY
			    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_AVAILABILITY
			}
			if (isset($options[SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL]) &&
				$options[SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL] > 1) {
				$encoder->startTag(SYNC_RESOLVERECIPIENTS_CERTIFICATES);
				$encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
			    $encoder->content((sizeof($value['entries']) == 0 ? 7 : 1));
			 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_STATUS
				$encoder->startTag(SYNC_RESOLVERECIPIENTS_CERTIFICATECOUNT);
			    $encoder->content(sizeof($value['entries']));
			 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_CERTIFICATECOUNT
				$encoder->startTag(SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT);
			    $encoder->content(sizeof($results[$item]));
			 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT
				switch ($options[SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL]) {
					case '2' :
						foreach($value['entries'] as $cert) {
							$encoder->startTag(SYNC_RESOLVERECIPIENTS_CERTIFICATE);
						    $encoder->content($cert);
						 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_CERTIFICATE
						}
						break;
					case '3' :
						foreach($value['entries'] as $cert) {
							$encoder->startTag(SYNC_RESOLVERECIPIENTS_MINICERTIFICATE);
						    $encoder->content($cert);
						 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_MINICERTIFICATE
						}
						break;
				}
				$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL
			}
/*		if (isset($item['options'][SYNC_RESOLVERECIPIENTS_PICTURE])) {
			$encoder->startTag(SYNC_RESOLVERECIPIENTS_PICTURE);
			$encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
		    $encoder->content();
		 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_STATUS
			$encoder->startTag(SYNC_RESOLVERECIPIENTS_DATA);
		    $encoder->content();
		 	$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_DATA
		    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_PICTURE
		}
*/
		    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_RECIPIENT
		}
   		$encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_RESPONSE
	}
    $encoder->endTag(); // end SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS

	return true;
}
// END ADDED dw2412 ResolveRecipient Support

// START ADDED dw2412 Notify Support (Yeah, AS 2.0 and for Motorola A1200 necessary)
function HandleNotify2($backend, $devid, $protocolverison) {
    header("HTTP/1.1 501 Not Implemented");
    print("Header Method specified is not implemented.");
    debugLog("Yuck, AS2.0 uses Notify - this is undocumented but sending 501 should calm down device!");
    return true;
}
// END ADDED dw2412 Notify Support - the above should do the trick...

function HandleRequest($backend, $cmd, $devid, $protocolversion, $multipart) {

    switch($cmd) {
        case 'Sync':
            $status = HandleSync($backend, $protocolversion, $devid);
            break;
        case 'SendMail':
            $status = HandleSendMail($backend, $protocolversion);
            break;
        case 'SmartForward':
            $status = HandleSmartForward($backend, $protocolversion);
            break;
        case 'SmartReply':
            $status = HandleSmartReply($backend, $protocolversion);
            break;
        case 'GetAttachment':
            $status = HandleGetAttachment($backend, $protocolversion);
            break;
        case 'GetHierarchy':
            $status = HandleGetHierarchy($backend, $protocolversion, $devid);
            break;
        case 'CreateCollection':
            $status = HandleCreateCollection($backend, $protocolversion);
            break;
        case 'DeleteCollection':
            $status = HandleDeleteCollection($backend, $protocolversion);
            break;
        case 'MoveCollection':
            $status = HandleMoveCollection($backend, $protocolversion);
            break;
        case 'FolderSync':
            $status = HandleFolderSync($backend, $devid, $protocolversion);
            break;
        case 'FolderCreate':
            $status = HandleFolderCreate($backend, $devid, $protocolversion);
            break;
        case 'FolderDelete':
            $status = HandleFolderDelete($backend, $devid, $protocolversion);
            break;
        case 'FolderUpdate':
            $status = HandleFolderUpdate($backend, $devid, $protocolversion);
            break;
        case 'MoveItems':
            $status = HandleMoveItems($backend, $devid, $protocolversion);
            break;
        case 'GetItemEstimate':
            $status = HandleGetItemEstimate($backend, $protocolversion, $devid);
            break;
        case 'MeetingResponse':
            $status = HandleMeetingResponse($backend, $protocolversion);
            break;
/*        case 'Notify': // Used for sms-based notifications (pushmail)
            $status = HandleNotify($backend, $protocolversion);
            break;
*/
		case 'Ping': // Used for http-based notifications (pushmail)
            $status = HandlePing($backend, $devid, $protocolversion);
            break;
        case 'Provision':
		    $status = (PROVISIONING === true) ? HandleProvision($backend, $devid, $protocolversion) : false;
            break;
        case 'Search':
            $status = HandleSearch($backend, $devid, $protocolversion);
            break;
        case 'Settings':
            $status = HandleSettings($backend, $devid, $protocolversion);
            break;
        case 'ItemOperations':
            $status = HandleItemOperations($backend, $devid, $protocolversion, $multipart);
            break;
		case 'Notify':
		    $status = HandleNotify2($backend, $devid, $protocolversion);
		    break;
		case 'ValidateCert':
			$status = HandleValidateCert($backend, $devid, $protocolversion);
            break;
		case 'ResolveRecipients':
			$status = HandleResolveRecipients($backend, $devid, $protocolversion);
			break;
        default:
            debugLog("unknown command - not implemented");
            $status = false;
            break;
    }

    return $status;
}

function readStream(&$input) {
    $s = "";

    while(1) {
        $data = fread($input, 4096);
        if(strlen($data) == 0)
            break;
        $s .= $data;
    }

    return $s;
}

function shutdownCommunication() {
    global $cmd, $cachestatus, $devid;
    
    sleep(2);
    
    switch (strtolower($cmd)) {
		case "sync" :
		case "ping" :
		    debugLog("verifyCommunication: Cachestatus is ".$cachestatus);
		    // in case our cache changed we request full sync request from client -
		    // we need to do this since we have no 100% way to find out if connection is really
		    // alive but need to have a clean synccache to ensure a 100% sync of elements...
		    if ($cachestatus == SYNCCACHE_CHANGED &&
				connection_aborted()) {
				    debugLog("verifyCommunication: Take care, connection aborted and synccache changed in cmd ".$cmd);
		    } else if (connection_aborted()) {
				debugLog("verifyCommunication: Unimportend connection abort situation - connection aborted during ".$cmd);
		    } else {
				debugLog("verifyCommunication: Device should have the data!");
		    };
		    break;
		default :
		    if (connection_aborted()) debugLog("verifyCommunication: Unimportend connection abort situation - connection aborted during ".$cmd);
		    else debugLog("verifyCommunication: Device should have the data!");
		    break;
	
	}
//    debugLog("Final Connection aborted :".(connection_aborted() ? "yes" : "no" ));
//    debugLog("Final Connection status  :".connection_status());
}


?>