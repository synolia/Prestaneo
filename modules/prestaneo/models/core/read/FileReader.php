<?php

/**
 * Class FileReader
 *
 * Design pattern Template Method
 */
abstract class FileReader extends Prestaneo implements IReader
{
    protected $_currentFileName;
    protected $_allFileNames;
    protected $_offsetsToFilename = array();
    protected $_path;
    protected $_manager;
    protected $_logger;
    protected $_mover;
    protected $_extension=false;
    protected $_pattern=false;

    public function __construct($manager)
    {
        $this->_path      = $manager->getPath();
        $this->_manager   = $manager;
        $this->_logger    = new Logger($this->_manager);
        $this->_mover     = new Mover($this->_manager);
    }

    public function setExtension($extension)
    {
        $this->_extension = $extension;
        return $this;
    }

    public function setPattern($pattern)
    {
        $this->_pattern = $pattern;
        return $this;
    }

    /**
     * @param $logger
     * @param $mover
     * @deprecated deprecated since version 1.8
     *
     * Backward compatibility
     */
    public function readFiles($logger, $mover)
    {
        $result = $this->getData();
        $this->_logger->writeLog(date("d/m/Y H:i:s").' readFiles rerouted to getData (backward compatibility) in  '. get_class($this));
        return $result;
    }

    /**
     * @param $filesNames
     * @return mixed
     */
    abstract protected function _parseFiles($filesNames);

    /**
     * @param string $extension If provided, filter by this extension
     * @param string filePathMask Shell mask (support joker * for file path mask's)
     * @return array|bool
     */
    protected function _getFilesNames($extension = false, $filePathMask = false)
    {
        if($extension)
            $this->setExtension($extension);
        if($filePathMask)
            $this->setPattern($filePathMask);
        $this->_allFileNames = array();

        $directory = $this->_path.'/files';
        $this->_logger->writeLog(date("d/m/Y H:i:s").' Lecture du dossier '.$directory);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        if($this->_extension)
        {
            $extensionLength = strlen($this->_extension);
        }

        $ignoredBasename = array(
            '.'  => false,
            '..' => false,
        );

        foreach($files as $filePath => $spl)
        {
            if(array_key_exists(basename($filePath), $ignoredBasename))
                continue;
            if ($this->_extension)
            {
                if(substr($filePath, -$extensionLength) != $this->_extension)
                    continue;
            }
            if ($this->_pattern)
            {
                if (!fnmatch($this->_pattern, $filePath))
                    continue;
            }
            $this->_allFileNames[] = basename($filePath);
        }

        if(count($this->_allFileNames)!=0)
            sort($this->_allFileNames);

        if(!count($this->_allFileNames))
            return false;

        return $this->_allFileNames;
    }

    /**
     * @return mixed
     *
     * Template Method
     */
    final public function getData()
    {
        $this->_logger->writeLog(date("d/m/Y H:i:s").' Parsing Files in  '. get_class($this). 'method : '. __FUNCTION__ );

        $filesNames = $this->_getFilesNames();
        if(!is_array($filesNames) || !count($filesNames))
            return array();
        return $this->_parseFiles($filesNames);
    }

    /**
     * @deprecated since version 1.8
     * Backward compatibility
     */
    public function getFileTemp()
    {
        $this->getCurrentFileName();
    }

    public function getAllFileNames()
    {
        return $this->_allFileNames;
    }

    public function setFileDataOffsets($start, $end, $fileName)
    {
        if(array_key_exists($end, $this->_offsetsToFilename))
            throw new Exception('End offset already attributed to filename '.$this->_offsetsToFilename[$end]);
        if(!in_array($fileName, $this->_allFileNames))
            throw new Exception('Unknown file '.$fileName);
        $this->_offsetsToFilename[$start] = $fileName;
        $this->_offsetsToFilename[$end]   = $fileName;
    }

    /**
     * @return mixed current file in the stack
     */
    public function getCurrentFileName($offset=false)
    {
        if(!$offset)
            return $this->_currentFileName;
        $ranges  = array_keys($this->_offsetsToFilename);
        if(!count($ranges))
            throw new Exception('No ranges at all '.print_r($ranges, true));
        $founded = false;
        for($number = count($ranges), $i=0; $i<$number; $i++)
        {
            $rangeStart = $ranges[$i];
            $i++;
            $rangeEnd   = $ranges[$i];
            if(($rangeStart <= $offset) && ($offset <= $rangeEnd))
            {
                $founded = true;
                $i = $number;
            }
        }
        if(!$founded)
            throw new Exception('Offset '.$offset.' out of known ranges : '.$ranges[0].'-'.$ranges[count($ranges)-1].'');
        return $this->_offsetsToFilename[$rangeEnd];
    }

    /**
     * @param String $fileName : name of the file
     */
    protected function setCurrentFileName($fileName)
    {
        $this->_currentFileName = $fileName;
    }

    public function log($message)
    {
        $this->_logger->writeLog($message);
        return;
    }

    public function logError($error)
    {
        $this->_errors[] = $error;
        return $this->log($error);
    }

    public function logNotification($notification)
    {
        $this->_notifications[] = $notification;
        return $this->log($notification);
    }
}