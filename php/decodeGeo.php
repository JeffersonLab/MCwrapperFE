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

//GET IPS FROM ACTIVE PROJECTS ONLY (Tested: 0=testing, 1=running, 40=declared complete/waiting to bundle)
$data = array();
$data2 = array();
$fdata = array();

$sql="SELECT DISTINCT UIp FROM Project WHERE UIp IS NOT NULL AND Tested IN (0, 1, 40);";
//dbg("SQL1: $sql");

$result = $conn->query($sql);
if(!$result) { /*dbg("SQL1 error: " . $conn->error);*/ }

if ($result && $result->num_rows > 0) {
    //dbg("Project IPs found: " . $result->num_rows);
    while($row = $result->fetch_assoc()) {
        $data[]=$row;
    }
} else {
    //dbg("No active project IPs found");
}

// Run IPs: only from attempts belonging to active projects
$sql2="SELECT DISTINCT a.RunIP FROM Attempts a
       INNER JOIN Project p ON a.Project_ID = p.ID
       WHERE a.RunIP IS NOT NULL AND a.BatchSystem='OSG' AND p.Tested IN (0, 1, 40);";
//dbg("SQL2: $sql2");

$result2 = $conn->query($sql2);
if(!$result2) { /*dbg("SQL2 error: " . $conn->error);*/ }

if ($result2 && $result2->num_rows > 0) {
    //dbg("Run IPs found: " . $result2->num_rows);
    while($row2 = $result2->fetch_assoc()) {
        $data2[]=$row2;
    }
} else {
    //dbg("No active run IPs found");
}


$count=0;
//var_dump($data);
//echo "<br>";
foreach ($data as $row)
{
    $IP_TO_LOOKUP=$row["UIp"];

    // Skip if already cached WITH valid coords; re-try if cached as NULL (old failed lookup)
    $check_DB="SELECT ID FROM Locations WHERE IP=\"" . $IP_TO_LOOKUP . "\" AND Latitude IS NOT NULL";
    $check=$conn->query($check_DB);

    if($check->num_rows != 0)
    {
        continue;
    }
    // Delete stale NULL entry so we can re-insert
    $conn->query("DELETE FROM Locations WHERE IP=\"" . $IP_TO_LOOKUP . "\" AND Latitude IS NULL");

    if($count>=5)
    {
        break;
    }

    Lookup($conn,$IP_TO_LOOKUP);
    $count=$count+1;
}
foreach ($data2 as $row2)
{
    $IP_TO_LOOKUP2=$row2["RunIP"];

    $check_DB2="SELECT ID FROM Locations WHERE IP=\"" . $IP_TO_LOOKUP2 . "\" AND Latitude IS NOT NULL";
    $check2=$conn->query($check_DB2);

    if($check2->num_rows != 0)
    {
        continue;
    }
    $conn->query("DELETE FROM Locations WHERE IP=\"" . $IP_TO_LOOKUP2 . "\" AND Latitude IS NULL");

    if($count>=5)
    {
        break;
    }

    Lookup($conn,$IP_TO_LOOKUP2);
    $count=$count+1;
   
    //echo "==================" . "<br>";
}


// Collect all IPs we need coords for
$allIPs = array();
foreach ($data  as $r) { $allIPs[] = "\"" . $conn->real_escape_string($r["UIp"])   . "\""; }
foreach ($data2 as $r) { $allIPs[] = "\"" . $conn->real_escape_string($r["RunIP"]) . "\""; }
//dbg("Total unique IPs to look up: " . count(array_unique($allIPs)));

if (count($allIPs) > 0) {
    $inList = implode(",", array_unique($allIPs));
    $fsql = "SELECT * FROM Locations WHERE IP IN ($inList) AND Latitude IS NOT NULL;";
} else {
    $fsql = "SELECT * FROM Locations WHERE 1=0;"; // nothing to fetch
}
//dbg("Final SQL: " . substr($fsql, 0, 200));

$fresult = $conn->query($fsql);
if(!$fresult) { /*dbg("Final SQL error: " . $conn->error);*/ }

if ($fresult && $fresult->num_rows > 0) {
    //dbg("Locations returned: " . $fresult->num_rows);
    while($frow = $fresult->fetch_assoc()) {
        $fdata[]=$frow;
    }
} else {
    //dbg("No locations found for these IPs");
}

$conn->close();

header('Content-Type: application/json');
echo json_encode($fdata);
return;

function Lookup($conn,$IP)
{
    // Using ip-api.com - free, no API key required, 45 req/min
    // Fields: status,lat,lon,query
    $baseUrlip = "http://ip-api.com/json/";
    $fields = "?fields=status,message,lat,lon,query";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_URL, $baseUrlip . $IP . $fields);

    $ret = curl_exec($ch);
    curl_close($ch);

    if ($ret === false) {
        error_log("decodeGeo curl error for IP $IP");
        return;
    }

    $vars = json_decode($ret, true);
    if (!$vars || $vars["status"] !== "success") {
        error_log("decodeGeo lookup failed for IP $IP: " . (isset($vars["message"]) ? $vars["message"] : "unknown"));
        // Still cache it as NULL so we don't keep retrying a bad IP
        $IP_TO_USE = $conn->real_escape_string($IP);
        $conn->query("INSERT INTO Locations (IP, Longitude, Latitude) VALUES (\"$IP_TO_USE\", NULL, NULL)");
        $conn->commit();
        return;
    }

    $IP_TO_USE   = $conn->real_escape_string($vars["query"]);
    $LONG_TO_USE = isset($vars["lon"]) ? floatval($vars["lon"]) : NULL;
    $LAT_TO_USE  = isset($vars["lat"]) ? floatval($vars["lat"]) : NULL;

    if ($LAT_TO_USE !== NULL && $LONG_TO_USE !== NULL) {
        $isql = "INSERT INTO Locations (IP, Longitude, Latitude) VALUES (\"$IP_TO_USE\", $LONG_TO_USE, $LAT_TO_USE)";
    } else {
        $isql = "INSERT INTO Locations (IP, Longitude, Latitude) VALUES (\"$IP_TO_USE\", NULL, NULL)";
    }

    $conn->query($isql);
    $conn->commit();
}
?>
