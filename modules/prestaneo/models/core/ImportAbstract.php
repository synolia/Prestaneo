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
     * Sanitizes a full line of data before returning it
     *
     * @param $line
     * @return mixed
     */
    protected function _cleanDataLine($line) {
        $enclosure = (Configuration::get('PS_IMPORT_ENCLOSURE') ? Configuration::get('PS_IMPORT_ENCLOSURE') : ';');

        foreach ($line as &$item) {
            $item = preg_replace('/\s+/', ' ', $item);
            $item = str_replace('' . $enclosure . '', '', $item);
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

        $filter = '/(.+?)-([a-zA-Z_-]+)$/';

        foreach ($headers as $offset => $header) {
            if (preg_match($filter, $header, $results)) {
                $field   = $results[1];
                $tag     = $results[2];
                $isoCode = substr($tag, 0, 2);

                if ($isoCode === false) {
                    continue;
                }

                $langId  = (int)LanguageCore::getIdByIso($isoCode, false);

                if ($langId != 0) {
                    $this->_langs[$langId]   = $isoCode;

                    $cleanTag = strstr($tag, '-', true);
                    if ($cleanTag) {
                        $tag = $cleanTag;
                    }
                    $this->_labels[$isoCode] = $tag;

                    if (!isset($this->_offsets[$field . '-' . $tag])) {
                        $this->_offsets[$field . '-' . $tag] = $offset;
                    }
                }
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
            $found = preg_grep("/^{$required}(?:$|-)/s", $fields);

            if (empty($found)) {
                $missingFields[] = $required;
            }
        }
        return $missingFields;
    }

    /**
     * Replaces offset names from Akeneo by the PrestaShop equivalents
     *
     * @param array $mappings
     * @param array $exclude list of PrestaShop fields for which name replacement should not be done
     */
    protected function _mapOffsets($mappings, $exclude = array()) {
        $newOffsets = array();
        $filter = '/(.+?)(-[a-zA-Z_-]+)$/';

        if (!is_array($exclude)) {
            $exclude = array($exclude);
        }

        $mappings = array_diff($mappings, $exclude);

        foreach ($this->_offsets as $fullField => $position) {
            if (preg_match($filter, $fullField, $matches)) {
                $field  = $matches[1];
                $suffix = $matches[2];
            } else {
                $field  = $fullField;
                $suffix = '';
            }

            if (isset($mappings[$field])) {
                $newOffsets[$mappings[$field] . $suffix] = $position;
            } else {
                $newOffsets[$fullField] = $position;
            }
        }
        $this->_offsets = $newOffsets;
        return;
    }

    /**
     * @return UtilsFtp
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