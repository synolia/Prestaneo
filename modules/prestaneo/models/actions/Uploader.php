<?php

class SyncUploader extends Uploader
{
    public function upload($file, $dest = null, $checkServerFileSize=true)
    {
        if ($this->validate($file))
        {
            if (isset($dest) && is_dir($dest))
            {
                $file_path = $dest.'/'.$file['name'];
            }
            elseif(isset($dest))
            {
                $file_path = $dest;
            }
            else
            {
                $file_path = $this->getFilePath(isset($dest) ? $dest : $file['name']);
            }

            if ($file['tmp_name'] && is_uploaded_file($file['tmp_name'] ))
            {
                move_uploaded_file($file['tmp_name'] , $file_path);
            }
            else
            {
                // Non-multipart uploads (PUT method support)
                file_put_contents($file_path, fopen('php://input', 'r'));
            }

            $file_size = $this->_getFileSize($file_path, true);

            if ($file_size === $file['size'] || !$checkServerFileSize)
            {
                $file['save_path'] = $file_path;
            }
            else
            {
                $file['size'] = $file_size;
                unlink($file_path);
                $file['error'] = Tools::displayError('Server file size is different from local file size');
            }
        }

        return $file;
    }
}