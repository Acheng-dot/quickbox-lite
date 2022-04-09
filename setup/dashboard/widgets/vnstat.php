<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/inc/localize.php');

// Valid values for other parameters you can pass to the script.
// Input parameters will always be limited to one of the values listed here.
// If a parameter is not provided or invalid it will revert to the default,
// the first parameter in the list.
/** @var string $page */
$page = null;
/** @var string $iface */
$iface = null;

$page_list = ['t', 'h', 'd', 'm'];

/**
 * @return void
 */
function validate_input() {
    global $page,  $page_list;
    global $iface, $iface_list;
    //
    // get interface data
    //
    $page  = $_GET['page'] ?? '';
    $iface = $_GET['if'] ?? '';

    if (!in_array($page, $page_list, true)) {
        $page = $page_list[0];
    }

    if (!in_array($iface, $iface_list, true)) {
        $iface = $iface_list[0];
    }
}

/**
 * @return void
 */
function get_vnstat_data() {
    global $iface, $page, $vnstat_bin, $data_dir;
    global $hour, $day, $month, $top, $summary;

    $vnstat_data = [];
    if (!isset($vnstat_bin) || $vnstat_bin === '') {
        if (file_exists("{$data_dir}/vnstat_dump_{$iface}")) {
            $file_data = file_get_contents("{$data_dir}/vnstat_dump_{$iface}");
            assert($file_data !== false);
            $vnstat_data = json_decode($file_data, true);
        }
    } else {
        $fd = popen("{$vnstat_bin} --json -i {$iface} {$page}", 'r');
        if (is_resource($fd)) {
            $buffer = '';
            while (!feof($fd)) {
                $buffer .= fgets($fd);
            }
            $vnstat_data = json_decode($buffer, true);
            pclose($fd);
        }
    }

    $day   = [];
    $hour  = [];
    $month = [];
    $top   = [];

    if (!isset($vnstat_data) || !isset($vnstat_data['vnstatversion'])) {
        return;
    }
    $json_version = $vnstat_data['jsonversion'];
    /**
     * json version 1: All traffic values in the output are in KiB.
     * json version 2: All traffic values in the output are in bytes.
     */
    $data_coefficient = $json_version === '1' ? 1024 : 1;

    $iface_index = array_search($iface, array_column($vnstat_data['interfaces'], 'name'), true);
    if (!$iface_index) {
        $iface_index = 0;
    }

    $iface_data   = $vnstat_data['interfaces'][$iface_index];
    $traffic_data = $iface_data['traffic'];

    // data are grouped for hour, day, month, ... and a data entry looks like this:
    // [0] => Array
    //   (
    //     [id] => 48032
    //     [date] => Array
    //       (
    //         [year] => 2020
    //         [month] => 8
    //         [day] => 23
    //       )
    //     [time] => Array
    //       (
    //         [hour] => 16
    //         [minute] => 0
    //       )
    //     [rx] => 2538730
    //     [tx] => 2175640
    //   )

    // per-hour data
    if ($page === 'h') {
        $hour_data = array_reverse($json_version === '1' ? $traffic_data['hours'] : $traffic_data['hour']);
        for ($i = 0; $i < min(24, count($hour_data)); ++$i) {
            $d          = $hour_data[$i];
            $hours      = $json_version === '1' ? 0 : $d['time']['hour'];
            $hour_delta = 23 - $d['id'];
            $ts         = mktime($hours, 0, 0, $d['date']['month'], $d['date']['day'], $d['date']['year']);
            assert($ts !== false);
            $diff_time = min(time() - $ts, 3600); // at most one hour
            $rx        = $d['rx'] * $data_coefficient;
            $tx        = $d['tx'] * $data_coefficient;

            $hour[$i] = [
                'time'   => $ts,
                'label'  => $json_version === '1' ? "T-{$hour_delta} h" : date('h A', $ts),
                'rx'     => $rx, // in bytes
                'tx'     => $tx, // int bytes
                'rx_avg' => round($rx / $diff_time) * 8, // in bits/s
                'tx_avg' => round($tx / $diff_time) * 8, // in bits/s
            ];
        }
    }

    // per-day data
    if ($page === 'd') {
        $day_data = array_reverse($json_version === '1' ? $traffic_data['days'] : $traffic_data['day']);
        for ($i = 0; $i < min(30, count($day_data)); ++$i) {
            $d  = $day_data[$i];
            $ts = mktime(0, 0, 0, $d['date']['month'], $d['date']['day'], $d['date']['year']);
            assert($ts !== false);
            $diff_time = min(time() - $ts, 86400); // at most one day
            $rx        = $d['rx'] * $data_coefficient;
            $tx        = $d['tx'] * $data_coefficient;

            $day[$i] = [
                'time'   => $ts,
                'label'  => date('d F', $ts),
                'rx'     => $rx, // in bytes
                'tx'     => $tx, // int bytes
                'rx_avg' => round($rx / $diff_time) * 8, // in bits/s
                'tx_avg' => round($tx / $diff_time) * 8, // in bits/s
            ];
        }
    }

    // per-month data
    if ($page === 'm') {
        $month_data = array_reverse($json_version === '1' ? $traffic_data['months'] : $traffic_data['month']);
        for ($i = 0; $i < min(12, count($month_data)); ++$i) {
            $d         = $month_data[$i];
            $first_day = mktime(0, 0, 0, $d['date']['month'], 1, $d['date']['year']);
            $last_day  = mktime(0, 0, 0, $d['date']['month'] + 1, 1, $d['date']['year']);
            assert($first_day !== false);
            assert($last_day !== false);
            $full_month_diff = $last_day - $first_day;
            $diff_time       = min(time() - $first_day, $full_month_diff); // at most one month
            $rx              = $d['rx'] * $data_coefficient;
            $tx              = $d['tx'] * $data_coefficient;

            $month[$i] = [
                'time'   => $first_day,
                'label'  => date('F Y', $first_day),
                'rx'     => $rx, // in bytes
                'tx'     => $tx, // int bytes
                'rx_avg' => round($rx / $diff_time) * 8, // in bits/s
                'tx_avg' => round($tx / $diff_time) * 8, // in bits/s
            ];
        }
    }

    // top10 days data
    if ($page === 't') {
        $top10_data = $json_version === '1' ? $traffic_data['tops'] : $traffic_data['top'];
        for ($i = 0; $i < min(10, count($top10_data)); ++$i) {
            $d  = $top10_data[$i];
            $ts = mktime(0, 0, 0, $d['date']['month'], $d['date']['day'], $d['date']['year']);
            assert($ts !== false);
            $diff_time = min(time() - $ts, 86400); // at most one day
            $rx        = $d['rx'] * $data_coefficient;
            $tx        = $d['tx'] * $data_coefficient;

            $top[$i] = [
                'time'   => $ts,
                'label'  => date('d F Y', $ts),
                'rx'     => $rx, // in bytes
                'tx'     => $tx, // int bytes
                'rx_avg' => round($rx / $diff_time) * 8, // in bits/s
                'tx_avg' => round($tx / $diff_time) * 8, // in bits/s
            ];
        }
    }

    // summary data from old dumpdb command
    $summary['totalrx']   = $traffic_data['total']['rx'] * $data_coefficient; // in bytes
    $summary['totaltx']   = $traffic_data['total']['tx'] * $data_coefficient; // in bytes
    $summary['interface'] = $iface_data['name'];
    $created              = $iface_data['created'];
    $summary['created']   = mktime(0, 0, 0, $created['date']['month'], $created['date']['day'], $created['date']['year']);
}
