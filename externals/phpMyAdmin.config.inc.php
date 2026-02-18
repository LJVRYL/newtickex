<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * phpMyAdmin sample configuration, you can use it as base for
 * manual configuration. For easier setup you can use setup/
 *
 * All directives are explained in documentation in the doc/ folder
 * or at <http://docs.phpmyadmin.net/>.
 *
 * @package PhpMyAdmin
 */

// No permitir ingreso a revendedores.
/*
if ($_SERVER["PHP_AUTH_USER"]!="dhmcontrol") {
        header("Location: http://".$_SERVER['SERVER_NAME'].":2083/fzerrors/001.php?id=3");
        die;
}
*/
// Fin No permitir ingreso a revendedores.

// Definicion de funciones
# server_hostname(): Idem dhm/includes.php
function server_hostname($mode="short"){
        if(isset($_SERVER["HOSTNAME"]) && !empty($_SERVER["HOSTNAME"])){
                $s_hostname = $_SERVER["HOSTNAME"];
        } elseif(isset($_ENV["HOSTNAME"]) && !empty($_ENV["HOSTNAME"])){
                $s_hostname = $_ENV["HOSTNAME"];
        } else {
                $s_hostname = trim(shell_exec("hostname -f"));
        }

        if($mode=="short"){
                $s_hostname = substr($s_hostname, 0, strpos($s_hostname,'.'));
        }

        return $s_hostname;
}

function isMysqlExternal(){
        $a_server_conf = parse_ini_file("/etc/ferozo/server.conf");
        if(is_array($a_server_conf) && isset($a_server_conf["FEROZO_DB_HOST"]) && $a_server_conf["FEROZO_DB_HOST"] == 'localhost'){
                return false;
        }else{
                return true;
        }
}

function getOnlyDBArray($cfg, $i){
        $a_knowsDB = array('mysql', 'information_schema', 'ferozo', 'ferozosite', 'horde', 'ferozomail');
        $a_onlyDB = $a_knowsDB;

        $link=mysql_connect($cfg['Servers'][$i]['host'], $cfg['Servers'][$i]['user'], $cfg['Servers'][$i]['password'], true);
        if(!$link){
                $a_onlyDB[]='*';
                return $a_onlyDB;
        }

        $b_seldb=mysql_select_db('ferozo', $link);
        if(!$b_seldb){
                $a_onlyDB[]='*';
                return $a_onlyDB;
        }

        $s_shorthostname = server_hostname();

        $s_sql="SELECT accounts.username
                        FROM
                                accounts
                                INNER JOIN parameters_per_account ON (
                                        accounts.username = parameters_per_account.username
                                        AND parameters_per_account.param_id = 25
                                        AND parameters_per_account.param_value = '$s_shorthostname'
                                )
                        WHERE accounts.username NOT IN ('".implode("', '", $a_onlyDB)."')
                        ORDER BY accounts.username ASC";
        $rs_result=mysql_query($s_sql, $link);
        if( !$rs_result || mysql_num_rows($rs_result)==0 ){
                $a_onlyDB[]='*';
                return $a_onlyDB;
        }

        while( $a_row=mysql_fetch_assoc($rs_result) ){
                $a_onlyDB[]=$a_row['username'];
        }

        // Agregar wildcards
        foreach($a_onlyDB as $i_key => $s_dbuser){
                if( !in_array($s_dbuser, $a_knowsDB) ){
                        $a_onlyDB[$i_key].='\_%';
                }
        }

        return $a_onlyDB;
}
// Fin definicion de funciones

/*
 * This is needed for cookie based authentication to encrypt password in
 * cookie
 */
$cfg['blowfish_secret'] = 'sadasdASdasdytgdfgfd&%%%#$@#%%%%%%Ĝgdfgdfgdgfdg'; /* YOU MUST FILL IN THIS FOR COOKIE AUTH! */

/*
 * Servers configuration
 */
$i = 0;

/*
 * First server
 */
$i++;
/* Authentication type */
$cfg['Servers'][$i]['auth_type'] = 'cookie';
/* Server parameters */
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['connect_type'] = 'socket'; // El VPS decia 'tcp'
$cfg['Servers'][$i]['compress'] = false;
/* Select mysql if your server does not have mysqli */
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['AllowNoPassword'] = false;

/*
 * phpMyAdmin configuration storage settings.
 */

/* User used to manipulate with storage */
// $cfg['Servers'][$i]['controlhost'] = '';
// $cfg['Servers'][$i]['controluser'] = 'pma';
// $cfg['Servers'][$i]['controlpass'] = 'pmapass';

