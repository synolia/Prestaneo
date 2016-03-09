<?php

/*
 * Cette class permet de passer toutes les configs aux autres objets
 */

Class UtilsSystem extends Utils
{
    protected $_errorReporting;// (E_ALL ^ (E_STRICT | E_NOTICE));
    protected $_memoryLimit     = '2048M';
    protected $_timeLimit       = 7200; //2 heures
    protected $_stopOnHttpClose = false;

    public function setErrorReporting($level)
    {
        $this->_errorReporting = ($level);
        return $this;
    }

    public function setStopOnHttpClose($stop)
    {
        $this->_stopOnHttpClose = ($stop?true:false);
        return $this;
    }

    public function setMemoryLimit($memoryLimit)
    {
        if(!$memoryLimit)
            return false;
        $this->_memoryLimit = $memoryLimit;
        return $this;
    }


    public function setTimeLimit($timeLimit)
    {
        if(!intval($timeLimit))
            return false;
        $this->_timeLimit = intval($timeLimit);
        return $this;
    }

    public function apply()
    {
        error_reporting($this->_errorReporting);
        set_time_limit($this->_timeLimit);
        ini_set('memory_limit', $this->_memoryLimit);
        ignore_user_abort($this->_stopOnHttpClose);
        return $this;
    }

}