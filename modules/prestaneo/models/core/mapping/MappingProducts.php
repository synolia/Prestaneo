<?php

class MappingProducts extends MappingAbstract
{
    public static $definition = array(
        'table'   => 'mapping_products',
        'primary' => 'id_mapping',
        'fields'  => array(
            'champ_akeneo'     => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'champ_prestashop' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'required'         => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
        )
    );

    public static function getImageFields()
    {
        $sql = 'SELECT `champ_akeneo`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE `champ_prestashop` = "image"';
        return Db::getInstance()->executeS($sql);
    }

    public static function getFileFields()
    {
        $sql = 'SELECT `champ_akeneo`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE `champ_prestashop` = "file"';
        return Db::getInstance()->executeS($sql);
    }
}