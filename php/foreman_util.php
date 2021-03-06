
<?php
require_once "writerConnection.php";
function NotifyComplete($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    //echo "<br> In NotifyComplete()";
    $sql = " SELECT ID, Submitter, Email, OutputLocation, FinalDestination FROM Project WHERE ID=?";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->bind_param("i", $_GET['projID'] );
    
    $msg="";
    if ($stmt->execute() == TRUE) {
        $stmt->store_result();
        $id="0";
        $submitter="na";
        $email="blah";
        $outputloc="./";
        $finaldest="";
        $stmt->bind_result($id,$submitter, $email, $outputloc, $finaldest);
        $stmt->fetch();
            // output data of each row
    
    
     $msg="Dear ";
     $msg= $msg . $submitter . ",\n";
     $msg = $msg . "\n\n";
     $msg = $msg . "Your MC sample (#" . $id . ") has been completed and may be found: \n";
     if (is_null($finaldest))
     {
        $msg = $msg . $outputloc;
     }
     else
     {
        $msg = $msg . $finaldest;
     }
     echo $msg;
     if($id != 0 && $submitter != "blah")
     {
     mail($email,"GlueX MC Request #" . $id . " Completed" ,$msg);
     }


    $sql2 = "UPDATE Project Set Notified=1 WHERE ID=?";
    $stmt2 = $conn->prepare($sql2);
    
    $stmt2->bind_param("i", $_GET['projID'] );
    $stmt2->execute();

    }
    else
    {
        echo "<br>Query Failed";
    }
     $stmt->free_result();
    
     /* close statement */
     $stmt->close();
     $conn->close();
     return "Notified of Completion";
}

function FullProjectReset($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    //echo "<br> In FullReset()<br>";
    $sql = "DELETE FROM Attempts where Job_ID in ( SELECT ID FROM Jobs WHERE Project_ID=" . $_GET["projID"]." );";
    //echo $sql;
    $sql2 = "DELETE FROM Jobs WHERE Project_ID=" . $_GET["projID"].";";
    $sql3 = "UPDATE Project SET Is_Dispatched='0',Completed_Time=NULL,Dispatched_Time=NULL WHERE ID=" . $_GET["projID"].";";

    $result = $conn->query($sql);

$result2 = $conn->query($sql2);

$result3 = $conn->query($sql3);


     $conn->close();
     return "RESET COMPLETE";
}
function RetestProject($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    //echo "<br> InTestReset()<br>";
    $sql = "UPDATE Project Set Tested=0 WHERE ID=" . $_GET["projID"];
    //echo $sql . "<br>";

    $result = $conn->query($sql);
    //echo "here";
    //echo $result;
    $conn->commit();

     $conn->close();
     return "READY TO RESTEST PROJECT";
}

function RecallProject($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    //echo "<br> InTestReset()<br>";
    $sql = "UPDATE Project Set Tested=2 WHERE ID=" . $_GET["projID"];
    //echo $sql . "<br>";

    $result = $conn->query($sql);
    //echo "here";
    //echo $result;
    $conn->commit();

     $conn->close();
     return "FLAGGING PROJECT FOR RECALL";
}
function CopyProject()
{
    return "https://halldweb.jlab.org/gluex_sim/SubmitSim.html?mod=2&prefill=" . $_GET["projID"] ;
   
}
function DeclareComplete($conn)
{
    
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

    //echo "<br> InTestReset()<br>";
    $sql = "UPDATE Project Set Tested=4 WHERE ID=" . $_GET["projID"];
    //echo $sql . "<br>";

    $result = $conn->query($sql);
    //echo "here";
    //echo $result;
    $conn->commit();

    $conn->close();
    return "Declaring Project complete even with uncompleted jobs";
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
    return $output;
    
}
function CancelProject($conn)
{
   
// Check connection
    if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    }

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
    return "Recalling jobs and deleting project";
}

//$servername = "hallddb-ext.jlab.org";
//$username = "mcuser";
//$password = "";
//$dbname = "gluex_mc_test";
//$rconn = mysqli_connect($servername, $username, $password, $dbname);
$fsql = "SELECT name from Users where Foreman=1;";
$fresult = $conn->query($fsql);

$foremen=[];

while ($frow = $fresult->fetch_assoc()) {
    #echo($frow["name"]);
    $foremen[]=$frow["name"];
}
#print_r($fresult->fetch);
//$rconn->close();

if (in_array($_SERVER['PHP_AUTH_USER'],$foremen,TRUE))
{   $out="";
    if($_GET["Mode"] == 'NotifyComplete')
    {
       $out=NotifyComplete($conn);
    }
    else if($_GET["Mode"]=="FullReset")
    {
        $out=FullProjectReset($conn);
    }
    else if($_GET["Mode"]=="ReTest")
    {
        $out=RetestProject($conn);
    }
    else if($_GET["Mode"]=="Recall")
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

}

echo $out;
return $out;
?>
