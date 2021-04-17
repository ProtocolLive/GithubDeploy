<?php
// 2021.04.17.00
// Protocol Corporation Ltda.
// https://github.com/ProtocolLive/GithubDeploy/

declare(strict_types = 1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('error_reporting', '-1');
ini_set('html_errors', '1');
ini_set('max_execution_time', '30');

$dir = dirname(__DIR__, 1);
require($dir . '/config.php');
require($dir . '/GithubDeploy.php');

set_error_handler('Error');
ob_start();
function Error(int $errno, string $errstr, ?string $errfile, ?int $errline){
  print "Error $errstr in $errfile in line $errline";
  header("HTTP/1.1 500 PHP error");
  die();
}

print 'Start deploy at ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ")\n";
print "Checking the repository...\n\n";
$GHD = new GithubDeploy(GithubDeployToken);

$GHD->Deploy('ProtocolLive', 'Ajax', SystemDir . '/GithubDeploy/deploys/Ajax', '');
print 'Ajax deployed';