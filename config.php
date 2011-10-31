<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   Main configuration file
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

	// disable globally enabled compression, as this causes issues with some devices
	// z-push handles output compression 
	ini_set("zlib.output_compression",0);

	$GLOBALS['egw_info'] = array(
		'flags' => array(
			'disable_Template_class'  => true,
	//		'login'                   => true,
			'noheader'                => true,
			'currentapp'              => 'login',
			'no_exception_handler' => 'errorlog',	// only logs exceptions
	));

	include('../header.inc.php');

	// no need to set default_timezone here, this is done during eGroupware header include.

    // Defines the default time zone
    // if (function_exists("date_default_timezone_set")){
    //    date_default_timezone_set("Europe/Berlin");
    // }

    // Defines the base path on the server, terminated by a slash
    define('BASE_PATH', dirname($_SERVER['SCRIPT_FILENAME']) . "/");

    // Define the include paths
    ini_set('include_path',
		BASE_PATH. "include/" . PATH_SEPARATOR .
		BASE_PATH. PATH_SEPARATOR .
		ini_get('include_path'));

  // DEPRECIATED USE STATE_PATH! only defined for compatibility
	define('STATE_DIR', $GLOBALS['egw_info']['server']['files_dir'].'/activesync');
	// state dir for active sync as "activesync" subdir EGw's files directory
	define('STATE_PATH', $GLOBALS['egw_info']['server']['files_dir'].'/activesync');
 	if (!file_exists(STATE_PATH))
 	{
 		mkdir(STATE_DIR,0700,true);
 	}


    // Try to set unlimited timeout
    define('SCRIPT_TIMEOUT', 3540+600);

    //Max size of attachments to display inline. Default is 1MB
    define('MAX_EMBEDDED_SIZE', 1048576);

    // Device Provisioning
    define('PROVISIONING', true);
    
    // Should UPN be separated for Login Username
	define('SEPARATE_UPN', false);


    // This option allows the 'loose enforcement' of the provisioning policies for older
    // devices which don't support provisioning (like WM 5 and HTC Android Mail) - dw2412 contribution
    // false (default) - Enforce provisioning for all devices
    // true - allow older devices, but enforce policies on devices which support it
    define('LOOSE_PROVISIONING', true);

    // Palm Pre AS2.5 PoomTasks:RTF Fix
    define('ENABLE_PALM_PRE_AS25_CONTACT_FIX',true);

    // Switch of imtoinet because of segfaults
    define('ICS_IMTOINET_SEGFAULT',true);

    // Defines the charset used in Backend. AirSync charset is UTF-8!
    // Leave as is in case you use default Zarafa Server.
    // In case your Backend needs another value just adapt it.
    // EGroupware uses utf-8 only
    define('BACKEND_CHARSET','utf-8');

    // Default conflict preference
    // Some devices allow to set if the server or PIM (mobile)
    // should win in case of a synchronization conflict
    //   SYNC_CONFLICT_OVERWRITE_SERVER - Server is overwritten, PIM wins
    //   SYNC_CONFLICT_OVERWRITE_PIM    - PIM is overwritten, Server wins (default)
    define('SYNC_CONFLICT_DEFAULT', SYNC_CONFLICT_OVERWRITE_PIM);

	// In case Function Overload is being detect for mbstring functions we set the define
	// to the overload level so that we can handle binary data propper...
	define('MBSTRING_OVERLOAD', (extension_loaded('mbstring') ? ini_get('mbstring.func_overload') : false));

	// For verification of SSL Certificates please define where to call the openssl binary
	//define("VERIFYCERT_SSLBIN","/usr/bin/openssl");
	// For verification of SSL Certificates please define where the Certificate Store is being located
	// define("VERIFYCERT_CERTSTORE","crtstore/");
	// For verification of SSL Certificates please define where to store temporary files
	// define("VERIFYCERT_TEMP","tmp/");

    // The data providers that we are using (see configuration below)
    $BACKEND_PROVIDER = "BackendEGW";

    // ************************
    //  BackendICS settings
    // ************************

    // Defines the server to which we want to connect
    define('MAPI_SERVER', 'file:///var/run/zarafa');


    // ************************
    //  BackendIMAP settings
    // ************************

    // Defines the server to which we want to connect
    // recommended to use local servers only
    define('IMAP_SERVER', 'localhost');
    // connecting to default port (143)
    define('IMAP_PORT', 143);
    // best cross-platform compatibility (see http://php.net/imap_open for options)
    define('IMAP_OPTIONS', '/notls/norsh');
    // overwrite the "from" header if it isn't set when sending emails
    // options: 'username'    - the username will be set (usefull if your login is equal to your emailaddress)
    //        'domain'    - the value of the "domain" field is used
    //        '@mydomain.com' - the username is used and the given string will be appended
    define('IMAP_DEFAULTFROM', '');
    // copy outgoing mail to this folder. If not set z-push will try the default folders
	// Additionally you have to have a Sent Items folder in case you plan to sync with Nokia MfE Built in client / certain HTC Adnroid devices!
    define('IMAP_SENTFOLDER', '');
	// You have to have a Deleted Items folder in case you plan to sync with Nokia MfE Built in client / certain HTC Adnroid devices!
    define('IMAP_DELETEDITEMSFOLDER', '');
	// You have to have a Draft folder in case you plan to sync with Nokia MfE Built in client / certain HTC Adnroid devices!
    define('IMAP_DRAFTSFOLDER', '');
    // forward messages inline (default off - as attachment)
    define('IMAP_INLINE_FORWARD', false);
    // use imap_mail() to send emails (default) - off uses mail()
    define('IMAP_USE_IMAPMAIL', true);
    // In case you have problems with messages being partially received set this to false and retry sync
    define('IMAP_USE_FETCHHEADER', true);
    // Define username => fullname changes here
    define('IMAP_USERNAME_FULLNAME', serialize(array(
	    'user1'=>'Lastname1, Firstname1',
	    'user2'=>'Lastname2, Firstname2',
	    )));



    // ************************
    //  BackendMaildir settings
    // ************************
    define('MAILDIR_BASE', '/tmp');
    define('MAILDIR_SUBDIR', 'Maildir');

    // **********************
    //  BackendVCDir settings
    // **********************
    define('VCARDDIR_DIR', '/home/%u/.kde/share/apps/kabc/stdvcf');

    // **************************
    //  BackendiConDir settings
    // **************************
    define('ICONDIR_DIR','C:\xampp\htdocs\as12.1\data\%u\iCon');
    define('ICONDIR_FOLDERNAME','Tobit Kontakte');

    // **************************
    //  BackendiCalDir settings
    // **************************
    define('ICALDIR_DIR','C:\xampp\htdocs\as12.1\data\%u\iCal');
    define('ICALDIR_FOLDERNAME','Calendar');

    // **************************
    //  BackendCombined settings
    // **************************
    define('BACKENDCOMBINED_CONFIG', serialize(array(
        //the order in which the backends are loaded.
        //login only succeeds if all backend return true on login
        //when sending mail: the mail is send with first backend that is able to send the mail
        'backends' => array(
            'i' => array(
                'name' => 'BackendIMAP',
            ),
            'v' => array(
                'name' => 'BackendiConDir',
            ),
            'c' => array(
                'name' => 'BackendiCalDir',
            ),
        ),
        'delimiter' => '/',
        //creating a new folder in the root folder should create a folder in one backend
        'rootcreatefolderbackend' => 'i',
	))
    );


?>