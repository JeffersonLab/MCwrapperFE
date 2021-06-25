<?php
require_once "readerConnection.php";
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$mcoverlord_query = "SELECT * FROM MCOverlord ORDER BY ID DESC LIMIT 5;";

$result = $conn->query($mcoverlord_query);
$data = array();
$Overlord_data=array();
if ($result->num_rows > 0) {
// output data of each row
    while($row = $result->fetch_assoc()) {
        $Overlord_data[]=$row;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
} 

$data["MCOverlord"]=$Overlord_data;

$mcdrone_query = "SELECT * FROM MCDrone ORDER BY ID DESC LIMIT 5;";

$Dresult = $conn->query($mcdrone_query);
$Drone_data=array();
if ($Dresult->num_rows > 0) {
// output data of each row
    while($Drow = $Dresult->fetch_assoc()) {
        $Drone_data[]=$Drow;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
}
$data["MCDrone"]=$Drone_data;

$mcsubmit_query = "SELECT * FROM MCSubmitter ORDER BY ID DESC LIMIT 5;";

$Sresult = $conn->query($mcsubmit_query);
$Submit_data=array();
if ($Sresult->num_rows > 0) {
// output data of each row
    while($Srow = $Sresult->fetch_assoc()) {
        $Submit_data[]=$Srow;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
}
$data["MCSubmitter"]=$Submit_data;

$mcmover_query = "SELECT * FROM MCMover ORDER BY ID DESC LIMIT 5;";

$Mresult = $conn->query($mcmover_query);
$mover_data=array();
if ($Mresult->num_rows > 0) {
// output data of each row
    while($Mrow = $Mresult->fetch_assoc()) {
        $mover_data[]=$Mrow;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
}
$data["MCMover"]=$mover_data;

$lastSub_query = "select BatchSystem from Attempts Order by ID desc limit 1;";
$LSresult = $conn->query($lastSub_query);
$LS_data=array();
if ($LSresult->num_rows > 0) {
// output data of each row
    while($LSrow = $LSresult->fetch_assoc()) {
        $LS_data[]=$LSrow;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
}
$data["LastSub"]=$LS_data;

$conn->close();

echo json_encode($data);
return json_encode($data);
?>