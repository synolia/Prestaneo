<?php
if (!class_exists('Net_SSH2'))
{
    $includePath = get_include_path();
    set_include_path(dirname(__FILE__) . '/../librairies/phpseclib-1.0.1/');
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

    public function setParameters($user, $password, $host, $port=22, $path=null, $timeout=null, $sshKey = null)
    {
        $this->_parameters = array(
            'username'      => $user,
            'password'      => $password,
            'host'          => $host,
            'path'          => $path,
            'port'          => $port,
            'timeout'       => $timeout,
            'ssh_key'       => $sshKey,
        );
        if($port)
        {
            $this->_parameters['host'].=':'.$port;
        }
        return $this;
    }

    /**
     * Disconnects the current connection
     */
    public function disconnect()
    {
        return $this->__destruct();
    }

    public function __destruct()
    {
        if($this->_opened)
        {
            if(!is_object($this->_connection))
            {
                $this->_handler->_connection->disconnect();
            }
            else
            {
                $this->_connection->disconnect();
            }
            $this->_opened = false;
        }
    }

    public function getFile($distantPath, $localPath)
    {
        while(strpos($localPath, '//')!==false)
            $localPath = str_replace('//', '/', $localPath);
        if(!file_exists(dirname($localPath)))
            mkdir(dirname($localPath), 0777, true);

        $fileData = $this->readFile($distantPath);
        if($fileData === false)
        {
            throw new Exception('Can\'t get distant file : '.$this->_parameters['path'].'/'.$distantPath, 2);
        }
        if(@file_put_contents($localPath, $fileData) === false)
        {
            throw new Exception('Can\'t write local file : '.$localPath, 3);
        }
        return true;
    }

    public function recursiveGetFiles($distantFolderPath, $localFolderPath, $grep = array())
    {
        $sftpClient = $this->_getHandler();
        $oldPath    = $sftpClient->pwd();

        if (!$sftpClient->cd($distantFolderPath))
        {
            throw new Exception('Can\'t open sftp folder : ' . $distantFolderPath, 5);
        }

        $ls = $sftpClient->rawls();

        if (!is_array($grep))
        {
            $grep = array($grep);
        }

        $return = true;
        foreach ($ls as $file)
        {
            if ($file['filename'] == '.' || $file['filename'] == '..')
            {
                continue;
            }

            $distantFile = $distantFolderPath . $file['filename'];
            $localFile   = $localFolderPath . $file['filename'];

            if ($file['type'] == 2)
            {
                if(!($this->recursiveGetFiles($distantFile . '/', $localFile . '/', $grep)))
                {
                    $return = false;
                }
            }
            elseif ($file['type'] == 1)
            {
                $isMatch = empty($grep);

                foreach ($grep as $pattern)
                {
                    if (fnmatch($pattern, $file['filename']))
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
        $sftpClient->cd($oldPath);
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

        if (!is_array($grep))
        {
            $grep = array($grep);
        }

        foreach ($folderContents as $folderContent)
        {
            $isMatch = empty($grep);

            foreach ($grep as $pattern)
            {
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
        $sftpClient = $this->_getHandler();
        return $sftpClient->read($filePath, null);
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
     * @param string $args[password] Connection password or passphrase in case of ssh key file
     * @param int $args[timeout] Connection timeout [=10]
     * @param string $args[ssh_key] Set key file path to establish connection authentication
     *
     */
    public function open(array $args = array())
    {
        $includePath = get_include_path();
        set_include_path(dirname(__FILE__).'/../librairies/phpseclib-1.0.1/');

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

        if (!empty($args['ssh_key']))
        {
            require_once('Crypt/RSA.php');
            $this->_connection = new Net_SFTP($host, $port, $args['timeout']);
            $key = new Crypt_RSA();
            if(strlen($args['password'])>0)
            {
                $key->setPassword($args['password']);
            }
            $key->loadKey($args['ssh_key']);
            if (!$this->_connection->login($args['username'], $key)) {
                throw new Exception(sprintf('Unable to open SFTP connection as %s@%s using RSA key', $args['username'], $args['host']));
            }
        }
        else
        {
            $this->_connection = new Net_SFTP($host, $port, $args['timeout']);
            if (!$this->_connection->login($args['username'], $args['password']))
            {
                throw new Exception(sprintf('Unable to open SFTP connection as %s@%s', $args['username'], $args['host']));
            }
        }
        $this->_opened = true;
        set_include_path($includePath);
    }

    /**
     * Close a connection
     *
     */
    public function close()
    {
        return $this->__destruct();
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
    public function write($filename, $src, $mode = NET_SFTP_LOCAL_FILE)
    {
        return $this->_connection->put($filename, $src, $mode);
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