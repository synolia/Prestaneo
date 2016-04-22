<?php

class MappingAttributeValues extends MappingAbstract
{
    public static $definition = array(
        'table'   => 'mapping_attribute_values',
        'primary' => 'id_mapping',
        'fields'  => array(
            'champ_akeneo'     => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'champ_prestashop' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'required'         => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
        )
    );
}