<?php
/**
 * Helper functions for the TextMate bundle "FTP/SSH"
 * Version 2.3, 2008-05-13
 *
 * @author Bernhard Fürst
 */

// start initialisation of variables and connection parameters

// More readable flag for stickieness of error dialogs
define('STICKY', TRUE);

// Initialize variables
$PROJECT_DIR = dirname(array_key_exists('TM_PROJECT_FILEPATH', $_SERVER) ? $_SERVER['TM_PROJECT_FILEPATH'] : $_ENV['TM_PROJECT_FILEPATH']);
$PREFS_FILE = (empty($PROJECT_DIR) ? (array_key_exists('TM_DIRECTORY', $_SERVER) ? $_SERVER['TM_DIRECTORY'] : $_ENV['TM_DIRECTORY']) : $PROJECT_DIR).'/.ftpssh_settings';
$PREFS = array();

// Load Settings from the project directory
$PREFS = load_settings_file($PREFS_FILE);

// Show Settings Dialog if no Settings found
if(empty($PREFS)) {
	$PREFS = settings_dialog($PREFS_FILE);
}

// end of initialisation

/**
 * Get a file from a remote host
 *
 * @param string $TM_FILENAME 
 * @param string $TM_DIRECTORY 
 * @param string $PROJECT_DIR 
 * @param string $PREFS 
 * @return void
 * @author Bernhard Fürst
 */
function get_file($TM_FILENAME, $TM_FILEPATH, $TM_DIRECTORY, $PROJECT_DIR, $PREFS) 
{
	// Get path of current file relative to the $PROJECT_DIR
	$relative_dir = get_relative_dir($TM_DIRECTORY, $PROJECT_DIR);

	if(0 == strcasecmp('ftp', $PREFS['protocol'])) {
		// The remote path must be encoded by rawurlencode() (RFC 1738)
		$path = rawurlencode($PREFS['path'].$relative_dir.$TM_FILENAME);

		// FTP command for uploading current file
		// The slash between the host and the path is a separator and not part of the remote path
		$command = '/usr/bin/ftp -V '.(empty($PREFS['cli_options'])?'':$PREFS['cli_options']).' '.
	             '-o '.escapeshellarg($TM_FILEPATH).' '.
							 escapeshellarg('ftp://'.$PREFS['user'].':'.$PREFS['password'].'@'.$PREFS['host'].'/'.$path);
	}

	elseif(0 == strcasecmp('ssh', $PREFS['protocol'])) {
  	// Escape spaces. That's essential for the remote SCP path. Simply quoting does not work.
  	$path = str_replace(' ', '\ ', $PREFS['path'].$relative_dir.$TM_FILENAME);
  	
		// SCP command for uploading current file
		$command = '/usr/bin/scp '.(empty($PREFS['cli_options'])?'':$PREFS['cli_options']).' '.
							 escapeshellarg($PREFS['user'].'@'.$PREFS['host']).':'.escapeshellarg($path).' '.escapeshellarg($TM_FILEPATH);
	}

	else {
		notify('Protocol "'.$PREFS['protocol'].'" not known. Please check your remote settings.');
		exit();
	}

	// Execute escaped (for security reasons) command
	$command .= ' 2>&1';
	$result = shell_exec($command);
	
	// Error occured
	if (!empty($result)) {
	  notify('Error ('.$PREFS['protocol'].'): '.$result."\nCommand being used:\n".$command, TRUE);
	  return;
	}
	
	// Upload sucessful
	notify('Reloaded "'.array_key_exists('TM_FILENAME', $_SERVER) ? $_SERVER['TM_FILENAME'] : $_ENV['TM_FILENAME'].'"');

  // reload current document 
  // see "rescan_project" in TextMate.app/Contents/SharedSupport/Support/lib/bash_init.sh
  shell_exec('osascript &>/dev/null -e \'tell app "SystemUIServer" to activate\' -e \'tell app "TextMate" to activate\'');

	return;

}

