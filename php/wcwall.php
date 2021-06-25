<?php

require_once "readerConnection.php";
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$sql = "SELECT wc FROM Users where name=\"" . $_SERVER['HTTP_HOST'] . "\"";
//echo $sql;
//echo "<br>";

$result = $conn->query($sql);

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

if(sizeof($data) == 0)
{
    $data[]=0;
}

echo json_encode($data);
//echo json_encode($containing);
//return json_encode($data);
?>

