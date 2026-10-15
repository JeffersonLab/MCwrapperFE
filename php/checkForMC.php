<?php
require_once "readerConnection.php";
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}


$bkg = $_GET["bkg"];

if ( $_GET["bkg"] == "loc" )
{
    $bkg = $bkg . ":/" . $_GET["randomtag"];
}

if ( $_GET["randomtag"] != "" && $_GET["bkg"] != "loc" )
{
    $bkg = $bkg . ":" . $_GET["randomtag"];
}

$sql = "SELECT * FROM Project where Generator=? && VersionSet=? && BKG=?";
$params = array($_GET["generator"], $_GET["versionSet"], $bkg);

// Add simVersionSet to query if provided (optional for backward compatibility)
if (isset($_GET["simVersionSet"]) && $_GET["simVersionSet"] != "") {
    $sql = $sql . " && SimVersionSet=?";
    $params[] = $_GET["simVersionSet"];
}

//echo $sql;
//echo "<br>";

$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat("s", count($params)), ...$params);
$stmt->execute();
$result = $stmt->get_result();

$data = array();
if ($result->num_rows > 0) {
// output data of each row
    while($row = $result->fetch_assoc()) {
        $data []= $row;
/*        $tempArray = array();
        $tempArray["ID"] = $row["ID"];
        $tempArray["Job_ID"] = $row["Job_ID"];
        $tempArray["BatchJobID"] = $row["BatchJobID"];
        $containing["data"] []= $tempArray;
        */
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
} 
$conn->close();


echo json_encode($data);
//echo json_encode($containing);
//return json_encode($data);
?>