/* Storage database and tables */
// $cfg['Servers'][$i]['pmadb'] = 'phpmyadmin';
// $cfg['Servers'][$i]['bookmarktable'] = 'pma__bookmark';
// $cfg['Servers'][$i]['relation'] = 'pma__relation';
// $cfg['Servers'][$i]['table_info'] = 'pma__table_info';
// $cfg['Servers'][$i]['table_coords'] = 'pma__table_coords';
// $cfg['Servers'][$i]['pdf_pages'] = 'pma__pdf_pages';
// $cfg['Servers'][$i]['column_info'] = 'pma__column_info';
// $cfg['Servers'][$i]['history'] = 'pma__history';
// $cfg['Servers'][$i]['table_uiprefs'] = 'pma__table_uiprefs';
// $cfg['Servers'][$i]['tracking'] = 'pma__tracking';
// $cfg['Servers'][$i]['designer_coords'] = 'pma__designer_coords';
// $cfg['Servers'][$i]['userconfig'] = 'pma__userconfig';
// $cfg['Servers'][$i]['recent'] = 'pma__recent';
/* Contrib / Swekey authentication */
// $cfg['Servers'][$i]['auth_swekey_config'] = '/etc/swekey-pma.conf';

/*
 * End of servers configuration
 */

/*
 * Directories for saving/loading files from server
 */
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';

//$cfg['Servers'][$i]['auth_type']     = 'config';                        // Authentication method (config, http or cookie based)?
//$cfg['Servers'][$i]['user']      = 'root';                                                      // MySQL user
//$cfg['Servers'][$i]['password']  = trim(file_get_contents("/opt/ferozo/etc/mysql_root_pass"));  // MySQL Pass
$cfg['DefaultLang']              = "es-iso-8859-1";                 // Default Language (for MySQL 4.0 compat.)
$cfg['ThemeDefault']             = 'pmahomme';                  // Tema por defecto
$cfg['ShowChgPassword']          = false;
$cfg['MaxDbList'] = 0;

// Likes for DBs (MySQL centralizado)
if (isMysqlExternal())$cfg['Servers'][$i]['only_db']   = getOnlyDBArray($cfg, $i);
//$cfg['Servers'][$i]['only_db']   = getOnlyDBArray($cfg, $i);
$cfg['Servers'][$i]['hide_db'] = 'ferozo3';

//var_dump($cfg['Servers'][$i]['only_db']);die();
//$cfg['Servers'][$i]['only_db']   = getOnlyDBArray($cfg, $i);

//Des-habilito alerta de nueva versión (Esta linea se paso del VPS)
#$cfg['VersionCheck'] = false;

/**
 * Defines whether a user should be displayed a "show all (records)"
 * button in browse mode or not.
 * default = false
 */
//$cfg['ShowAll'] = true;

/**
 * Number of rows displayed when browsing a result set. If the result
 * set contains more rows, "Previous" and "Next".
 * default = 30
 */
//$cfg['MaxRows'] = 50;

/**
 * disallow editing of binary fields
 * valid values are:
 *   false    allow editing
 *   'blob'   allow editing except for BLOB fields
 *   'noblob' disallow editing except for BLOB fields
 *   'all'    disallow editing
 * default = blob
 */
//$cfg['ProtectBinary'] = 'false';

/**
 * Default language to use, if not browser-defined or user-defined
 * (you find all languages in the locale folder)
 * uncomment the desired line:
 * default = 'en'
 */
//$cfg['DefaultLang'] = 'en';
//$cfg['DefaultLang'] = 'de';

/**
 * default display direction (horizontal|vertical|horizontalflipped)
 */
//$cfg['DefaultDisplay'] = 'vertical';


/**
 * How many columns should be used for table display of a database?
 * (a value larger than 1 results in some information being hidden)
 * default = 1
 */
//$cfg['PropertiesNumColumns'] = 2;

/**
 * Set to true if you want DB-based query history.If false, this utilizes
 * JS-routines to display query history (lost by window close)
 *
 * This requires configuration storage enabled, see above.
 * default = false
 */
//$cfg['QueryHistoryDB'] = true;

/**
 * When using DB-based query history, how many entries should be kept?
 *
 * default = 25
 */
//$cfg['QueryHistoryMax'] = 100;

/*
 * You can find more configuration options in the documentation
 * in the doc/ folder or at <http://docs.phpmyadmin.net/>.
 */
?>
