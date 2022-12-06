<?php
require_once "vsConnection.php";
// Check connection
if (!$conn_vs) {
    die("Connection failed: " . mysqli_connect_error());
}

if( $_GET["recon_ver"] == "unknown")
{
    $sql="SELECT DISTINCT version FROM version WHERE packageID in (SELECT id from package where name=\"halld_recon\") && versionSetId in (SELECT DISTINCT versionSetId from version where packageID in (SELECT id from package where name=\"halld_sim\") ) && versionSetID in (SELECT id from versionSet where onOasis=1 && filename NOT LIKE \"analysis-%\" ) ORDER BY version desc;";
    #echo $sql;
}
else if( $_GET["sim_ver"] == "unknown")
{
    $sql="SELECT DISTINCT version from version where packageID in (SELECT id from package where name=\"halld_sim\") && versionSetID IN (SELECT DISTINCT versionSetId from version where packageId in (SELECT id from package where name=\"halld_recon\") && version=\"" . $_GET["recon_ver"] . "\") && versionSetID in (SELECT id from versionSet where onOasis=1 && filename NOT LIKE \"analysis-%\" ) ORDER BY version desc;";
}
else if( !isset($_GET["verSet"]) )
{

    if( $_GET["sim_ver"] == "master" && $_GET["recon_ver"] == "master")
    {
        $sql="SELECT filename as version from versionSet where id in (SELECT DISTINCT versionSetId from version where version is NULL) && onOasis=1";
    }
    else
    {
    
    $sqlsub="SELECT DISTINCT versionSetID from version where versionSetID IN (SELECT DISTINCT versionSetId from version where packageId in (SELECT id from package where name=\"halld_recon\") && version=\"" . $_GET["recon_ver"] . "\")" . " && versionSetID IN (SELECT DISTINCT versionSetId from version where packageId in (SELECT id from package where name=\"halld_sim\") && versionSetID in (SELECT id from versionSet where onOasis=1 && filename NOT LIKE \"analysis-%\" ) && version=\"" . $_GET["sim_ver"] . "\") ORDER BY version desc";
    $sql="SELECT filename as version,id as SetNum from versionSet where id in (" . $sqlsub . ") ORDER BY filename desc;";
    //$sql="SELECT DISTINCT versionSetID from version where versionSetID IN (SELECT DISTINCT versionSetId from version where packageId=2 && version=\"" . $_GET["recon_ver"] . "\")" . " && versionSetID IN (SELECT DISTINCT versionSetId from version where packageId=3 && version=\"" . $_GET["sim_ver"] . "\");";
    //echo $sql;
    }

}
else
{
    $sql="SELECT DISTINCT filename as version from versionSet where id in (SELECT DISTINCT analysisSetId from version_set_correlations where reconSetId in (SELECT id from versionSet where filename=\"" . $_GET["verSet"] . "\" )) ORDER BY filename;";
    #echo $sql;
}

//echo $sql;
$result = $conn_vs->query($sql);

$data = array();

if( isset($_GET["verSet"]) )
{
    $none["version"]="None";
    $data[]=$none;
}

if ($result->num_rows > 0) {
// output data of each row
    while($row = $result->fetch_assoc()) {
        $data[]=$row;
     //echo "id: " . $row["id"]. " - Run: " . $row["run"]. "<br>";
    }
}
else if( $_GET["recon_ver"] == "master")
{
    $master["version"]="master";
    $data[]=$master;
}


$conn_vs->close();

echo json_encode($data);
return json_encode($data);
?>
