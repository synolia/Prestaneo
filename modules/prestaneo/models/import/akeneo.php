<?php

class ImportAkeneo extends ImportAbstract
{
    public function process()
    {
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

        $productImporter = new ImportProduct();
        if (!$productImporter->import()) {
            return false;
        }

        return true;
    }
}