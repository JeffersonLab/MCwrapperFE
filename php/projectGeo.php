<?php
// Returns where a project's jobs ran / are running (latest attempt per job), aggregated by location,
// plus the submit location (Project.UIp). Unusable IPs (NULL, empty, 0.0.0.0, private, reserved) fall
// back to the hostname in RunningLocation; anything still unlocatable is counted in "unlocated".
require_once "writerConnection.php";

header('Content-Type: application/json');

$DEFAULT_ORIGIN = array("lat" => 37.0583, "lon" => -76.4607, "label" => "Jefferson Lab");
$MAX_NEW_HOSTS  = 15;   // DNS resolutions per request
$IP_BATCH_SIZE  = 100;  // ip-api batch maximum
$IPAPI_RESERVE  = 1;    // keep this many of ip-api's 15 req/min unused as headroom
$IPAPI_DEFAULT_WINDOW = 60;

if (!isset($conn) || !$conn) {
    echo json_encode(array("error" => "db"));
    exit;
}
// projID=everything: whole-DB view, precomputed daily by MCwrapper_scigroup/geoSnapshot.py
if (isset($_GET["projID"]) && $_GET["projID"] === "everything") {
    $res = $conn->query("SELECT Data FROM GeoSnapshot WHERE Scope = 'everything' ORDER BY ID DESC LIMIT 1");
    $row = $res ? $res->fetch_row() : null;
    $json = $row ? @gzuncompress($row[0]) : false;
    $conn->close();
    echo $json !== false ? $json : json_encode(array("error" => "no snapshot yet"));
    exit;
}
// projID=all: every active (un-notified) project, with streamers aggregated per origin->site route
$all = isset($_GET["projID"]) && $_GET["projID"] === "all";
if (!$all && (!isset($_GET["projID"]) || !ctype_digit((string)$_GET["projID"]))) {
    echo json_encode(array("error" => "bad projID"));
    exit;
}
$projID = $all ? 0 : intval($_GET["projID"]);
// seconds this request may spend blocking on ip-api lookups (waiting out rate-limit windows included);
// whatever is still unlocated is left as "pending" and picked up by the next poll
$LOOKUP_BUDGET = 10;
$LOOKUP_CHUNKS = 1; // batches per request: keeps responses fast so the globe fills in as the client re-polls
set_time_limit($all ? 180 : 60);

function isPublicIP($ip)
{
    if ($ip === null) return false;
    $ip = trim($ip);
    if ($ip === "" || !filter_var($ip, FILTER_VALIDATE_IP)) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    // carrier-grade NAT 100.64.0.0/10 is not covered by the PHP flags
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if (($long & 0xFFC00000) == (ip2long("100.64.0.0") & 0xFFC00000)) return false;
    }
    return true;
}

// RunningLocation looks like slot1_2@glidein_123_456@host.domain.edu
function hostFromRunningLocation($loc)
{
    if ($loc === null) return null;
    $parts = explode("@", trim($loc));
    $host = strtolower(end($parts));
    if (strlen($host) > 100) return null;
    if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,24}$/', $host)) return null;
    return $host;
}

function domainLabel($host)
{
    if ($host === null) return null;
    $parts = explode(".", $host);
    if (count($parts) > 2) array_shift($parts);
    return implode(".", $parts);
}

// key => array(lat, lon) or null (known unlocatable); missing key => not cached
function loadCache($conn, $keys)
{
    $out = array();
    if (count($keys) == 0) return $out;
    $esc = array();
    foreach ($keys as $k) $esc[] = "'" . $conn->real_escape_string($k) . "'";
    $res = $conn->query("SELECT IP, Latitude, Longitude FROM Locations WHERE IP IN (" . implode(",", $esc) . ")");
    if (!$res) return $out;
    while ($r = $res->fetch_assoc()) {
        if ($r["Latitude"] !== null && $r["Longitude"] !== null) {
            $out[$r["IP"]] = array(floatval($r["Latitude"]), floatval($r["Longitude"]));
        } elseif (!array_key_exists($r["IP"], $out)) {
            $out[$r["IP"]] = null;
        }
    }
    return $out;
}

