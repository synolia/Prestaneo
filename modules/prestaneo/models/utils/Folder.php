<?php

class UtilsFolder extends Utils
{
    public function recurse_copy($source, $distant, $replace=true)
    {
        $directory = opendir($source);
        if($replace && file_exists($distant))
            $this->delTree($distant);
        @mkdir($distant);
        while(($file = readdir($directory)) !== false)
        {
            if (($file != '.') && ($file != '..'))
            {
                if (is_dir($source . '/' . $file))
                    $this->recurse_copy($source.'/'.$file, $distant.'/'.$file);
                else
                    copy($source.'/'.$file, $distant.'/'.$file);
            }
        }
        closedir($directory);
    }

    public function delTree($dir, $removeRoot = true)
    {
        $files = array_diff(scandir($dir), array('.','..'));
        $result = true;

        foreach ($files as $file) {
            if (is_dir($dir . '/' . $file)) {
                $result &= $this->delTree($dir . '/' . $file, true);
            } else {
                $result &= unlink($dir . '/' . $file);
            }
        }
        if ($removeRoot) {
            $result &= rmdir($dir);
        }

        return $result;
    }

    /**
     * @param string $rootFolder
     * @param string $pattern
     *
     * @return array
     */
    public function getAllFilesInFolder($rootFolder, $pattern='*')
    {
        $iterator = new RecursiveIteratorIterator(
            new UtilsFolderRecursiveFilterIterator(
                new RecursiveDirectoryIterator(
                    $rootFolder,
                    FilesystemIterator::FOLLOW_SYMLINKS
                ), $pattern
            )
        );
        $files = array();
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[] = $file->getRealPath();
        }
        return $files;
    }

    /**
     * @param $folder
     *
     * @return bool
     */
    public function isFolderEmpty($folder)
    {
        $content = scandir($folder);

        return !$content || (count($content) == 2);
    }
}

class UtilsFolderRecursiveFilterIterator extends RecursiveFilterIterator {

    protected $_pattern;
    protected $_extension=null;

    public function __construct($iterator, $pattern='*')
    {
        /** @var RecursiveIteratorIterator $iterator */
        if(preg_match('#^\*\.[A-Za-z0-9]+$#', $pattern))
        {
            $this->_extension = substr($pattern, 2);
            $this->_pattern   = '*';
        }
        else
            $this->_pattern = $pattern;
        parent::__construct($iterator);
    }

    public function accept() {
        /** @var SplFileInfo $file */
        $file   = $this->current();
        $return = true;

        if($this->_extension && pathinfo($file->getFilename(), PATHINFO_EXTENSION) != $this->_extension)
            $return = false;
        if($return && $this->_pattern != '*' && !fnmatch($this->_pattern, $file->getRealPath()))
            $return = false;
        return $return;
    }
}