<?php

Class UtilsFtp
{
    const REMOTE_TIMEOUT = 10;
    const FTP_PORT = 21;

    /**
     * @var ftp_connect $_connection
     */
    protected $_connection = null;


    protected $_parameters;
    protected $_handler;
    protected $_opened = false;

    public $status;

    public function __construct()
    {
    }

    public function setParameters($user, $password, $host, $port=null, $path=null, $timeout=null, $passiveMode=false,
                                  $forceSsl = false)
    {
        $this->_parameters = array(
            'username'     => $user,
            'password'     => $password,
            'host'         => $host,
            'path'         => $path,
            'port'         => $port,
            'timeout'      => $timeout,
            'passive_mode' => $passiveMode,
            'force_ssl'    => $forceSsl,
        );
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
        $ftpClient = $this->_getHandler();

        if (!$ftpClient->cd($distantFolderPath))
        {
            throw new Exception('Can\'t open ftp folder : ' . $distantFolderPath, 5);
        }

        $ls = $ftpClient->rawls();

        if (!is_array($grep))
        {
            $grep = array($grep);
        }

        $return = true;
        foreach ($ls as $file)
        {
            preg_match('/^(.)(?:\S+\s+){8}(.+)$/', $file, $matches);
            //$matches[1] is the type of file
            //$matches[2] is the name of the file

            $distantFile = $distantFolderPath . $matches[2];
            $localFile   = $localFolderPath . $matches[2];

            if ($matches[1] == 'd')
            {
                if(!($this->recursiveGetFiles($distantFile . '/', $localFile . '/', $grep)))
                {
                    $return = false;
                }
            }
            elseif ($matches[1] == '-')
            {
                $isMatch = empty($grep);

                foreach ($grep as $pattern)
                {
                    if (fnmatch($pattern, $matches[2]))
                    {
                        $isMatch = true;
                        break;
                    }
                }

                if ($isMatch && !$this->getFile($distantFile, $localFile))
                {
                    $return = false;
                }
            }
        }

        return $return;
    }

    public function getFiles($distantFolderPath, $localFolderPath, $grep=array())
    {
        if(empty($localFolderPath))
        {
            $localFolderPath = null;
        }
        $matchingFileList = $this->getFolderContent($grep, $distantFolderPath);
        $return           = !empty($matchingFileList);
        foreach($matchingFileList as $matchingFile)
        {
            if(!$this->getFile($matchingFile, $localFolderPath.'/'.$matchingFile))
            {
                $return = false;
            }
        }
        return $return;
    }

    public function getFolderContent($grep=array(), $path=null)
    {
        $ftpClient = $this->_getHandler();
        if($path)
        {
            if(!$ftpClient->cd($path))
            {
                throw new Exception('Can\'t open ftp folder : '.$path, 5);
            }
        }
        $folderContents       = $ftpClient->ls();
        $formatFolderContents = array();

        if (!is_array($grep))
        {
            $grep = array($grep);
        }

        foreach ($folderContents as $folderContent)
        {
            $isMatch = empty($grep);

            foreach ($grep as $pattern) {
                if (fnmatch($pattern, $folderContent['text']))
                {
                    $isMatch = true;
                    break;
                }
            }

            if ($isMatch)
            {
                $formatFolderContents[] = $folderContent['text'];
            }
        }
        return $formatFolderContents;
    }

    public function readFile($filePath)
    {
        $ftpClient = $this->_getHandler();
        return $ftpClient->read($filePath);
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
                        throw new Exception('Can\'t open ftp folder : '.$this->_parameters['path'], 4);
                    }
                }
            }catch (Exception $e){
                throw new Exception('Can\'t open ftp connection', 1, $e);
                return false;
            }
            $this->_opened = true;
        }
        return $this->_handler;
    }

    /**
     * Open a FTP connection to a remote site.
     *
     * @param array $args Connection arguments
     * @param string $args[host] Remote hostname
     * @param string $args[port] Remote port
     * @param string $args[username] Remote username
     * @param string $args[password] Connection password
     * @param string $args[force_ssl] force SSL connection, by default set to false
     * @param string $args[passive_mode] Set passive mode, by default set to false
     * @param int $args[timeout] Connection timeout [=10]
     *
     */
    public function open(array $args = array())
    {
        if (!isset($args['timeout']))
        {
            $args['timeout'] = self::REMOTE_TIMEOUT;
        }

        if (!isset($args['force_ssl']))
        {
            $args['force_ssl'] = false;
        }

        if (!isset($args['passive_mode']))
        {
            $args['passive_mode'] = false;
        }

        if (strpos($args['host'], ':') !== false)
        {
            list($host, $port) = explode(':', $args['host'], 2);
        }
        elseif($args['port'])
        {
            $host = $args['host'];
            $port = $args['port'];
        }
        else
        {
            $host = $args['host'];
            $port = self::FTP_PORT;
        }

        if ($args['force_ssl'] && !function_exists('ftp_ssl_connect'))
        {
            throw new Exception(sprintf('Your server doesn\'t support FTPs connection', $host));
        }

        $this->_connection = ftp_ssl_connect($host, $port, $args['timeout']);
        if (!$this->_connection)
        {
            if ($args['force_ssl'])
            {
                throw new Exception(sprintf('Unable to open FTPs connection as %s@%s', $args['username'], $host));
            }
            $this->_connection = ftp_connect($host, $port, $args['timeout']);
        }

        if (!ftp_login($this->_connection, $args['username'], $args['password']))
        {
            throw new Exception(sprintf('Unable to open FTP connection as %s@%s', $args['username'], $host));
        }

        ftp_pasv($this->_connection, $args['passive_mode']);
    }

    /**
     * Close a connection
     *
     */
    public function close()
    {
        return @ftp_close($this->_connection);
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
            $cwd = ftp_pwd($this->_connection);
            while ($no_errors && ($dir_item = next($dirlist)))
            {
                $no_errors = (ftp_mkdir($this->_connection, $dir_item) && ftp_chdir($this->_connection, $dir_item));
            }
            ftp_chdir($this->_connection, $cwd);
            return $no_errors;
        }
        else
        {
            return ftp_mkdir($this->_connection, $dir);
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
            $pwd = ftp_pwd($this->_connection);
            if(!ftp_chdir($this->_connection, $dir))
            {
                throw new Exception("chdir(): $dir: Not a directory");
            }
            $list = ftp_nlist($this->_connection, '.');
            if(!is_array($list))
                $list = array();
            if (!count($list))
            {
                // Go back
                ftp_chdir($this->_connection, $pwd);
                return $this->rmdir($dir, false);
            }
            else
            {
                foreach ($list as $filename)
                {
                    if(ftp_chdir($this->_connection, $filename))
                    { // This is a directory
                        ftp_chdir($this->_connection, '..');
                        $no_errors = $no_errors && $this->rmdir($filename, $recursive);
                    }
                    else
                    {
                        $no_errors = $no_errors && $this->rm($filename);
                    }
                }
            }
            $no_errors = $no_errors && (ftp_chdir($this->_connection, $pwd) && ftp_rmdir($this->_connection, $dir));
            return $no_errors;
        }
        else
        {
            return ftp_rmdir($this->_connection, $dir);
        }
    }

    /**
     * Get current working directory
     *
     */
    public function pwd()
    {
        return ftp_pwd($this->_connection).'/';
    }

    /**
     * Change current working directory
     *
     */
    public function cd($dir)
    {
        return ftp_chdir($this->_connection, $dir);
    }

    /**
     * Read a file
     *
     */
    public function read($filename, $dest=null)
    {
        if($dest)
        {
            return ftp_get($this->_connection, $filename, $dest,  FTP_BINARY);
        }
        else
        {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if($sockets === false)
            {
                throw new Exception('Unable to create socket pair');
            }

            list($socket, $socketData) = $sockets;
            stream_set_write_buffer($socket, 0);
            stream_set_timeout($socketData, 0);

            $this->_startReading($socket, $filename);

            $content = '';
            while(!$this->_isReadFinished()) {
                $currentContent = stream_get_contents($socketData);
                if($currentContent !== false)
                    $content.= $currentContent;
                $this->_continueReading();
            }

            $content.= stream_get_contents($socketData);

            return $content;
        }
    }

    protected function _startReading($stream, $filename)
    {
        $this->status = ftp_nb_fget($this->_connection, $stream, $filename, FTP_BINARY);
    }

    protected function _isReadFinished()
    {
        return $this->status !== FTP_MOREDATA;
    }

    public function _continueReading()
    {
        if($this->_isReadFinished())
            throw new Exception('Cannot continue download; already finished');

        $this->status = ftp_nb_continue($this->_connection);
    }

    /**
     * Write a file
     * @param $src Must be a local file name
     */
    public function write($filename, $src)
    {
        return ftp_put($this->_connection, $filename, $src, FTP_BINARY);
    }

    /**
     * Delete a file
     *
     */
    public function rm($filename)
    {
        return ftp_delete($this->_connection, $filename);
    }

    /**
     * Rename or move a directory or a file
     *
     */
    public function mv($src, $dest)
    {
        return ftp_rename($this->_connection, $src, $dest);
    }

    /**
     * Chamge mode of a directory or a file
     *
     */
    public function chmod($filename, $mode)
    {
        return ftp_chmod($this->_connection, $mode, $filename);
    }

    /**
     * Get list of cwd subdirectories and files
     *
     */
    public function ls()
    {
        $list = ftp_nlist($this->_connection, '.');

        // If an error occured during nlist, try passive mode
        if (!$list)
        {
            ftp_pasv($this->_connection, true);
            $list = ftp_nlist($this->_connection, '.');
        }

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
        $list = ftp_rawlist($this->_connection, '.');
        return $list;
    }
}