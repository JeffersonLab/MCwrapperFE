<?php
// DataTables server-side endpoint for the Dashboard attempts table (large projects):
// returns only the requested page of latest-attempt-per-job rows.
require_once "readerConnection.php";

header('Content-Type: application/json');

$draw = isset($_REQUEST["draw"]) ? intval($_REQUEST["draw"]) : 0;
if (!isset($conn) || !$conn || !isset($_REQUEST["projID"])) {
    echo json_encode(array("draw" => $draw, "recordsTotal" => 0, "recordsFiltered" => 0, "data" => array(), "error" => "unavailable"));
    exit;
}
$projID = intval($_REQUEST["projID"]);
$start  = max(0, isset($_REQUEST["start"]) ? intval($_REQUEST["start"]) : 0);
$length = isset($_REQUEST["length"]) ? intval($_REQUEST["length"]) : 10;
if ($length < 1 || $length > 1000) $length = 1000;

// index = Dashboard column order; values are trusted SQL
$sortable = array(
    "Jobs.IsActive", "Jobs.RunNumber", "Jobs.FileNumber", "Jobs.NumEvts", "Attempts.Start_Time",
    "Attempts.BatchJobID", "Attempts.Status", "Attempts.ExitCode", "Attempts.ProgramFailed",
    "Attempts.WallTime", "Jobs.DataVerified"
);
$orderBy = array();
if (isset($_REQUEST["order"]) && is_array($_REQUEST["order"])) {
    foreach ($_REQUEST["order"] as $o) {
        $c = isset($o["column"]) ? intval($o["column"]) : -1;
        if (!isset($sortable[$c])) continue;
        $dir = (isset($o["dir"]) && strtolower($o["dir"]) === "desc") ? "DESC" : "ASC";
        $orderBy[] = $sortable[$c] . " " . $dir;
    }
}
$orderBy[] = "Jobs.ID ASC"; // stable paging

$from = "FROM Jobs
         INNER JOIN Attempts ON Attempts.Job_ID = Jobs.ID
           AND Attempts.ID = (SELECT MAX(la.ID) FROM Attempts la WHERE la.Job_ID = Jobs.ID)
         WHERE Jobs.Project_ID = $projID AND Jobs.IsActive = 1";

$search = isset($_REQUEST["search"]["value"]) ? trim($_REQUEST["search"]["value"]) : "";
$where = "";
if ($search !== "") {
    $like = "'%" . $conn->real_escape_string(addcslashes($search, "%_\\")) . "%'";
    $fields = array("Jobs.RunNumber", "Jobs.FileNumber", "Attempts.BatchJobID", "Attempts.Status",
                    "Attempts.ExitCode", "Attempts.ProgramFailed", "Attempts.Start_Time");
    $parts = array();
    foreach ($fields as $f) $parts[] = "CAST($f AS CHAR) LIKE $like";
    $where = " AND (" . implode(" OR ", $parts) . ")";
}

$sql = "SELECT SQL_CALC_FOUND_ROWS Attempts.Start_Time, Attempts.BatchJobID, Attempts.Status, Attempts.ExitCode,
               Attempts.ProgramFailed, Attempts.WallTime, Jobs.IsActive, Jobs.RunNumber, Jobs.NumEvts,
               Jobs.FileNumber, Jobs.DataVerified
        $from $where
        ORDER BY " . implode(", ", $orderBy) . "
        LIMIT $start, $length";

$data = array();
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) $data[] = $r;
}
$filtered = 0;
$fr = $conn->query("SELECT FOUND_ROWS() n");
if ($fr) $filtered = intval($fr->fetch_assoc()["n"]);

$total = $filtered;
if ($where !== "") {
    $tr = $conn->query("SELECT COUNT(*) n $from");
    if ($tr) $total = intval($tr->fetch_assoc()["n"]);
}
$conn->close();

echo json_encode(array(
    "draw" => $draw,
    "recordsTotal" => $total,
    "recordsFiltered" => $filtered,
    "data" => $data
));
?>
