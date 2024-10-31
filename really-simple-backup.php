<?php
/*
Plugin Name: Really Simple Backup
Description: A simple backup of your Theme, Uploads, Plugins and Database - proceed at your own risk...
Version: 1.3.5
Author: Hotscot

Copyright 2011 Hotscot  (email : support@hotscot.net)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/
if ( ! defined( 'ABSPATH' ) ) exit('No access'); // disable direct access

add_action('admin_menu', 'rsb_addBackupLink');
add_action( 'admin_post_rsb_backup_do', 'rsb_backup_response');

function rsb_backup_response(){
    if(current_user_can('manage_options')){
        if( isset($_POST['rsb_timestamp']) && is_numeric($_POST['rsb_timestamp']) && isset( $_POST['rsb_backup_nonce_field'] ) && wp_verify_nonce( $_POST['rsb_backup_nonce_field'], 'rsb_backup_nonce_' . $_POST['rsb_timestamp']) ) {
            if(strtotime("Now") - $_POST['rsb_timestamp'] < 300){
                // 5 minutes
                ini_set('max_execution_time', 3000);
                rsb_doBackup();
                exit();
            }else{
                exit('No access');    
            }
        }else{
            exit('No access');
        }
    }else{
        exit('No access');
    }
}

function rsb_addBackupLink(){
    add_submenu_page("index.php",
                     "Really Simple Backup",
                     "Backup",
                     "manage_options",
                     "really-simple-backup",
                     "rsb_displayBackupPage");
}

function rsb_displayBackupPage(){
    if(current_user_can('manage_options')){
        rsb_cleanup_closed();
        ?>
        <div class="wrap">
            <h2>Really Simple Backup</h2>
            <p>
                Choose from the tick boxes below what you want to backup and then click on the Backup button.<br />
                Recommended backups are already ticekd, backing up your Theme and Plugins can lead to a larger download.<br /><br />
                <b>Please Note:<br />when you click the "Backup" a new window will popup and will stay blank for a few minutes,<br />please do not close this window and wait until it prompts for your backup to be downloaded</b>.
            </p>
            <form target="_blank" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php 
                    $timettamp = strtotime("Now");
                ?>
                <input type="hidden" name="action" value="rsb_backup_do">
                <input type="hidden" name="rsb_timestamp" value="<?php echo($timettamp); ?>">
                <input type="hidden" name="rsb_backup_nonce_field" value="<?php echo wp_create_nonce( 'rsb_backup_nonce_' . $timettamp ); ?>" />

                <p><input type="checkbox" id="database" name="database" checked="checked" /> Database</p>
                <h2>Uploaded Media</h2>
                <p><input type="checkbox" id="uploadsall" name="uploadsall" /> Download all Uploaded media</p>
                <p>OR Tick individual boxes for the folders below you want to backup from the uploaded media</p>
                <ul>
                <?php
                    $iterator = new DirectoryIterator(WP_CONTENT_DIR . '/uploads/');
                    foreach($iterator as $k){
                        if(!preg_match('/\.\.?$/',$k) && !preg_match('/\.svn/',$k) && $k->isDir()){
                            echo '<li><input type="checkbox" id="uploads_'.md5($k).'" name="uploads[]" value="'. md5($k) .'" /> <label for="uploads_'.md5($k).'">'. $k .'</label></li>';
                        }
                    }
                ?>
                </ul>
                <input type="submit" class="button-primary" value="<?php _e('Backup') ?>" />
            </form>
        </div>
        <?php
    }else{
        exit('No access');
    }
}

function rsb_doBackup(){
    register_shutdown_function('rsb_cleanup_closed');

    $isError = false;
    $hash_file = md5($_POST['rsb_backup_nonce_field'] . date('Ymdhis'));
    $backupDir = WP_PLUGIN_DIR . '/really-simple-backup/backup/' . $hash_file .'/';
    $filename = '';

    $backupName = "Backup.zip";

    //Supress error if dir already exists
    @mkdir($backupDir, 0777);

    //Creating mysqldump if requested
    $dumpOutput = 0;
    if(isset($_POST['database']) && $_POST['database']=='on'){
        system('mysqldump -u' . DB_USER . ' -h'. DB_HOST .' -p\'' . DB_PASSWORD . '\' ' . DB_NAME . ' > ' . $backupDir . '/dump_'. $hash_file .'.sql',$dumpOutput);
    }
    
    if($dumpOutput != 0){
        $isError = true;
        echo 'Error code 1<br />';
        if(file_exists($backupDir . '/dump_' . $hash_file . '.sql')) unlink($backupDir . '/dump_'. $hash_file .'.sql');
    }else{
        if(rsb_zipFolder(WP_CONTENT_DIR, $backupDir . "content_" . $hash_file . ".zip")){
            $filename = WP_PLUGIN_DIR . '/really-simple-backup/backup/backup_' . $hash_file . '.zip';
            if(rsb_zipFolder_final($backupDir, $filename)){
                if(file_exists($backupDir . '/dump_' . $hash_file . '.sql')) unlink($backupDir . '/dump_'. $hash_file .'.sql');
                if(file_exists($backupDir . '/content_' . $hash_file . '.zip')) unlink($backupDir . '/content_' . $hash_file . '.zip');
                rmdir($backupDir);
            }else{
                $isError = true;
                echo 'Error code 2<br />';
                if(file_exists($backupDir . '/dump_' . $hash_file . '.sql')) unlink($backupDir . '/dump_'. $hash_file .'.sql');
                if(file_exists($backupDir . '/content_' . $hash_file . '.zip')) unlink($backupDir . '/content_' . $hash_file . '.zip');
                rmdir($backupDir);
            }
        }else{
            $isError = true;
            echo 'Error code 3<br />';
            if(file_exists($backupDir . '/dump_' . $hash_file . '.sql')) unlink($backupDir . '/dump_'. $hash_file .'.sql');
            rmdir($backupDir);
        }
    }

    if(!$isError){
        if (!is_file($filename) or connection_status()!=0){
            echo 'Error code 4<br />';
            exit();
        }else{
            $fsize = (string)(filesize($filename));
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=Backup.zip");
            header('Content-Transfer-Encoding: binary');
            header("Content-Length: ".$fsize);
            header("Expires: 0");
            header("Pragma: public");

            if ($file = fopen($filename, 'rb')) {
                while(!feof($file) and (connection_status()==0)) {
                    print(fread($file, 1024*8));
                    flush();
                }
                fclose($file);
            }
            exit();
        }
    }else{
        exit();
    }
}

function rsb_cleanup_closed(){
    //clean backup folder
    $iterator3 = new DirectoryIterator(WP_PLUGIN_DIR . '/really-simple-backup/backup/');
    foreach($iterator3 as $k3){
        if(!preg_match('/\.\.?$/',$k3) && !preg_match('/\.svn/',$k3)){
            if($k3->isDir()){
                if(file_exists(WP_PLUGIN_DIR . '/really-simple-backup/backup/'. $k3 .'/dump_' . $k3 . '.sql')) unlink(WP_PLUGIN_DIR . '/really-simple-backup/backup/'. $k3 .'/dump_' . $k3 . '.sql');
                if(file_exists(WP_PLUGIN_DIR . '/really-simple-backup/backup/'. $k3 .'/content_' . $k3 . '.zip')) unlink(WP_PLUGIN_DIR . '/really-simple-backup/backup/'. $k3 .'/content_' . $k3 . '.zip');
                rmdir(WP_PLUGIN_DIR . '/really-simple-backup/backup/' . $k3);
            }else{
                if(file_exists(WP_PLUGIN_DIR . '/really-simple-backup/backup/' . $k3)) unlink(WP_PLUGIN_DIR . '/really-simple-backup/backup/' . $k3);
            }
        }
    }
}

function rsb_zipFolder($srcDir, $zipFileName){
    $tmpZip = new ZipArchive();
    $chkState = false;

    $zipOutput = $tmpZip->open($zipFileName, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE);

    if($zipOutput){
        //uploads directory (ALL)
        if(isset($_POST['uploadsall']) && $_POST['uploadsall']=='on'){
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir . '/uploads/'));
            foreach($iterator as $k=>$v){
                if(!preg_match('/\.\.?$/',$k) && !preg_match('/\.svn/',$k)){
                    $tmpZip->addFile(realpath($k), str_ireplace($srcDir . '/', '', $k));
                }
            }
        }else{
            //uploads directory specific folders
            if(isset($_POST['uploads']) && is_array($_POST['uploads'])){

                $uploadsArr = array();
                
                // loop through available folders to see which one needs to be added to backup
                $iterator = new DirectoryIterator(WP_CONTENT_DIR . '/uploads/');
                foreach($iterator as $k2){
                    if(!preg_match('/\.\.?$/',$k2) && !preg_match('/\.svn/',$k2) && $k2->isDir()){
                        foreach ($_POST['uploads'] as $postedUpload) {
                            if(md5($k2) == $postedUpload){
                                $uploadsArr[] = $k2->getFilename();
                            }
                        }
                    }
                }

                if(count($uploadsArr) > 0){
                    foreach ($uploadsArr as $postedUpload) {
                        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir . '/uploads/' . $postedUpload . '/'));
                        foreach($iterator as $k=>$v){
                            if(!preg_match('/\.\.?$/',$k) && !preg_match('/\.svn/',$k)){
                                $tmpZip->addFile(realpath($k), str_ireplace($srcDir . '/', '', $k));
                            }
                        }
                    }
                }
            }
        }

        $tmpZip->close();
        $chkState = true;
    }else{
        echo 'Error code 5<br />';
        $chkState = false;
    }

    return $chkState;
}

function rsb_zipFolder_final($srcDir, $zipFileName){
    $tmpZip = new ZipArchive();
    $chkState = false;

    $zipOutput = $tmpZip->open($zipFileName, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE);

    if($zipOutput){
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
        foreach($iterator as $k=>$v){
            if(!preg_match('/\.\.?$/',$k) && !preg_match('/\.svn/',$k)){
                $tmpZip->addFile(realpath($k), str_ireplace($srcDir, '', $k));
            }
        }
        $tmpZip->close();
        $chkState = true;
    }else{
        echo 'Error code 6<br />';
        $chkState = false;
    }

    return $chkState;
}
?>