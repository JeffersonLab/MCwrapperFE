<?php
// TEMPORARY DEBUG - remove when working
//ini_set('display_errors', 0);
//error_reporting(E_ALL);
//$debug_log = array();
//function dbg($msg) { global $debug_log; $debug_log[] = $msg; }

require_once "writerConnection.php";
// Check connection
if (!$conn) {
    header('Content-Type: application/json');
    //echo json_encode(array("error" => "DB connection failed: " . mysqli_connect_error(), "debug" => $debug_log));
    echo json_encode(array());
    exit;
}

// Submit IPs of everything shown in the Projects table (same filter as MakeTables.php's ProjectF).
// Run IPs are geolocated per project by projectGeo.php; scanning them all here took ~100 s.
$ips = array();
$result = $conn->query("SELECT DISTINCT UIp FROM Project WHERE UIp IS NOT NULL AND UIp <> '' AND Notified IS NULL");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ip = trim($row["UIp"]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $ips[$ip] = true;
        }
    }
}
$ips = array_keys($ips);

function FetchCached($conn, $ips)
{
    $rows = array();
    if (count($ips) == 0) return $rows;
    $esc = array();
    foreach ($ips as $ip) $esc[] = "'" . $conn->real_escape_string($ip) . "'";
    $res = $conn->query("SELECT ID, IP, Longitude, Latitude FROM Locations WHERE IP IN (" . implode(",", $esc) . ")");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if (!isset($rows[$r["IP"]]) || $r["Latitude"] !== null) $rows[$r["IP"]] = $r;
        }
    }
    return $rows;
}

$cached = FetchCached($conn, $ips);

// Uncached IPs only; NULL rows are known failures and are not retried
$count = 0;
foreach ($ips as $ip) {
    if (isset($cached[$ip])) continue;
    if ($count >= 5) break;
    Lookup($conn, $ip);
    $count++;
}
if ($count > 0) $cached = FetchCached($conn, $ips);

$fdata = array();
foreach ($cached as $r) {
    if ($r["Latitude"] !== null && $r["Longitude"] !== null) $fdata[] = $r;
}

$conn->close();

header('Content-Type: application/json');
echo json_encode($fdata);
return;

function Upsert($conn, $ip, $lon, $lat)
{
    $stmt = $conn->prepare("INSERT INTO Locations (IP, Longitude, Latitude) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE Longitude = COALESCE(VALUES(Longitude), Longitude), Latitude = COALESCE(VALUES(Latitude), Latitude)");
    if (!$stmt) return;
    $stmt->bind_param("sdd", $ip, $lon, $lat);
    $stmt->execute();
    $stmt->close();
}

function Lookup($conn, $IP)
{
    // ip-api.com: free, no key, 45 req/min
    $ch = curl_init("http://ip-api.com/json/" . urlencode($IP) . "?fields=status,message,lat,lon,query");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $ret = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // network error or rate limit: leave uncached so it is retried later
    if ($ret === false || $code != 200) return;
    $vars = json_decode($ret, true);
    if (!is_array($vars) || !isset($vars["status"])) return;

    if ($vars["status"] === "success" && isset($vars["lat"]) && isset($vars["lon"])) {
        Upsert($conn, $IP, floatval($vars["lon"]), floatval($vars["lat"]));
    } else {
        error_log("decodeGeo lookup failed for IP $IP: " . (isset($vars["message"]) ? $vars["message"] : "unknown"));
        Upsert($conn, $IP, null, null);
    }
}
?>
