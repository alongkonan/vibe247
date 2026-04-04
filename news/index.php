<?php
date_default_timezone_set('Asia/Bangkok');
$currentHour = date('H');
$remoteUrl = "http://news.pakeefm.org/news" . $currentHour . ".mp3";

// ส่งคนไปที่ลิงก์ต้นทางตรงๆ เลย
header("Location: " . $remoteUrl);
exit;
?>
