<?php
/***********************************************
* File      :   imap.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               IMAP interface
*
* Created   :   10.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once('diffbackend.php');

// get this from http://www.chuggnutt.com/phpcode/class.html2text.inc.gz
// extract it to include folder and rename the file to class.html2text.inc.php
if (include_file_exists('class.html2text.inc.php') == true) {
    include_once 'class.html2text.inc.php';
}

// The is an improved version of mimeDecode from PEAR that correctly
// handles charsets and charset conversion
include_once('mime.php');
include_once('mimeDecode.php');
include_once('mimeMagic.php');
require_once('z_RFC822.php');

class BackendIMAP extends BackendDiff {

	var $_loggedin=false;

    /* Called to logon a user. These are the three authentication strings that you must
     * specify in ActiveSync on the PDA. Normally you would do some kind of password
     * check here. Alternatively, you could ignore the password here and have Apache
     * do authentication via mod_auth_*
     */
    function Logon($username, $domain, $password) {

        $this->_wasteID = false;
        $this->_sentID = false;
        $this->_server = "{" . IMAP_SERVER . ":" . IMAP_PORT . "/imap" . IMAP_OPTIONS . "}";

        if (!function_exists("imap_open"))
            debugLog("ERROR BackendIMAP : PHP-IMAP module not installed!!!!!");

        // open the IMAP-mailbox
        debugLog("Login starts here");
        $this->_mbox = @imap_open($this->_server , $username, $password, OP_HALFOPEN);
        debugLog("Login ended here");
        $this->_mboxFolder = "";

        if ($this->_mbox) {
            debugLog("IMAP connection opened sucessfully ");
            $this->_username = $username;
            $this->_domain = $domain;
            // set serverdelimiter
             $this->_serverdelimiter = $this->getServerDelimiter();
			$this->_loggedin=true;
            return true;
        }
        else {
            debugLog("IMAP can't connect: " . imap_last_error());
            return false;
        }


    }

    /* Called before shutting down the request to close the IMAP connection
     */
    function Logoff() {
        if ($this->_mbox) {
            // list all errors
            $errors = imap_errors();
            if (is_array($errors)) {
                foreach ($errors as $e)    debugLog("IMAP-errors: $e");
            }
            @imap_close($this->_mbox);
            debugLog("IMAP connection closed");
        }
    }

    /* Called directly after the logon. This specifies the client's protocol version
     * and device id. The device ID can be used for various things, including saving
     * per-device state information.
     * The $user parameter here is normally equal to the $username parameter from the
     * Logon() call. In theory though, you could log on a 'foo', and then sync the emails
     * of user 'bar'. The $user here is the username specified in the request URL, while the
     * $username in the Logon() call is the username which was sent as a part of the HTTP
     * authentication.
     */
    function Setup($user, $devid, $protocolversion) {
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

		// FolderID Cache
    	$dir = opendir(STATE_PATH. "/" .strtolower($this->_devid));
        if(!$dir) {
	    	debugLog("IMAP Backend: creating folder for device ".strtolower($this->_devid));
	    	if (mkdir(STATE_PATH. "/" .strtolower($this->_devid), 0744) === false) 
				debugLog("IMAP Backend: failed to create folder ".strtolower($this->_devid));
		}
		$filename = STATE_DIR . '/' . strtolower($this->_devid). '/imap_folders_'. $this->_user;
		$this->_folders = false;
		if (file_exists($filename)) {
	    	if (($this->_folders = file_get_contents(STATE_DIR . '/' . strtolower($this->_devid). '/imap_folders_'. $this->_user)) !== false) {
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

        return true;
    }

    /* Sends a message which is passed as rfc822. You basically can do two things
     * 1) Send the message to an SMTP server as-is
     * 2) Parse the message yourself, and send it some other way
     * It is up to you whether you want to put the message in the sent items folder. If you
     * want it in 'sent items', then the next sync on the 'sent items' folder should return
     * the new message as any other new message in a folder.
     */
    function SendMail($rfc822, $smartdata=array(), $protocolversion = false) {
	// file_put_contents(BASE_PATH."/mail.dmp/".$this->_folderid(), $rfc822);
        if ($protocolversion < 14.0)
    	    debugLog("IMAP-SendMail: " . (isset($rfc822) ? $rfc822 : ""). "task: ".(isset($smartdata['task']) ? $smartdata['task'] : "")." itemid: ".(isset($smartdata['itemid']) ? $smartdata['itemid'] : "")." parent: ".(isset($smartdata['folderid']) ? $smartdata['folderid'] : ""));

        $mimeParams = array('decode_headers' => false,
                            'decode_bodies' => true,
                            'include_bodies' => true,
                            'input' => $rfc822,
                            'crlf' => "\r\n",
                            'charset' => 'utf-8');
        $mobj = new Mail_mimeDecode($mimeParams['input'], $mimeParams['crlf']);
        $message = $mobj->decode($mimeParams, $mimeParams['crlf']);


        $Mail_RFC822 = new Mail_RFC822();
        $toaddr = $ccaddr = $bccaddr = "";
        if(isset($message->headers["to"]))
            $toaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["to"]));
        if(isset($message->headers["cc"]))
            $ccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["cc"]));
        if(isset($message->headers["bcc"]))
            $bccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["bcc"]));


        $map_user_fullname = false;
        if (defined('IMAP_USERNAME_FULLNAME') && strlen(IMAP_USERNAME_FULLNAME) > 0) 
    	    $map_user_fullname = unserialize(IMAP_USERNAME_FULLNAME);
		$addedfullname = false;
        // save some headers when forwarding mails (content type & transfer-encoding)
        $headers = "";
        $forward_h_ct = "";
        $forward_h_cte = "";
        $envelopefrom = "";

        $use_orgbody = false;

        // clean up the transmitted headers
        // remove default headers because we are using imap_mail
        $changedfrom = false;
        $returnPathSet = false;         
        $body_base64 = false;
        $org_charset = "";
        $org_boundary = false;
        foreach($message->headers as $k => $v) {
            if ($k == "subject" || $k == "to" || $k == "cc" || $k == "bcc" || $k == "sender")
                continue;

			debugLog("Header Sentmail: " . $k.  " = ".trim($v));
            if ($k == "content-type") {
                // if the message is a multipart message, then we should use the sent body
                if (preg_match("/multipart/i", $v)) {
                    $use_orgbody = true;
                    $org_boundary = $message->ctype_parameters["boundary"];
                }

                // save the original content-type header for the body part when forwarding
                if ($smartdata['task'] == 'forward' && $smartdata['itemid'] && !$use_orgbody) {
                    $forward_h_ct = $v;
                    continue;
                }

                if ($smartdata['task'] == 'reply' && $smartdata['itemid'] && !$use_orgbody) {
                    $forward_h_ct = $v;
                    continue;
                }

                // set charset always to utf-8
                $org_charset = $v;
                $v = preg_replace("/charset=([A-Za-z0-9-\"']+)/", "charset=\"utf-8\"", $v);
            }

            if ($k == "content-transfer-encoding") {
                // if the content was base64 encoded, encode the body again when sending
                if (trim($v) == "base64") $body_base64 = true;

                // save the original encoding header for the body part when forwarding
                if ($smartdata['task'] == 'forward' && $smartdata['itemid']) {
                    $forward_h_cte = $v;
                    continue;
                }
            }

            // if the message is a multipart message, then we should use the sent body
            if (($smartdata['task'] == 'new' || $smartdata['task'] == 'reply' || $smartdata['task'] == 'forward') && 
	        	((isset($smartdata['replacemime']) && $smartdata['replacemime'] == true) || 
        	  	$k == "content-type" && preg_match("/multipart/i", $v))) {
                $use_orgbody = true;
                $org_boundary = $message->ctype_parameters["boundary"];
            }

			// check if "from"-header is set, do nothing if it's set
			// else set it to IMAP_DEFAULTFROM
			if ($k == "from") {
				if (trim($v)) {
					$changedfrom = true;
					ini_set('sendmail_from', $v);
					$fromaddr = $v;
				} elseif (! trim($v) && IMAP_DEFAULTFROM) {
					$changedfrom = true;
					if (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
					else if (IMAP_DEFAULTFROM == 'domain') $v = $this->_domain;
					else $v = $this->_username . IMAP_DEFAULTFROM;
					$envelopefrom = "-f$v";
					ini_set('sendmail_from', $v);
					$fromaddr = $v;
				}
			}

	    	// depending on from field and if username is in map_user_fullname, we add users full name here to the email
	    	// address
	   		if ($k == "from" && $map_user_fullname && isset($map_user_fullname[$this->_username])) {
				$addedfullname = true;
				$v_parts = explode (" ",str_replace(">","",str_replace("<","",$v)));
				$v = '"'.$map_user_fullname[$this->_username].'" <'.$v_parts[sizeof($v_parts)-1].'>';
	    	}

            // check if "Return-Path"-header is set
            if ($k == "return-path") {
                $returnPathSet = true;
                if (! trim($v) && IMAP_DEFAULTFROM) {
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->_domain;
                    else $v = $this->_username . IMAP_DEFAULTFROM;
	        	    $envelopefrom = "-f$v";
					ini_set('sendmail_from', $v);
					$fromaddr = $v;
                }
            }

            // all other headers stay
            if ($headers) $headers .= "\n";
            $headers .= ucfirst($k) . ": ". trim($v);
        }

        // set "From" header if not set on the device
        if(IMAP_DEFAULTFROM && !$changedfrom){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->_domain;
            else $v = $this->_username . IMAP_DEFAULTFROM;
            $envelopefrom = "-f$v";
			ini_set('sendmail_from', $v);
			$fromaddr = $v;
			$v_parts = explode (" ",str_replace(">","",str_replace("<","",$v)));
	    	if ($map_user_fullname &&
				isset($map_user_fullname[$this->_username])) $v = '"'.$map_user_fullname[$this->_username].'" <'.$v_parts[sizeof($v_parts)-1].'>';
            if ($headers) $headers .= "\n";
            $headers .= 'From: '.$v;
        }

        // set "Return-Path" header if not set on the device
        if(IMAP_DEFAULTFROM && !$returnPathSet){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->_username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->_domain;
            else $v = $this->_username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'Return-Path: '.$v;
        }

        // if this is a multipart message with a boundary, we must use the original body
        if ($use_orgbody) {
    	    debugLog("IMAP-Sendmail: use_orgbody = true");
            list(,$body) = $mobj->_splitBodyHeader($rfc822);
            $repl_body = $this->getBody($message);
        }
        else {
    	    debugLog("IMAP-Sendmail: use_orgbody = false");
    	    $body = $this->getBody($message);
		}

    	if (isset($smartdata['replacemime']) && $smartdata['replacemime'] == true && 
    	    isset($message->ctype_primary)) {  
            if ($headers) $headers .= "\n";
    	    $headers .= "Content-Type: ". $message->ctype_primary . "/" . $message->ctype_secondary .
    			(isset($message->ctype_parameters['boundary']) ? ";\n\tboundary=".$message->ctype_parameters['boundary'] : "");
		}
		$body = str_replace("\r","",$body);

        // reply
        if ($smartdata['task'] == 'reply' && isset($smartdata['itemid']) && 
    	    isset($smartdata['folderid']) && $smartdata['itemid'] && $smartdata['folderid'] &&
	    	(!isset($smartdata['replacemime']) || 
	    	 (isset($smartdata['replacemime']) && $smartdata['replacemime'] == false))) {
            $this->imap_reopenFolder($smartdata['folderid']);
            // receive entire mail (header + body) to decode body correctly
	    	if (defined("IMAP_USE_FETCHHEADER") &&
				IMAP_USE_FETCHHEADER === false) {
				$origmail = @imap_fetchbody($this->_mbox, $smartdata['itemid'], "", FT_UID | FT_PEEK);
	    	} else { 
        		$origmail = @imap_fetchheader($this->_mbox, $smartdata['itemid'], FT_UID) . @imap_body($this->_mbox, $smartdata['itemid'], FT_PEEK | FT_UID);
	    	}
            $mobj2 = new Mail_mimeDecode($origmail);
            // receive only body
            $body .= $this->getBody($mobj2->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $origmail, 'crlf' => "\n", 'charset' => 'utf-8')));
            // unset mimedecoder & origmail - free memory
            unset($mobj2);
            unset($origmail);
        }

        // encode the body to base64 if it was sent originally in base64 by the pda
        // the encoded body is included in the forward
        if ($body_base64 && !$use_orgbody && !isset($forward)) { 
    	    debugLog("IMAP-Sendmail: body_base64 = true and use_orgbody = false");
			$body = chunk_split(base64_encode($body));
		} else {
    	    debugLog("IMAP-Sendmail: body_base64 = false or use_orgbody = false");
		}

        // forward
        if ($smartdata['task'] == 'forward' && isset($smartdata['itemid']) && 
    	    isset($smartdata['folderid']) && $smartdata['itemid'] && $smartdata['folderid'] && 
    	    (!isset($smartdata['replacemime']) || 
    	     (isset($smartdata['replacemime']) && $smartdata['replacemime'] == false))) {
			debugLog("IMAP Smartfordward is called");
            $this->imap_reopenFolder($smartdata['folderid']);
            // receive entire mail (header + body)
	    	if (defined("IMAP_USE_FETCHHEADER") &&
				IMAP_USE_FETCHHEADER === false) {
				$origmail = @imap_fetchbody($this->_mbox, $smartdata['itemid'], "", FT_UID | FT_PEEK);
	   		} else { 
        		$origmail = @imap_fetchheader($this->_mbox, $smartdata['itemid'], FT_UID) . @imap_body($this->_mbox, $smartdata['itemid'], FT_PEEK | FT_UID);
	    	}
            // build a new mime message, forward entire old mail as file
            if (!defined('IMAP_INLINE_FORWARD') || IMAP_INLINE_FORWARD === false) {
                if ($body_base64) $body = chunk_split(base64_encode($body));
				$boundary = ($org_boundary) ? $org_boundary : false;
                // build a new mime message, forward entire old mail as file
                list($aheader, $body) = $this->mail_attach("forwarded_message.eml",strlen($origmail),$origmail, $body, $forward_h_ct, $forward_h_cte,$boundary);
        		$headers .= "\n$aheader";
            }
            else {
                $mobj2 = new Mail_mimeDecode($origmail);
                $mess2 = $mobj2->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

                if (!$use_orgbody)
                    $nbody = $body;
                else
                    $nbody = $repl_body;

                $nbody .= "\r\n\r\n";
                $nbody .= "-----Original Message-----\r\n";
                if(isset($mess2->headers['from']))
                    $nbody .= "From: " . $mess2->headers['from'] . "\r\n";
                if(isset($mess2->headers['to']) && strlen($mess2->headers['to']) > 0)
                    $nbody .= "To: " . $mess2->headers['to'] . "\r\n";
                if(isset($mess2->headers['cc']) && strlen($mess2->headers['cc']) > 0)
                    $nbody .= "Cc: " . $mess2->headers['cc'] . "\r\n";
                if(isset($mess2->headers['date']))
                    $nbody .= "Sent: " . $mess2->headers['date'] . "\r\n";
                if(isset($mess2->headers['subject']))
                    $nbody .= "Subject: " . $mess2->headers['subject'] . "\r\n";
                $nbody .= "\r\n";
                $nbody .= $this->getBody($mess2);

                if ($body_base64) {
					$nbody = chunk_split(base64_encode($nbody));
                    if ($use_orgbody)
						$repl_body = chunk_split(base64_encode($repl_body));
                }

                if ($use_orgbody) {
                    debugLog("-------------------");
					debugLog("old:\n'$repl_body'\nnew:\n'$nbody'\nund der body:\n'$body'");
					//$body is quoted-printable encoded while $repl_body and $nbody are plain text,
					//so we need to decode $body in order replace to take place
					$body = str_replace($repl_body, $nbody, quoted_printable_decode($body));
                }
                else
                    $body = $nbody;


                if(isset($mess2->parts)) {
                    $attached = false;

                    if ($org_boundary) {
                        $att_boundary = $org_boundary;

                        // cut end boundary from body
                        $body = substr($body, 0, strrpos($body, "--$att_boundary--"));
                    }
                    else {
                        $att_boundary = strtoupper(md5(uniqid(time())));
                        // add boundary headers
                        $headers .= "\n" . "Content-Type: multipart/mixed; boundary=$att_boundary";
                    }

                    foreach($mess2->parts as $part) {
                        if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) {

                            if(isset($part->d_parameters['filename']))
                                $attname = $part->d_parameters['filename'];
                            else if(isset($part->ctype_parameters['name']))
                                $attname = $part->ctype_parameters['name'];
                            else if(isset($part->headers['content-description']))
                                $attname = $part->headers['content-description'];
                            else $attname = "unknown attachment";

                            // ignore html content
                            if ($part->ctype_primary == "text" && $part->ctype_secondary == "html") {
                                continue;
                            }
                            //
                            if ($use_orgbody || $attached) {
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                            // first attachment
                            else {
                                $encmail = $body;
                                $attached = true;
                                $body = $this->enc_multipart($att_boundary, $body, $forward_h_ct, $forward_h_cte);
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                        }
                    }
                    $body .= "--$att_boundary--\n\n";
                }

                unset($mobj2);
            }


            // unset origmail - free memory
            unset($origmail);

        }

        // remove carriage-returns from body
        $body = str_replace("\r\n", "\n", $body);

        //advanced debugging
        //debugLog("IMAP-SendMail: parsed message: ". print_r($message,1));
        debugLog("IMAP-SendMail: headers: $headers");
        debugLog("IMAP-SendMail: subject: {$message->headers["subject"]}");
        debugLog("IMAP-SendMail: body: $body");
		// 
        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            $send =  @imap_mail ( $toaddr, (!isset($message->headers["subject"]) ? "" : $message->headers["subject"]), $body, $headers, $ccaddr, $bccaddr);
        }
        else {
            if (!empty($ccaddr))  $headers .= "\nCc: $ccaddr";
			if (defined('INTERNAL_SMTPCLIENT_SERVERNAME') && INTERNAL_SMTPCLIENT_SERVERNAME != '') {
				$headers .="\nSubject: ".(!isset($message->headers["subject"]) ? "" : $message->headers["subject"]);
				$headers .="\nTo: ".(!isset($message->headers["to"]) ? "" : $message->headers["to"]);
				$send = @InternalSMTPClient($fromaddr, 
											(!isset($message->headers["to"]) ? "" : $message->headers["to"]), 
											(!isset($message->headers["cc"]) ? "" : $message->headers["cc"]), 
											(!isset($message->headers["bcc"]) ? "" :$message->headers["bcc"]), $headers."\n".$body);
			} else {
	            if (!empty($bccaddr)) $headers .= "\nBcc: $bccaddr";
	            $send = @mail ( $toaddr, (!isset($message->headers["subject"]) ? "" : $message->headers["subject"]), $body, $headers, $envelopefrom );
			}
        }

        // email sent?
        if (!$send) {
            debugLog("The email could not be sent. Last-IMAP-error: ". imap_last_error());
            return 120;
        }

        // add message to the sent folder
        // build complete headers
        $headers .= "\nTo: $toaddr";
        $headers .= "\nSubject: " . $message->headers["subject"];

        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            if (!empty($ccaddr))  $headers .= "\nCc: $ccaddr";
            if (!empty($bccaddr)) $headers .= "\nBcc: $bccaddr";
        }
        //debugLog("IMAP-SendMail: complete headers: $headers");

        $asf = false;
        if ($this->_sentID) {
            $asf = $this->addSentMessage($this->_sentID, $headers, $body);
        }
        else if (defined('IMAP_SENTFOLDER') && IMAP_SENTFOLDER) {
            $asf = $this->addSentMessage(IMAP_SENTFOLDER, $headers, $body);
            debugLog("IMAP-SendMail: Outgoing mail saved in configured 'Sent' folder '".IMAP_SENTFOLDER."': ". (($asf)?"success":"failed"));
        }
        // No Sent folder set, try defaults
        else {
            debugLog("IMAP-SendMail: No Sent mailbox set");
            if($this->addSentMessage("INBOX.Sent", $headers, $body)) {
                debugLog("IMAP-SendMail: Outgoing mail saved in 'INBOX.Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent", $headers, $body)) {
                debugLog("IMAP-SendMail: Outgoing mail saved in 'Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent Items", $headers, $body)) {
                debugLog("IMAP-SendMail: Outgoing mail saved in 'Sent Items'");
                $asf = true;
            }
        }

        // unset mimedecoder - free memory
        unset($mobj);
        return ($send && $asf);
    }

    /* Should return a wastebasket folder if there is one. This is used when deleting
     * items; if this function returns a valid folder ID, then all deletes are handled
     * as moves and are sent to your backend as a move. If it returns FALSE, then deletes
     * are always handled as real deletes and will be sent to your importer as a DELETE
     */
    function GetWasteBasket() {
        return $this->_wasteID;
    }

    /* Should return a list (array) of messages, each entry being an associative array
     * with the same entries as StatMessage(). This function should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * The cutoffdate is a date in the past, representing the date since which items should be shown.
     * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
     * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
     * will work OK apart from that.
     */

    function GetMessageList($folderid, $cutoffdate) {
        debugLog("IMAP-GetMessageList: (fid: '".$this->_folders[$folderid]."'  cutdate: '$cutoffdate' )");

        $messages = array();
		$mod = false;
        $this->imap_reopenFolder($folderid, true);
 
        $sequence = "1:*";
        if ($cutoffdate > 0) {
            $search = @imap_search($this->_mbox, "SINCE ". date("d-M-Y", $cutoffdate));
            if ($search !== false)
                $sequence = implode(",", $search);
        }
        $overviews = @imap_fetch_overview($this->_mbox, $sequence);

        if (!$overviews) {
            debugLog("IMAP-GetMessageList: Failed to retrieve overview");
        } else {
            foreach($overviews as $overview) {
                $date = "";
                $vars = get_object_vars($overview);
                if (array_key_exists( "date", $vars)) {
                    // message is out of range for cutoffdate, ignore it
                    if(strtotime($overview->date) < $cutoffdate) continue;
                    $date = $overview->date;
                }

                // cut of deleted messages
                if (array_key_exists( "deleted", $vars) && $overview->deleted)
                    continue;

                if (array_key_exists( "uid", $vars)) {
                    $message = array();
                    $message["mod"] = $date;
                    $message["id"] = $overview->uid;
                    // 'seen' aka 'read' is the only flag we want to know about
                    $message["flags"] = 0;
				    // outlook supports additional flags, set them to 0
                    $message["olflags"] = 0;

                    if(array_key_exists( "seen", $vars) && $overview->seen)
                        $message["flags"] = 1;

                    array_push($messages, $message);
                }
            }
        }

        return $messages;
    }

    /* This function is analogous to GetMessageList.
     *
     */
    function GetFolderList() {
        $folders = array();

        $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
        if (is_array($list)) {
            // reverse list to obtain folders in right order
            $list = array_reverse($list);
            foreach ($list as $val) {
                $box = array();

                // cut off serverstring
				debugLog("Real Server folder name (b64) : ".base64_encode($val->name));
                $box["id"] = imap_utf7_decode(substr($val->name, strlen($this->_server)));

                // always use "." as folder delimiter
                $box["id"] = imap_utf7_encode(str_replace($val->delimiter, ".",$box["id"]));

				// put real imap id in cache and create unique folderid instead
				if (($fid = array_search($box["id"],$this->_folders)) === false) {
				    $fid = $this->_folderid();
				    $this->_folders[$fid] = $box["id"];
				} 
            	$box["id"] = $fid;

                // explode hierarchies
                $fhir = explode(".",$this->_folders[$box["id"]]);
                if (count($fhir) > 1) {
				    $folder = $this->GetFolder($box["id"]);
                    $box["mod"] = $folder->displayname; // mod is last part of path
				    if (($box["parent"] = array_search(imap_utf7_encode(implode(".", $fhir)),$this->_folders)) === false) {
						$box["parent"] = $this->_folderid();
						$this->_folders[$box["parent"]] = imap_utf7_encode(implode(".", $fhir));
				    } 
                } else {
		    // imap_utf7_encode
				    $folder = $this->GetFolder($box["id"]);
                    $box["mod"] = $folder->displayname; // mod is last part of path
                    $box["parent"] = "0";
                }

                $folders[]=$box;
            }
        }
        else {
            debugLog("GetFolderList: imap_list failed: " . imap_last_error());
        }

        return $folders;
    }

    /* GetFolder should return an actual SyncFolder object with all the properties set. Folders
     * are pretty simple really, having only a type, a name, a parent and a server ID.
     */

    function _folderid() {
        return sprintf( '%04x%04x%04x%04x%04x%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }

    function GetFolder($id) {
        $folder = new SyncFolder();

		$folder->serverid = $id;

		// get real imap id from cache
		$id = $this->_folders[$id];

        // explode hierarchy
        $fhir = explode(".", $id);

        // compare on lowercase strings
        $lid = strtolower($id);

        if($lid == "inbox") {
            $folder->parentid = "0"; // Root
            $folder->displayname = "Inbox";
            $folder->type = SYNC_FOLDER_TYPE_INBOX;
        }
        // Zarafa IMAP-Gateway outputs
        else if($lid == "drafts" || str_replace(array("\0"),"",mb_convert_encoding(imap_utf7_decode($id),"utf-8","iso-8859-1")) == IMAP_DRAFTSFOLDER) {
            $folder->parentid = "0";
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        } 
        else if($lid == "trash" || $lid == "deleted items" || str_replace(array("\0"),"",mb_convert_encoding(imap_utf7_decode($id),"utf-8","iso-8859-1")) == IMAP_DELETEDITEMSFOLDER) {
            $folder->parentid = "0";
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->_wasteID = $id;
        }
        else if($lid == "sent" || $lid == "sent items" || str_replace(array("\0"),"",mb_convert_encoding(imap_utf7_decode($id),"utf-8","iso-8859-1")) == IMAP_SENTFOLDER) {
            $folder->parentid = "0";
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->_sentID = $id;
        }
        // Nokia MfE 2.01 Built in Client (on i.e. E75-1) needs outbox. Otherwise no sync occurs!
        else if($lid == "outbox") {
            $folder->parentid = "0"; // Root
            $folder->displayname = "Outbox";
            $folder->type = SYNC_FOLDER_TYPE_OUTBOX;
        }
        // courier-imap outputs
        else if($lid == "inbox.drafts") {
            $folder->parentid = array_search($fhir[0],$this->_folders);
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "inbox.trash") {
            $folder->parentid = array_search($fhir[0],$this->_folders);
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->_wasteID = $id;
        }
        else if($lid == "inbox.sent") {
            $folder->parentid = array_search($fhir[0],$this->_folders);
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->_sentID = $id;
        }
        // Nokia MfE 2.01 Built in Client (on i.e. E75-1) needs outbox. Otherwise no sync occurs!
        else if($lid == "inbox.outbox") { 
            $folder->parentid = "0"; // Root
            $folder->displayname = "Outbox";
            $folder->type = SYNC_FOLDER_TYPE_OUTBOX;
        }

        // define the rest as other-folders
        else {
            if (count($fhir) > 1) {
        		$folder->displayname = w2u(imap_utf7_decode(array_pop($fhir)));
				if (($folder->parentid = array_search(implode(".", $fhir),$this->_folders)) === false) {
		    		$folder->parentid = $this->_folderid();
				    $this->_folders[$folder->parentid] = implode(".", $fhir);
				}
    	    } else {
                $folder->displayname = w2u(imap_utf7_decode($id));
                $folder->parentid = "0";
            }
            $folder->type = SYNC_FOLDER_TYPE_USER_MAIL; // Type Other is not displayed on i.e. Nokia
        }

           //advanced debugging
           //debugLog("IMAP-GetFolder(id: '$id') -> " . print_r($folder, 1));

		file_put_contents(STATE_DIR . '/' . strtolower($this->_devid). '/imap_folders_'. $this->_user, serialize($this->_folders));
        return $folder;
    }

    /* Return folder stats. This means you must return an associative array with the
     * following properties:
     * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
     *         How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
     * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
     * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
     *          the folder has not changed. In practice this means that 'mod' can be equal to the folder name
     *          as this is the only thing that ever changes in folders. (the type is normally constant)
     */
    function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $this->_folders[$folder->parentid];
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    /* Creates or modifies a folder
     * "folderid" => id of the parent folder
     * "oldid" => if empty -> new folder created, else folder is to be renamed
     * "displayname" => new folder name (to be created, or to be renamed to)
     * "type" => folder type, ignored in IMAP
     *
     */
    function ChangeFolder($folderid, $oldid, $displayname, $type){
        debugLog("ChangeFolder: (parent: '".$this->_folders[$folderid]."'  oldid: '".$this->_folders[$oldid]."'  displayname: '$displayname'  type: '$type')");

        // go to parent mailbox
        $this->imap_reopenFolder($folderid);

        // build name for new mailbox
        $newname = $this->_server . str_replace(".", $this->_serverdelimiter, $this->_folders['folderid']) . $this->_serverdelimiter . $displayname;

        $csts = false;
        // if $id is set => rename mailbox, otherwise create
        if ($oldid) {
            // rename doesn't work properly with IMAP
            // the activesync client doesn't support a 'changing ID'
            //$csts = imap_renamemailbox($this->_mbox, $this->_server . imap_utf7_encode(str_replace(".", $this->_serverdelimiter, $oldid)), $newname);
        }
        else {
            $csts = @imap_createmailbox($this->_mbox, $newname);
        }
        if ($csts) {
            return $this->StatFolder($this->_folders[$folderid] . "." . $displayname);
        }
        else
            return false;
    }

    /* Should return attachment data for the specified attachment. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment. So, you should
     * encode any information you need to find the attachment in that 'attname' property.
     */
    function GetAttachmentData($attname) {
        debugLog("getAttachmentData: (attname: '$attname')");

        list($folderid, $id, $part) = explode(":", $attname);

        $this->imap_reopenFolder($folderid);

		if (defined("IMAP_USE_FETCHHEADER") &&
		    IMAP_USE_FETCHHEADER === false) {
		    $mail = @imap_fetchbody($this->_mbox, $id, "", FT_UID | FT_PEEK);
		} else { 
            $mail = @imap_fetchheader($this->_mbox, $id, FT_UID) . @imap_body($this->_mbox, $id, FT_PEEK | FT_UID);
		}


        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'rfc_822bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => 'utf-8'));

        $attachment = new SyncAirSyncBaseFileAttachment();
		$n=1;
		$this->getnthAttachmentRecursive($message,$attachment,$n,$part);
		print $attachment->_data;

        // unset mimedecoder & mail
        unset($mobj);
        unset($mail);
        return true;
    }

    function ItemOperationsGetAttachmentData($attname) {
        debugLog("ItemOperationsGetAttachmentDate: (attname: '$attname')");

        list($folderid, $id, $part) = explode(":", $attname);

        $this->imap_reopenFolder($folderid);

		if (defined("IMAP_USE_FETCHHEADER") &&
		    IMAP_USE_FETCHHEADER === false) {
	    	$mail = @imap_fetchbody($this->_mbox, $id, "", FT_UID | FT_PEEK);
		} else { 
	        $mail = @imap_fetchheader($this->_mbox, $id, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $id, FT_PEEK | FT_UID);
		}

        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'rfc_822bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => 'utf-8'));
		debugLog("getAttachmentData ContentType: ".$message->parts[$part]->ctype_primary."/".$message->parts[$part]->ctype_secondary);

        $attachment = new SyncAirSyncBaseFileAttachment();
        $n=1;
		$this->getnthAttachmentRecursive($message,$attachment,$n,$part);
        // unset mimedecoder & mail
        unset($mobj);
        unset($mail);
        return $attachment;
    }

    /* StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
     * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
     * 'flags'     => simply '0' for unread, '1' for read
     * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
     *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
     *             time for this field, which will change as soon as the contents have changed.
     */

    function StatMessage($folderid, $id) {
        debugLog("IMAP-StatMessage: (fid: '".$this->_folders[$folderid]."'  id: '".$id."' )");

        $this->imap_reopenFolder($folderid);
        $overview = @imap_fetch_overview( $this->_mbox , $id , FT_UID);

        if (!$overview) {
            debugLog("IMAP-StatMessage: Failed to retrieve overview: ". imap_last_error());
            return false;
        }

        else {
            // check if variables for this overview object are available
            $vars = get_object_vars($overview[0]);

            // without uid it's not a valid message
            if (! array_key_exists( "uid", $vars)) return false;


            $entry = array();
            $entry["mod"] = (array_key_exists( "date", $vars)) ? $overview[0]->date : "";
            $entry["id"] = $overview[0]->uid;
            // 'seen' aka 'read' is the only flag we want to know about
            $entry["flags"] = 0;
            $entry["olflags"] = 0;

            if(array_key_exists( "seen", $vars) && $overview[0]->seen)
                $entry["flags"] = 1;

            //advanced debugging
            //debugLog("IMAP-StatMessage-parsed: ". print_r($entry,1));

            return $entry;
        }
    }

    /* GetMessage should return the actual SyncXXX object type. You may or may not use the '$folderid' parent folder
     * identifier here.
     * Note that mixing item types is illegal and will be blocked by the engine; ie returning an Email object in a
     * Tasks folder will not do anything. The SyncXXX objects should be filled with as much information as possible,
     * but at least the subject, body, to, from, etc.
     */
    function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0) {
        debugLog("IMAP-GetMessage: (fid: '".$this->_folders[$folderid]."'  id: '".$id."'  truncsize: $truncsize)");

        // Get flags, etc
        $stat = $this->StatMessage($folderid, $id);

        if ($stat) {
            $this->imap_reopenFolder($folderid);

		    if (defined("IMAP_USE_FETCHHEADER") &&
				IMAP_USE_FETCHHEADER === false) {
				$mail = @imap_fetchbody($this->_mbox, $id, "", FT_UID | FT_PEEK);
		    } else { 
    	    	$mail = @imap_fetchheader($this->_mbox, $id, FT_UID) . @imap_body($this->_mbox, $id, FT_PEEK | FT_UID);
	    	}

// Return in case any errors occured...
            $errors = imap_errors();
            if (is_array($errors)) {
                foreach ($errors as $e) {
                    debugLog("IMAP-errors: $e");
        		    $fields = explode(':', $e);
					switch (strtolower(trim($fields[0]))) {
						case 'security problem' : // don't care about security problems!
							break;
						default : 
							return false;
					}
				}
            }
            $mobj = new Mail_mimeDecode($mail);
            $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'rfc_822bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => BACKEND_CHARSET));

            $output = new SyncMail();

	    // start AS12 Stuff (bodypreference === false) case = old behaviour
		    if ($bodypreference === false) {
    	    	$body = $this->getBody($message);
        		$body = str_replace("\n","\r\n", str_replace("\r","",$body));

    	        // truncate body, if requested
	        	if(strlen($body) > $truncsize) {
                    $body = utf8_truncate($body, $truncsize);
		            $output->bodytruncated = 1;
    	        } else {
        		    $body = $body;
            	    $output->bodytruncated = 0;
        		}
        		$output->bodysize = strlen($body);
	        	$output->body = $body;
		    } else {
	    	    if (isset($bodypreference[1]) && !isset($bodypreference[1]["TruncationSize"])) 
	    		    $bodypreference[1]["TruncationSize"] = 1024*1024;
				if (isset($bodypreference[2]) && !isset($bodypreference[2]["TruncationSize"])) 
				    $bodypreference[2]["TruncationSize"] = 1024*1024;
				if (isset($bodypreference[3]) && !isset($bodypreference[3]["TruncationSize"]))
				    $bodypreference[3]["TruncationSize"] = 1024*1024;
				if (isset($bodypreference[4]) && !isset($bodypreference[4]["TruncationSize"]))
			    	$bodypreference[4]["TruncationSize"] = 1024*1024;
				$output->airsyncbasebody = new SyncAirSyncBaseBody();
				debugLog("airsyncbasebody!");
				$body="";
				$this->getBodyRecursive($message, "html", $body);
			    if ($body != "") {
				    $output->airsyncbasenativebodytype=2;
				} else {
				    $output->airsyncbasenativebodytype=1;
				    $this->getBodyRecursive($message, "plain", $body);
				}
		        $body = str_replace("\n","\r\n", str_replace("\r","",$body));
				if (isset($bodypreference[4]) &&
					($mimesupport == 2 ||
					 ($mimesupport == 1 && strtolower($message->ctype_secondary) == 'signed'))) {
					debugLog("MIME Body");
					$output->airsyncbasebody->type = 4;
		        	$rawmessage = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'rfc_822bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => BACKEND_CHARSET));
					$body = "";
					foreach($rawmessage->headers as $key=>$value) {
						if ($key != "content-type" && $key != "mime-version" && $key != "content-transfer-encoding" &&
						    !is_array($value)) {
							$body .= $key.":";
							// Split -> Explode replace
							$tokens = explode(" ",trim($value));
							$line = "";
							foreach($tokens as $valu) {
			    				if ((strlen($line)+strlen($valu)+2) > 60) {
									$line .= "\n";
									$body .= $line;
									$line = " ".$valu;
							    } else {
									$line .= " ".$valu;
							    }
							}
							$body .= $line."\n";
						}
					}
					unset($rawmessage);
					$mimemsg = new Mail_mime(array( 'head_encoding'	=> 'quoted-printable',
									    		    'text_encoding'	=> 'quoted-printable',
									    		    'html_encoding'	=> 'base64',
									    		    'head_charset'	=> 'utf-8',
									    		    'text_charset'	=> 'utf-8',
									    		    'html_charset'	=> 'utf-8',
				   				    			    'eol'		=> "\n",
					    				    	    'delay_file_io'	=> false,
												)
					   					    );
					$this->getAllAttachmentsRecursive($message,$mimemsg); 
					if ($output->airsyncbasenativebodytype==1) {
					    $this->getBodyRecursive($message, "plain", $plain);
					    $this->getBodyRecursive($message, "html", $html);
		    	    	if ($html == "") {
			    	    	$this->getBodyRecursive($message, "plain", $html);
			    		}
			    		if ($html == "" && $plain == "" && strlen($mobj->_body) != "") {
					   		$body .= "Content-Type:".$message->headers['content-type']."\r\n";
						    $body .= "Content-Transfer-Encoding:".$message->headers['content-transfer-encoding']."\r\n";
						    $body .= "\n\n".$mobj->_body;
				    		$output->airsyncbasebody->data = $body;
						}
						$mimemsg->setTXTBody(str_replace("\n","\r\n", str_replace("\r","",w2u($plain))));
				    	$html = '<html>'.
	    					    '<head>'.
						        '<meta name="Generator" content="Z-Push">'.
						        '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.
							    '</head>'.
							    '<body>'.
						        str_replace("\n","<BR>",str_replace("\r","", str_replace("\r\n","<BR>",w2u($html)))).
							    '</body>'.
								'</html>';
					   	$mimemsg->setHTMLBody(str_replace("\n","\r\n", str_replace("\r","",$html)));
					}
					if ($output->airsyncbasenativebodytype==2) {
						$this->getBodyRecursive($message, "plain", $plain);
				    	if ($plain == "") {
						    $this->getBodyRecursive($message, "html", $plain);
						    // remove css-style tags
						    $plain = preg_replace("/<style.*?<\/style>/is", "", $plain);
					    	// remove all other html
						    $plain = preg_replace("/<br.*>/is","<br>",$plain);
						    $plain = preg_replace("/<br >/is","<br>",$plain);
						    $plain = preg_replace("/<br\/>/is","<br>",$plain);
						    $plain = str_replace("<br>","\r\n",$plain);
						    $plain = strip_tags($plain);
			    		}
		    			$mimemsg->setTXTBody(str_replace("\n","\r\n", str_replace("\r","",w2u($plain))));
				    	$this->getBodyRecursive($message, "html", $html);
				    	$mimemsg->setHTMLBody(str_replace("\n","\r\n", str_replace("\r","",w2u($html))));
					}
					if (!isset($output->airsyncbasebody->data))
					    $output->airsyncbasebody->data = $body.$mimemsg->txtheaders()."\n\n".$mimemsg->get();
				    $output->airsyncbasebody->estimateddatasize = byte_strlen($output->airsyncbasebody->data);
				} else if (isset($bodypreference[2])) {
				    debugLog("HTML Body");
				    // Send HTML if requested and native type was html
				    $output->airsyncbasebody->type = 2;
				    $this->getBodyRecursive($message, "plain", $plain);
				    $this->getBodyRecursive($message, "html", $html);
				    if ($html == "") {
				        $this->getBodyRecursive($message, "plain", $html);
				    }
				    if ($html == "" && $plain == "" && byte_strlen($mobj->_body) > 0) {
						$plain = $html = $mobj->_quotedPrintableDecode($mobj->_body);
				    }
				    if ($output->airsyncbasenativebodytype==2) {
				        $html = w2u($html);
				    } else {
				        $html = '<html>'.
								'<head>'.
								'<meta name="Generator" content="Z-Push">'.
								'<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.
								'</head>'.
								'<body>'.
								str_replace("\n","<BR>",str_replace("\r","<BR>", str_replace("\r\n","<BR>",w2u($plain)))).
								'</body>'.
								'</html>';
				    }
		    	    if(isset($bodypreference[2]["TruncationSize"]) &&
	    	            strlen($html) > $bodypreference[2]["TruncationSize"]) {
		                $html = utf8_truncate($html,$bodypreference[2]["TruncationSize"]);
				        $output->airsyncbasebody->truncated = 1;
				    }
				    $output->airsyncbasebody->data = $html;
				    $output->airsyncbasebody->estimateddatasize = byte_strlen($html);
		    	} else {
					    // Send Plaintext as Fallback or if original body is plaintext
				    debugLog("Plaintext Body");
				    $plain = $this->getBody($message);
				    $plain = w2u(str_replace("\n","\r\n",str_replace("\r","",$plain)));
				    $output->airsyncbasebody->type = 1;
		    	    if(isset($bodypreference[1]["TruncationSize"]) &&
			    		strlen($plain) > $bodypreference[1]["TruncationSize"]) {
			       		$plain = utf8_truncate($plain, $bodypreference[1]["TruncationSize"]);
				    	$output->airsyncbasebody->truncated = 1;
		   	        }
				    $output->airsyncbasebody->estimateddatasize = byte_strlen($plain);
		    	    $output->airsyncbasebody->data = $plain;
		    	}
				// In case we have nothing for the body, send at least a blank... 
				// dw2412 but only in case the body is not rtf!
		    	if ($output->airsyncbasebody->type != 3 && (!isset($output->airsyncbasebody->data) || byte_strlen($output->airsyncbasebody->data) == 0))
		       	    $output->airsyncbasebody->data = " ";
			}
			// end AS12 Stuff
			// small dirty correction for (i.e. Tobit David) since it has the opinion the UTC Timezone abbreviation is UT :-(
	        $output->datereceived = isset($message->headers["date"]) ? strtotime($message->headers["date"].(substr($message->headers["date"],-3)==" UT" ? "C" : "")) : null;
	        $output->importance = isset($message->headers["x-priority"]) ? preg_replace("/\D+/", "", $message->headers["x-priority"]) : null;
	    	$output->messageclass = "IPM.Note";
			if (strtolower($message->ctype_primary) == "multipart") {
			    switch(strtolower($message->ctype_secondary)) {
					case 'signed'	:
    				    $output->messageclass = "IPM.Note.SMIME.MultipartSigned"; break;
					default 	:
			   		    $output->messageclass = "IPM.Note";
		    	}
	    	}
    	    $output->subject = isset($message->headers["subject"]) ? trim(w2u($message->headers["subject"])) : "";
	        $output->read = $stat["flags"];

			if (isset($message->headers["to"]) && 
				(preg_match('/^\"{0,1}(.+[^ ^\"]){0,1}\"{0,1}[ ]{0,1}<(.*)>$/',$message->headers["to"],$addrparts) ||
				 preg_match('/^(.*@.*)$/',$message->headers["to"],$addrparts))
				) {
				$output->to = trim(w2u('"'.(($addrparts[1] != "" ||  $addrparts[2] == "") ? $addrparts[1] : $addrparts[2]).'" <'.(!isset($addrparts[2]) || $addrparts[2] == "" ? $addrparts[1] : $addrparts[2]).'>'));
				$output->displayto = trim(w2u($addrparts[1] != "" ? $addrparts[1] : $addrparts[2]));
		    	unset($addrparts);
			} else {
				$output->to = trim(w2u('"Unknown@localhost" <Unknown@localhost>'));
			}
			if (isset($message->headers["from"]) && 
				(preg_match('/^\"{0,1}(.+[^ ^\"]){0,1}\"{0,1}[ ]{0,1}<(.*)>$/',$message->headers["from"],$addrparts) ||
				 preg_match('/^(.*@.*)$/',$message->headers["from"],$addrparts))
				) {
				$output->from = trim(w2u('"'.(($addrparts[1] != "" || $addrparts[2] == "") ? $addrparts[1] : $addrparts[2]).'" <'.(!isset($addrparts[2]) || $addrparts[2] == "" ? $addrparts[1] : $addrparts[2]).'>'));
		    	unset($addrparts);
			} else {
				$output->from = trim(w2u('"Unknown@localhost" <Unknown@localhost>'));
			}

	        $output->cc = isset($message->headers["cc"]) ? trim(w2u($message->headers["cc"])) : null;
	        $output->reply_to = isset($message->headers["reply-to"]) ? trim(w2u($message->headers["reply-to"])) : null;

			// start AS12 Stuff
			$output->poommailflag = new SyncPoommailFlag();
			$output->poommailflag->flagstatus = 0;
	    	$output->internetcpid = 65001;
			$output->contentclass="urn:content-classes:message";
			// end AS12 Stuff

	        // Attachments are only searched in the top-level part
	        if (isset($message->parts)) {
				$this->getAttachmentDetailsRecursive($message,$output,$folderid,$id);
	        }
	        // unset mimedecoder & mail
	        unset($mobj);
	        unset($mail);
    		return $output;
        }
        return false;
    }

    /* This function is called when the user has requested to delete (really delete) a message. Usually
     * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the PDA
     * as it will be seen as a 'new' item. This means that if you don't implement this function, you will
     * be able to delete messages on the PDA, but as soon as you sync, you'll get the item back
     */
    function DeleteMessage($folderid, $id) {
        debugLog("IMAP-DeleteMessage: (fid: '".$this->_folders[$folderid]."'  id: '".$id."' )");

        $this->imap_reopenFolder($folderid);
        $s1 = @imap_delete ($this->_mbox, $id, FT_UID);
        $s11 = @imap_setflag_full($this->_mbox, $id, "\\Deleted", FT_UID);
        $s2 = @imap_expunge($this->_mbox);

        debugLog("IMAP-DeleteMessage: s-delete: $s1   s-expunge: $s2    setflag: $s11");

        return ($s1 && $s2 && $s11);
    }

    /* This should change the 'read' flag of a message on disk. The $flags
     * parameter can only be '1' (read) or '0' (unread). After a call to
     * SetReadFlag(), GetMessageList() should return the message with the
     * new 'flags' but should not modify the 'mod' parameter. If you do
     * change 'mod', simply setting the message to 'read' on the PDA will trigger
     * a full resync of the item from the server
     */
    function SetReadFlag($folderid, $id, $flags) {
        debugLog("IMAP-SetReadFlag: (fid: '".$this->_folders[$folderid]."'  id: '".$id."'  flags: '$flags' )");

        $this->imap_reopenFolder($folderid);

        if ($flags == 0) {
            // set as "Unseen" (unread)
            $status = @imap_clearflag_full ( $this->_mbox, $id, "\\Seen", ST_UID);
        } else {
            // set as "Seen" (read)
            $status = @imap_setflag_full($this->_mbox, $id, "\\Seen",ST_UID);
        }

        debugLog("IMAP-SetReadFlag -> set as " . (($flags) ? "read" : "unread") . "-->". $status);

        return $status;
    }


	function ImportMessageFlag($id, $flag) {
		return true;
	}

    /* This function is called when a message has been changed on the PDA. You should parse the new
     * message here and save the changes to disk. The return value must be whatever would be returned
     * from StatMessage() after the message has been saved. This means that both the 'flags' and the 'mod'
     * properties of the StatMessage() item may change via ChangeMessage().
     * Note that this function will never be called on E-mail items as you can't change e-mail items, you
     * can only set them as 'read'.
     */
    function ChangeMessage($folderid, $id, $message) {
        return false;
    }

    /* This function is called when the user moves an item on the PDA. You should do whatever is needed
     * to move the message on disk. After this call, StatMessage() and GetMessageList() should show the items
     * to have a new parent. This means that it will disappear from GetMessageList() will not return the item
     * at all on the source folder, and the destination folder will show the new message
     *
     */
    function MoveMessage($folderid, $id, $newfolderid) {
        debugLog("IMAP-MoveMessage: (sfid: '".$this->_folders[$folderid]."'  id: '".$id."'  dfid: '".$this->_folders[$newfolderid]."' )");

        $this->imap_reopenFolder($folderid);

        // read message flags
        $overview = @imap_fetch_overview ( $this->_mbox , $id, FT_UID);

        if (!$overview) {
            debugLog("IMAP-MoveMessage: Failed to retrieve overview");
            return false;
        } else {
            // get next UID for destination folder
            // when moving a message we have to announce through ActiveSync the new messageID in the
            // destination folder. This is a "guessing" mechanism as IMAP does not inform that value.
            // when lots of simultaneous operations happen in the destination folder this could fail.
            // in the worst case the moved message is displayed twice on the mobile.
            $destStatus = imap_status($this->_mbox, $this->_server . str_replace(".", $this->_serverdelimiter, $this->_folders[$newfolderid]), SA_ALL);
			$newid = $destStatus->uidnext;
            // move message
            $s1 = imap_mail_move($this->_mbox, $id, str_replace(".", $this->_serverdelimiter, $this->_folders[$newfolderid]), CP_UID);

            // delete message in from-folder
            $s2 = imap_expunge($this->_mbox);

            // open new folder
            $this->imap_reopenFolder($newfolderid);

            // remove all flags
            $s3 = @imap_clearflag_full ($this->_mbox, $newid, "\\Seen \\Answered \\Flagged \\Deleted \\Draft", FT_UID);
            $newflags = "";
            if ($overview[0]->seen) $newflags .= "\\Seen";
            if ($overview[0]->flagged) $newflags .= " \\Flagged";
            if ($overview[0]->answered) $newflags .= " \\Answered";
            $s4 = @imap_setflag_full ($this->_mbox, $newid, $newflags, FT_UID);

            debugLog("MoveMessage: (" . $this->_folders[$folderid] . "->" . $this->_folders[$newfolderid] . ":". $newid. ") s-move: $s1   s-expunge: $s2    unset-Flags: $s3    set-Flags: $s4");

            // return the new id "as string""
            return $newid . "";
        }
    }

    // new ping mechanism for the IMAP-Backend
    function AlterPing() {
        return true;
    }

    // returns a changes array using imap_status
    // if changes occurr default diff engine computes the actual changes
    function AlterPingChanges($folderid, &$syncstate) {
        debugLog("AlterPingChanges on ".$this->_folders[$folderid]." stat: ". $syncstate);
        $this->imap_reopenFolder($folderid);

        // courier-imap only cleares the status cache after checking
        @imap_check($this->_mbox);

        $status = imap_status($this->_mbox, $this->_server . str_replace(".", $this->_serverdelimiter, $this->_folders[$folderid]), SA_ALL);
        if (!$status) {
            debugLog("AlterPingChanges: could not stat folder ".$this->_folders[$folderid]." : ". imap_last_error());
            return false;
        } else {
            $newstate = "M:". $status->messages ."-R:". $status->recent ."-U:". $status->unseen;

            // message number is different - change occured
            if ($syncstate != $newstate) {
                $syncstate = $newstate;
                debugLog("AlterPingChanges: Change FOUND!");
                // build a dummy change
                return array(array("type" => "fakeChange"));
            }
        }

        return array();
    }

    // ----------------------------------------
    // imap-specific internals

	function getAttachmentDetailsRecursive($message,&$output,$folderid,$id) {
        if(!isset($message->ctype_primary)) return;

	    if (isset($message->headers['content-id']) ||
	        isset($message->disposition)) {
		    if (isset($output->_mapping['POOMMAIL:Attachments'])) {
		        $attachment = new SyncAttachment();
				$n = isset($output->attachments) ? sizeof($output->attachments)+1 : 1;
        	} else if (isset($output->_mapping['AirSyncBase:Attachments'])) {
		        $attachment = new SyncAirSyncBaseAttachment();
				$n = isset($output->airsyncbaseattachments) ? sizeof($output->airsyncbaseattachments)+1 : 1;
			}

    	    $attachment->attsize = byte_strlen($message->body);

	        if (isset($message->d_parameters['filename']))
    	    	$attname = $message->d_parameters['filename'];
        	else if(isset($message->ctype_parameters['name']))
                $attname = $message->ctype_parameters['name'];
	        else if(isset($message->headers['content-description']))
    	        $attname = $message->headers['content-description'];
	        else {
        		if ($message->ctype_primary == "message" &&
        	    	$message->ctype_secondary == "rfc822") {
        	    	$attname = "message.eml";
        	    } else {
	             	$attname = "unknown attachment";
				}
			}

            $attachment->displayname = w2u($attname);
            $attachment->attname = $folderid . ":" . $id . ":" . $n;
            $attachment->attmethod = 1;
            $attachment->attoid = isset($part->headers['content-id']) ? trim($part->headers['content-id']) : "";

			if ((isset($message->disposition) &&
				 $message->disposition == "inline") ||
				isset($message->headers['content-id'])) {
				$attachment->isinline=true;
				$attachment->attmethod=6;
				$attachment->contentid = isset($message->headers['content-id']) ? trim(str_replace("\"","",str_replace("<","",str_replace(">","",$message->headers['content-id'])))) : "";
		    } else {
			    $attachment->attmethod=1;
		    }

            if (isset($output->_mapping['POOMMAIL:Attachments'])) {
				if (!isset($output->attachments) ||
				  	!is_array($output->attachments)) 
					$output->attachments = array();
		       	array_push($output->attachments, $attachment);
			} else if(isset($output->_mapping['AirSyncBase:Attachments'])) {
				if (!isset($output->airsyncbaseattachments) ||
			    	!is_array($output->airsyncbaseattachments)) 
				  	$output->airsyncbaseattachments = array();
		        array_push($output->airsyncbaseattachments, $attachment);
			}

		}

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
           	foreach($message->parts as $part) {
               	$this->getAttachmentDetailsRecursive($part,$output,$folderid,$id);
           	}
 		}
	}

    function getnthAttachmentRecursive($message,&$attachment,&$number,$nth) {

		debugLog("getnthAttachmentsRecursive ".$nth."/".$number." ".$message->disposition." ".$message->ctype_primary." ".$message->ctype_secondary." ".(isset($message->ctype_parameters['charset']) ? trim($message->ctype_parameters['charset']) : ""));
        if(!isset($message->ctype_primary)) return;

		if ((isset($message->disposition) ||
			isset($message->headers['content-id']))) {
	    	if ($number == $nth) {
	        	if (isset($message->body)) {
    	        	$attachment->_data = $message->body;
	    	    	if (isset($message->d_parameters['filename']))
						$attname = $message->d_parameters['filename'];
					else if(isset($message->ctype_parameters['name']))
						$attname = $message->ctype_parameters['name'];
					else if(isset($message->headers['content-description']))
						$attname = $message->headers['content-description'];
	        		else {
        				if ($message->ctype_primary == "message" &&
        	    			$message->ctype_secondary == "rfc822") {
        	    			$attname = "message.eml";
        	    		} else {
	             			$attname = "unknown attachment";
						}
					}

	        		$attachment->displayname = w2u($attname);

					if (isset($message->body) && $message->body != "" &&
						($contenttype1 = trim($message->ctype_primary).'/'.trim($message->ctype_secondary)) != ($contenttype2 = trim(get_mime_type_from_content($attachment->displayname, $message->body)))) {
						debugLog("Content-Type in message differs determined one (".$contenttype1."/".$contenttype2."). Using determined one.");
						$attachment->contenttype = $contenttype2;
					} else {
		    			$attachment->contenttype = $contenttype1;
					}
           			$number++;
           			return;
				};
			} else $number++;
		} else {
			debugLog(print_r($message,true));
		}

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
           	foreach($message->parts as $part) {
               	$this->getnthAttachmentRecursive($part,$attachment,$number,$nth);
           	}
 		}
    }
    function getAllAttachmentsRecursive($message,&$export_msg) {

        if(!isset($message->ctype_primary)) return;

		if(isset($message->disposition) ||
			isset($message->headers['content-id'])) {
            if (isset($message->d_parameters['filename'])) 
            	$filename = $message->d_parameters['filename'];
            else if (isset($message->ctype_parameters['name'])) 
            	$filename = $message->ctype_parameters['name'];
			else if(isset($message->headers['content-description']))
				$filename = $message->headers['content-description'];
        	else {
        		if ($message->ctype_primary == "message" &&
        	    	 $message->ctype_secondary == "rfc822") {
        	    	$filename = "message.eml";
        	    } else {
	             	$filename = "unknown attachment";
				}
			}

			if (isset($message->body) && $message->body != "" &&
				($contenttype1 = trim($message->ctype_primary).'/'.trim($message->ctype_secondary)) != ($contenttype2 = trim(get_mime_type_from_content(trim($filename), $message->body)))) {
				debugLog("Content-Type in message differs determined one (".$contenttype1."/".$contenttype2."). Using determined one.");
				$contenttype = $contenttype2;
			} else {
				$contenttype = $contenttype1;
			}

			if (isset($message->headers['content-id'])) {
				$export_msg->addHTMLImage(	$message->body,
						$contenttype,
						$filename,
						false,
						substr(trim($message->headers['content-id']),1,-1));
			} else {
				$export_msg->addAttachment(	$message->body,
						$contenttype,
						$filename,
						false,
						trim($message->headers['content-transfer-encoding']),
						trim($message->disposition),
						(isset($message->ctype_parameters['charset']) ? trim($message->ctype_parameters['charset']) : ""));
			}
		} else {
//			Just for debugging in case something goes wrong and inline attachment is not being recognized right way
//			debugLog(print_r($message,true));
		}

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
           	foreach($message->parts as $part) {
               	$this->getAllAttachmentsRecursive($part,$export_msg);
           	}
 		}
    }
    
    /* Parse the message and return only the plaintext body
     */
    function getBody($message) {
        $body = "";
        $htmlbody = "";

        $this->getBodyRecursive($message, "plain", $body);

        if(!isset($body) || $body === "") {
            $this->getBodyRecursive($message, "html", $body);
            if (class_exists('html2text')) {
            	$h2t = new html2text($body,false);
            	$body = $h2t->get_text();
            	unset($h2t);
            } else {
            	// remove css-style tags
            	$body = preg_replace("/<style.*?<\/style>/is", "", $body);
            	// remove all other html
            	$body = strip_tags($body);
            }
        }

        return $body;
    }

    // Get all parts in the message with specified type and concatenate them together, unless the
    // Content-Disposition is 'attachment', in which case the text is apparently an attachment
    function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    $this->getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }

    // save the serverdelimiter for later folder (un)parsing
    function getServerDelimiter() {
        $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
        if (is_array($list)) {
            $val = $list[0];

            return $val->delimiter;
        }
        return "."; // default "."
    }

    // speed things up
    // remember what folder is currently open and only change if necessary
    function imap_reopenFolder($folderid, $force = false) {
        // to see changes, the folder has to be reopened!
		if ($this->_mboxFolder != $this->_folders[$folderid] || $force) {
        	$s = @imap_reopen($this->_mbox, $this->_server . str_replace(".", $this->_serverdelimiter, $this->_folders[$folderid]));
			if (!$s) debugLog("failed to change folder: ". implode(", ", imap_errors()));
			$this->_mboxFolder = $this->_folders[$folderid];
		}
	}


    // build a multipart email, embedding body and one file (for attachments)
    function mail_attach($filenm,$filesize,$file_cont,$body, $body_ct, $body_cte, $boundary = false) {
		// $boundary = strtoupper(md5(uniqid(time())));
        if (!$boundary) $boundary = strtoupper(md5(uniqid(time())));

		//remove the ending boundary because we will add it at the end
		$body = str_replace("--$boundary--", "", $body);

        $mail_header = "Content-Type: multipart/mixed; boundary=$boundary\n";

        // build main body with the sumitted type & encoding from the pda
        $mail_body  = $this->enc_multipart($boundary, $body, $body_ct, $body_cte);
        $mail_body .= $this->enc_attach_file($boundary, $filenm, $filesize, $file_cont, ($filenm == "forwarded_message.eml" ? "" : ""));

        $mail_body .= "--$boundary--\n\n";

        return array($mail_header, $mail_body);
    }

    function enc_multipart($boundary, $body, $body_ct, $body_cte) {
        $mail_body = "This is a multi-part message in MIME format\n\n";
        $mail_body .= "$body\n\n";

        return $mail_body;
    }

    function enc_attach_file($boundary, $filenm, $filesize, $file_cont, $content_type = "") {
        if (!$content_type || $content_type=="") $content_type = "text/plain";
        $mail_body = "--$boundary\n";
        $mail_body .= "Content-Type: $content_type; name=\"$filenm\"\n";
        $mail_body .= "Content-Transfer-Encoding: base64\n";
        $mail_body .= "Content-Disposition: attachment; filename=\"$filenm\"\n";
        $mail_body .= "Content-Description: $filenm\n\n";
		$mail_body .= chunk_split(base64_encode($file_cont)) . "\n\n";

        return $mail_body;
    }

    // adds a message as seen to a specified folder (used for saving sent mails)
    function addSentMessage($folderid, $header, $body) {
        return @imap_append($this->_mbox,$this->_server . $folderid, $header . "\n\n" . $body ,"\\Seen");
    }


    // parses address objects back to a simple "," separated string
    function parseAddr($ad) {
        $addr_string = "";
        if (isset($ad) && is_array($ad)) {
            foreach($ad as $addr) {
                if ($addr_string) $addr_string .= ",";
                    $addr_string .= $addr->mailbox . "@" . $addr->host;
            }
        }
        return $addr_string;
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
//	    	$response["userinformation"]["emailaddresses"][] = $userdetails["emailaddress"];
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

    function formatAddress($str) {
		if (strrpos($str,",") && $str{0} != "\"") {
							// Split -> Explode replace
		    $split = explode(",",$str);
		    $str = "";
		    foreach($split as $val) {
				$val = trim($val);
				if (strlen($str) > 0) $str .= ", ";
				if (strrpos($val," ") && $val{0} != "\"") 
		    	    $str .= "\"".substr($val,0,strrpos($val," "))."\"".substr($val,strrpos($val," "));
		    	else 
	    		    $str .= $val;
		    }
		}
        if (strrpos($str," ") && $str{0} != "\"") 
    	    $str = "\"".substr($str,0,strrpos($str," "))."\"".substr($str,strrpos($str," "));
		$address = imap_rfc822_parse_adrlist($str,"localhost");
		$str = "";
		for($i=0;$i<sizeof($address);$i++) {
		    if (strlen($str) > 0) $str .= ", ";
			    $email = $address[$i]->mailbox."@".$address[$i]->host;
    	    if (isset($address[$i]->personal)) 
    			$str .= '"'.$address[$i]->personal. '" <'.$email.'>';
    	    else 
				$str .= '"'.$email.'" <'.$email.'>';
			};
		return w2u($str);
    }

    function getDisplayTo($str) {
		if (strrpos($str," ") && $str{0} != "\"") 
		    $str = "\"".substr($str,0,strrpos($str," "))."\"".substr($str,strrpos($str," "));
		$address = imap_rfc822_parse_adrlist($str,"domain.com");
		if ($address[0]) {
		    $email = $address[0]->mailbox."@".(isset($address[0]->host) ? $address[0]->host : "localhost");
    	    if (isset($address[0]->personal)) 
    			$str = $address[0]->personal;
    		else 
				$str = $email;
		};
		return w2u($str);
    }

    function getEmailAddress($str) {
		if (strrpos($str," ") && $str{0} != "\"") 
		    $str = "\"".substr($str,0,strrpos($str," "))."\"".substr($str,strrpos($str," "));
			$address = imap_rfc822_parse_adrlist($str,"domain.com");
			if ($address[0]) {
			    $email = $address[0]->mailbox."@".(isset($address[0]->host) ? $address[0]->host : "localhost");
			    $str = $email;
			};
		return w2u($str);
    }

};

?>