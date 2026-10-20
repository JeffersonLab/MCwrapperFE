<?php
// System health summary for the Dashboard "Health" tab: outcomes and failure causes over a selectable window
// (?range=1h..30d, or range=custom&from=YYYY-MM-DDTHH:MM&to=... in JLab local time), plus current queue depth and
// drain-rate estimate. Status 3 (removed by a human) is ignored throughout.
// PHP 8.1+ makes mysqli throw by default; this script checks return values instead
mysqli_report(MYSQLI_REPORT_OFF);
require_once "readerConnection.php";

header('Content-Type: application/json');

$CACHE_TTL   = 15;  // seconds; many dashboards poll this, the window scan is not free
$SCAN_DAYS   = 10;  // attempts created this long before the window start are still scanned for completions (Attempts has no time index)
$RATE_HOURS  = 3;   // window for the start/creation rates behind the time-to-empty estimate
$MAX_SPAN    = 93 * 86400;
$SUCCESS_STATUSES = array("4", "44", "succeeded");
$PRESETS = array("1h" => 3600, "2h" => 7200, "6h" => 21600, "12h" => 43200, "24h" => 86400, "7d" => 604800, "30d" => 2592000);
$BUCKETS = array(300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800);
$TARGET_BUCKETS = 32;

$range = isset($_GET["range"]) ? (string)$_GET["range"] : "24h";
$custom = ($range === "custom");
if (!$custom && !isset($PRESETS[$range])) $range = "24h";
$fromIn = ($custom && isset($_GET["from"])) ? (string)$_GET["from"] : "";
$toIn   = ($custom && isset($_GET["to"])) ? (string)$_GET["to"] : "";
$DT_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';
if ($custom && (!preg_match($DT_RE, $fromIn) || !preg_match($DT_RE, $toIn))) {
    echo json_encode(array("error" => "bad custom range"));
    exit;
}

$cacheKey = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : "") . "|" . $range . "|" . $fromIn . "|" . $toIn;
$cacheFile = sys_get_temp_dir() . "/mcwrapper_health_" . md5($cacheKey) . ".json";
// long windows are expensive and barely move between polls
$CACHE_TTL = $custom ? 300 : ($PRESETS[$range] <= 86400 ? $CACHE_TTL : max($CACHE_TTL, min(600, (int)floor($PRESETS[$range] / 2880))));
if (is_file($cacheFile) && time() - filemtime($cacheFile) < $CACHE_TTL) {
    readfile($cacheFile);
    exit;
}

if (!isset($conn) || !$conn) {
    echo json_encode(array("error" => "db"));
    exit;
}

function scalar($conn, $sql)
{
    $res = $conn->query($sql);
    if (!$res) return null;
    $row = $res->fetch_row();
    return $row ? $row[0] : null;
}

// Smallest Attempts.ID created at or after unix time $ts, by binary search on the primary key (IDs grow with Creation_Time).
function firstIdSince($conn, $maxID, $ts)
{
    $lo = 1;
    $hi = $maxID + 1;
    while ($lo < $hi) {
        $mid = (int)floor(($lo + $hi) / 2);
        $res = $conn->query("SELECT ID, UNIX_TIMESTAMP(Creation_Time) ct FROM Attempts WHERE ID >= " . intval($mid) . " ORDER BY ID LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            $hi = $mid;
        } elseif ($row["ct"] !== null && intval($row["ct"]) >= $ts) {
            $hi = $mid;
        } else {
            $lo = intval($row["ID"]) + 1;
        }
    }
    return $lo;
}

function failureCause($status, $exit, $pf)
{
    if ($exit === null) return "lost (status " . $status . ")";
    $exit = intval($exit);
    if ($exit == 999) return "condor";
    if ($exit == 404) return "missing output";
    if ($exit == -232) return "randoms (retried)";
    if ($pf !== null && $pf !== "") return (string)$pf;
    return "exit " . $exit;
}

function fail_json($msg)
{
    echo json_encode(array("error" => $msg));
    exit;
}

