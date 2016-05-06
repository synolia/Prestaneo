<?php

class ImportAbstract extends Importer
{
    protected $_offsets = null;
    protected $_langs   = array();
    protected $_labels  = array();

    public function process()
    {
        return true;
    }

    /**
     * Trims a full line of data before returning it
     *
     * @param array $line
     *
     * @return array
     */
    protected function _cleanDataLine($line) {
        foreach ($line as &$item) {
            $item = trim($item);
        }
        return $line;
    }

    /**
     * Gets the list of used languages in the CSV file and cleans the corresponding offset names to remove possible channel names
     *
     * @param array $headers
     * @return bool
     */
    protected function _getLangsInCsv($headers)
    {
        if (!is_array($headers) || empty($headers))
            return false;

        $filter = '#^(.+?)-(\w{2,3})_\w+(?:-\w+)?$#';

        $this->_offsets = array();

        foreach ($headers as $offset => $header) {
            if (preg_match($filter, $header, $results)) {
                $field   = $results[1];
                $isoCode = $results[2];

                if ($isoCode === false) {
                    continue;
                }

                $langId  = (int)Language::getIdByIso($isoCode, false);

                if ($langId != 0) {
                    $this->_langs[$isoCode] = $langId;

                    if (!isset($this->_offsets[$field . '-' . $isoCode])) {
                        $this->_offsets[$field . '-' . $isoCode] = $offset;
                    }
                } else {
                    $this->_offsets[$header] = $offset;
                }
            } else {
                $this->_offsets[$header] = $offset;
            }
        }
        return true;
    }

    /**
     * Checks if required fields are missing from the data
     *
     * @param array $requiredFields
     * @param array $fields
     * @return array
     */
    protected function _checkMissingRequiredFields($requiredFields, $fields) {
        $missingFields = array();

        foreach ($requiredFields as $required) {
            //Matching normal, translatable and currencies fields
            $found = preg_grep('#^' . $required . '(?:$|-)#', $fields);

            if (empty($found)) {
                $missingFields[] = $required;
            }
        }
        return $missingFields;
    }

    /**
     * Replaces offset names from Akeneo by the PrestaShop equivalents and filters non wanted offsets
     *
     * @param array|string $classes
     * @param array        $mappings
     * @param array        $exclude list of PrestaShop fields for which name replacement should not be done
     *
     * @return bool
     */
    protected function _mapOffsets($classes, $mappings, $exclude = array()) {
        if (!is_array($classes)) {
            $classes = array($classes);
        }

        $isFirst = true;
        $objectFields = array();
        foreach ($classes as $class) {
            $className  = ucfirst($class);
            $reflection = new ReflectionClass($className);

            if (!$reflection->hasProperty('definition')) {
                return false;
            }

            $definition = $reflection->getStaticPropertyValue('definition');

            if ($isFirst) {
                $isFirst = false;
                $objectFields = $definition['fields'];
            } else {
                $objectFields = array_intersect_assoc($objectFields, $definition['fields']);
            }
        }

        if (!is_array($exclude)) {
            $exclude = array($exclude);
        }
        $mappings = array_diff($mappings, $exclude);

        $defaultLangId = Configuration::get('PS_LANG_DEFAULT');
        $filter        = '#^(.+?)-(\w{2,3})$#';

        $offsetFields  = array_keys($this->_offsets);
        //Used to easily check if a translated special field is present
        $presentFields = array_unique(preg_replace($filter, '$1', $offsetFields));

        $newOffsets = array(
            'default' => array(),
            'lang'    => array(),
            'date'    => array(),
            'special' => array(),
        );

        foreach ($mappings as $akeneo => $presta) {
            if (array_key_exists($presta, $objectFields)) {
                if (array_key_exists('lang', $objectFields[$presta]) && $objectFields[$presta]['lang']) {
                    $fieldType = 'lang';
                } elseif ($objectFields[$presta]['type'] == ObjectModel::TYPE_DATE) {
                    $fieldType = 'date';
                } else {
                    $fieldType = 'default';
                }
            } elseif (array_key_exists($akeneo, $this->_offsets) || in_array($akeneo, $presentFields)) {
                $fieldType = 'special';
            } else {
                continue;
            }

            if ($fieldType == 'lang' || ($fieldType == 'special' && !array_key_exists($akeneo, $this->_offsets))) {
                $matches = preg_grep('#^' . $akeneo . '-(\w{2,3})$#', $offsetFields);

                foreach ($matches as $fullField) {
                    if (preg_match($filter, $fullField, $result)) {
                        if (!array_key_exists($result[2], $this->_langs)) {
                            continue;
                        }
                        $langId = $this->_langs[$result[2]];
                    } else {
                        $langId = $defaultLangId;
                    }
                    $newOffsets[$fieldType][$presta][$langId] = $this->_offsets[$fullField];
                }
            } elseif (array_key_exists($akeneo, $this->_offsets)) {
                $newOffsets[$fieldType][$presta] = $this->_offsets[$akeneo];
            }
        }

        $this->_offsets = $newOffsets;
        return true;
    }

    /**
     * @return UtilsFtp|UtilsSftp
     */
    protected function _getFtpConnection()
    {
        $fileTransferPort = Configuration::get(MOD_SYNC_NAME.'_ftpport');

        return Utils::exec(($fileTransferPort == 22?'sftp':'ftp'))->setParameters(
            Configuration::get(MOD_SYNC_NAME.'_ftplogin'),
            Configuration::get(MOD_SYNC_NAME.'_ftppassword'),
            Configuration::get(MOD_SYNC_NAME.'_ftphost'),
            $fileTransferPort,
            Configuration::get(MOD_SYNC_NAME.'_ftppath')
        );
    }
}