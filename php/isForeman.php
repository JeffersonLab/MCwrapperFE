<?php
require_once "readerConnection.php";
$fsql = "SELECT name from Users where Foreman=1;";
$fresult = $rconn->query($fsql);

$foremen=[];

while ($frow = $fresult->fetch_assoc()) {
    #echo($frow["name"]);
    $foremen[]=$frow["name"];
}
#print_r($fresult->fetch);
$rconn->close();

if (in_array($_SERVER['PHP_AUTH_USER'],$foremen,TRUE))
{
    echo "1";
    return "1";
}
echo "0";
return "0";
?>
    

