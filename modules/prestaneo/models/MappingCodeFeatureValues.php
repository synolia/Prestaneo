<?php

class MappingCodeAttributeValues extends MappingCodeAbstract
{
    public $id_attribute_value;
    public $code;

    public static $definition = array(
        'table'   => 'mapping_code_attribute_values',
        'primary' => 'id_attribute_value',
        'fields'  => array(
            'id_attribute_value' => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedId'),
            'code'               => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );
}