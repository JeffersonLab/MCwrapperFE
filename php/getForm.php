<?php
require_once "readerConnection.php";
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}


$sql = "SELECT * FROM Project where ID=" . intval($_GET["id"]);

$result = $conn->query($sql);
$data = array();
if ($result->num_rows > 0) {
// output data of each row
    while($row = $result->fetch_assoc()) {
        $data[]=$row;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
} 

require_once "vsConnection.php";
// Check connection
if (!$conn_vs) {
    die("Connection failed: " . mysqli_connect_error());
}

$vsq="select version from version where packageId in (SELECT id from package where name=?) && versionSetId in (SELECT id from versionSet where filename=?);";

$vsstmt = $conn_vs->prepare($vsq);
$pkg = "halld_recon";
$vsfile = $data[0]['VersionSet'];
$vsstmt->bind_param("ss", $pkg, $vsfile);
$vsstmt->execute();
$recon = $vsstmt->get_result()->fetch_assoc();
//print_r($recon);
$data[0]["recon_ver"]=$recon["version"];

$pkg = "halld_sim";
$vsfile = $data[0]['SimVersionSet'];
$vsstmt->execute();
$sim = $vsstmt->get_result()->fetch_assoc();
//print_r($recon);
$data[0]["sim_ver"]=$sim["version"];
$vsstmt->close();

$conn->close();
$conn_vs->close();

echo json_encode($data);
return json_encode($data);
?>
ql="SELECT COUNT(ID) FROM Attempts as a WHERE ID IN (SELECT Max(ID) FROM Attempts GROUP BY Job_ID) && Job_ID IN (SELECT ID FROM Jobs where Project_ID in (SELECT ID From Project as P WHERE Notified is NULL)) && ExitCode is NULL;";
//    $sql="SELECT Attempts.*,Max(Attempts.Creation_Time) FROM Attempts,Jobs WHERE Attempts.Job_ID = Jobs.ID && Jobs.Project_ID=" . $_GET["projID"] . " GROUP BY Attempts.Job_ID;";
}
if($_GET["Table"]=="RunMap")
{
    $sql="SELECT RunIP FROM Attempts WHERE RunIP is NOT NULL && BatchSystem=\"OSG\" && Job_ID in (SELECT ID From Jobs where Project_ID=". $_GET["projID"] . ");";
    //$sql="SELECT RunIP FROM Attempts WHERE RunIP is NOT NULL && BatchSystem=\"OSG\";";
//    $sql="SELECT Attempts.*,Max(Attempts.Creation_Time) FROM Attempts,Jobs WHERE Attempts.Job_ID = Jobs.ID && Jobs.Project_ID=" . $_GET["projID"] . " GROUP BY Attempts.Job_ID;";
}

//WITH V1 AS ( SELECT P.ID as PROJ_ID, J.ID as JOB_ID, A.Creation_Time as TIME_STAMP FROM Attempts A JOIN Jobs J ON A.Job_ID = J.ID JOIN PROJECTS P ON P.ID = J.Project_ID ) , V2 AS ( SELECT V1.JOB_ID, MAX(V1.TIME_STAMP) AS CURR_ATTEMPT FROM V1 GROUP BY JOB_ID ) , V3 AS ( SELECT V1.PROJ_ID, V1.JOB_ID, V1.TIME_STAMP FROM V1 JOIN V2 ON V1.TIME_STAMP = V2.CURR_ATTEMPT ) SELECT * FROM V1 WHERE PROJ_ID = 72;
//echo "<br>";
//echo $sql;
//echo "<br>";
$result = $conn->query($sql);
$data = array();
if ($result->num_rows > 0) {
// output data of each row
    while($row = $result->fetch_assoc()) {
        $data[]=$row;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
} 
$conn->close();

echo json_encode($data);
return json_encode($data);
?>
