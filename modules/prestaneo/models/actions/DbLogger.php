<?php

/*
Class de log qui permet d'ecrire une ligne en base pour chaque imports / exports
*/

class DbLogger extends ObjectModel
{
    public $id_status;
    public $type;
    public $status;
    public $date_add;
    public $date_end;
    public $emulate_date;
    public $comments;

    public static $definition = array(
        'primary' => 'id_status',
        'multilang' => false,
        'fields' => array(
            'type'         => array('type' => self::TYPE_STRING),
            'status'       => array('type' => self::TYPE_BOOL),
            'date_add'     => array('type' => self::TYPE_DATE),
            'date_end'     => array('type' => self::TYPE_DATE),
            'emulate_date' => array('type' => self::TYPE_BOOL),
            'comments'     => array('type' => self::TYPE_STRING)
        ),
    );

    public function __construct($id = null, $id_lang = null, $id_shop = null)
    {
        self::$definition['table'] = MOD_SYNC_NAME.'_status';
        parent::__construct($id, $id_lang, $id_shop);
    }

    public static function getAllUnfinishedByType($type)
    {
        if(!$type)
            return false;

        $sql = 'SELECT * FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_status`
                WHERE `type` = "'.pSQL($type).'"
                AND   `date_end` IS NULL
                ORDER BY id_status DESC';
        return Db::getInstance()->executeS($sql);
    }

    public static function getLastLoggerByType($type)
    {
        if(!$type)
          return false;

        $sql = 'SELECT id_status FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_status`
                WHERE `type` = "'.pSQL($type).'"
                ORDER BY id_status DESC';
        $id_status = Db::getInstance()->getRow($sql);
        if(empty($id_status))
            return new DbLogger();
        return new DbLogger($id_status['id_status']);

    }

    public static function getLastLoggerByTypeAndStatus($type, $status)
    {
        if(!$type)
            return false;

        $sql = 'SELECT id_status FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_status`
                WHERE `type` = "'.pSQL($type).'" AND `status` = "'.pSQL($status).'"
                ORDER BY id_status DESC';
        $id_status = Db::getInstance()->getRow($sql);
        if(empty($id_status))
            return new DbLogger();
        return new DbLogger($id_status['id_status']);

    }

}