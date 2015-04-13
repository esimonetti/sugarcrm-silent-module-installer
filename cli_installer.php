#!/usr/bin/env php
<?php

// Based on code by Zac Sprackett from http://developer.sugarcrm.com/2013/08/26/silent-install-a-module-loadable-package-from-the-command-line/
// Slightly modified by Enrico Simonetti to automatically unzip the package into a tmp folder, and few other tweaks

function usage()
{
    print("usage: -i /path/to/instance -z /path/to/zipfile\n");
    exit(1);
}

$opts = getopt('i:z:');

if (!$opts) usage();
$sugar_directory = array_key_exists('i', $opts) ? $opts['i'] : false;
$zip_file = array_key_exists('z', $opts) ? $opts['z'] : false;

if (!$sugar_directory || !$zip_file) {
    usage();
}

if ( function_exists('posix_getpwuid') ) {
    $euinfo = posix_getpwuid(posix_geteuid());
    $euser = $euinfo['name'];
    $auser = exec("echo $(ps axho user,comm|grep -E \"httpd|apache\"|uniq|grep -v \"root\"|awk 'END {if ($1) print $1}')");
    if ($euser != $auser ) {
        error_log("Must be run as $auser! [not: $euser]");
        usage();
    }
}

if (!is_readable($zip_file)) {
    error_log("Can't read zip file at $zip_file");
    exit(1);
}

// Create temporary dir to unzip the package
$package_dir = '/tmp/sugarpkg'.rand(10000,99999);
mkdir($package_dir);

if (!is_dir($package_dir)) {
    error_log("Failed to create temporary dir ".$package_dir);
    exit(1);
}

// Initialize SugarCRM
if(!defined('sugarEntry'))  define('sugarEntry', true);
chdir($sugar_directory);
require('config.php');
require_once('include/entryPoint.php');
require_once('include/utils/zip_utils.php');
require_once('include/dir_inc.php');
require_once('ModuleInstall/ModuleInstaller.php');
$current_user = new User();
$current_user->getSystemUser();

// unzip the zip
unzip($zip_file, $package_dir);

// some additional checks
if (!chdir($sugar_directory)) {
    error_log("Failed to chdir to $sugar_directory");
    exit(1);
}

if (!is_readable($package_dir . '/manifest.php')) {
    error_log("Package dir does not contain a readable manifest.php\n");
    exit(1);
}

require_once($package_dir . '/manifest.php');
if (!is_array($manifest)) {
    error_log("Sourced manifest but \$manifest was not set!\n");
    exit(1);
}

if (!array_key_exists('name', $manifest) || $manifest['name'] == '') {
    error_log("Manifest doesn't specify a name!\n");
    exit(1);
}

//initialize the module installer
$modInstaller = new ModuleInstaller();
$modInstaller->silent = true;  //shuts up the javscript progress bar

// disable packageScanner
$sugar_config['moduleInstaller']['packageScan'] = false;

// Squelch some warnings
$GLOBALS['app_list_strings']['moduleList'] = Array();

// Check for already installed
$new_upgrade = new UpgradeHistory();
$new_upgrade->name = $manifest['name'];
if ($new_upgrade->checkForExisting($new_upgrade) !== null) {
    error_log("Already installed.\n");
    exit(0);
}

// Installation
echo "Patching $sugar_directory\n";
$modInstaller->install($package_dir);

$to_serialize = array(
    'manifest'         => $manifest,
    'installdefs'      => isset($installdefs) ? $installdefs : Array(),
    'upgrade_manifest' => isset($upgrade_manifest) ? $upgrade_manifest : Array(),
);

echo "Adding UpgradeHistory object\n";
$new_upgrade->name          = $manifest['name'];
$new_upgrade->filename      = $package_dir;
$new_upgrade->md5sum        = md5_file($zip_file);
$new_upgrade->type          = array_key_exists('type', $manifest) ? $manifest['type'] : 'module';
$new_upgrade->version       = array_key_exists('version', $manifest) ? $manifest['version'] : '';
$new_upgrade->status        = "installed";
$new_upgrade->author        = array_key_exists('author', $manifest) ? $manifest['author'] : '';
$new_upgrade->description   = array_key_exists('description', $manifest) ? $manifest['description'] : '';
$new_upgrade->id_name       = array_key_exists('id', $installdefs) ? $installdefs['id'] : '';
$new_upgrade->manifest      = base64_encode(serialize($to_serialize));

$new_upgrade->save();
echo "Success!\n";

// Delete unzipped package
echo "Deleting temporay folder ".$package_dir."\n";
rmdir_recursive($package_dir);

exit(0);
