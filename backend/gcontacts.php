<?php
/***********************************************
* File      :   gcontacts.php
* Project   :   Z-Push
* Descr     :   This backend is for google contacts
*	       directories.
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once('diffbackend.php');
include_once('z_RTF.php');

define('GCONTACTS_URL', 'http://www.google.com/m8/feeds/contacts/default/full');

// load Zend Gdata libraries
require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Gdata');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
Zend_Loader::loadClass('Zend_Http_Client');
Zend_Loader::loadClass('Zend_Gdata_Query');
Zend_Loader::loadClass('Zend_Gdata_Feed');

//bfunction
function AtoX($array, $DOM=null, $root=null){

//	print "Debug ".$array['@value']." ".$root->tagName." xx\n";
//	print "Debug ".$array['@value']." ".$root->childNodes->length." xx\n";
//	print "Debug ".$array['@value']." ".$root->parentNode->tagName." xx\n";
//	print "Debug ".$array['@value']." ".$root->parentNode->childNodes->length." xx\n";
//	print "Debug ".$array['@value']." ".$root->getAttribute('rel')." xx ".$attributes['rel']."xx\n";

	if($DOM  == null){$DOM  = new DOMDocument('1.0', 'iso-8859-1');}
	if($root == null){$root = $DOM->appendChild($DOM->createElement('root'));}

	if(is_array($array)){
		// get the attributes first.;
		if(isset($array['@attributes'])) {
			foreach($array['@attributes'] as $key => $value) {
				$root->setAttribute($key,$value);
			}
			if ((isset($array['@attributes']['when']) && empty($array['@attributes']['when'])) || 
			    (isset($array['@attributes']['href']) && empty($array['@attributes']['href'])) ||
			    (isset($array['@attributes']['value']) && empty($array['@attributes']['value'])) ||
			    (isset($array['@attributes']['startTime']) && empty($array['@attributes']['startTime'])) ||
			    (isset($array['@attributes']['address']) && empty($array['@attributes']['address'])) ) {
				//cancella
				debugLog("GContacts::ChangeMessage - #1 remove element ".$root->tagName);
				$root->parentNode->removeChild($root);
			}
			unset($array['@attributes']); //remove the key from the array once done.
		}
		if(isset($array['@value'])) {
			if ($root->childNodes->length == 0) {
				if (!empty($array['@value'])) {
					debugLog("GContacts::ChangeMessage - #1 create node for ".$root->tagName." => ".$array['@value']);
					$root->appendChild($DOM->createTextNode($array['@value']));
				} else {
					//cancella
					debugLog("GContacts::ChangeMessage - #1 remove element ".$root->tagName);
					$root->parentNode->removeChild($root);
				}
			} else {
				if (!empty($array['@value'])) {
					//assegna
					debugLog("GContacts::ChangeMessage - #1 assign value to ".$root->tagName." => ".$array['@value']);
					$root->nodeValue = $array['@value'];
				} else {
					//cancella
					debugLog("GContacts::ChangeMessage - #1 remove element ".$root->tagName);
					$root->parentNode->removeChild($root);
				}
			}
			unset($array['@value']);    //remove the key from the array once done.
			//return from recursion, as a note with value cannot have child nodes.
		} else if (isset($array['@CDATA'])) {
			$root->appendChild($DOM->createCDATASection($array['@cdata']));
			unset($array['@value']);    //remove the key from the array once done.
			//return from recursion, as a note with value cannot have child nodes.
		}
	}

	//create subnodes using recursion
	if(is_array($array)){
		// recurse to get the node for that key
		foreach($array as $key=>$value){
			if(is_array($value) && isset($value[0])) {
				// MORE THAN ONE NODE OF ITS KIND;
				// if the new array is numeric index, means it is array of nodes of the same kind
				// it should follow the parent key name
				//print "DEBUGDEBUG#1 $key $subroot->tagName ".$subroot->getAttribute('rel')."\n";
				foreach($value as $k=>$v){
					$subroot = NULL;
					//print "DEBUGDEBUG#2 $key $root->tagName $root->nodeName\n";
					if($root->childNodes->length) {
						foreach($root->childNodes as $item) {
							if ($item->nodeName == $key) {
								$rel   = (isset($v['@attributes']) && isset($v['@attributes']['rel']))?$v['@attributes']['rel']:NULL;
								$akey  = (isset($v['@attributes']) && isset($v['@attributes']['key']))?$v['@attributes']['key']:NULL;
								$proto = (isset($v['@attributes']) && isset($v['@attributes']['proto']))?$v['@attributes']['proto']:NULL;
								//debugLog("GContacts::ChangeMessage - ".$item->tagName." arrayREL:".$rel. " domREL:". $item->getAttribute('rel'));
								if ($rel == $item->getAttribute('rel') && $akey == $item->getAttribute('key') && $proto == $item->getAttribute('proto')) {
									debugLog("GContacts::ChangeMessage - #2 found matching Element ".$key);
									$subroot = $item;
									break;
								}
							}
						}
					}
					if (!isset($subroot)) {
						debugLog("GContacts::ChangeMessage - #2 create element ".$key);
						$subroot = $root->appendChild($DOM->createElement($key));
					}
					AtoX($v, $DOM, $subroot);
				}
			} else {
				// ONLY ONE NODE OF ITS KIND
				$subroot = NULL;
				if($root->childNodes->length) {
					foreach($root->childNodes as $item) {
						if ($item->nodeName == $key) {
							$rel   = (isset($value['@attributes']) && isset($value['@attributes']['rel']))?$value['@attributes']['rel']:NULL;
							$akey  = (isset($value['@attributes']) && isset($value['@attributes']['key']))?$value['@attributes']['key']:NULL;
							$proto = (isset($value['@attributes']) && isset($value['@attributes']['proto']))?$value['@attributes']['proto']:NULL;
							//debugLog("GContacts::ChangeMessage - ".$item->tagName." arrayREL:".$rel. " domREL:". $item->getAttribute('rel'));
							if ($rel == $item->getAttribute('rel') && $akey == $item->getAttribute('key') && $proto == $item->getAttribute('proto')) {
								debugLog("GContacts::ChangeMessage - #3 found matching Element ".$key);
								$subroot = $item;
								break;
							}
						}
					}
				}
				if (!isset($subroot)) {
					debugLog("GContacts::ChangeMessage - #3 create element ".$key);
					$subroot = $root->appendChild($DOM->createElement($key));
				}
				AtoX($value, $DOM, $subroot);
			}
			unset($array[$key]); //remove the key from the array once done.
		}
	}
	if(!is_array($array)) {
		if ($root->childNodes->length == 0) {
			if (!empty($array)) {
				debugLog("GContacts::ChangeMessage - #4 create node for ".$root->tagName." => ".$array);
				$root->appendChild($DOM->createTextNode($array['@value']));
			} else {
				//cancella
				debugLog("GContacts::ChangeMessage - #4 remove element ".$root->tagName);
				$root->parentNode->removeChild($root);
			}
		} else {
			if (!empty($array)) {
				//assegna
				debugLog("GContacts::ChangeMessage - #4 assign value to ".$root->tagName." => ".$array);
				$root->nodeValue = $array;
			} else {
				//cancella
				debugLog("GContacts::ChangeMessage - #4 remove element ".$root->tagName);
				$root->parentNode->removeChild($root);
			}
		}
	}

	return $DOM;
}

//efunction

class BackendGcontacts extends BackendDiff {
    var $_config;
    var $_user;
    var $_devid;
    var $_protocolversion;
    //var $_path;

    //GData Client and Service
    var $client;
    var $service;

	function Logon($username, $domain, $password) {
		debugLog('Gcontacts::Logon('.$username.', '.$domain.', ***)');

		// Create an authenticated HTTP client
		try {
			$this->client = Zend_Gdata_ClientLogin::getHttpClient($username, $password, 'cp');
			$this->client->setHeaders('If-Match: *');
			//$this->client = Zend_Gdata_ClientLogin::getHttpClient('dpeddi@gmail.com', $password, 'cp');
		} catch (Zend_Gdata_App_CaptchaRequiredException $cre) {
			debugLog('Gcontacts::Logon - Captcha! Don\'t know how to deal with this... :-/');
			return false;
		} catch (Zend_Gdata_App_AuthException $ae) {
			debugLog('Gcontacts::Logon - Problem authenticating (' . $ae->exception() . ')');
			return false;
		}
		try {
			$this->service = new Zend_Gdata($this->client);
			$this->service->setMajorProtocolVersion(3);
		} catch (Exception $e) {
			debugLog('Gcontacts::Logon - Problem setting protocol (' . $e->exception() . ')');
			return false;
		}

		debugLog('Gcontacts::Logon - Login successful :)');
		return true;
	}

    function Setup($user, $devid, $protocolversion) {
	$this->_user = $user;
	$this->_devid = $devid;
	$this->_protocolversion = $protocolversion;

	return true;
	
    }

    function SendMail($rfc822, $smartdata=array(), $protocolversion = false) {
	return false;
    }

    function GetWasteBasket() {
	return false;
    }

    function GetMessageList($folderid, $cutoffdate) {
	debugLog('GContacts::GetMessageList('.$folderid.')');
	$messages = array();

	try {
		// perform query and get feed of all results
		$query = new Zend_Gdata_Query(GCONTACTS_URL);
		$query->maxResults = 999999;
		//$query->group = "My Contacts";
		$query->setParam('orderby', 'lastmodified');
		$query->setParam('sortorder', 'descending');
		$feed = $this->service->getFeed($query);

	} catch (Exception $e) {
		debugLog('Gcontacts::GetMessageList - Error while opening feed (' . $e->exception() . ')');
		return false;
	}

	debugLog('GContacts::GetMessageList('.$folderid.')'. ' -'. $feed->title. ' '. $feed->totalResults);

	// parse feed and extract contact information
	// into simpler objects
	$results = array();
	try {
		foreach($feed as $entry){
		    $obj = new stdClass;
		    $obj->edit = $entry->getEditLink()->href;
		    $xmldata = $entry->getXML();
		    
		    //filter out real contact id without other garbage
		    preg_match("/[_a-z0-9]+$/", $entry->id, $matches);
		    $contactid = $matches[0];

		    $xml = simplexml_load_string($xmldata);

		    $e["id"] = (string)$contactid;
		    $e["flags"] = "1";
		    $e["mod"] = strtotime((string)$entry->getUpdated());
		    $results[] = $e;
		    //debugLog((string)$entry->getUpdated());
		}
	} catch (Exception $e) {
		debugLog('Gcontacts::GetMessageList - Problem retrieving data (' . $e->exception() . ')');
		return false;
	}
	return $results;

    }

    function GetFolderList() {
	debugLog('GContacts::GetFolderList()');
	$contacts = array();
	$folder = $this->StatFolder("root");
	$contacts[] = $folder;

	return $contacts;
    }

    function GetFolder($id) {
	debugLog('GContacts::GetFolder('.$id.')');
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
	debugLog('GContacts::StatFolder('.$id.')');
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

	debugLog("GContacts::StatMessage: (fid: '$folderid'  id: '$id' )");

	$message = Array();
	
	try {
		// perform query and get feed of all results
		$query = new Zend_Gdata_Query(GCONTACTS_URL .'/'. $id);
		$entry = $this->service->getEntry($query);
		
		$message["id"] = (string)$id;
		$message["flags"] = "1";
		$message["mod"] = strtotime((string)$entry->getUpdated());
	
	} catch (Zend_Gdata_App_Exception $e) {
		debugLog("GContacts::StatMessage - ERROR! (" . $e->getMessage() . ")");
		return false;
	}

	//file_put_contents("stat_message_dump.txt", serialize($message) . "\n", FILE_APPEND);
	return $message;

    }

    function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0) {
	debugLog('GContacts::GetMessage('.$folderid.', '.$this->_items[$id].', ..)');
	if($folderid != "root")
	    return;

	debugLog("GContacts::GetMessage: (fid: '$folderid'  id: '$id' )");

	// Parse the vcard
	$message = new SyncContact();

	try {
		// perform query and get feed of all results
		$query = new Zend_Gdata_Query(GCONTACTS_URL .'/'. $id);
		$entry = $this->service->getEntry($query);
	} catch (Zend_Gdata_App_Exception $e) {
		debugLog("GContacts::GetMessage - ERROR! (" . $e->getMessage() . ")");
		return false;
	}

	// parse feed and extract contact information
	// into simpler objects
	try {
		    $doc = new DOMDocument();
		    $doc->formatOutput = true;

		    //$obj = new stdClass;
		    //$obj->edit = $entry->getEditLink()->href;
		    $xmldata = $entry->getXML();
		    $doc->loadXML($xmldata);
		    
		    //filter out real contact id without other garbage
		    preg_match("/[_a-z0-9]+$/", $entry->id, $matches);
		    $contactid = $matches[0];

		    $fh = fopen(STATE_DIR.'/xml-get/'.(string) $entry->title.'_'.(string) $contactid.'.xml', 'w');
		    fwrite($fh,$doc->saveXML());
		    $xml = simplexml_load_string($xmldata);
		    fclose($fh);

		    
		    //Prefix:	
		    //givenName:	 ok
		    //Middle:	
		    //familyName:		ok
		    //nameSuffix:	ok
		    
		    //last
		    //first
		    //middlename
		    //title
		    //suffix

		    if(!empty($xml->name->fullName)) {
			debugLog('GContacts::GetMessage - fullName: '.(string) $xml->name->fullName);
			$message->fileas = w2ui($xml->name->fullName);
		    }

		//    if (!empty($xml->name->namePrefix)){
		//	debugLog('GContacts::GetMessage - namePrefix: '.(string) $xml->name->namePrefix);
		//	$message->middlename = w2ui($xml->name->namePrefix);
		//    }
		
		    if (!empty($xml->name->givenName)){
			debugLog('GContacts::GetMessage - givenName: '.(string) $xml->name->givenName);
			$message->firstname = w2ui($xml->name->givenName);
		    }

		    //if (!empty($xml->name->????)){
		//	debugLog('GContacts::GetMessage - familyName: '.(string) $xml->name->????);
		//	$message->title = w2ui($xml->name->^^^^);
		//    }

		    if (!empty($xml->name->familyName)){
			debugLog('GContacts::GetMessage - familyName: '.(string) $xml->name->familyName);
			$message->lastname = w2ui($xml->name->familyName);
		    }

		    if (!empty($xml->name->nameSuffix)){
			debugLog('GContacts::GetMessage - nameSuffix: '.(string) $xml->name->nameSuffix);
			$message->suffix = w2ui($xml->name->nameSuffix);
		    }

		    if (!empty($xml->organization->orgName)){
			debugLog('GContacts::GetMessage - orgName: '.(string) $xml->organization->orgName);
			$message->companyname = w2ui($xml->organization->orgName);
		    }
		    if (!empty($xml->organization->orgTitle)){
			debugLog('GContacts::GetMessage - orgName: '.(string) $xml->organization->orgTitle);
			$message->jobtitle = w2ui($xml->organization->orgTitle);
		    }
		    
		    if (!empty($xml->nickname)){
			debugLog('GContacts::GetMessage - Nickname: '.(string) $xml->nickname);
			$message->nickname = w2ui($xml->nickname);
		    }

		    foreach ($xml->email as $e) {
			debugLog('GContacts::GetMessage - email: '.(string) $e['address']);
			if(empty($message->email1address)){
			    $message->email1address = w2ui($e['address']);
			} elseif (empty($message->email2address)){
			    $message->email2address = w2ui($e['address']);
			} elseif (empty($message->email3address)){
			    $message->email3address = w2ui($e['address']);
			} else {
			    debugLog('GContacts::GetMessage - LOST email address: '.(string) $e['address']);
			}
		    }

		    foreach ($xml->im as $i) {
			debugLog('GContacts::GetMessage - im: '.(string) $i['address']);
			if(empty($message->imaddress)){
			    $message->imaddress = w2ui($i['address']);
			} elseif (empty($message->im2address)){
			    $message->imaddress2 = w2ui($i['address']);
			} elseif (empty($message->imaddress3)){
			    $message->imaddress3 = w2ui($i['address']);
			} else {
			    debugLog('GContacts::GetMessage - LOST im address: '.(string) $i['address']);
			}
		    }

		    foreach ($xml->structuredPostalAddress as $p) {
			switch ($p['key']) {
				case 'Children':
					debugLog($p['key'].': '.(string) $p['value']);
					$message->children = w2ui($p['value']);
					break;
				case 'Spouse':
					debugLog($p['key'].': '.(string) $p['value']);
					$message->spouse = w2ui($p['value']);
					break;
			}
		    }

		    foreach ($xml->structuredPostalAddress as $p) {
			preg_match("/[_a-z0-9]+$/", $p['rel'], $matches);
			$rel = $matches[0];
			switch ($rel) {
				case 'home':
					$a = 'home';
					break;
				case 'work':
					break;
				case 'office':
					$a= 'business';
					break;
				case 'other':
					$a = 'other';
					break;
				default:
					debugLog('GContacts::GetMessage - structuredPostalAddress di tipo '.$rel.': non censito');
					break;
			}
			if (!empty($p->street)) {
				$b=$a.'street';
				debugLog($b.': '.(string) $p->street);
				$message->$b = w2ui($p->street);
			}
			if (!empty($p->city)) {
				$b=$a.'city';
				debugLog($b.': '.(string) $p->city);
				$message->$b = w2ui($p->city);
			}
			if (!empty($p->postcode)) {
				$b=$a.'postalcode';
				debugLog($b.': '.(string) $p->postcode);
				$message->$b = w2ui($p->postcode);
			}
			if (!empty($p->region)) {
				$b=$a.'state';
				debugLog($b.': '.(string) $p->region);
				$message->$b = w2ui($p->region);
			}
			if (!empty($p->country)) {
				$b=$a.'country';
				debugLog($b.': '.(string) $p->country);
				$message->$b = w2ui($p->country);
			}
		    }
		    
		    foreach ($xml->phoneNumber as $p) {
			preg_match("/[_a-z0-9]+$/", $p['rel'], $matches);
			$rel = $matches[0];
			switch ($rel) {
				case 'home':
					if(empty($message->homephonenumber)){
					    debugLog('GContacts::GetMessage - homephonenumber: '.(string) $p);
					    $message->homephonenumber = w2ui($p);
					}elseif(empty($message->home2phonenumber)){
					    debugLog('GContacts::GetMessage - home2phonenumber: '.(string) $p);
					    $message->home2phonenumber = w2ui($p);
					}else {
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p);
					}
					break;
				case 'home_fax':
					if(empty($message->homefaxnumber)){
					    debugLog('GContacts::GetMessage - homefaxnumber: '.(string) $p);
					    $message->homefaxnumber = w2ui($p);
					}else {
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p);
					}
					break;
				case 'work_fax':
					if(empty($message->businessfaxnumber)){
					    debugLog('GContacts::GetMessage - businessfaxnumber: '.(string) $p);
					    $message->businessfaxnumber = w2ui($p);
					}else {
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p);
					}
					break;
				case 'mobile':
					if(empty($message->mobilephonenumber)){
					    debugLog('GContacts::GetMessage - mobilephonenumber: '.(string) $p);
					    $message->mobilephonenumber = w2ui($p);
					}elseif(empty($message->home2phonenumber)){
					    debugLog('GContacts::GetMessage - home2phonenumber: '.(string) $p);
					    $message->home2phonenumber = w2ui($p);
					}elseif(empty($message->radiophonenumber)){
					    debugLog('GContacts::GetMessage - radiophonenumber: '.(string) $p);
					    $message->radiophonenumber = w2ui($p);
					}else {
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p);
					}
					break;
				case 'work':
					if(empty($message->businessphonenumber)){
					    debugLog('GContacts::GetMessage - businessphonenumber: '.(string) $p);
					    $message->businessphonenumber = w2ui($p);
					}elseif(empty($message->business2phonenumber)){
					    debugLog('GContacts::GetMessage - business2phonenumber: '.(string) $p);
					    $message->business2phonenumber = w2ui($p);
					}else {
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p);
					}
					break;
				case 'pager':
					if(empty($message->pagernumber)){
					    debugLog('GContacts::GetMessage - pagernumber: '.(string) $p);
					    $message->pagernumber = w2ui($p);
					}else {
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p);
					}
					break;
				case 'main':
				case 'other':
					if(empty($message->homephonenumber)){
					    debugLog('GContacts::GetMessage - homephonenumber: '.(string) $p);
					    $message->homephonenumber = w2ui($p);
					}elseif(empty($message->home2phonenumber)){
					    debugLog('GContacts::GetMessage - home2phonenumber: '.(string) $p);
					    $message->home2phonenumber = w2ui($p);
					}elseif(empty($message->businessphonenumber)){
					    debugLog('GContacts::GetMessage - businessphonenumber: '.(string) $p);
					    $message->businessphonenumber = w2ui($p);
					}elseif(empty($message->business2phonenumber)){
					    debugLog('GContacts::GetMessage - business2phonenumber: '.(string) $p);
					    $message->business2phonenumber = w2ui($p);
					}elseif(empty($message->carphonenumber)){
					    debugLog('GContacts::GetMessage - carphonenumber: '.(string) $p);
					    $message->carphonenumber = w2ui($p);
					}else {
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p);
					}
					break;
				default:
					    debugLog('GContacts::GetMessage - LOST phone number: '.(string) $p. ' phoneNumber di tipo '.$rel.': non censito');
					break;
			}
		    }
		    
		    if(!empty($xml->birthday['when'])){
			    debugLog('GContacts::GetMessage - birthday: '.(string) $xml->birthday['when']);
			    $tz = date_default_timezone_get();
			    date_default_timezone_set('UTC');
			    $message->birthday = strtotime($xml->birthday['when']);
			    date_default_timezone_set($tz);
		    }

		    foreach ($xml->website as $w) {
			debugLog('GContacts::GetMessage - webpage: '.(string) $w['href']);
			$message->webpage = w2ui($w['href']);
		    }

		    
		    //$e["id"] = (string)$contactid;
		    //$e["flags"] = "1";
		    //$e["mod"] = strtotime((string)$entry->getUpdated());
		    //$results[] = $e;
		    //debugLog((string)$entry->getUpdated());

	    if(!empty($entry->content)){
		debugLog('GContacts::GetMessage - Note: '.(string) $entry->content);
		if ($bodypreference === false) {
		$message->body = w2ui($entry->content);
		    $message->bodysize = strlen($entry->content);
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
				$plain = $entry->content;
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
					$plain = $entry->content;
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

	if(!empty($vcard['categories'][0]['val']))
	    $message->categories = $vcard['categories'][0]['val'];

	if(!empty($vcard['photo'][0]['val'][0]))
	    $message->picture = base64_encode($vcard['photo'][0]['val'][0]);

	} catch (Exception $e) {
		debugLog('Gcontacts::GetMessageList - Problem retrieving data (' . $e->exception() . ')');
		return false;
	}

	return $message;

	/*
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
	*/


