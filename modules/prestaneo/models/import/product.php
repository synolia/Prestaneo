<?php
class ImportProduct extends ImportAbstract
{
    protected $_resetImages            = false;
    protected $_resetFeatures          = false;
    protected $_resetCombinations      = false;
    protected $_quantityDefault        = 0;
    protected $_delimiter              = "";
    protected $_knownProductReferences = array();

    public static $icon  = 'cubes';
    public static $order = 5;

    /**
     * Imports products
     *
     * @return bool
     * @throws Exception
     * @throws PrestaShopException
     */
    public function process()
    {
        $this->_delimiter = (Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') ? Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') : ';');

        $folderUtil = Utils::exec('Folder');
        $path       = $this->_manager->getPath() . '/files/';
        $baseUrl    = str_replace(_PS_ROOT_DIR_, _PS_BASE_URL_, $path);

        if ($folderUtil->isFolderEmpty($path)) {
            $fileTransferHost = Configuration::get(MOD_SYNC_NAME . '_ftphost');
            if (!empty($fileTransferHost)) {
                if (_PS_MODE_DEV_) {
                    $this->log('Fetching files from FTP');
                }
                $fileTransfer = $this->_getFtpConnection();
                $distantPath  = Configuration::get(MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH');

                $files = $fileTransfer->getFolderContent(array(), $distantPath);

                if (in_array('files', $files)) {
                    $success = $fileTransfer->recursiveGetFiles($distantPath . '/files/', $path . 'files/');
                    $success &= $fileTransfer->getFiles($distantPath, $path, '*.csv');
                } else {
                    $success = $fileTransfer->getFiles($distantPath, $path, array('*.zip', '*.csv'));
                }

                if (!$success) {
                    $this->logError('There was an error while retrieving the files from the FTP');
                    $folderUtil->delTree($path, false);
                    return false;
                }
            }
        }

        $archives = $folderUtil->getAllFilesInFolder($path, '*.zip');

        if (!empty($archives)) {
            Utils::exec('Zip')->extractAll($archives, $path);
        }

        $reader = new CsvReader($this->_manager, $this->_delimiter, '"', 32 * 1024);
        $reader->setExtension('.csv');

        $dataLines   = $reader->getData();
        $currentFile = $reader->getCurrentFileName(0);

        $this->_resetImages       = Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_IMAGES');
        $this->_resetFeatures     = Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_FEATURES');
        $this->_resetCombinations = Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_COMBINATIONS');
        $this->_quantityDefault   = Configuration::get(MOD_SYNC_NAME . '_IMPORT_DEFAULT_QTY_PRODUCT');

        if (!is_array($dataLines) || empty($dataLines)){
            $this->logError('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($currentFile), false);
            $folderUtil->delTree($path, false);
            return false;
        }

        $requiredFields = MappingProducts::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->logError('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($currentFile), false);
            $folderUtil->delTree($path, false);
            return false;
        }

        $offsets = array_flip($headers);

        //Getting the list of the picture fields in the csv
        $mappedPictureFields  = MappingProducts::getImageFields();
        $pictureFields = array();
        foreach ($mappedPictureFields as $mappedPictureField) {
            if (array_key_exists($mappedPictureField['champ_akeneo'], $offsets)) {
                $pictureFields[$mappedPictureField['champ_akeneo']] = $offsets[$mappedPictureField['champ_akeneo']];
            }
        }
        $hasImages = !empty($pictureFields);

        $mappedFileFields  = MappingProducts::getFileFields();
        $fileFields = array();
        foreach ($mappedFileFields as $mappedFileField) {
            if (array_key_exists($mappedFileField['champ_akeneo'], $offsets)) {
                $fileFields[$mappedFileField['champ_akeneo']] = $offsets[$mappedFileField['champ_akeneo']];
            }
        }
        $hasFiles = !empty($fileFields);

        $axisOffsets = array();

        foreach (MappingTmpAttributes::getAll() as $mapping) {
            $axes = explode(',', $mapping['axis']);
            foreach ($axes as $axis) {
                if (array_key_exists($axis, $offsets)) {
                    $axisOffsets[$mapping['code']][$axis] = $offsets[$axis];
                }
            }
        }

        $defaultCurrency = Currency::getDefaultCurrency()->iso_code;

        if (isset($offsets['price-' . $defaultCurrency])) {
            $priceOffset = $offsets['price-' . $defaultCurrency];
            unset($offsets['price-' . $defaultCurrency]);
        } elseif (isset($offsets['price'])) {
            $priceOffset = $offsets['price'];
            unset($offsets['price']);
        } else {
            $priceOffset = 'zeroValue';
        }

        $this->_getLangsInCsv($headers);

        $featureOffsets = $this->_getFeatureOffsets();

        $this->_mapOffsets('Product', MappingProducts::getAllPrestashopFields(), array('image'));

        $defaultLangId     = Configuration::get('PS_LANG_DEFAULT');
        $processedProducts = array();

        $referenceOffset     = isset($this->_offsets['default']['reference']) ?           $this->_offsets['default']['reference'] :           'emptyValue';
        $ean13Offset         = isset($this->_offsets['default']['ean13']) ?               $this->_offsets['default']['ean13'] :               'emptyValue';
        $idShopDefaultOffset = isset($this->_offsets['default']['id_shop_default']) ?     $this->_offsets['default']['id_shop_default'] :     'emptyValue';
        $categoryOffset      = isset($this->_offsets['default']['id_category_default']) ? $this->_offsets['default']['id_category_default'] : 'emptyValue';
        $nameOffsets         = isset($this->_offsets['lang']['name']) ?                   $this->_offsets['lang']['name'] :                   array($defaultLangId => 'emptyValue');

        unset(
            $this->_offsets['default']['reference'],
            $this->_offsets['default']['ean13'],
            $this->_offsets['default']['id_shop_default'],
            $this->_offsets['default']['id_category_default'],
            $this->_offsets['lang']['name']
        );

        //Will contain the list of associations that could not be created when the object was
        $missingAccessories = array();

        $errorCount     = 0;
        $lastErrorCount = 0;

        $importControllerReflection = new ReflectionClass('AdminImportController');

        $copyImgMethod = $importControllerReflection->getMethod('copyImg');
        $copyImgMethod->setAccessible(true);

        $maxFeatureValueSize = FeatureValue::$definition['fields']['value']['size'];

        foreach ($dataLines as $line => $data) {
            $nextFile = $reader->getCurrentFileName($line);
            if ($nextFile != $currentFile) {
                $treatmentResult = 1;
                if($errorCount > $lastErrorCount)
                    $treatmentResult = 0;
                $this->_mover->finishAction(basename($currentFile), $treatmentResult, 'import');
                $lastErrorCount = $errorCount;
                $currentFile    = $nextFile;
            }

            if ($data === false || $data == array(null))  {
                $this->logError('Product line wrong, skipping : ' . ($line + 2));
                $errorCount++;
                continue;
            }

            $data      = $this->_cleanDataLine($data);
            $reference = $data[$referenceOffset];
            $groupCode = $data[$this->_offsets['special']['groups']];

            // QUICK FIX: Helper for Stock Update
            $dataKeyValue = array_combine($headers, $data);
            
            $data['emptyValue'] = '';
            $data['zeroValue']  = 0;

            if (empty($reference)) {
                $this->logError('Missing reference on line ' . ($line + 2));
                $errorCount++;
                continue;
            } elseif (!empty($groupCode) && !array_key_exists($groupCode, $axisOffsets)) {
                $this->logError('Variant group ' . $groupCode . ' does not exist, skipping product ' . $reference);
                $errorCount++;
                continue;
            } elseif (_PS_MODE_DEV_) {
                $this->log('Importing ' . $reference);
            }

            //Is out of the product update because it is needed even if the line is only a combination of an existing product
            $ean13 = $data[$ean13Offset];
            if (!Validate::isEan13($ean13)) {
                $this->logNotification($reference . ' : ean13 not valid');
                $errorCount++;
                $ean13 = '';
            }

            $isNewProduct = true;
            $knownProduct = false;

            if (empty($groupCode)) {
                $productId = $this->_getProductIdByReference($reference);
            } else {
                $productId = $this->_getProductIdByReference($groupCode);
            }

            if ($productId != false) {
                $knownProduct = true;
                if (isset($processedProducts[$productId])) {
                    $isNewProduct = false;
                    $product      = $processedProducts[$productId];
                } else {
                    $product = new Product($productId);
                }
            } else {
                $product = new Product();
            }

            $defaultShopId = (!empty($data[$idShopDefaultOffset]) ? $data[$idShopDefaultOffset] : Context::getContext()->shop->id);
            Shop::setContext(Shop::CONTEXT_SHOP, $defaultShopId);

            if ($isNewProduct) {
                foreach ($this->_offsets['lang'] as $field => $offsets) {
                    foreach ($offsets as $idLang => $offset) {
                        $product->{$field}[$idLang] = $data[$offset];
                    }
                }

                foreach ($this->_offsets['default'] as $field => $offset) {
                    $product->{$field} = $data[$offset];
                }

                foreach ($this->_offsets['date'] as $field => $offset) {
                    if (Validate::isDate($data[$offset])) {
                        $product->{$field} = $data[$offset];
                    } else {
                        $this->logError($reference . ' : wrong date format for field ' . $field . ' : ' . $data['offset']);
                        $errorCount++;
                    }
                }

                //Adding missing attributes and default values for some
                $product->ean13 = $ean13;

                if (empty($groupCode)) {
                    $product->reference = $reference;
                    $emptyName = true;
                    foreach ($nameOffsets as $idLang => $nameOffset) {
                        if (!empty($data[$nameOffset])) {
                            $product->name[$idLang] = $data[$nameOffset];
                            $emptyName              = false;
                        }
                    }
                } else {
                    $product->reference = $groupCode;
                    if (!is_array($product->name)) {
                        $product->name = array($defaultLangId => $product->name);
                    }

                    $emptyName = !array_filter($product->name);
                }

                if ($emptyName) {
                    $product->name[$defaultLangId] = $reference;
                }

                //link_rewrites are needed so if they are not present, we set them from the name
                if (!is_array($product->link_rewrite)) {
                    $product->link_rewrite = array($defaultLangId => $product->link_rewrite);
                }

                if (!array_filter($product->link_rewrite)) {
                    $links = array();
                    foreach ($product->name as $idLang => $value) {
                        $links[$idLang] = Tools::link_rewrite($value);
                    }
                    $product->link_rewrite = $links;
                }

                if (empty($data[$categoryOffset])) {
                    $categories    = array();
                    $categoryCodes = explode(',', $data[$this->_offsets['special']['categories']]);

                    foreach ($categoryCodes as $categoryCode) {
                        $categoryId = (int)MappingCodeCategories::getIdByCode($categoryCode);
                        if ($categoryId > 0) {
                            $categories[] = $categoryId;
                        } else {
                            $this->logError($reference . ' : category ' . $categoryCode . ' does not exist');
                            $errorCount++;
                        }
                    }
                } else {
                    $categories = preg_split('#,#', $data[$categoryOffset], -1, PREG_SPLIT_NO_EMPTY);
                }

                if (!empty($categories)) {
                    $product->id_category_default = $categories[0];
                } else {
                    $product->id_category_default = 2;
                }

                if (!empty($groupCode)) {
                    $product->price = 0;
                } else {
                    $product->price = $data[$priceOffset];
                }

                switch ($product->visibility) {
                    case "1":
                        $product->visibility = "none";
                        break;
                    case "2":
                        $product->visibility = "catalog";
                        break;
                    case "3":
                        $product->visibility = "search";
                        break;
                    default:
                        $product->visibility = "both";
                        break;
                }

                if (!isset($this->_offsets['default']['shop'])) {
                    $product->shop = $defaultShopId;
                }

                if (!isset($this->_offsets['default']['id_shop_list'])) {
                    $product->id_shop_list[] = $defaultShopId;
                }

                // Force re-indexing search process with new datas
                $product->indexed = 0;

                //Must be true if product is available for order
                $product->show_price = ($product->available_for_order || $product->show_price);

                try {
                    if (!$product->save()) {
                        $this->logError($reference . ' : could not be saved');
                        $errorCount++;
                        continue;
                    } elseif (_PS_MODE_DEV_) {
                        $this->log($reference . ' : saved');
                    }
                } catch (PrestaShopDatabaseException $e) {
                    $this->logError($e->getMessage());
                    $errorCount++;
                    continue;
                }

                //Product has been saved
                $processedProducts[$product->id] = $product;

                // QUICK FIX: Save Stock for non Variant products
                if (isset($dataKeyValue['stock'])) {
                    $quantity = ($dataKeyValue['stock']) ? $dataKeyValue['stock'] : $this->_quantityDefault;
                    StockAvailable::setQuantity($product->id, 0, $quantity, $defaultShopId);
                }
                
                // Save Features
                if ($this->_resetFeatures) {
                    if (!$product->deleteFeatures()) {
                        $this->logError($reference . ' : could not remove features');
                        $errorCount++;
                    }
                }

                foreach ($featureOffsets['select'] as $featureCode => $featureInfos) {
                    if (!empty($groupCode) && array_key_exists($featureCode, $axisOffsets[$groupCode])) {
                        continue; //Skipping variant attributes
                    }

                    $featureValueCode = $data[$featureInfos['offset']];

                    if (!empty($featureValueCode)) {
                        $featureValueId   = MappingCodeFeatureValues::getIdByCodeAndFeature($featureValueCode, $featureCode);

                        if (!$featureValueId) {
                            $this->logError($reference . ' : feature value ' . $featureValueCode . ' does not exist for feature ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        if (!Product::addFeatureProductImport($product->id, $featureInfos['id_feature'], $featureValueId)) {
                            $this->logError($reference . ' : could not associate to feature value ' . $featureValueCode . ' for feature ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        if (_PS_MODE_DEV_) {
                            $this->log($reference . ' : feature value ' . $featureValueCode . ' added for feature ' . $featureCode);
                        }
                    }
                }

                foreach ($featureOffsets['multiSelect'] as $featureCode => $featureInfos) {
                    if (empty($data[$featureInfos['offset']])) {
                        continue;
                    }

                    $featureValueCodes = explode(',', $data[$featureInfos['offset']]);

                    $values       = array();
                    $valueLengths = array();
                    $emptyValue   = true;

                    foreach ($featureValueCodes as $featureValueCode) {
                        $featureValueId = MappingCodeFeatureValues::getIdByCodeAndFeature($featureValueCode, $featureCode);

                        if (!$featureValueId) {
                            $this->logError($reference . ' : feature value ' . $featureValueCode . ' does not exist for feature ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        $featureValue = new FeatureValue($featureValueId);
                        foreach ($featureValue->value as $langId => $value) {
                            if (!array_key_exists($langId, $valueLengths)) {
                                $valueLengths[$langId] = -2;
                            }

                            $newLength = $valueLengths[$langId] + strlen($value) + 2;
                            if ($newLength > $maxFeatureValueSize) {
                                $this->logError($product->reference . ' : can not add value ' . $value . ' to ' . $featureCode . ' : maximum length reached');
                                $errorCount++;
                            } else {
                                if (!array_key_exists($langId, $values)) {
                                    $values[$langId] = $value;
                                } else {
                                    $values[$langId] .= ', ' . $value;
                                }

                                $valueLengths[$langId] = $newLength;
                                $emptyValue = false;
                            }
                        }
                    }

                    if (!$emptyValue) {
                        if ($this->_saveCustomFeature($product, $featureInfos['id_feature'], $featureCode, $values) && _PS_MODE_DEV_) {
                            $this->log($reference . ' : custom feature value ' . $values[$defaultLangId] . ' added for feature ' . $featureCode);
                        }
                    }
                }

                //Custom feature values
                foreach ($featureOffsets['default'] as $featureCode => $featureInfos) {
                    $featureValues = array();
                    $emptyValue    = true;

                    if (is_array($featureInfos['offset'])) {
                        foreach ($featureInfos['offset'] as $idLang => $offset) {
                            $featureValues[$idLang] = $data[$offset];
                            $emptyValue &= empty($data[$offset]);
                        }
                    } else {
                        $featureValues[$defaultLangId] = $data[$featureInfos['offset']];
                        $emptyValue = empty($data[$featureInfos['offset']]);
                    }

                    if (!$emptyValue) {
                        if ($this->_saveCustomFeature($product, $featureInfos['id_feature'], $featureCode, $featureValues) && _PS_MODE_DEV_) {
                            $this->log($reference . ' : custom feature value ' . $featureValues[$defaultLangId] . ' added for feature ' . $featureCode);
                        }
                    }
                }

                //Save Associations Categories
                if (!$product->updateCategories($categories)) {
                    $this->logError($reference . ' : could not update categories');
                    $errorCount++;
                }

                if ($knownProduct) {
                    if ($this->_resetImages) {
                        if (!$product->deleteImages()) {
                            $this->logError($reference . ' : could not delete images');
                            $errorCount++;
                        } elseif (_PS_MODE_DEV_) {
                            $this->log($reference . ' : images deleted');
                        }
                    }

                    if (!empty($groupCode) && $this->_resetCombinations) {
                        if (!$product->deleteProductAttributes()) {
                            $this->logError($reference . ' : error while resetting product combinations');
                            $errorCount++;
                        } elseif (_PS_MODE_DEV_) {
                            $this->log($reference . ' : combinations deleted');
                        }
                    }
                } else {
                    //We save quantities only if the product is totally new
                    StockAvailable::setQuantity($product->id, 0, $this->_quantityDefault, $defaultShopId);
                }

                if ($hasFiles) {
                    $paths = array();

                    foreach ($fileFields as $fileField => $offset) {
                        $filePath = $data[$offset];
                        if (!empty($filePath)) {
                            $paths[] = $path . $filePath;
                        }
                    }

                    if (!$this->_updateAttachments($product, $paths, $defaultLangId)) {
                        $errorCount++;
                    }
                }

                if (
                    isset($this->_offsets['special']['cross_sell_product'])
                    || isset($this->_offsets['special']['cross_sell_group'])
                ) {
                    $product->deleteAccessories();
                }
            }

            $accessories = array();

            if (isset($this->_offsets['special']['cross_sell_product'])) {
                if (!empty($data[$this->_offsets['special']['cross_sell_product']])) {
                    $accessories = explode(',', $data[$this->_offsets['special']['cross_sell_product']]);
                }
            }

            if (isset($this->_offsets['special']['cross_sell_group'])) {
                if (!empty($data[$this->_offsets['special']['cross_sell_group']])) {
                    $accessories = array_unique(
                        array_merge(
                            $accessories,
                            explode(',', $data[$this->_offsets['special']['cross_sell_group']])
                        )
                    );
                }
            }

            if (!empty($accessories)) {
                $accessoryIds = array();

                foreach ($accessories as $accessoryReference) {
                    $accessoryId = $this->_getProductIdByReference($accessoryReference);

                    if ($accessoryId != false) {
                        $accessoryIds[] = $accessoryId;
                    } else {
                        $missingAccessories[$accessoryReference][] = $product->id;
                    }
                }

                $product->changeAccessories(array_unique($accessoryIds));

                if (_PS_MODE_DEV_) {
                    $this->log($reference . ' : product accessories saved');
                }
            }

            $addedImages = array();
            //Save Images
            if ($hasImages) {
                $isCover         = !((bool)Product::getCover($product->id));
                $highestPosition = Image::getHighestPosition($product->id);

                foreach ($pictureFields as $pictureField => $offset) {
                    $imageRelativePath = $data[$offset];
                    if (empty($imageRelativePath)) {
                        continue;
                    }
                    $imagePath = $path . $imageRelativePath;

                    if (!file_exists($imagePath)) {
                        $this->logError($reference . ' : image ' . $imagePath . ' does not exist');
                        $errorCount++;
                        continue;
                    }

                    $image             = new Image();
                    $image->id_product = $product->id;
                    $image->position   = ++$highestPosition;
                    $image->legend     = $product->name;
                    $image->cover      = $isCover;

                    if (!$image->add()) {
                        $this->logError($reference . ' : could not save image ' . $imagePath);
                        $errorCount++;
                        $highestPosition--;
                    } else {
                        if (!$copyImgMethod->invoke(null, $product->id, $image->id, $baseUrl . $imageRelativePath, 'products', true)) {
                            $this->logError($reference . ' : could not copy image ' . $imagePath);
                            $errorCount++;
                            $image->delete();
                            $highestPosition--;
                        } else {
                            if ($isCover) {
                                //Forcing cache cleaning
                                Cache::clean('Product::getCover_' . $product->id . '-' . $defaultShopId);
                            }
                            $addedImages[] = $image->id;
                        }
                    }
                    unlink($imagePath);
                    $isCover = false;
                }
                if (!empty($addedImages) && _PS_MODE_DEV_) {
                    $this->log($reference . ' : images added');
                }
            }

            //Save Combination + Quantity Stock
            if (!empty($groupCode)) {
                $attributeIds = array();

                foreach ($axisOffsets[$groupCode] as $axis => $offset) {
                    $attributeValueId = MappingCodeAttributeValues::getIdByCodeAndGroup($data[$offset], $axis);

                    if (!$attributeValueId) {
                        $this->logError($reference . ' : attribute value ' . $data[$offset] . ' does not exist for attribute ' . $axis);
                        $errorCount++;
                    } else {
                        $attributeIds[] = $attributeValueId;
                    }
                }

                $price = (isset($priceOffset) ? $data[$priceOffset] : 0);

                $this->addOrUpdateProductAttributeCombination($product, $ean13, $attributeIds, $reference, $price, $addedImages);

                if ($isNewProduct) {
                    $product->checkDefaultAttributes();
                }

                if (_PS_MODE_DEV_) {
                    $this->log($reference . ' : combination saved');
                }
            }
        }

        $accessoriesToAdd = array();

        foreach ($missingAccessories as $accessoryReference => $productIds) {
            $accessoryId = $this->_getProductIdByReference($accessoryReference);

            if ($accessoryId != false) {
                foreach ($productIds as $productId) {
                    $accessoriesToAdd[$productId][] = $accessoryId;
                }
            } else {
                $this->logError($accessoryReference . ' does not exist, accessories pointing to it can not be saved');
                $errorCount++;
            }
        }

        foreach ($accessoriesToAdd as $productId => $accessories) {
            if (isset($processedProducts[$productId])) {
                $product = $processedProducts[$productId];
            } else {
                $product = new Product($productId);
            }

            $product->changeAccessories(array_unique($accessories));
            if (_PS_MODE_DEV_) {
                $this->log($product->reference . ' : missing accessories added');
            }
        }

        $endStatus = ($errorCount == $lastErrorCount);
        $this->_mover->finishAction(basename($currentFile), $endStatus);
        $folderUtil->delTree($path, false);

        $this->_removeUnusedAttachments();

        // Search Indexation
        if (!Search::indexation()) {
            $this->logError('Product reindexation failed');
        } elseif (_PS_MODE_DEV_) {
            $this->log('Products reindexed');
        }

        return true;
    }

    /**
     * Get a product id given its reference or the reference of one of its combinations
     *
     * @param string $reference
     * @return int|bool
     */
    protected function _getProductIdByReference($reference)
    {
        if (strlen($reference) == 0) {
            return false;
        }

        if (array_key_exists($reference, $this->_knownProductReferences)) {
            return $this->_knownProductReferences[$reference];
        }

        $sqlReference = pSQL($reference);

        $query = new DbQuery();
        $query->select('p.id_product')
            ->from('product', 'p')
            ->leftJoin('product_attribute', 'pa', 'p.id_product = pa.id_product')
            ->where('p.reference = \''.$sqlReference.'\' OR pa.reference = \''.$sqlReference.'\'')
            ->groupBy('p.id_product')
        ;

        $id = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
        if ($id) {
            $this->_knownProductReferences[$reference] = $id;
        }

        return $id;
    }

    /**
     * Add or update a product combination
     *
     * @param Product $product
     * @param string  $attributeEan
     * @param array   $attributeValueIds , all value ids for ONE combination (example for red (value id=1), XL (value id=9) => array(array(0 => 1, 1 => 9, 2 => 3))
     * @param string  $reference
     * @param float   $price
     * @param array   $images
     *
     * @return int product attribute id if success
     */
    protected function addOrUpdateProductAttributeCombination(Product $product, $attributeEan, array $attributeValueIds, $reference, $price, $images)
    {
        if (!$product)
            return false;

        $returnId = true;

        // Image combination
        if (empty($images)) {
            $cover = Product::getCover($product->id);
            if ($cover) {
                $images[] = $cover['id_image'];
            }
        }

        $productAttributeId = $product->productAttributeExists($attributeValueIds, false, null, false, $returnId);

        if ($productAttributeId > 0) {
            $combination = new Combination($productAttributeId);
            if (!$this->_resetImages) {
                $images = array_unique(array_merge($images, $product->_getAttributeImageAssociations($productAttributeId)));
            }
        } else {
            $combination             = new Combination();
            $combination->id_product = $product->id;
            $combination->price      = $price;
            $combination->add();

            if (!$combination->id) {
                return false;
            }

            $combination->setAttributes($attributeValueIds);
            StockAvailable::setQuantity($product->id, $combination->id, $this->_quantityDefault);
        }

        $combination->reference = $reference;
        $combination->ean13     = $attributeEan;

        $combination->setImages($images);
        $combination->update();
        
        // QUICK FIX: Save Stock for non Variant products
        if (isset($dataKeyValue['stock'])) {
            $quantity = ($dataKeyValue['stock']) ? $dataKeyValue['stock'] : $this->_quantityDefault;
            StockAvailable::setQuantity($product->id, $combination->id, $quantity);
        }

        StockAvailable::setProductDependsOnStock($product->id, $product->depends_on_stock, null, $productAttributeId);
        StockAvailable::setProductOutOfStock($product->id, $product->out_of_stock, null, $productAttributeId);

        return (int)$productAttributeId;
    }

    /**
     * Add or update feature value
     *
     * @param int $featureId
     * @param array $langFeaturesValues
     *
     * @return bool|int
     * @throws PrestaShopDatabaseException
     */
    protected static function _addFeatureValue($featureId, $langFeaturesValues)
    {
        if (!$featureId || $featureId == 0 || !is_array($langFeaturesValues))
            return false;

        $sql = '
            SELECT fv.`id_feature_value`
            FROM ' . _DB_PREFIX_ . 'feature_value fv
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON (fvl.`id_feature_value` = fv.`id_feature_value`)
            WHERE fvl.`value` = \'' . pSQL(current($langFeaturesValues)) . '\'
                AND fv.`id_feature` = ' . (int)$featureId . '
                AND fvl.`id_lang` = ' . pSQL(key($langFeaturesValues)) . '
            GROUP BY fv.`id_feature_value` LIMIT 1
        ';

        $result = Db::getInstance()->executeS($sql);

        if (!$result || empty($result)) {
            $featureValue             = new FeatureValue();
            $featureValue->id_feature = (int)$featureId;
        } else {
            $featureValue             = new FeatureValue((int)$result[0]['id_feature_value']);
        }

        $featureValue->value  = $langFeaturesValues;
        $featureValue->custom = 0;

        if (!$featureValue->save()) {
            return false;
        } else {
            return $featureValue->id;
        }
    }

    protected function _getFeatureOffsets()
    {
        $features     = array(
            'select'      => array(),
            'multiSelect' => array(),
            'default'     => array()
        );

        $filter       = '#^(.+?)-(\w{2,3})$#';
        $offsetFields = array_keys($this->_offsets);

        foreach (MappingCodeFeatures::getAll() as $mapping) {
            $matching = preg_grep('#^' . $mapping['code'] . '(-\w{2,3})?$#', $offsetFields);

            if (!empty($matching)) {

                if ($mapping['type'] == 'pim_catalog_simpleselect') {
                    $featureType = 'select';
                } elseif ($mapping['type'] == 'pim_catalog_multiselect') {
                    $featureType = 'multiSelect';
                } else {
                    $featureType = 'default';
                }

                $features[$featureType][$mapping['code']]['id_feature'] = $mapping['id_feature'];

                foreach ($matching as $field) {
                    if (preg_match($filter, $field, $results)) {
                        $features[$featureType][$mapping['code']]['offset'][$this->_langs[$results[2]]] = $this->_offsets[$field];
                    } else {
                        $features[$featureType][$mapping['code']]['offset'] = $this->_offsets[$field];
                    }
                }
            }
        }
        return $features;
    }

    /**
     * Updates the attachments for a product
     *
     * @param Product $product       the product that will be updated
     * @param array   $files         list of paths for each file that will be attached to the product
     * @param int     $defaultLangId the id of the default lang
     *
     * @return bool
     */
    protected function _updateAttachments($product, $files, $defaultLangId)
    {
        $errors = 0;

        $oldAttachments = $product->getAttachments($defaultLangId);
        $attachments    = array();
        foreach ($oldAttachments as $oldAttachment) {
            $attachments[$oldAttachment['file_name']] = $oldAttachment['id_attachment'];
        }

        Attachment::deleteProductAttachments($product->id);
        $newAttachments = array();

        foreach ($files as $path) {
            $fileName = basename($path);

            if (!file_exists($path)) {
                $this->logError($product->reference . ' : attachment ' . $path . ' does not exist');
                $errors++;
                continue;
            }

            if (array_key_exists($fileName, $attachments)) {
                $attachment = new Attachment($attachments[$fileName]);
            } else {
                $attachment            = new Attachment();
                $attachment->file_name = $fileName;
                $attachment->name      = array($defaultLangId => substr($attachment->file_name, 0, 32));

                do {
                    $uniqueId = sha1(microtime());
                } while (file_exists(_PS_DOWNLOAD_DIR_ . $uniqueId));
                $attachment->file = $uniqueId;
            }

            $fileInfo         = finfo_open(FILEINFO_MIME_TYPE);
            $attachment->mime = finfo_file($fileInfo, $path);
            finfo_close($fileInfo);

            if (!rename($path, _PS_DOWNLOAD_DIR_ . $attachment->file)) {
                $this->logError($product->reference . ' : could not copy attachment ' . $attachment->file_name);
                $errors++;
            } elseif (!$attachment->save()) {
                $this->logError($product->reference . ' : could not save attachment ' . $attachment->file_name);
                $errors++;
                unlink(_PS_DOWNLOAD_DIR_ . $attachment->file);
            } else {
                $newAttachments[] = $attachment->id;

                if (_PS_MODE_DEV_) {
                    $this->log($product->reference . ' : attachment ' . $attachment->file_name . ' saved');
                }
            }
        }

        if (!empty($newAttachments)) {
            if (!Attachment::attachToProduct($product->id, $newAttachments)) {
                $this->logError($product->reference . ' : could not attach files to product');
                $errors++;
            } elseif (_PS_MODE_DEV_) {
                $this->log($product->reference . ' : files attached to product');
            }
        }

        return $errors == 0;
    }

    /**
     * Remove the attachments that are not attached to any product
     *
     * @return bool
     */
    protected function _removeUnusedAttachments()
    {
        $attachments = Db::getInstance()->executeS('
            SELECT `id_attachment`
            FROM `' . _DB_PREFIX_ . 'attachment`
            WHERE `id_attachment` NOT IN (
                SELECT DISTINCT (`id_attachment`)
                FROM `' . _DB_PREFIX_ . 'product_attachment`
            )'
        );

        if ($attachments === false) {
            $this->logError('Could not retrieve unused attachments');
            return false;
        } else {
            $result = true;

            if (!empty($attachments)) {
                foreach ($attachments as $attachmentId) {
                    $attachment = new Attachment($attachmentId['id_attachment']);
                    if (!$attachment->delete()) {
                        $this->logError('Could not delete attachment ' . $attachment->file_name);
                        $result = false;
                    }
                }

                if (_PS_MODE_DEV_) {
                    $this->log('Unused attachments removed');
                }
            }
            return $result;
        }
    }

    /**
     * Saves a custom feature for a product
     *
     * @param Product $product
     * @param int     $featureId
     * @param string  $featureCode
     * @param array   $values
     *
     * @return bool
     */
    protected function _saveCustomFeature($product, $featureId, $featureCode, $values)
    {
        try {
            $featureValueId = $this->_addFeatureValue($featureId, $values);
        } catch (PrestaShopException $e) {
            $this->logError('Error while saving feature value for ' . $featureCode . ' : ' . $e->getMessage());
            return false;
        }

        if (!$featureValueId) {
            $this->logError($product->reference . ' : could not save feature value for ' . $featureCode);
            return false;
        }

        if (!Product::addFeatureProductImport($product->id, $featureId, $featureValueId)) {
            $this->logError($product->reference . ' : could not associate to feature value ' . $featureCode . ' for feature ' . $featureCode);
            return false;
        }

        return true;
    }
}