$res = $conn->query("SELECT UNIX_TIMESTAMP(), TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())");
$row = $res ? $res->fetch_row() : null;
if (!$row) fail_json("db query");
$dbNow = intval($row[0]);
// "local seconds" (unix + offset) put floor()/gmdate() on JLab wall-clock time, so day buckets start at local midnight
$tzOff = intval($row[1]);

if ($custom) {
    $fromLocal = strtotime($fromIn . ":00 UTC");
    $toLocal = strtotime($toIn . ":00 UTC");
    if ($fromLocal === false || $toLocal === false) fail_json("bad custom range");
    $from = $fromLocal - $tzOff;
    $to = min($toLocal - $tzOff, $dbNow);
    if ($to - $from < 300 || $to - $from > $MAX_SPAN) fail_json("range must be 5 minutes to 93 days, ending no later than now");
    $label = gmdate("M j H:i", $from + $tzOff) . " \xE2\x80\x93 " . gmdate("M j H:i", $to + $tzOff);
} else {
    $to = $dbNow;
    $from = $to - $PRESETS[$range];
    $label = "last " . $range;
}
$span = $to - $from;
$live = ($to >= $dbNow - 60);

$bucket = end($BUCKETS);
foreach ($BUCKETS as $b) {
    if ($b >= $span / $TARGET_BUCKETS) { $bucket = $b; break; }
}
$kFrom = (int)floor(($from + $tzOff) / $bucket);
$kTo = (int)floor(($to - 1 + $tzOff) / $bucket);
$buckets = array();
for ($k = $kFrom; $k <= $kTo; $k++) $buckets[$k] = array("ok" => 0, "fail" => 0, "causes" => array());

$maxID    = intval(scalar($conn, "SELECT MAX(ID) FROM Attempts"));
$scanFrom = firstIdSince($conn, $maxID, $from - $SCAN_DAYS * 86400);
$fromID   = firstIdSince($conn, $maxID, $from);
$toID     = $live ? $maxID + 1 : firstIdSince($conn, $maxID, $to);
$fromRate = firstIdSince($conn, $maxID, $dbNow - $RATE_HOURS * 3600);

$causes = array();
$ok = 0;
$fail = 0;
$okWall = 0.0;
$okWallN = 0;
$res = $conn->query(
    "SELECT FLOOR((UNIX_TIMESTAMP(Completed_Time) + " . $tzOff . ") / " . $bucket . ") k, Status, ExitCode,
            IF(ExitCode=404, NULL, ProgramFailed) pf, COUNT(*) n, SUM(TIME_TO_SEC(WallTime)) wall, SUM(WallTime IS NOT NULL) walln
     FROM Attempts
     WHERE ID >= " . intval($scanFrom) . " AND ID < " . intval($toID) . "
       AND Completed_Time >= FROM_UNIXTIME(" . intval($from) . ") AND Completed_Time <= FROM_UNIXTIME(" . intval($to) . ")
       AND Status != '3'
     GROUP BY k, Status, ExitCode, pf"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $k = max($kFrom, min($kTo, intval($r["k"])));
        $n = intval($r["n"]);
        $status = (string)$r["Status"];
        $isOk = $r["ExitCode"] !== null && intval($r["ExitCode"]) == 0 && in_array($status, $SUCCESS_STATUSES, true);
        $isFail = !$isOk && ($status === "6" || ($r["ExitCode"] !== null && intval($r["ExitCode"]) != 0));
        if ($isOk) {
            $ok += $n;
            $buckets[$k]["ok"] += $n;
            $okWall += floatval($r["wall"]);
            $okWallN += intval($r["walln"]);
        } elseif ($isFail) {
            $fail += $n;
            $buckets[$k]["fail"] += $n;
            $c = failureCause($status, $r["ExitCode"], $r["pf"]);
            $causes[$c] = (isset($causes[$c]) ? $causes[$c] : 0) + $n;
            $buckets[$k]["causes"][$c] = (isset($buckets[$k]["causes"][$c]) ? $buckets[$k]["causes"][$c] : 0) + $n;
        }
    }
    $res->free();
}
arsort($causes);