function storeCache($conn, $key, $coords)
{
    if ($key === null || $key === "") return;
    $stmt = $conn->prepare("INSERT INTO Locations (IP, Longitude, Latitude) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE Longitude = COALESCE(VALUES(Longitude), Longitude), Latitude = COALESCE(VALUES(Latitude), Latitude)");
    if (!$stmt) return;
    $lon = $coords ? $coords[1] : null;
    $lat = $coords ? $coords[0] : null;
    $stmt->bind_param("sdd", $key, $lon, $lat);
    $stmt->execute();
    $stmt->close();
}

// One ip-api batch call. Returns array(results, code, rl, ttl): results is ip => array(lat, lon) | null (only IPs
// that were answered); rl/ttl are ip-api's X-Rl (requests left in window) and X-Ttl (seconds to window reset).
function batchLookup($ips)
{
    $out = array();
    $hdr = array("rl" => null, "ttl" => null);
    $payload = array();
    foreach ($ips as $ip) $payload[] = array("query" => $ip);
    $ch = curl_init("http://ip-api.com/batch?fields=status,message,query,lat,lon");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$hdr) {
        if (preg_match('/^X-Rl:\s*(\d+)/i', $line, $m)) $hdr["rl"] = intval($m[1]);
        if (preg_match('/^X-Ttl:\s*(\d+)/i', $line, $m)) $hdr["ttl"] = intval($m[1]);
        return strlen($line);
    });
    $ret = curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    if ($ret !== false && $code == 200) {
        $vars = json_decode($ret, true);
        if (is_array($vars)) {
            foreach ($vars as $v) {
                if (!isset($v["query"]) || $v["query"] === "") continue;
                if (isset($v["status"]) && $v["status"] === "success" && isset($v["lat"]) && isset($v["lon"])) {
                    $out[$v["query"]] = array(floatval($v["lat"]), floatval($v["lon"]));
                } else {
                    $out[$v["query"]] = null;
                }
            }
        }
    }
    return array($out, $code, $hdr["rl"], $hdr["ttl"]);
}

// Locate up to $maxChunks batches (of $chunkSize IPs) per call, so each request returns quickly and the client
// polls again for the rest. The ip-api free-tier window (15 batch req/min per source IP) is shared by every
// request on this server through a locked state file, so concurrent users/polls queue behind one another
// (up to $budget seconds) instead of tripping a 429 and the resulting ban.
// Returns array(results, stats): results is ip => array(lat, lon) | null for the IPs that were answered;
// stats = attempted, answered, code (last HTTP status or 0), error, retry_after (seconds until the next
// lookup can succeed; 0 = none needed).
function locateIPs($ips, $budget, $chunkSize, $maxChunks, $reserve, $defaultWindow)
{
    $out = array();
    $stats = array("attempted" => 0, "answered" => 0, "code" => 0, "error" => null, "retry_after" => 0);
    $ips = array_values($ips);
    if (count($ips) == 0) return array($out, $stats);
    $deadline = microtime(true) + $budget;
    $fh = @fopen(sys_get_temp_dir() . "/ipapi_ratelimit.json", "c+");
    if (!$fh) { // cannot coordinate: do not risk unthrottled calls
        $stats["error"] = "no rate-limit state file";
        $stats["retry_after"] = 60;
        return array($out, $stats);
    }
    $locked = false;
    while (!($locked = flock($fh, LOCK_EX | LOCK_NB))) {
        if (microtime(true) >= $deadline) break;
        usleep(200000);
    }
    if (!$locked) {
        fclose($fh);
        $stats["error"] = "busy";
        $stats["retry_after"] = 2;
        return array($out, $stats);
    }
    $state = json_decode(stream_get_contents($fh), true);
    if (!is_array($state)) $state = array();
    $remaining = isset($state["remaining"]) ? intval($state["remaining"]) : null;
    $resetAt   = isset($state["reset_at"]) ? floatval($state["reset_at"]) : 0;

    $chunks = array_slice(array_chunk($ips, $chunkSize), 0, $maxChunks);
    foreach ($chunks as $chunk) {
        $now = microtime(true);
        if ($now >= $resetAt) $remaining = null; // new window, allowance unknown/full
        if ($remaining !== null && $remaining <= $reserve) {
            if ($resetAt > $deadline) { // window will not reopen within our budget
                $stats["retry_after"] = (int)ceil($resetAt - $now);
                break;
            }
            usleep((int)(max(0, $resetAt - $now) * 1000000) + 250000);
            $remaining = null;
        }
        $stats["attempted"] += count($chunk);
        list($res, $code, $rl, $ttl) = batchLookup($chunk);
        $now = microtime(true);
        $stats["code"] = $code;
        if ($ttl === null) $ttl = $defaultWindow;
        if ($code == 429) {
            $remaining = 0;
            $resetAt = $now + $ttl + 1;
            $stats["error"] = "rate limited";
            $stats["retry_after"] = (int)ceil($ttl + 1);
            break;
        }
        if ($code != 200) { // network/server trouble: leave for a later poll
            $stats["error"] = $code == 0 ? "ip-api unreachable" : "ip-api HTTP " . $code;
            $stats["retry_after"] = 15;
            break;
        }
        foreach ($res as $ip => $c) $out[$ip] = $c;
        $stats["answered"] += count($res);
        if ($rl !== null) {
            $remaining = $rl;
            $resetAt = $now + $ttl;
        } elseif ($remaining !== null) {
            $remaining--;
        }
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(array("remaining" => $remaining, "reset_at" => $resetAt)));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return array($out, $stats);
}

