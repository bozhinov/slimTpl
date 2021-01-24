<?php

require_once("tpl.php");

$tpl = new SlimTpl();
$tpl->configure([]);
$tpl->assign("cat", $cat);
$tpl->assign("cat_list", $video_cat_list);
$tpl->draw("view_all");

?>