<?php
#return;
    $news_loc="../news/";
    $files=scandir($news_loc);

    $newsdata = array();
    foreach ($files as $file)
    {
        $item = array();
        if($file=="." || $file=="..")
        {
            continue;
        }
        $readfile=fopen($news_loc . $file,"r");

        if(!$readfile)
        {
            continue;
        }
        $linen=0;
        $newstext="";
        while(! feof($readfile))
        {
            $line=fgets($readfile);
            if($linen == 0)
            {
                $item["type"] = str_replace("\n","",$line);
            }
            else if($linen==1)
            {
                $item["header"] = str_replace("\n","",$line);
            }
            else
            {
                $newstext=$newstext . $line . "\n";
            }

            $linen++;
        
        }
        $item["text"] = $newstext;
        $newsdata[]=$item;
        fclose($readfile);
    }
    echo json_encode($newsdata);
    return json_encode($newsdata);
?>