function originStatus($uip)
{
    if ($uip === null || trim($uip) === "" || strtoupper(trim($uip)) === "NULL") return "null";
    if (!isPublicIP($uip)) return "internal";
    return "ok";
}

function resolveOrigin($uip, $cache, $default)
{
    $st = originStatus($uip);
    if ($st === "ok") {
        $k = trim($uip);
        if (isset($cache[$k])) {
            return array("lat" => $cache[$k][0], "lon" => $cache[$k][1], "label" => "Submitted here", "default" => false, "ip_status" => "ok");
        }
        $st = array_key_exists($k, $cache) ? "unresolved" : "pending";
    }
    $o = $default;
    $o["default"] = true;
    $o["ip_status"] = $st;
    return $o;
}

function cellOf($lat, $lon)
{
    return round($lat, 1) . "," . round($lon, 1);
}

// ---- project(s) / submit location ----
$projects = array(); // ID => UIp
if ($all) {
    $res = $conn->query("SELECT ID, UIp FROM Project WHERE Notified IS NULL");
    if ($res) {
        while ($r = $res->fetch_assoc()) $projects[intval($r["ID"])] = $r["UIp"];
    }
} else {
    $stmt = $conn->prepare("SELECT UIp FROM Project WHERE ID = ?");
    $stmt->bind_param("i", $projID);
    $stmt->execute();
    $stmt->bind_result($uip);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) {
        echo json_encode(array("error" => "no such project"));
        exit;
    }
    $projects[$projID] = $uip;
}

// ---- latest attempt per job ----
$rows = array();
if (count($projects)) {
    $sql = "SELECT j.Project_ID, a.RunIP, a.RunningLocation, a.Status, a.ExitCode, a.Start_Time
            FROM Jobs j
            LEFT JOIN Attempts a ON a.ID = (SELECT MAX(la.ID) FROM Attempts la WHERE la.Job_ID = j.ID)
            WHERE j.Project_ID IN (" . implode(",", array_keys($projects)) . ") AND j.IsActive = 1";
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
}