/**
 * Put a file on a remote host
 *
 * @param string $TM_FILENAME 
 * @param string $TM_DIRECTORY 
 * @param string $PROJECT_DIR 
 * @param string $PREFS 
 * @return void
 * @author Bernhard Fürst
 */
function put_file($TM_FILENAME, $TM_FILEPATH, $TM_DIRECTORY, $PROJECT_DIR, $PREFS) {
	// Get path of current file relative to the $PROJECT_DIR
	$relative_dir = get_relative_dir($TM_DIRECTORY, $PROJECT_DIR);

	if(0 == strcasecmp('ftp', $PREFS['protocol'])) {
		// Encoded by rawurlencode() (RFC 1738) does not work here. May be a bug in the ftp client?
		// Escaping spaces not nessecary because of escapeshellarg() below
  	$path = $PREFS['path'].$relative_dir.$TM_FILENAME;

		// FTP command for uploading current file
		$command = '/usr/bin/ftp -V '.(empty($PREFS['cli_options'])?'':$PREFS['cli_options']).' -u '.
							 escapeshellarg('ftp://'.$PREFS['user'].':'.$PREFS['password'].'@'.$PREFS['host'].'/'.$path).' '.escapeshellarg($TM_FILEPATH);
	}

	elseif(0 == strcasecmp('ssh', $PREFS['protocol'])) {
  	// Escape spaces. That's essential for the remote SCP path. Simply quoting does not work.
  	$path = str_replace(' ', '\ ', $PREFS['path'].$relative_dir);

		// Remote path must be quoted
		$command = '/usr/bin/scp '.(empty($PREFS['cli_options'])?'':$PREFS['cli_options']).
							 ' '.escapeshellarg($TM_FILEPATH).' '.escapeshellarg($PREFS['user'].'@'.$PREFS['host']).':'.escapeshellarg($path).'';
	}

	else {
		notify('Protocol "'.$PREFS['protocol'].'" not found. Please check your .ftpssh_settings file.', STICKY);
		exit();
	}

	// Execute escaped (for security reasons) command
	$command .= ' 2>&1';
	$result = shell_exec($command);
	
	// Error occured
	if (!empty($result)) {
	  notify('Error ('.$PREFS['protocol'].'): '.$result."\nCommand being used:\n".$command, TRUE);
	  return;
	}
	
	// Upload sucessful
	notify('Uploaded "'.array_key_exists('TM_FILENAME', $_SERVER) ? $_SERVER['TM_FILENAME'] : $_ENV['TM_FILENAME'].'"');
  return;
}

/**
 * Echo a message using Growl if available or just by the echo command
 *
 * @param string $message The message text
 * @param boolean $sticky Growl: Make the message sticky on the screen. Use this for error mssages 
 * @return void
 * @author Bernhard Fürst
 */
function notify($message, $sticky = false) {
	$sticky = ($sticky) ? '-s' : '';
	
	// Use GROWL for notifying
	if(file_exists('/usr/local/bin/growlnotify')) {
		shell_exec('/usr/local/bin/growlnotify '.$sticky.' -a TextMate.app -m '.escapeshellarg($message).' FTP/SSH Bundle'.($sticky?' Error':''));
		return;
	}

	// Just use echo
	echo $message;
}

