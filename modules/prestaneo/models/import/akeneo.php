<?php

class ImportAkeneo extends ImportAbstract
{
    public function process()
    {
        $folderUtil = Utils::exec('folder');
        $path       = $this->_manager->getPath() . '/files/';

        if (!$folderUtil->isFolderEmpty($path)) {
            if (_PS_MODE_DEV_) {
                $this->log('Cleaning working folder');
            }
            $folderUtil->delTree($path, false);
        }

        $categoryImporter = new ImportCategory();
        if (!$categoryImporter->import()) {
            return false;
        }

        $variantImporter = new ImportVariant();
        if (!$variantImporter->import()) {
            return false;
        }

        $attributeImporter = new ImportAttribute();
        if (!$attributeImporter->import()) {
            return false;
        }

        $attributeValueImporter = new ImportAttributeValues();
        if (!$attributeValueImporter->import()) {
            return false;
        }

        $productImporter = new ImportProduct();
        if (!$productImporter->import()) {
            return false;
        }

        return true;
    }
}