$unlocated = array("waiting" => 0, "no_ip" => 0, "internal" => 0, "unresolved" => 0, "pending" => 0);
$jobs = array(); // each: key (IP or hostname), host, outcome
foreach ($rows as $r) {
    // same success/failure definitions as the Progress columns in MakeTables.php
    $status = strtolower((string)$r["Status"]);
    $outcome = "other";
    if ($status === "2" || $status === "running") {
        $outcome = "running";
    } elseif ($status === "3") {
        $outcome = "succeeded"; // condor removed: deliberate, needs no intervention
    } elseif (in_array($status, array("4", "44", "succeeded", "problem", "5"), true) && $r["ExitCode"] !== null) {
        $outcome = intval($r["ExitCode"]) === 0 ? "succeeded" : "failed";
    }
    $ip = $r["RunIP"] === null ? null : trim($r["RunIP"]);
    $isInternal = ($ip !== null && $ip !== "0.0.0.0" && $ip !== "::" && filter_var($ip, FILTER_VALIDATE_IP));
    $host = hostFromRunningLocation($r["RunningLocation"]);
    $pid = intval($r["Project_ID"]);
    if (isPublicIP($ip)) {
        $jobs[] = array("key" => $ip, "host" => $host, "outcome" => $outcome, "proj" => $pid);
    } elseif ($host !== null) {
        $jobs[] = array("key" => $host, "host" => $host, "outcome" => $outcome, "internal" => $isInternal, "proj" => $pid);
    } elseif ($r["RunIP"] === null && $r["RunningLocation"] === null && $r["Start_Time"] === null) {
        $unlocated["waiting"]++;
    } elseif ($isInternal) {
        $unlocated["internal"]++;
    } else {
        // NULL, empty, "NULL"/"None" strings, 0.0.0.0, or garbage
        $unlocated["no_ip"]++;
    }
}

// ---- resolve coordinates ----
$keys = array();
foreach ($projects as $p => $u) {
    if (originStatus($u) === "ok") $keys[trim($u)] = true;
}
$keyJobs = array(); // key => job count, so the busiest sites are located first
foreach ($jobs as $j) {
    $keys[$j["key"]] = true;
    $keyJobs[$j["key"]] = isset($keyJobs[$j["key"]]) ? $keyJobs[$j["key"]] + 1 : 1;
}
arsort($keyJobs);
$cache = loadCache($conn, array_keys($keys));

$needIPs = array(); // ip => job weight
$hostToIP = array();
$hostsTried = 0;
foreach (array_keys($keys) as $k) {
    if (array_key_exists($k, $cache)) continue;
    $w = isset($keyJobs[$k]) ? $keyJobs[$k] : 0;
    if (isPublicIP($k)) {
        $needIPs[$k] = (isset($needIPs[$k]) ? $needIPs[$k] : 0) + $w;
    } elseif ($hostsTried < $MAX_NEW_HOSTS) {
        $hostsTried++;
        $resolved = gethostbyname($k);
        if ($resolved !== $k && isPublicIP($resolved)) {
            $hostToIP[$k] = $resolved;
            $needIPs[$resolved] = (isset($needIPs[$resolved]) ? $needIPs[$resolved] : 0) + $w;
        } else {
            storeCache($conn, $k, null);
            $cache[$k] = null;
        }
    }
}
// resolved host IPs may already be cached
$hostIPCache = loadCache($conn, array_values($hostToIP));
foreach ($hostIPCache as $ip => $c) {
    $cache[$ip] = $c;
    unset($needIPs[$ip]);
}
// locate the next batch of uncached IPs (most jobs first, throttled), persisting each answer so it is never looked up again
arsort($needIPs);
list($lookup, $lookupStats) = locateIPs(array_keys($needIPs), $LOOKUP_BUDGET, $IP_BATCH_SIZE, $LOOKUP_CHUNKS, $IPAPI_RESERVE, $IPAPI_DEFAULT_WINDOW);
$lookupStats["queued"] = count($needIPs) - $lookupStats["answered"];
$lookupStats["hosts_queued"] = count(array_filter(array_keys($keys), function ($k) use ($cache, $hostToIP) {
    return !array_key_exists($k, $cache) && !isPublicIP($k) && !isset($hostToIP[$k]);
}));
foreach ($lookup as $ip => $c) {
    storeCache($conn, $ip, $c);
    $cache[$ip] = $c;
}
foreach ($hostToIP as $h => $ip) {
    if (array_key_exists($ip, $cache)) {
        storeCache($conn, $h, $cache[$ip]);
        $cache[$h] = $cache[$ip];
    }
}

