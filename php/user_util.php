
<?php
require_once "writerConnection.php";
function RecallProject($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    $usrcheck="SELECT UName FROM Project where ID=" . $_GET["projID"];
    $checkres=$conn->query($usrcheck);

    $urow = $checkres->fetch_assoc();
    if( $_SERVER['PHP_AUTH_USER'] == $urow["UName"])
    {
        //echo "<br> InTestReset()<br>";
        $sql = "UPDATE Project Set Tested=2 WHERE ID=" . $_GET["projID"];
        //echo $sql . "<br>";

        $result = $conn->query($sql);
        //echo "here";
        //echo $result;
        $conn->commit();

        $conn->close();
        return "FLAGGING PROJECT FOR RECALL IF ABLE";
    }
    $conn->close();
    return "Error.  You do not own this project";

}

function DeclareComplete($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    $usrcheck="SELECT UName FROM Project where ID=" . $_GET["projID"];
    $checkres=$conn->query($usrcheck);

    $urow = $checkres->fetch_assoc();
    if( $_SERVER['PHP_AUTH_USER'] == $urow["UName"])
    {
        //echo "<br> InTestReset()<br>";
        $sql = "UPDATE Project Set Tested=4 WHERE ID=" . $_GET["projID"];
        //echo $sql . "<br>";

        $result = $conn->query($sql);
        //echo "here";
        //echo $result;
        $conn->commit();
        $conn->close();
        return "Declaring project complete. Even with uncompleted jobs";
    }
    $conn->close();
    return "Error.  You do not own this project";

}
function CopyProject()
{
    return "https://halldweb.jlab.org/gluex_sim/SubmitSim.html?mod=2&prefill=" . $_GET["projID"] ;
   
}
function getMCconfig()
{
    $cmdstr='python /group/halld/www/halldweb/html/gluex_sim/MCDispatcher.py writeconfig -rlim ' . $_GET["projID"];
    $command = escapeshellcmd($cmdstr);
    #echo $command;
    #echo "<br>";
    $output = shell_exec($command);
    #echo "done with shell exec";
    #echo "<br>";
    #echo $output;
    return nl2br($output);
    
}
function CancelProject($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    $usrcheck="SELECT UName FROM Project where ID=" . $_GET["projID"];
    $checkres=$conn->query($usrcheck);

    $urow = $checkres->fetch_assoc();
    if( $_SERVER['PHP_AUTH_USER'] == $urow["UName"])
    {


        $compSQL="SELECT COUNT(ID) from Attempts where Status='4' && ExitCode=0 && Job_ID in (SELECT ID FROM Jobs where Project_ID=". $_GET["projID"] .");";

        $result = $conn->query($compSQL);
        $row = $result->fetch_assoc();
  
        if($row["COUNT(ID)"] != 0)
        {
            $conn->close();
            return "Cannot cancel as the project has some completed jobs.  Either 'recall' the project to effectively pause the project.  Or declare it completed";
        }

        //echo "<br> InTestReset()<br>";
        $sql = "UPDATE Project Set Tested=3 WHERE ID=" . $_GET["projID"];
        //echo $sql . "<br>";

        $result = $conn->query($sql);
        //echo "here";
        //echo $result;
        $conn->commit();
        $conn->close();
        return "Declaring project complete. Even with uncompleted jobs";
    }
    $conn->close();
    return "Error.  You do not own this project";

}

   $out="";

    if($_GET["Mode"]=="Recall")
    {
        $out=RecallProject($conn);
    }
    else if($_GET["Mode"]=="DeclareComplete")
    {
        $out=DeclareComplete($conn);
    }
    else if($_GET["Mode"]=="Cancel")
    {
        $out=CancelProject($conn);
    }
    else if($_GET["Mode"]=="MC.config")
    {
        $out=getMCconfig();
    }
    else if($_GET["Mode"]=="CopyProject")
    {
        $out=CopyProject();
    }


echo $out;
return $out;
?>
