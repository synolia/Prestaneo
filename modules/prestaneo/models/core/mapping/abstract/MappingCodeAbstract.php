<?php

abstract class MappingCodeAbstract extends ObjectModel
{
    public $champ_akeneo;
    public $champ_prestashop;

    public static function getCount()
    {
        $sql = 'SELECT count(*)
                FROM ' . _DB_PREFIX_ . static::$definition['table'];
        return (int)Db::getInstance()->getValue($sql);
    }

    public static function getIdByCode($code)
    {
        if (empty($code) || !$code)
            return false;

        $sql = 'SELECT `' . static::$definition['primary'] . '`
                FROM ' . _DB_PREFIX_ . static::$definition['table'] . '
                WHERE `code` = "' . pSQL($code) . '"';
        return Db::getInstance()->getValue($sql);
    }

    public static function getCodeById($id)
    {
        if (empty($id) || !$id || $id <= 0)
            return false;

        $sql = 'SELECT `code`
                FROM ' . _DB_PREFIX_ . static::$definition['table'] . '
                WHERE `' . static::$definition['primary'] . '` = ' . pSQL($id);
        return Db::getInstance()->getValue($sql);
    }

    public static function getRowByCode($code)
    {
        if (empty($code) || !$code)
            return false;

        $sql = 'SELECT *
                FROM ' . _DB_PREFIX_ . static::$definition['table'] . '
                WHERE `code` = "' . pSQL($code) . '"';
        return Db::getInstance()->executeS($sql);
    }

    public static function getAll()
    {
        $sql = 'SELECT *
                FROM ' . _DB_PREFIX_ . static::$definition['table'];
        return Db::getInstance()->executeS($sql);
    }
}