$queue = array("idle" => 0, "running" => 0, "held" => 0);
$res = $conn->query("SELECT Status, COUNT(*) n FROM Attempts WHERE Status IN ('1','2') GROUP BY Status");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $queue[(string)$r["Status"] === "1" ? "idle" : "running"] = intval($r["n"]);
    }
    $res->free();
}
$idRange = "ID >= " . intval($fromID) . " AND ID < " . intval($toID);
// held attempts linger indefinitely, so only count those created inside the window
$queue["held"] = intval(scalar($conn, "SELECT COUNT(*) FROM Attempts WHERE " . $idRange . " AND Status = '5'"));
$created = intval(scalar($conn, "SELECT COUNT(*) FROM Attempts WHERE " . $idRange . " AND Status != '3'"));

$scanNow = firstIdSince($conn, $maxID, $dbNow - $SCAN_DAYS * 86400);
$createdRate = intval(scalar($conn, "SELECT COUNT(*) FROM Attempts WHERE ID >= " . intval($fromRate) . " AND Status != '3'"));
$startedRate = intval(scalar($conn, "SELECT COUNT(*) FROM Attempts WHERE ID >= " . intval($scanNow) .
    " AND Start_Time >= NOW() - INTERVAL " . intval($RATE_HOURS) . " HOUR AND Status != '3'"));
// drain rate for the ETA: completions are unaffected by a fresh submission, which only adds to idle
$completed24h = intval(scalar($conn, "SELECT COUNT(*) FROM Attempts WHERE ID >= " . intval($scanNow) .
    " AND Completed_Time >= NOW() - INTERVAL 24 HOUR AND Status != '3' AND (ExitCode IS NOT NULL OR Status = '6')"));
$conn->close();

$startsPerHour  = $startedRate / $RATE_HOURS;
$createdPerHour = $createdRate / $RATE_HOURS;
$completionsPerHour = $completed24h / 24;
$etaHours = null;
if ($queue["idle"] == 0) {
    $etaHours = 0;
} elseif ($completionsPerHour > 0) {
    $etaHours = $queue["idle"] / $completionsPerHour;
}

$bucketList = array();
foreach ($buckets as $k => $bk) {
    $bucketList[] = array("k" => $k, "t" => gmdate("Y-m-d H:i", $k * $bucket), "ok" => $bk["ok"], "fail" => $bk["fail"],
        "causes" => (object)$bk["causes"]);
}

$finished = $ok + $fail;
$out = array(
    "generated"       => gmdate("c", $dbNow),
    "window"          => array("label" => $label, "range" => $range, "bucketSec" => $bucket, "live" => $live,
                               "from" => gmdate("Y-m-d H:i", $from + $tzOff), "to" => gmdate("Y-m-d H:i", $to + $tzOff)),
    "finished"        => $finished,
    "created"         => $created,
    "success"         => $ok,
    "failed"          => $fail,
    "successPct"      => $finished ? round(100 * $ok / $finished, 1) : null,
    "failPct"         => $finished ? round(100 * $fail / $finished, 1) : null,
    "causes"          => (object)$causes,
    "buckets"         => $bucketList, // oldest first
    "avgWallSec"      => $okWallN ? round($okWall / $okWallN) : null,
    "throughputPerHour" => round($finished / ($span / 3600), 1),
    "queue"           => $queue,
    "rateHours"       => $RATE_HOURS,
    "startsPerHour"   => round($startsPerHour, 1),
    "createdPerHour"  => round($createdPerHour, 1),
    "completionsPerHour" => round($completionsPerHour, 1),
    "etaHours"        => $etaHours === null ? null : round($etaHours, 2),
);
$json = json_encode($out, defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0);
@file_put_contents($cacheFile, $json, LOCK_EX);
echo $json;

