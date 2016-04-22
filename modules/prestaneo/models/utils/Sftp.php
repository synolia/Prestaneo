<?php
if (!class_exists('Net_SSH2'))
{
    $includePath = get_include_path();
    set_include_path(dirname(__FILE__) . '/../librairies/phpseclib-0.3.1/');
    require_once('Net/SFTP.php');
    set_include_path($includePath);
}

Class UtilsSftp
{
    const REMOTE_TIMEOUT = 10;
    const SSH2_PORT = 22;

    /**
     * @var Net_SFTP $_connection
     */
    protected $_connection = null;


    protected $_parameters;
    protected $_handler;
    protected $_opened = false;

    public function __construct()
    {
    }

    public function setParameters($user, $password, $host, $port=null, $path=null, $timeout=null)
    {
        $this->_parameters = array(
            'username'  => $user,
            'password'  => $password,
            'host'      => $host,
            'path'      => $path,
            'port'      => $port,
            'timeout'   => $timeout,
        );
        if($port)
        {
            $this->_parameters['host'].':'.$port;
        }
        return $this;
    }

    public function __destruct()
    {
        if($this->_opened)
        {
            $this->_getHandler()->close();
        }
    }

    public function getFile($distantPath, $localPath)
    {
        while(strpos($localPath, '//')!==false)
            $localPath = str_replace('//', '/', $localPath);
        if(!file_exists(dirname($localPath)))
            mkdir(dirname($localPath), 0777, true);

        $fileData = $this->readFile($distantPath);
        if(!$fileData)
        {
            throw new Exception('Can\'t get distant file : '.$this->_parameters['path'].'/'.$distantPath, 2);
        }
        if(!@file_put_contents($localPath, $fileData))
        {
            throw new Exception('Can\'t write local file : '.$localPath, 3);
        }
        return true;
    }

    public function recursiveGetFiles($distantFolderPath, $localFolderPath, $grep = array())
    {
        $sftpClient = $this->_getHandler();

        if (!$sftpClient->cd($distantFolderPath)) {
            throw new Exception('Can\'t open sftp folder : ' . $distantFolderPath, 5);
        }

        $ls = $sftpClient->rawls();

        if (!is_array($grep)) {
            $grep = array($grep);
        }

        $return = true;
        foreach ($ls as $file) {
            preg_match('/^(.)(?:\S+\s+){8}(.+)$/', $file, $matches);
            //$matches[1] is the type of file
            //$matches[2] is the name of the file

            $distantFile = $distantFolderPath . $matches[2];
            $localFile   = $localFolderPath . $matches[2];

            if ($matches[1] == 'd') {
                if(!($this->recursiveGetFiles($distantFile . '/', $localFile . '/', $grep))) {
                    $return = false;
                }
            } elseif ($matches[1] == '-') {
                $isMatch = empty($grep);

                foreach ($grep as $pattern) {
                    if (fnmatch($pattern, $matches[2])) {
                        $isMatch = true;
                        break;
                    }
                }

                if ($isMatch && !$this->getFile($distantFile, $localFile)) {
                    $return = false;
                }
            }
        }

        return $return;
    }

    public function getFiles($distantFolderPath, $localFolderPath, $grep=array())
    {
        $includePath = get_include_path();
        set_include_path(dirname(__FILE__).'/../librairies/phpseclib-0.3.1/');
        if(empty($localFolderPath))
        {
            $localFolderPath = null;
        }
        $matchingFileList = $this->getFolderContent($grep, $distantFolderPath);
        $return = !empty($matchingFileList);
        foreach($matchingFileList as $matchingFile)
        {
            if(!$this->getFile($matchingFile, $localFolderPath.'/'.$matchingFile))
            {
                $return = false;
            }
        }
        set_include_path($includePath);
        return $return;
    }

    public function getFolderContent($grep=array(), $path=null)
    {
        $sftpClient = $this->_getHandler();
        if($path)
        {
            if(!$sftpClient->cd($path))
            {
                throw new Exception('Can\'t open sftp folder : '.$path, 5);
            }
        }
        $folderContents       = $sftpClient->ls();
        $formatFolderContents = array();

        if (!is_array($grep)) {
            $grep = array($grep);
        }

        foreach ($folderContents as $folderContent) {
            $isMatch = empty($grep);

            foreach ($grep as $pattern) {
                if (fnmatch($pattern, $folderContent['text'])) {
                    $isMatch = true;
                    break;
                }
            }

            if ($isMatch) {
                $formatFolderContents[] = $folderContent['text'];
            }
        }
        return $formatFolderContents;
    }

    public function readFile($filePath)
    {
        $sftpClient = $this->_getHandler();
        return $sftpClient->read($filePath);
    }

    protected function _getHandler()
    {
        if(is_null($this->_handler))
        {
            $this->_handler = clone $this;
            try{
                $this->_handler->open($this->_parameters);
                if($this->_parameters['path'])
                {
                    if(!$this->_handler->cd($this->_parameters['path']))
                    {
                        throw new Exception('Can\'t open sftp folder : '.$this->_parameters['path'], 4);
                    }
                }
            }catch (Exception $e){
                throw new Exception('Can\'t open sftp connection', 1, $e);
                return false;
            }
            $this->_opened = true;
        }
        return $this->_handler;
    }

    /**
     * Open a SFTP connection to a remote site.
     *
     * @param array $args Connection arguments
     * @param string $args[host] Remote hostname
     * @param string $args[username] Remote username
     * @param string $args[password] Connection password
     * @param int $args[timeout] Connection timeout [=10]
     *
     */
    public function open(array $args = array())
    {
        if (!isset($args['timeout']))
        {
            $args['timeout'] = self::REMOTE_TIMEOUT;
        }
        if (strpos($args['host'], ':') !== false)
        {
            list($host, $port) = explode(':', $args['host'], 2);
        }
        else
        {
            $host = $args['host'];
            $port = self::SSH2_PORT;
        }
        $this->_connection = new Net_SFTP($host, $port, $args['timeout']);
        if (!$this->_connection->login($args['username'], $args['password']))
        {
            throw new Exception(sprintf("Unable to open SFTP connection as %s@%s", $args['username'], $args['host']));
        }

    }

    /**
     * Close a connection
     *
     */
    public function close()
    {
        return $this->_connection->disconnect();
    }

    /**
     * Create a directory
     *
     * @param $recursive Analogous to mkdir -p
     *
     * Note: if $recursive is true and an error occurs mid-execution,
     * false is returned and some part of the hierarchy might be created.
     * No rollback is performed.
     */
    public function mkdir($dir, $recursive=true)
    {
        if ($recursive)
        {
            $no_errors = true;
            $dirlist = explode('/', $dir);
            reset($dirlist);
            $cwd = $this->_connection->pwd();
            while ($no_errors && ($dir_item = next($dirlist)))
            {
                $no_errors = ($this->_connection->mkdir($dir_item) && $this->_connection->chdir($dir_item));
            }
            $this->_connection->chdir($cwd);
            return $no_errors;
        }
        else
        {
            return $this->_connection->mkdir($dir);
        }
    }

    /**
     * Delete a directory
     *
     */
    public function rmdir($dir, $recursive=false)
    {
        if ($recursive)
        {
            $no_errors = true;
            $pwd = $this->_connection->pwd();
            if(!$this->_connection->chdir($dir))
            {
                throw new Exception("chdir(): $dir: Not a directory");
            }
            $list = $this->_connection->nlist();
            if (!count($list))
            {
                // Go back
                $this->_connection->chdir($pwd);
                return $this->rmdir($dir, false);
            }
            else
            {
                foreach ($list as $filename)
                {
                    if($this->_connection->chdir($filename))
                    { // This is a directory
                        $this->_connection->chdir('..');
                        $no_errors = $no_errors && $this->rmdir($filename, $recursive);
                    }
                    else
                    {
                        $no_errors = $no_errors && $this->rm($filename);
                    }
                }
            }
            $no_errors = $no_errors && ($this->_connection->chdir($pwd) && $this->_connection->rmdir($dir));
            return $no_errors;
        }
        else
        {
            return $this->_connection->rmdir($dir);
        }
    }

    /**
     * Get current working directory
     *
     */
    public function pwd()
    {
        return $this->_connection->pwd();
    }

    /**
     * Change current working directory
     *
     */
    public function cd($dir)
    {
        return $this->_connection->chdir($dir);
    }

    /**
     * Read a file
     *
     */
    public function read($filename, $dest=null)
    {
        if (is_null($dest))
        {
            $dest = false;
        }
        return $this->_connection->get($filename, $dest);
    }

    /**
     * Write a file
     * @param $src Must be a local file name
     */
    public function write($filename, $src)
    {
        return $this->_connection->put($filename, $src);
    }

    /**
     * Delete a file
     *
     */
    public function rm($filename)
    {
        return $this->_connection->delete($filename);
    }

    /**
     * Rename or move a directory or a file
     *
     */
    public function mv($src, $dest)
    {
        return $this->_connection->rename($src, $dest);
    }

    /**
     * Chamge mode of a directory or a file
     *
     */
    public function chmod($filename, $mode)
    {
        return $this->_connection->chmod($mode, $filename);
    }

    /**
     * Get list of cwd subdirectories and files
     *
     */
    public function ls()
    {
        $list = $this->_connection->nlist();
        $pwd = $this->pwd();
        $result = array();
        foreach($list as $name)
        {
            $result[] = array(
                'text' => $name,
                'id' => "{$pwd}{$name}",
            );
        }
        return $result;
    }

    public function rawls()
    {
        $list = $this->_connection->rawlist();
        return $list;
    }
}