/*
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
*/

	if(!empty($vcard['categories'][0]['val']))
	    $message->categories = $vcard['categories'][0]['val'];

	if(!empty($vcard['photo'][0]['val'][0]))
	    $message->picture = base64_encode($vcard['photo'][0]['val'][0]);

	return $message;
    }

    function DeleteMessage($folderid, $id) {
	debugLog("GContacts::DeleteMessage: (fid: '$folderid'  id: '$id' )");

	try {
		// delete entry
		$this->service->delete(GCONTACT_URL. '/' . $id);
		debugLog("GContacts::DeleteMessage - $id deleted");
	} catch (Exception $e) {
		debugLog("GContacts::DeleteMessage - ERROR! (" . $e->getMessage() . ")");
		return false;
	}
	return true;
    }

    function SetReadFlag($folderid, $id, $flags) {
	return false;
    }


    function ChangeMessage($folderid, $id, $message) {

	debugLog('GContacts::ChangeMessage('.$folderid.', '.$this->_items[$id].', ..)');

	$this->service->enableRequestDebugLogging(STATE_DIR. '/zendlog.txt'); //non mi funziona

	$doc  = new DOMDocument();
	$doc->formatOutput = true;

//bmain

	$xmlns2005='http://schemas.google.com/g/2005';
	$xmlns2008='http://schemas.google.com/contact/2008';

$atomentry = array (
    'name' => array (
	'@attributes' => array( 'xmlns' => $xmlns2005, ),
	'fullName' => array(
	    '@value' => u2wi($message->fileas),
	),
	'givenName' => array(
	    '@value' => u2wi($message->firstname),
	),
	'familyName' => array(
	    '@value' => u2wi($message->lastname),
	),
	'nameSuffix' => array(
	    '@value' => u2wi($message->suffix),
	),
    ),
    'organization' => array (
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#other", ),
	'orgName' => array(
	    '@value' => u2wi($message->companyname),
	),
	'orgTitle' => array(
	    '@value' => u2wi($message->jobtitle),
	),
    ),
    'nickname' => array (
	'@attributes' => array( 'xmlns' => $xmlns2008, ),
	'@value' => u2wi($message->nickname),
    ),
    'birthday' => array (
	'@attributes' => array ( 'xmlns' => $xmlns2008, 
	'when' => u2wi($message->birthday),
	),
    ),
    'website' => array (
	'@attributes' => array ( 'xmlns' => $xmlns2008, 'rel' => 'home-page',
	'href' => u2wi($message->webpage),
	),
    ),
    'event' =>  array (
	'@attributes' => array ( 'xmlns' => $xmlns2005, 'rel' => 'anniversary','xmlns:default'=>"http://schemas.google.com/g/2005" ),
	'default:when' => array(
	    '@attributes' => array(
		'startTime' => u2wi($message->anniversary),
	    ),
	),
    ),
    'structuredPostalAddress' =>  array (
	array (
	    '@attributes' => array ( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005.'#home',),
	    'formattedAddress' => u2wi($message->homestreet)."\n".
				  u2wi($message->homecity)."\n".
				  u2wi($message->homestate)."\n".
				  u2wi($message->homepostalcode)."\n".
				  u2wi($message->homecountry),
	    'street'=>u2wi($message->homestreet),
	    'postcode'=>u2wi($message->homepostalcode),
	    'city'=>u2wi($message->homecity),
	    'region'=>u2wi($message->homestate),
	    'country'=>u2wi($message->homecountry),
	),
	array (
	    '@attributes' => array ( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005.'#work',),
	    'formattedAddress' => u2wi($message->businessstreet)."\n".
				  u2wi($message->businesscity)."\n".
				  u2wi($message->businessstate)."\n".
				  u2wi($message->businesspostalcode)."\n".
				  u2wi($message->businesscountry),
	    'street'=>u2wi($message->businessstreet),
	    'postcode'=>u2wi($message->businesspostalcode),
	    'city'=>u2wi($message->businesscity),
	    'region'=>u2wi($message->businessstate),
	    'country'=>u2wi($message->businesscountry),
	),
	array (
	    '@attributes' => array ( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005.'#other',),
	    'formattedAddress' => u2wi($message->otherstreet)."\n".
				  u2wi($message->othercity)."\n".
				  u2wi($message->otherstate)."\n".
				  u2wi($message->otherpostalcode)."\n".
				  u2wi($message->othercountry),
	    'street'=>u2wi($message->otherstreet),
	    'postcode'=>u2wi($message->otherpostalcode),
	    'city'=>u2wi($message->othercity),
	    'region'=>u2wi($message->otherstate),
	    'country'=>u2wi($message->othercountry),
	),
    ),
    'email' => array (
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#home", 
	'address'=>u2wi($message->email1address)),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#work", 
	'address'=>u2wi($message->email2address)),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#other", 
	'address'=>u2wi($message->email3address)),
       ),
    ),
    'im' => array(
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#other", 
	'protocol' => 'http://schemas.google.com/g/2005#MSN',
	'address' => u2wi($message->imaddress2),),
       ),
    ),
    'userDefinedField' => array(
       array(
	'@attributes' => array( 'xmlns' => $xmlns2008,
	     'key' => 'Children',
	     'value' => u2wi($message->children),),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2008,
	     'key' => 'Spouse',
	     'value' => u2wi($message->spouse),),
       ),
    ),
    'phoneNumber' => array (
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#home", ),
	'@value' => u2wi($message->homephonenumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#home", ),
	'@value' => u2wi($message->home2phonenumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#work", ),
	'@value' => u2wi($message->businessphonenumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#work", ),
	'@value' => u2wi($message->business2phonenumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#mobile", ),
	'@value' => u2wi($message->mobilephonenumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#pager", ),
	'@value' => u2wi($message->pagernumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#other", ),
	'@value' => u2wi($message->carphonenumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#home_fax", ),
	'@value' => u2wi($message->homefaxnumber),
       ),
       array(
	'@attributes' => array( 'xmlns' => $xmlns2005, 'rel' => $xmlns2005."#work_fax", ),
	'@value' => u2wi($message->businessfaxnumber),
       ),
    ),
);
	//if no ID given -> create new event
	//if ID given -> load old event, modify it, save it

	if(!empty($id)) {

		try {
		  debugLog("GContacts::ChangeMessage - Import data from existing contact");
		
		  // perform query and get entry
		  $query = new Zend_Gdata_Query(GCONTACTS_URL .'/'. $id);
		  $entry = $this->service->getEntry($query);

		  $success = $doc->loadXML($entry->getXML()); // creo le stesse strutture

		  //$xml = simplexml_load_string($entry->getXML());
		  $root = $doc->getElementsByTagName("entry")->item(0);

		} catch (Exception $e) {
		    debugLog("GContacts::ChangeMessage - ERROR! (" . $e->getMessage() . ")");
		}

	} else {
		try {
		  debugLog("GContacts::ChangeMessage - Creating atom:entry structure");
		  if ($doc->getElementsByTagName("entry")->length == 0 ) {
		  // create new entry
		  $entry = $doc->createElement('atom:entry');
		  $entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,
		   'xmlns:atom', 'http://www.w3.org/2005/Atom');
		  $entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,
		   'xmlns:gd', 'http://schemas.google.com/g/2005');
		  $root = $doc->appendChild($entry);
		  } else {
		     //only for internal debug
		     $root = $doc->getElementsByTagName("entry")->item(0);
		  }

		} catch (Exception $e) {
		    debugLog("GContacts::ChangeMessage - ERROR! (" . $e->getMessage() . ")");
		    return false;
		}
	}

	$doc = AtoX($atomentry, $doc, $root);


//emain

/*
	
	if(!empty($message->fileas)){
		$xml->name->fullName = u2wi($message->fileas);
	} else {
		$x= 1;
	}

		    foreach ($xml->email as $email) {
			preg_match("/[_a-z0-9]+$/", $email['rel'], $matches);
			$rel = $matches[0];
			
			//isset($email['primary']
			switch ($rel) {
			    case 'home':
					if (!empty($message->email1address)) {
						$email['address'] = $message->email1address;
					}
				break;
			    case 'work':
					if (!empty($message->email2address)) {
						$email['address'] = $message->email2address;
					}
				break;
			    case 'other':
					if (!empty($message->email3address)) {
						$email['address'] = $message->email3address;
					}
				break;
			//    default:
			//	break;
			}
			
		    }
		  
*/
/*		  // add email element
		  if (!empty($message->email1address)) {
		    $email = $doc->createElement('gd:email');
		    $email->setAttribute('address' , u2wi($message->email1address));
		    $email->setAttribute('rel' ,'http://schemas.google.com/g/2005#home');
		    $entry->appendChild($email);
		  }
		  if (!empty($message->email2address)) {
		    $email = $doc->createElement('gd:email');
		    $email->setAttribute('address' , u2wi($message->email2address));
		    $email->setAttribute('rel' ,'http://schemas.google.com/g/2005#work');
		    $entry->appendChild($email);
		  }
		  if (!empty($message->email3address)) {
		    $email = $doc->createElement('gd:email');
		    $email->setAttribute('address' , u2wi($message->email3address));
		    $email->setAttribute('rel' ,'http://schemas.google.com/g/2005#other');
		    $entry->appendChild($email);
		  }

*/


		    $fh = fopen(STATE_DIR.'/xml-put/'.(string) $message->fileas.'.xml', 'w');
		    fwrite($fh,$doc->saveXML());
		    $xml = simplexml_load_string($xmldata);
		    fclose($fh);

	try {
		$newEntry = NULL;
		if(!empty($id)) {
			$extra_header = array();
			$extra_header['If-Match']='*';
			//$extra_header = array('If-Match'=>'*');
			$newEntry = $this->service->updateEntry($doc->saveXML(), $entry->getEditLink()->href,null,$extra_header);
			file_put_contents(STATE_DIR."/obj_updateEntry_dump.txt", serialize($newEntry) . "\n\n", FILE_APPEND);
		} else {
			$newEntry = $this->service->insertEntry($doc->saveXML(), GCONTACTS_URL);
			file_put_contents(STATE_DIR."/obj_insertEntry_dump.txt", serialize($newEntry) . "\n\n", FILE_APPEND);
		}
		
		//filter out real contact id without other garbage
		preg_match("/[_a-z0-9]+$/", $newEntry->id, $matches);
		$contactid = $matches[0];

		$m = Array();
		$m["id"] = (string)$contactid;
		$m["flags"] = "1";
		$m["mod"] = strtotime((string)$newEntry->getUpdated());
		debugLog("GCalendar::ChangeMessage - Inserted Entry: ".$m['id']);
		return $m;

	} catch (Exception $e) {
		debugLog("GContacts::ChangeMessage - ERROR! (" . $e->getMessage() . ")");
		return false;
	}
	
	return false;

/*	$mapping = array(
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
	    'nickname' => 'NICKNAME',
	);
*/

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
	    $ks = preg_split("/;/", $k);
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
	$data .= "END:VCARD\n";

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
			file_put_contents(STATE_DIR . '/' . strtolower($this->_devid). '/gcontacts_items_'. $this->_user, serialize($this->_items));
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
