<?php
if(isset($_GET["debug"])){
  error_reporting(E_ALL);
  ini_set ('display_errors', 'On');
}
// Authorization
require_once __DIR__ . "/../timezone.php";
session_start();
// TEMPORARILY DISABLED FOR TESTING
// if(!isset($_SESSION["rpidbauth"])){
//   $output = array('auth' => 'false');
//   echo json_encode($output);
//   exit();
// }
require "Config.php";
$config = new Config;
$config->load("../local.config", "../defaults.php");
// Uptime
$uptime = shell_exec("cat /proc/uptime");
$uptime = explode(" ", $uptime);
$uptime = (int) $uptime[0];
$y = (int) floor($uptime / 60 / 60 / 24 / 365);
$d = (int) floor($uptime / 60 / 60 / 24) % 365;
$h = (int) floor(($uptime / 3600) % 24);
$m = (int) floor(($uptime / 60) % 60);
$s = (int) $uptime % 60;
$uptime_string = '';
if ($y > 0) {
  $yw = $y > 1 ? ' years ' : ' year ';
  $uptime_string .= $y . $yw;
}
if ($d > 0) {
  $dw = $d > 1 ? ' days ' : ' day ';
  $uptime_string .= $d . $dw;
}
if ($h > 0) {
  $hw = $h > 1 ? ' hours ' : ' hour ';
  $uptime_string .= $h . $hw;
}
if ($m > 0) {
  $mw = $m > 1 ? ' mins ' : ' min ';
  $uptime_string .= $m . $mw;
}
if ($s > 0) {
  $sw = $s > 1 ? ' secs ' : ' sec ';
  $uptime_string .= $s . $sw;
}
// CPU temperature
$cputemp = 0;
exec("cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null",$cputemp_arr);
if(!empty($cputemp_arr) && isset($cputemp_arr[0])){
  $cputemp = $cputemp_arr[0] / 1000;
}
// CPU frequency
$cpufreq = 0;
exec("cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq 2>/dev/null",$cpufreq_arr);
if(!empty($cpufreq_arr) && isset($cpufreq_arr[0])){
  $cpufreq = $cpufreq_arr[0] / 1000;
}
// load of processor
$getLoad = sys_getloadavg();
// time (use system clock to respect OS timezone)
$timed = trim(shell_exec("date +'%H:%M:%S'"));
// RAM
$free = shell_exec('free -m'); // output in megabytes (-m)
$free = (string)trim($free);
$free_arr = explode("\n", $free);
$mem = explode(" ", $free_arr[1]);
$mem = array_filter($mem);
$mem = array_merge($mem);
$free_version=trim(shell_exec("free --version")); // required trim(), to remove trailing whitespace
//echo "<pre>".$free_version."</pre>";
if ($free_version == "free from procps-ng 3.3.9"){ // old free version Linux 8
  $memtotal = $mem[1];
  $memused = $mem[2];
  $memfree = $mem[3];
  $membuffer = $mem[5];
  $memcached = $mem[6];
  $mavail = $memfree + $membuffer + $memcached;
  $munavail = $memused - $membuffer - $memcached;
  $memperc = round(($munavail / $memtotal)*100);
  // Swap
  $swap = explode(" ", $free_arr[3]);
  $swap=array_filter($swap, function($value) { return $value !== ''; });
  $swap = array_merge($swap);
  $swaptotal = $swap[1];
  $swapused = $swap[2];
  $swapfree = $swap[3];
  $swapperc = round(($swapused/$swaptotal)*100);
}else{ // new free version Linux 9 + 10
  $memtotal = $mem[1];
  $memused = $mem[2];
  $memfree = $mem[3];
  $membuffcache = $mem[5];
  //$memcached = $mem[6];
  $mavail = $mem[6];
  //$mavail = $memfree + $membuffer + $memcached;
  $munavail = $memtotal - $mavail;
  $memperc = round(($munavail / $memtotal)*100);
  // Swap
  $swap = explode(" ", $free_arr[2]);
  $swap=array_filter($swap, function($value) { return $value !== ''; });
  $swap = array_merge($swap);
  $swaptotal = $swap[1];
  $swapused = $swap[2];
  $swapfree = $swap[3];
  if($swaptotal == 0){
    $swapperc = 0;
  }else{
    $swapperc = round(($swapused/$swaptotal)*100);
  }

}
$output = array('auth' => 'true', 'timest' => $timed, 'uptime' => $uptime_string, 'cputemp' => $cputemp, 'cpufreq' => $cpufreq, 'load' => $getLoad, 'memperc' => $memperc, 'memavail' => $mavail, 'memunavail' => $munavail, 'swapperc' => $swapperc, 'swaptotal' => $swaptotal, 'swapused' => $swapused);
echo json_encode($output);
?>
