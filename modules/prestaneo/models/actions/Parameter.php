<?php
/*
Class de traitements en fonction du type désiré de variables pour chaque imports / exports
*/

class Parameter
{
    public $value;
    protected $_type;

    protected static $_instance;

    public static function load($data, $key, $type)
    {
        if(!array_key_exists($key, $data))
            return null;
        $value = $data[$key];
        if(!is_object(self::$_instance))
        {
            self::$_instance = new Parameter($value, $type);
            return self::$_instance->value;
        }
        return self::$_instance->loadValue($value, $type);
    }

    public function __construct($value, $type)
    {
        $this->loadValue($value, $type);
    }

    protected function _getType($type)
    {
        switch(strtolower($type))
        {
            case 'date':
            case 'datetime':
                $this->_type = 'datetime';
                break;

            case 'string':
            case 'str':
                $this->_type = 'string';
                break;

            case 'int':
            case 'integer':
                $this->_type = 'int';
                break;

            default:
                return false;

        }
        return true;
    }

    public function loadValue($value, $type)
    {
        if(!$this->_getType($type))
        {
            return null;
        }
        $this->value = $this->{'process'.ucfirst($this->_type)}($value);
        return $this->value;
    }

    public function processDatetime($datetime)
    {
        $datetime = $this->processString($datetime);
        if(empty($datetime))
        {
            return null;
        }
        if(substr_count($datetime, '_')==1)
        {
            list($date, $time) = explode('_', $datetime);
            $datetime = $date.' '.str_replace('-', ':', $time);
        }
        try{
            $datetime = new DateTime($datetime);
        }catch (Exception $e){
            $datetime = null;
        }
        return $datetime;
    }

    public function processString($string)
    {
        return urldecode($string);
    }

    public function processInt($int)
    {
        return intval($int);
    }
}