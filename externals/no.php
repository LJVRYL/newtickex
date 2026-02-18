<?php
file_put_contents("/opt/ferozo3/app/logs/.login-panelac-forbidden.log","\n".date("Y-m-d H:i:s")." ".$_SERVER['REMOTE_ADDR'],FILE_APPEND);
echo "forbidden";
die();