<?php
/**
 *    Copyright (C) 2018 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace OPNsense\Backup;
use OPNsense\Core\Config;


/**
 * Class google drive backup
 * @package OPNsense\Backup
 */
class Gdrive extends Base implements IBackupProvider
{

    /**
     * get required (user interface) fields for backup connector
     * @return array configuration fields, types and description
     */
    public function getConfigurationFields()
    {
        $fields = array();

        $fields[] = array(
            "name" => "GDriveEnabled",
            "type" => "checkbox",
            "label" => gettext("Enable")
        );
        $fields[] = array(
            "name" => "GDriveEmail",
            "type" => "text",
            "label" => gettext("Email Address")
        );
        $fields[] = array(
            "name" => "GDriveP12file",
            "type" => "file",
            "label" => gettext("P12 key (not loaded)")
        );
        $fields[] = array(
            "name" => "GDriveFolderID",
            "type" => "text",
            "label" => gettext("Folder ID")
        );
        $fields[] = array(
            "name" => "GDrivePrefixHostname",
            "type" => "text",
            "label" => gettext("Prefix hostname to backupfile")
        );
        $fields[] = array(
            "name" => "GDriveBackupCount",
            "type" => "text",
            "label" => gettext("Backup Count")
        );
        $fields[] = array(
            "name" => "GDrivePassword",
            "type" => "password",
            "label" => gettext("Password")
        );
        $fields[] = array(
            "name" => "GDrivePasswordConfirm",
            "type" => "password",
            "label" => gettext("Confirm")
        );

        return $fields;
    }

    /**
     * backup provider name
     * @return string user friendly name
     */
    public function getName()
    {
        return gettext("Google Drive");
    }

    /**
     * validate and set configuration
     * @param array $conf configuration array
     * @return array of validation errors
     */
    public function setConfiguration($conf)
    {
        // TODO: Implement setConfiguration() method.
    }

    /**
     * @return array filelist
     */
    public function backup()
    {
        $cnf = Config::getInstance();
        if ($cnf->isValid()) {
            $config = $cnf->object();
            if (isset($config->system->remotebackup) && isset($config->system->remotebackup->GDriveEnabled)
                    && !empty($config->system->remotebackup->GDriveEnabled)) {
                if (!empty($config->system->remotebackup->GDrivePrefixHostname)) {
                    $fileprefix = (string)$config->system->hostname . "." . (string)$config->system->domain . "-";
                } else {
                    $fileprefix = "config-";
                }
                try {
                    $client = new Google\API\Drive();
                    $client->login($config->system->remotebackup->GDriveEmail->__toString(),
                        $config->system->remotebackup->GDriveP12key->__toString());
                } catch (Exception $e) {
                    log_error("error connecting to Google Drive");
                    return array();
                }

                // backup source data to local strings (plain/encrypted)
                $confdata = file_get_contents('/conf/config.xml');
                $confdata_enc = chunk_split(
                    $this->encrypt($confdata, $config->system->remotebackup->GDrivePassword->__toString())
                );

                // read filelist ({prefix}*.xml)
                try {
                    $files = $client->listFiles($config->system->remotebackup->GDriveFolderID->__toString());
                } catch (Exception $e) {
                    log_error("error while fetching filelist from Google Drive");
                    return array();
                }

                $configfiles = array();
                foreach ($files as $file) {
                    if (fnmatch("{$fileprefix}*.xml", $file['title'])) {
                        $configfiles[$file['title']] = $file;
                    }
                }
                krsort($configfiles);


                // backup new file if changed (or if first in backup)
                $target_filename = $fileprefix . time() . ".xml";
                if (count($configfiles) > 1) {
                    // compare last backup with current, only save new
                    try {
                        $bck_data_enc = $client->download($configfiles[array_keys($configfiles)[0]]);
                        $bck_data = $this->decrypt($bck_data_enc,
                            $config->system->remotebackup->GDrivePassword->__toString());
                        if ($bck_data == $confdata) {
                            $target_filename = null;
                        }
                    } catch (Exception $e) {
                        log_error("unable to download " . $configfiles[array_keys($configfiles)[0]]->description . " from Google Drive (" . $e . ")");
                    }
                }
                if (!is_null($target_filename)) {
                    log_error("backup configuration as " . $target_filename);
                    try {
                        $configfiles[$target_filename] = $client->upload($config->system->remotebackup->GDriveFolderID->__toString(), $target_filename, $confdata_enc);
                    } catch (Exception $e) {
                        log_error("unable to upload " . $target_filename . " to Google Drive (" . $e . ")");
                        return array();
                    }

                    krsort($configfiles);
                }

                // cleanup old files
                if (isset($config->system->remotebackup->GDriveBackupCount) && is_numeric($config->system->remotebackup->GDriveBackupCount->__toString())) {
                    $fcount = 0;
                    foreach ($configfiles as $filename => $file) {
                        if ($fcount >= $config->system->remotebackup->GDriveBackupCount->__toString()) {
                            log_error("remove " . $filename . " from Google Drive");
                            try {
                                $client->delete($file);
                            } catch (Google_Service_Exception $e) {
                                log_error("unable to remove " . $filename . " from Google Drive");
                            }
                        }
                        $fcount++;
                    }
                }

                // return filelist
                return $configfiles;
            }
        }

        // not configured / issue, return empty list
        return array();
    }

    /**
     * Is this provider enabled
     * @return boolean enabled status
     */
    public function isEnabled()
    {
        $cnf = Config::getInstance();
        if ($cnf->isValid()) {
            $config =$cnf->object();
            return isset($config->system->remotebackup) && isset($config->system->remotebackup->GDriveEnabled)
                && !empty($config->system->remotebackup->GDriveEnabled);
        }
        return false;
    }
}