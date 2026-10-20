<?php
require_once "readerConnection.php";

$defaultFont = '"Geist", sans-serif';
$userFonts = array(
    
    "test" => 'Creepster'
);
$username = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : "";
$font = isset($userFonts[$username]) ? $userFonts[$username] : $defaultFont;
$sillyFonts = array(
    "Bangers",
    "Barriecito",
    "Barrio",
    "Chewy",
    "Creepster",
    "Freckle Face",
    "Henny Penny",
    "Luckiest Guy",
    "Mystery Quest",
    "Rubik Bubbles"
);
$webFont = null;
$fontFamilies = explode(",", $font);
$requestedFont = trim($fontFamilies[0], " \t\n\r\0\x0B\"'");
foreach ($sillyFonts as $candidate)
{
    if (strcasecmp($requestedFont, $candidate) === 0)
    {
        $webFont = $candidate;
        break;
    }
}
$today = new DateTime("now", new DateTimeZone("America/New_York"));
if ($today->format("m-d") === "04-01")
{
    $seed = hash("sha256", $today->format("Y-m-d") . "|" . $username);
    $index = hexdec(substr($seed, 0, 7)) % count($sillyFonts);
    $webFont = $sillyFonts[$index];
}
elseif ($today->format("m-d") === "10-31")
{
    $webFont = "Creepster";
}
if ($webFont !== null)
{
    $font = '"' . $webFont . '", ' . $defaultFont;
}
header('Cache-Control: private, no-store');

$fsql = "SELECT name from Users where Foreman=1;";
$fresult = $conn->query($fsql);

$foremen=[];

while ($frow = $fresult->fetch_assoc()) {
    #echo($frow["name"]);
    $foremen[]=$frow["name"];
}
#print_r($fresult->fetch);
$conn->close();
#var_dump($foremen);
$isForeman = in_array($username, $foremen, TRUE);
if (isset($_GET['profile']) && $_GET['profile'] === '1')
{
    header('Content-Type: application/json');
    echo json_encode(array("foreman" => $isForeman ? 1 : 0, "font" => $font, "webFont" => $webFont));
    return;
}
if ($isForeman)
{
    echo "1";
    return "1";
}
echo "0";
return "0";
?>
    

