<?php

class UtilsZip
{

    /**
     * Extracts an archive to the given directory
     *
     * @param string $zipPath
     * @param string $destinationDir
     * @param int    $umask
     *
     * @return bool
     */
    public function extractFile($zipPath, $destinationDir, $umask = 0774)
    {
        if (!file_exists($zipPath)) {
            return false;
        }

        $zipSize = filesize($zipPath);
        if ($zipSize > Tools::getMemoryLimit()) {
            Utils::exec('System')
                ->setMemoryLimit($zipSize)
                ->apply()
            ;
        }

        $zip = zip_open($zipPath);

        if (is_resource($zip)) {
            while ($file = zip_read($zip)) {
                $fileName = zip_entry_name($file);
                $fullPath = $destinationDir . $fileName;

                if (strpos($fileName, '.') !== false) {
                    file_put_contents($fullPath, zip_entry_read($file, zip_entry_filesize($file)));
                    chmod($fullPath, $umask);
                } else {
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, $umask, true);
                    }
                }
            }
            zip_close($zip);
        } else {
            return false;
        }
        return true;
    }

    /**
     * Extracts all archives to the given directory
     *
     * @param string $files
     * @param string $destinationDir
     * @param int    $umask
     *
     * @return bool
     */
    public function extractAll($files, $destinationDir, $umask = 0774)
    {
        $return = true;
        foreach ($files as $file) {
            if (!$this->extractFile($file, $destinationDir, $umask)) {
                $return = false;
            }
        }
        return $return;
    }
}