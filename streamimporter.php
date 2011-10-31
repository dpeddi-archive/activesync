<?php
/***********************************************
* File      :   streamimporter.php
* Project   :   Z-Push
* Descr     :   Stream import classes
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

// We don't support caching changes for messages
class ImportContentsChangesStream {
    var $_encoder;
    var $_type;
    var $_seenObjects;
    var $_deletedObjects;
    var $_optiontype;
    var $_onlyoption;
    var $_lastObjectStatus;
    var $_readids;
    var $_flagids;
	var $_msginfos;

    function ImportContentsChangesStream(&$encoder, $type, $ids, &$msginfos) {
        $this->_encoder = &$encoder;
        $this->_type = $type;
        $this->_seenObjects = array();
        $this->_deletedObjects = array();
        $this->_readids = $ids['readids'];
        $this->_flagids = $ids['flagids'];
		if (!is_array($msginfos)) $this->_msginfos = array();
		else $this->_msginfos = $msginfos;
    }

    function ImportMessageChange($id, $message) {
//	debugLog("Class of this message: ".strtolower(get_class($message)) . " Expected Class ".$this->_type . " Option Type ".$this->_optiontype);
//	debugLog("HERE ImportMessageChange ".$this->_optiontype);

		$class = strtolower(get_class($message));
        if( $class != $this->_type) {
	    	$this->_lastObjectStatus = -1;
    	    return true; // ignore other types
		}

        // prevent sending the same object twice in one request
        if (in_array($id, $this->_seenObjects)) {
            debugLog("Object $id discarded! Object already sent in this request. Flags=".$message->flags);
	    	$this->_lastObjectStatus = -1;
            return true;
        }

		// prevent sending changes for objects that delete information was sent prior the change details arrived
        if (in_array($id, $this->_deletedObjects)) {
            debugLog("Object $id discarded! Object deleted prior change submission.");
		    $this->_lastObjectStatus = -1;
            return true;
        } 

        debugLog("ImportMessageChange: Object $id Class ".strtolower(get_class($message))." Flags=".$message->flags);
		switch($class) {
			case 'syncnote' 		:
				$md5msg = array('messageclass' 				=> (isset($message->messageclass) 			? $message->messageclass 			: ''),
								'subject' 					=> (isset($message->subject) 				? $message->subject 				: ''),
								'categories' 				=> (isset($message->categories)				? $message->categories				: ''),
								'body' 						=> (isset($message->md5body) 				? $message->md5body 				: ""),
								'lastmodifieddate' 			=> (isset($message->lastmodifieddate) 		? $message->lastmodifieddate		: ""),
								);
				$md5flags = array();
				break;
			case 'synctask' 		:
				$md5msg = array('complete' 					=> (isset($message->complete) 				? $message->complete 				: ''),
								'datecompleted' 			=> (isset($message->datecompleted) 			? $message->datecompleted			: ''),
								'duedate' 					=> (isset($message->duedate) 				? $message->duedate					: ''),
								'utcduedate' 				=> (isset($message->utcduedate) 			? $message->utcduedate				: ''),
								'importance' 				=> (isset($message->importance) 			? $message->importance				: ''),
								'recurrence' 				=> (isset($message->recurrence) 			? $message->recurrence				: ''),
								'regenerate' 				=> (isset($message->regenerate) 			? $message->regenerate				: ''),
								'deadoccur' 				=> (isset($message->deadoccur)	 			? $message->deadoccur				: ''),
								'reminderset' 				=> (isset($message->reminderset)	 		? $message->reminderset				: ''),
								'remindertime' 				=> (isset($message->remindertime)	 		? $message->remindertime			: ''),
								'sensitivity' 				=> (isset($message->sensitivity)	 		? $message->sensitivity				: ''),
								'startdate' 				=> (isset($message->startdate) 				? $message->startdate				: ''),
								'utcstartdate' 				=> (isset($message->utcstartdate) 			? $message->utcstartdate			: ''),
								'subject' 					=> (isset($message->subject) 				? $message->subject 				: ''),
								'categories' 				=> (isset($message->categories)				? $message->categories				: ''),
								'body' 						=> (isset($message->md5body) 				? $message->md5body 				: ""),
								);
				$md5flags = array();
				break;
			case 'syncappointment' 	:
				$md5msg = array('timezone' 					=> (isset($message->timezone) 				? $message->timezone 				: ''),
								'dtstamp' 					=> (isset($message->dtstamp) 				? $message->dtstamp					: ''),
								'starttime' 				=> (isset($message->starttime) 				? $message->starttime				: ''),
								'subject' 					=> (isset($message->subject) 				? $message->subject 				: ''),
								'uid' 						=> (isset($message->uid) 					? $message->uid						: ''),
								'organizername' 			=> (isset($message->organizername) 			? $message->organizername			: ''),
								'organizeremail' 			=> (isset($message->organizeremail) 		? $message->organizeremail			: ''),
								'location' 					=> (isset($message->location) 				? $message->location				: ''),
								'endtime' 					=> (isset($message->endtime)	 			? $message->endtime					: ''),
								'recurrence' 				=> (isset($message->recurrence)	 			? $message->recurrence				: ''),
								'sensitivity' 				=> (isset($message->sensitivity)	 		? $message->sensitivity				: ''),
								'busystatus' 				=> (isset($message->busystatus) 			? $message->busystatus				: ''),
								'alldayevent' 				=> (isset($message->alldayevent) 			? $message->alldayevent				: ''),
								'reminder' 					=> (isset($message->reminder)				? $message->reminder				: ''),
								'meetingstatus' 			=> (isset($message->meetingstatus)			? $message->meetingstatus			: ''),
								'attendees' 				=> (isset($message->attendees)				? $message->attendees				: ''),
								'exceptions' 				=> (isset($message->exceptions)				? $message->exceptions				: ''),
								'deleted' 					=> (isset($message->deleted)				? $message->deleted					: ''),
								'exceptionstarttime' 		=> (isset($message->exceptionsstarttime)	? $message->exceptionsstarttime		: ''),
								'categories' 				=> (isset($message->categories)				? $message->categories				: ''),
								'body' 						=> (isset($message->md5body) 				? $message->md5body 				: ""),
							);
				$md5flags = array();
				break;
			case 'synccontact' 		:
				$md5msg = array('anniversary'				=> (isset($message->anniversary)			? $message->anniversary				: ''),
								'assistentname'				=> (isset($message->assistentname)			? $message->assistentname			: ''),
								'assistnamephonenumber'		=> (isset($message->assistnamephonenumber)	? $message->assistnamephonenumber	: ''),
								'birthday'					=> (isset($message->birthday)				? $message->birthday				: ''),
								'business2phonenumber'		=> (isset($message->business2phonenumber)	? $message->business2phonenumber	: ''),
								'businesscity'				=> (isset($message->businesscity)			? $message->businesscity			: ''),
								'businesscountry'			=> (isset($message->businesscountry)		? $message->businesscountry			: ''),
								'businesspostalcode'		=> (isset($message->businesspostalcode)		? $message->businesspostalcode		: ''),
								'businessstate'				=> (isset($message->businessstate)			? $message->businessstate			: ''),
								'businessstreet'			=> (isset($message->businessstreet)			? $message->businessstreet			: ''),
								'businessfaxnumber'			=> (isset($message->businessfaxnumber)		? $message->businessfaxnumber		: ''),
								'businessphonenumber'		=> (isset($message->businessphonenumber)	? $message->businessphonenumber		: ''),
								'carphonenumber'			=> (isset($message->carphonenumber)			? $message->carphonenumber			: ''),
								'children'					=> (isset($message->children)				? $message->children				: ''),
								'companyname'				=> (isset($message->companyname)			? $message->companyname				: ''),
								'department'				=> (isset($message->department)				? $message->department				: ''),
								'email1address'				=> (isset($message->email1address)			? $message->email1address			: ''),
								'email2address'				=> (isset($message->email2address)			? $message->email2address			: ''),
								'email3address'				=> (isset($message->email3address)			? $message->email3address			: ''),
								'fileas'					=> (isset($message->fileas)					? $message->fileas					: ''),
								'firstname'					=> (isset($message->firstname)				? $message->firstname				: ''),
								'home2phonenumber'			=> (isset($message->home2phonenumber)		? $message->home2phonenumber		: ''),
								'homecity'					=> (isset($message->homecity)				? $message->homecity				: ''),
								'homecountry'				=> (isset($message->homecountry)			? $message->homecountry				: ''),
								'homepostalcode'			=> (isset($message->homepostalcode)			? $message->homepostalcode			: ''),
								'homestate'					=> (isset($message->homestate)				? $message->homestate				: ''),
								'homestreet'				=> (isset($message->homestreet)				? $message->homestreet				: ''),
								'homefaxnumber'				=> (isset($message->homefaxnumber)			? $message->homefaxnumber			: ''),
								'homephonenumber'			=> (isset($message->homephonenumber)		? $message->homephonenumber			: ''),
								'jobtitle'					=> (isset($message->jobtitle)				? $message->jobtitle				: ''),
								'lastname'					=> (isset($message->lastname)				? $message->lastname				: ''),
								'middlename'				=> (isset($message->middlename)				? $message->middlename				: ''),
								'mobilephonenumber'			=> (isset($message->mobilephonenumber)		? $message->mobilephonenumber		: ''),
								'officelocation'			=> (isset($message->officelocation)			? $message->officelocation			: ''),
								'othercity'					=> (isset($message->othercity)				? $message->othercity				: ''),
								'othercountry'				=> (isset($message->othercountry)			? $message->othercountry			: ''),
								'otherpostalcode'			=> (isset($message->otherpostalcode)		? $message->otherpostalcode			: ''),
								'otherstate'				=> (isset($message->otherstate)				? $message->otherstate				: ''),
								'otherstreet'				=> (isset($message->otherstreet)			? $message->otherstreet				: ''),
								'pagernumber'				=> (isset($message->pagernumber)			? $message->pagernumber				: ''),
								'radiophonenumber'			=> (isset($message->radiophonenumber)		? $message->radiophonenumber		: ''),
								'spouse'					=> (isset($message->spouse)					? $message->spouse					: ''),
								'suffix'					=> (isset($message->suffix)					? $message->suffix					: ''),
								'title'						=> (isset($message->title)					? $message->title					: ''),
								'webpage'					=> (isset($message->webpage)				? $message->webpage					: ''),
								'yomicompanyname'			=> (isset($message->yomicompanyname)		? $message->yomicompanyname			: ''),
								'yomifirstname'				=> (isset($message->yomifirstname)			? $message->yomifirstname			: ''),
								'yomilastname'				=> (isset($message->yomilastname)			? $message->yomilastname			: ''),
								'picture'					=> (isset($message->picture)				? $message->picture					: ''),
								'customerid'				=> (isset($message->customerid)				? $message->customerid				: ''),
								'governmentid'				=> (isset($message->governmentid)			? $message->governmentid			: ''),
								'imaddress'					=> (isset($message->imaddress)				? $message->imaddress				: ''),
								'imaddress2'				=> (isset($message->imaddress2)				? $message->imaddress2				: ''),
								'imaddress3'				=> (isset($message->imaddress3)				? $message->imaddress3				: ''),
								'managername'				=> (isset($message->managername)			? $message->managername				: ''),
								'companymainphone'			=> (isset($message->companymainphone)		? $message->companymainphone		: ''),
								'accountname'				=> (isset($message->accountname)			? $message->accountname				: ''),
								'nickname'					=> (isset($message->nickname)				? $message->nickname				: ''),
								'mms'						=> (isset($message->mms)					? $message->mms						: ''),
								'categories' 				=> (isset($message->categories)				? $message->categories				: ''),
								'body' 						=> (isset($message->md5body) 				? $message->md5body 				: ""),
							);
				$md5flags = array();
				break;
			case 'syncsms' 			:
				debugLog(bin2hex($message->to));
				debugLog(bin2hex($message->from));
				debugLog(bin2hex($message->cc));
				debugLog(bin2hex($message->airsyncbasebody->data));
				$md5msg = array('datereceived' 				=> (isset($message->datereceived) 			? strval($message->datereceived) 				: ''),
								'importance' 				=> (isset($message->importance) 			? strval($message->importance) 					: ''),
								'messageclass' 				=> (isset($message->messageclass) 			? strval($message->messageclass) 				: ''),
								'to' 						=> (isset($message->to) 					? strval($message->to) 							: ''),
								'cc' 						=> (isset($message->cc) 					? strval($message->cc) 							: ''),
								'from' 						=> (isset($message->from) 					? strval($message->from) 						: ''),
								'internetcpid' 				=> (isset($message->internetcpid) 			? strval($message->internetcpid) 				: ''),
//								'conversationid' 			=> (isset($message->conversationid) 		? bin2hex($message->conversationid) 	: ''),
//								'conversationindex' 		=> (isset($message->conversationindex) 		? bin2hex($message->conversationindex) 	: ''),
								'body' 						=> (isset($message->airsyncbasebody->data)	? strval($message->airsyncbasebody->data) 		: ''),
								);
				$md5flags = array('flagstatus' 		=> (isset($message->poommailflag->flagstatus) 		? $message->poommailflag->flagstatus 		: ''),
								  'flagtype'		=> (isset($message->poommailflag->flagtype) 		? $message->poommailflag->flagtype 			: ''),
								  'startdate'		=> (isset($message->poommailflag->startdate) 		? $message->poommailflag->startdate 		: ''),
								  'utcstartdate'	=> (isset($message->poommailflag->utcstartdate) 	? $message->poommailflag->utcstartdate 		: ''),
								  'duedate'			=> (isset($message->poommailflag->duedate) 			? $message->poommailflag->duedate 			: ''),
								  'utcduedate'		=> (isset($message->poommailflag->utcduedate) 		? $message->poommailflag->utcduedate 		: ''),
								  'datecomplete'	=> (isset($message->poommailflag->datecompleted) 	? $message->poommailflag->datecompleted 	: ''),
								  'reminderset' 	=> (isset($message->poommailflag->reminderset) 		? $message->poommailflag->reminderset 		: ''),
								  'subject'			=> (isset($message->poommailflag->subject) 			? $message->poommailflag->subject 			: ''),
								  'ordinaldate'		=> (isset($message->poommailflag->ordinaldate) 		? $message->poommailflag->ordinaldate 		: ''),
								  'subordinaldate'	=> (isset($message->poommailflag->subordinaldate) 	? $message->poommailflag->subordinaldate 	: ''),
								  'completetime'	=> (isset($message->poommailflag->completetime) 	? $message->poommailflag->completetime 		: ''),
								  );
				break;
			case 'syncmail' 		:
				$md5msg = array('datereceived' 				=> (isset($message->datereceived) 			? $message->datereceived 			: ''),
								'displayto' 				=> (isset($message->displayto) 				? $message->displayto 				: ''),
								'importance' 				=> (isset($message->importance) 			? $message->importance 				: ''),
								'messageclass' 				=> (isset($message->messageclass) 			? $message->messageclass 			: ''),
								'subject' 					=> (isset($message->subject) 				? $message->subject 				: ''),
								'to' 						=> (isset($message->to) 					? $message->to 						: ''),
								'cc' 						=> (isset($message->cc) 					? $message->cc 						: ''),
								'from' 						=> (isset($message->from) 					? $message->from 					: ''),
								'reply_to' 					=> (isset($message->reply_to)				? $message->reply_to 				: ''),
								'threadtopic' 				=> (isset($message->threadtopic) 			? $message->threadtopic 			: ''),
								'attachments' 				=> (isset($message->attachments) 			? $message->attachments 			: ''),
								'airsyncbaseattachments' 	=> (isset($message->airsyncbaseattachments) ? $message->airsyncbaseattachments 	: ''),
								'displayname' 				=> (isset($message->displayname) 			? $message->displayname 			: ''),
								'internetcpid' 				=> (isset($message->internetcpid) 			? $message->internetcpid 			: ''),
								'meetingrequest' 			=> (isset($message->meetingrequest) 		? $message->meetingrequest 			: ''),
								'umcallerid' 				=> (isset($message->umcallerid)		 		? $message->umcallerid	 			: ''),
								'umusernotes' 				=> (isset($message->umusernotes)	 		? $message->umusernotes				: ''),
//								'conversationid' 			=> (isset($message->conversationid) 		? bin2hex($message->conversationid) 			: ''),
//								'conversationindex' 		=> (isset($message->conversationindex) 		? bin2hex($message->conversationindex) 		: ''),
							    'lastverbexecutiontime' 	=> (isset($message->lastverbexecutiontime) 	? $message->lastverbexecutiontime 	: ''),
							    'lastverbexecuted' 			=> (isset($message->lastverbexecuted) 		? $message->lastverbexecuted 		: ''),
								'receivedasbcc'				=> (isset($message->receivedasbcc)	 		? $message->receivedasbcc			: ''),
								'sender'	 				=> (isset($message->sender)			 		? $message->sender					: ''),
								'body' 						=> (isset($message->md5body) 				? $message->md5body 				: ""),
								);
//		debugLog(print_r($md5msg,true));
				$md5flags = array('flagstatus' 		=> (isset($message->poommailflag->flagstatus) 		? $message->poommailflag->flagstatus 		: ''),
								  'flagtype'		=> (isset($message->poommailflag->flagtype) 		? $message->poommailflag->flagtype 			: ''),
								  'startdate'		=> (isset($message->poommailflag->startdate) 		? $message->poommailflag->startdate 		: ''),
								  'utcstartdate'	=> (isset($message->poommailflag->utcstartdate) 	? $message->poommailflag->utcstartdate 		: ''),
								  'duedate'			=> (isset($message->poommailflag->duedate) 			? $message->poommailflag->duedate 			: ''),
								  'utcduedate'		=> (isset($message->poommailflag->utcduedate) 		? $message->poommailflag->utcduedate 		: ''),
								  'datecomplete'	=> (isset($message->poommailflag->datecompleted) 	? $message->poommailflag->datecompleted 	: ''),
								  'reminderset' 	=> (isset($message->poommailflag->reminderset) 		? $message->poommailflag->reminderset 		: ''),
								  'subject'			=> (isset($message->poommailflag->subject) 			? $message->poommailflag->subject 			: ''),
								  'ordinaldate'		=> (isset($message->poommailflag->ordinaldate) 		? $message->poommailflag->ordinaldate 		: ''),
								  'subordinaldate'	=> (isset($message->poommailflag->subordinaldate) 	? $message->poommailflag->subordinaldate 	: ''),
								  'completetime'	=> (isset($message->poommailflag->completetime) 	? $message->poommailflag->completetime 		: ''),
								  );
				break;
			default 				:
				$md5msg = array();
				$md5flags = array();
				break;
		}
		$msginfo['md5msg'] = md5(serialize($md5msg));
		$msginfo['md5flags'] = md5(serialize($md5flags));
		$msginfo['read'] = (isset($message->read) ? $message->read : '');
		$msginfo['class'] = $class;
/*		if ($message->flags === false || $message->flags === SYNC_NEWMESSAGE) {
			if (isset($this->_msginfos[$id])) 
				unset($this->_msginfos[$id]);
		}
*/		unset($md5msg);
		unset($md5flags);

		if (isset($this->_msginfos[$id]) &&
			$this->_msginfos[$id]['md5msg'] == $msginfo['md5msg'] &&
			$this->_msginfos[$id]['md5flags'] == $msginfo['md5flags'] &&
			$this->_msginfos[$id]['read'] == $msginfo['read']) {
			debugLog("ImportMessageChange: Discarding change since read,  md5 sums for flags and message didn't change");
		    $this->_lastObjectStatus = -1;
            return true;
		} 

        $this->_seenObjects[] = $id;

        if (!isset($this->_msginfos[$id]) && ($message->flags === false || $message->flags === SYNC_NEWMESSAGE)) 
    	    $this->_encoder->startTag(SYNC_ADD);
        else
    	    $this->_encoder->startTag(SYNC_MODIFY);

		if (isset($this->_msginfos[$id])) {
			if ($this->_msginfos[$id]['md5msg'] != $msginfo['md5msg']) debugLog("ImportMessageChange: Whole message changed");
			if ($this->_msginfos[$id]['md5flags'] != $msginfo['md5flags']) debugLog("ImportMessageChange: MD5 Flags changes ".$msginfo['md5flags']." vs ".$this->_msginfos[$id]['md5flags']);
			if ($this->_msginfos[$id]['read'] != $msginfo['read']) debugLog("ImportMessageChange: Read change");
		} else {
			debugLog("ImportMessageChange: Seems to be new message, no entry in msginfos");
		}

		if ($class == 'syncsms') {
	    	$this->_encoder->startTag(SYNC_FOLDERTYPE);
		    $this->_encoder->content("SMS");
    	    $this->_encoder->endTag();
    	}
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->startTag(SYNC_DATA);

        if (((isset($this->_msginfos[$id]) && $this->_msginfos[$id]['md5msg'] != $msginfo['md5msg']) ||
        	  !isset($this->_msginfos[$id])) && !isset($this->_readids[$id]) && !isset($this->_flagids[$id])) {
    	    $message->encode($this->_encoder);
		} else {
    	    if ((isset($this->_msginfos[$id]) && $this->_msginfos[$id]['read'] != $msginfo['read']) ||
        	 	isset($this->_readids[$id])) {
				$this->_encoder->startTag(SYNC_POOMMAIL_READ);
    			$this->_encoder->content($message->read);
	    		$this->_encoder->endTag();
				unset($this->_readids[$id]);
		    }
		    if ((isset($this->_msginfos[$id]) && $this->_msginfos[$id]['md5flags'] != $msginfo['md5flags']) ||
        	 	isset($this->_flagids[$id])) {
				if ($message->poommailflag->flagstatus == 0 || $message->poommailflag->flagstatus == "") {
				    $this->_encoder->startTag(SYNC_POOMMAIL_FLAG,false,true);
				} else {
				    $this->_encoder->startTag(SYNC_POOMMAIL_FLAG);
	        	    $message->poommailflag->encode($this->_encoder);
	    		    $this->_encoder->endTag();
				}
				unset($this->_flagids[$id]);
		    }
        }