function settings_dialog($PREFS_FILE, $prefs='') {
	// default settings
	$default = '<plist version="1.0"><dict><key>protocol</key><string>ftp</string></dict></plist>';
	
	// Show current remote settings if any
	if(is_array($prefs)) {
		$default = '<plist version="1.0"><dict>';
		foreach($prefs as $key => $value) {
			$default .= '<key>'.$key.'</key><string>'.$value.'</string>';
		}
		$default .= '</dict></plist>';
	}

	// Show settings dialog
	$result = shell_exec((array_key_exists('DIALOG', $_SERVER) ? $_SERVER['DIALOG'] : $_ENV['DIALOG'])." -cmp '".$default."' '".(array_key_exists('TM_BUNDLE_SUPPORT', $_SERVER) ? $_SERVER['TM_BUNDLE_SUPPORT'] : $_ENV['TM_BUNDLE_SUPPORT'])."/nibs/FTP_SSH Settings.nib'");

	// Make array from $result
	$result = parse_plist($result);

	// User caneled the Settings dialog, full stop here
	if(empty($result['save'])) {
		exit();
	}
	
	// remove the "save" key because we don't need to save it to the settings file
	$result = clean_settings($result);
	
	// Trailer for settings file (don't forget closing \n !)
	$settings = "; Preferences for the FTP/SSH Bundle for TextMate\n; See http://internalaffairs.fuerstnet.de/ftp-ssh-bundle-textmate\n; (c) 2007 Bernhard Fürst <bernhard@fuersten.info>\n; Warning: Content of this file will be overwritten when using the\n; \"Remote Connection Settings...\" command of FTP/SSH Bundle\n";

	// Get values
	foreach($result as $key => $value) {
		$settings .= $key.'="'.$value."\"\n";
	}

	// TODO: check values

	// Save settings to .ftpssh_settings
  if (!$handle = fopen($PREFS_FILE, 'w')) {
		notify("Cannot open or create settings file.", STICKY);
		return false;
  }

  // Write $settings to our opened file.
  if (fwrite($handle, $settings) === FALSE) {
		notify("Cannot write to the settings file.", STICKY);
		return false;
  }

  fclose($handle);

	// Change mode to owner r/w, group and others no rights
	chmod($PREFS_FILE, 0600);

	// Finally load the settings
	$PREFS = load_settings_file($PREFS_FILE);
	
	return $PREFS;
}

/**
 * Little dirty parser for key/value pairs in plist's
 *
 * @param string $plist 
 * @return array $result
 * @author Bernhard Fürst
 */
function parse_plist($plist) {
	// Check for key/value pairs
	$matches = array();
	preg_match_all('@<key>([^<]+)</key>\s*<(string|integer)>([^<]+)</(string|integer)>@', $plist, $matches);
	
	// transform to an array(key => value)
	// keys are in $matches[1], values in $matches[3]
	$result = array();
	while (list($k, $key) = each($matches[1])) {
		$result[$key] = $matches[3][$k];
	}

	// Finally check for the key "returnArgument" which will indicate the user clicked the "Save" button
	if(preg_match('@<key>returnArgument</key>@', $plist)) {
		$result['save'] = true;
	}
	
	return $result;
}

function load_settings_file($PREFS_FILE) {
	// check if settings file exists
	if(!file_exists($PREFS_FILE)) {
		notify("Remote settings file not found.");
		return array();
	}

	// Load settings
	$PREFS = parse_ini_file($PREFS_FILE);

	if(empty($PREFS)) {
		notify("Remote settings file is empty or invalide.");
		return array();
	}

	// Clean settings from unwanted parameters
	$PREFS = clean_settings($PREFS);

	// Append trailing slash to the path if it is not empty
	$PREFS['path'] = (!empty($PREFS['path']) AND !preg_match('|/$|', $PREFS['path'] )) ? $PREFS['path'].'/' : $PREFS['path'];

	return $PREFS;
}

function clean_settings($settings) {
	$allowed_keys = array('cli_options','host','password','protocol','user', 'path');
	
	// remove all unwanted keys from the settings
	foreach($settings as $key => $value) {
		if(!in_array($key, $allowed_keys)) {
			unset($settings[$key]);
		}
	}
	
	// return clean settings
	return $settings;
}

function get_relative_dir($TM_DIRECTORY, $PROJECT_DIR) {
  // If no TM Project is used there is no chance to get any relative dir
  if (empty($PROJECT_DIR)) {
    return '';
  }
  
  // Get path of current file relative to the $PROJECT_DIR
  $relative_dir = substr($TM_DIRECTORY, strlen($PROJECT_DIR));
  $relative_dir = ltrim($relative_dir, '/');

  // Append trailing slash only if $relative_dir is not empty
	$relative_dir = '' === $relative_dir ? '' : $relative_dir.'/';

	return $relative_dir;
}