$projOrigin = array();
foreach ($projects as $p => $u) $projOrigin[$p] = resolveOrigin($u, $cache, $DEFAULT_ORIGIN);

// ---- aggregate by ~10 km cell ----
$sites = array();
$routes = array();
foreach ($jobs as $j) {
    $k = $j["key"];
    if (!array_key_exists($k, $cache)) { $unlocated["pending"]++; continue; }
    if ($cache[$k] === null) {
        if (!empty($j["internal"])) $unlocated["internal"]++; else $unlocated["unresolved"]++;
        continue;
    }
    $lat = $cache[$k][0];
    $lon = $cache[$k][1];
    $cell = cellOf($lat, $lon);
    if (!isset($sites[$cell])) {
        $sites[$cell] = array("lat" => $lat, "lon" => $lon, "jobs" => 0, "running" => 0, "succeeded" => 0, "failed" => 0, "domains" => array());
    }
    $sites[$cell]["jobs"]++;
    if ($j["outcome"] !== "other") $sites[$cell][$j["outcome"]]++;
    if ($all) {
        $o = $projOrigin[$j["proj"]];
        $oc = cellOf($o["lat"], $o["lon"]);
        $rk = $oc . ">" . $cell;
        if (!isset($routes[$rk])) {
            $routes[$rk] = array("origin" => $oc, "site" => $cell,
                "from" => array("lat" => $o["lat"], "lon" => $o["lon"]),
                "to" => array("lat" => $sites[$cell]["lat"], "lon" => $sites[$cell]["lon"]),
                "jobs" => 0, "running" => 0, "succeeded" => 0, "failed" => 0);
        }
        $routes[$rk]["jobs"]++;
        if ($j["outcome"] !== "other") $routes[$rk][$j["outcome"]]++;
    }
    $d = domainLabel($j["host"]);
    if ($d !== null) {
        if (!isset($sites[$cell]["domains"][$d])) $sites[$cell]["domains"][$d] = 0;
        $sites[$cell]["domains"][$d]++;
    }
}
$siteList = array();
foreach ($sites as $cell => $s) {
    arsort($s["domains"]);
    $s["label"] = count($s["domains"]) ? key($s["domains"]) : "";
    $s["cell"] = $cell;
    unset($s["domains"]);
    $siteList[] = $s;
}
$byJobs = function ($a, $b) { return $b["jobs"] - $a["jobs"]; };
usort($siteList, $byJobs);

$conn->close();

if ($all) {
    $origins = array();
    foreach ($projOrigin as $p => $o) {
        $oc = cellOf($o["lat"], $o["lon"]);
        if (!isset($origins[$oc])) {
            $origins[$oc] = array("cell" => $oc, "lat" => $o["lat"], "lon" => $o["lon"], "label" => $o["label"],
                "default" => $o["default"], "projects" => array(), "default_projects" => 0);
        }
        $origins[$oc]["projects"][] = $p;
        if ($o["default"]) $origins[$oc]["default_projects"]++;
        else $origins[$oc]["default"] = false; // a real submit location shares this cell
    }
    $routeList = array_values($routes);
    usort($routeList, $byJobs);
    echo json_encode(array(
        "mode" => "all",
        "project_count" => count($projects),
        "origins" => array_values($origins),
        "sites" => $siteList,
        "routes" => $routeList,
        "total_jobs" => count($rows),
        "unlocated" => $unlocated,
        "lookup" => $lookupStats
    ));
    exit;
}

echo json_encode(array(
    "mode" => "project",
    "projID" => $projID,
    "origin" => $projOrigin[$projID],
    "sites" => $siteList,
    "total_jobs" => count($rows),
    "unlocated" => $unlocated,
    "lookup" => $lookupStats
));
?>
