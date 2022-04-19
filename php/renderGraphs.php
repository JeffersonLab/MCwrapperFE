<?php
require_once "./graph_token.php";


$lookback=4;#hours
$now = round(microtime(true) * 1000);
$then =$now-$lookback*60*60*1000;

$url="https://scigraf.jlab.org/render/d-solo/_XK5tYPWk/condor-general-view?orgId=1&from=".$then."&to=".$now."&theme=light&panelId=9&width=1000&height=500&tz=America%2FNew_York";
#echo $url;
#echo "<br><br>";

#curl image from url with token in header
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $token,
    'Content-Type: image/png'
));
$running = curl_exec($ch);
curl_close($ch);



$url="https://scigraf.jlab.org/render/d-solo/_XK5tYPWk/condor-general-view?orgId=1&from=".$then."&to=".$now."&theme=light&panelId=10&width=1000&height=500&tz=America%2FNew_York";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $token,
    'Content-Type: image/png'
));
$idle = curl_exec($ch);
curl_close($ch);

$rungraph=base64_encode($running);
$idlegraph=base64_encode($idle);

$data = array();

$data[]="data:image/jpeg;base64,".$rungraph;
$data[]="data:image/jpeg;base64," . $idlegraph;


echo json_encode($data);
return json_encode($data);

?>