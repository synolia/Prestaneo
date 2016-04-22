<?php

class MappingCodeAttributes extends MappingCodeAbstract
{
    public $id_attribute_group;
    public $code;

    public static $definition = array(
        'table'   => 'mapping_code_attributes',
        'primary' => 'id_attribute_group',
        'fields'  => array(
            'id_attribute_group' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'code'               => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );

}