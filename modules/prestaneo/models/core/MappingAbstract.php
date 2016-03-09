<?php

class MappingAbstract extends ObjectModel
{
    public $champ_akeneo;
    public $champ_prestashop;

    public static function getAll()
    {
        $sql = 'SELECT *
                FROM ' . _DB_PREFIX_ . static::$definition['table'];
        return Db::getInstance()->executeS($sql);
    }

    public static function getPrestashopField($champ_akeneo)
    {
        if (empty($champ_akeneo) || !$champ_akeneo)
            return false;

        $sql = 'SELECT `champ_prestashop`
                FROM ' . _DB_PREFIX_ . static::$definition['table'] . '
                WHERE `champ_akeneo` = "' . pSQL($champ_akeneo) . '"';
        return Db::getInstance()->getValue($sql);
    }

    public static function getAkeneoField($champ_prestashop)
    {
        if (empty($champ_prestashop) || !$champ_prestashop)
            return false;

        $sql = 'SELECT `champ_akeneo`
                FROM ' . _DB_PREFIX_ . static::$definition['table'] . '
                WHERE `champ_prestashop` = "' . pSQL($champ_prestashop) . '"';
        return Db::getInstance()->getValue($sql);
    }

    public static function existCodeAkeneo($code)
    {
        if (empty($code) || !$code)
            return false;

        $sql = 'SELECT `champ_akeneo`
                FROM ' . _DB_PREFIX_ . static::$definition['table'] . '
                WHERE `champ_akeneo` = "' . pSQL($code) . '"';
        return Db::getInstance()->getValue($sql);
    }

    /**
     * Gets all Prestashop fields with Akeneo fields as keys
     *
     * @return array|bool
     */
    public static function getAllPrestashopFields() {
        return self::getAllFields('prestashop');
    }

    /**
     * Gets all Akeneo fields with Prestashop fields as keys
     *
     * @return array|bool
     */
    public static function getAllAkeneoFields() {
        return self::getAllFields('akeneo');
    }

    /**
     * Gets the fields of $type type with the other type as key
     *
     * @param string $type
     * @return array|bool
     */
    protected static function getAllFields($type)
    {
        if ($type != 'akeneo' && $type != 'prestashop') {
            return false;
        }

        $key  = 'champ_' . ($type == 'akeneo' ? 'prestashop' : 'akeneo');
        $type = 'champ_' . $type;

        $fields  = self::getAll();
        $results = array();

        foreach ($fields as $field) {
            $results[$field[$key]] = $field[$type];
        }

        return $results;
    }

    /**
     * Get all required fields with $type name as their key
     *
     * @param string $type
     * @return array|bool
     */
    public static function getRequiredFields($type) {
        return self::getRequiredOrNotFields($type, true);
    }

    /**
     * Get all optionnal fields with $type name as their key
     *
     * @param string $type
     * @return array|bool
     */
    public static function getOptionnalFields($type) {
        return self::getRequiredOrNotFields($type, false);
    }

    /**
     * @param string $type
     * @param bool $required
     * @return array|bool
     */
    protected static function getRequiredOrNotFields($type, $required) {
        if ($type != 'akeneo' && $type != 'prestashop' || !is_bool($required)) {
            return false;
        }

        $fieldName = 'champ_' . $type;

        $sql = 'SELECT `' . $fieldName . '`
                FROM ' . _DB_PREFIX_ . static::$definition['table'] . '
                WHERE required = ' . $required;

        $results = Db::getInstance()->executeS($sql);

        if ($results !== false) {
            $fields = array();
            foreach ($results as $result) {
                $fields[]= $result[$fieldName];
            }
            return $fields;
        }

        return false;
    }
}