/*        if (!in_array($id, $this->_readids) && !in_array($id, $this->_flagids)) {
    	    $message->encode($this->_encoder);
	} else {
    	    if (in_array($id, $this->_readids)) {
		$this->_encoder->startTag(SYNC_POOMMAIL_READ);
    		$this->_encoder->content($message->read);
    		$this->_encoder->endTag();
	    }
	    if (in_array($id, $this->_flagids)) {
		if ($message->poommailflag->flagstatus == 0 || $message->poommailflag->flagstatus == "") {
		    $this->_encoder->startTag(SYNC_POOMMAIL_FLAG,false,true);
		} else {
		    $this->_encoder->startTag(SYNC_POOMMAIL_FLAG);
        	    $message->poommailflag->encode($this->_encoder);
    		    $this->_encoder->endTag();
		}
	    }
        }
*/
        $this->_encoder->endTag();
        $this->_encoder->endTag();

		$this->_lastObjectStatus = 1;
		$this->_msginfos[$id] = $msginfo;
        return true;
    }

    function ImportMessageDeletion($id) {
//	debugLog("HERE ImportMessageDeletion ".$this->_optiontype);

	// prevent sending changes for objects that delete information was sent already
        if (in_array($id, $this->_deletedObjects)) {
            debugLog("Object $id discarded! Object already deleted.");
	    	$this->_lastObjectStatus = -1;
    	    return true;
        } 
        $this->_deletedObjects[] = $id;
		if (isset($this->_msginfos[$id])) {
			if (isset($this->_msginfos[$id]['class']) &&
				$this->_type != $this->_msginfos[$id]['class']) {
            	debugLog("Object $id Optiontype and type not matching class stored in msginfo, discarding");
	    		$this->_lastObjectStatus = -1;
            	return true;
			}
		} else {
        	debugLog("Delete for Object $id should be exported but object is not in sync with client, discarding");
	    	$this->_lastObjectStatus = -1;
        	return true;
        }
        $this->_encoder->startTag(SYNC_REMOVE);
		if ($this->_msginfos[$id]['class'] == "syncsms") {
		    $this->_encoder->startTag(SYNC_FOLDERTYPE);
		    $this->_encoder->content("SMS");
    	    $this->_encoder->endTag();
		}
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->endTag();

		$this->_lastObjectStatus = 1;
		unset($this->_msginfos[$id]);
        return true;
    }

    function ImportMessageReadFlag($id, $flags) {
		debugLog("HERE ImportMessageReadFlag ".$this->_type);
		// prevent sending readflags for objects that delete information was sentbefore
        if (in_array($id, $this->_deletedObjects)) {
    	    debugLog("Object $id discarded! Object got deleted prior the readflag set request arrived.");
		    $this->_lastObjectStatus = -1;
    	    return true;
        }
        if($this->_type != "syncmail") {
	    	$this->_lastObjectStatus = -1;
    	    return true;
		}
		if (isset($this->_msginfos[$id])) {
			if (isset($this->_msginfos[$id]['class']) &&
				$this->_type != $this->_msginfos[$id]['class']) {
            	debugLog("Object $id Optiontype and type not matching class stored in msginfo, discarding");
	    		$this->_lastObjectStatus = -1;
            	return true;
			}
			if (isset($this->_msginfos[$id])) $this->_msginfos[$id]['read'] = $flags;
		} else {
        	debugLog("Object $id is not in sync with client, discarding");
	    	$this->_lastObjectStatus = -1;
        	return true;
        }
        $this->_encoder->startTag(SYNC_MODIFY);
		if ($this->_msginfos[$id]['class'] == "syncsms") {
		    $this->_encoder->startTag(SYNC_FOLDERTYPE);
		    $this->_encoder->content("SMS");
    	    $this->_encoder->endTag();
    	}
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->startTag(SYNC_DATA);
        $this->_encoder->startTag(SYNC_POOMMAIL_READ);
        $this->_encoder->content($flags);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        $this->_encoder->endTag();

		$this->_lastObjectStatus = 1;
        return true;
    }

    function ImportMessageMove($message) {
		debugLog("HERE ImportMessageMove ".$this->_type);
    	return true;
    }
};

class ImportHierarchyChangesStream {

    function ImportHierarchyChangesStream() {
        return true;
    }

    function ImportFolderChange($folder) {
        return true;
    }

    function ImportFolderDeletion($folder) {
        return true;
    }
};

?>