<div class="panel parts col-md-12">
    <h3><i class="icon-list-ul"></i> {l s='Informations' mod={$mod_name}}
        <span class="panel-heading-action">
    </span>
    </h3>
    <div class="module_confirmation conf confirm alert alert-warning">
        <p>{l s='In the headers of csv columns attention to local : « -fr_FR » and no « _fr-FR »'}</p>
    </div>
</div>

{$formSettingsCsv}
{$formProductsImport}

{include file="./mappingForm.tpl"
    title='Mapping Import Category'
    minRequiredFields=$categoryMinRequiredFields
    prestashopFields=$categoryPrestashopFields
    mappings=$mappingCategory
    inputSuffix='category'
    submitName='submitMappingCategories'
}

{include file="./mappingForm.tpl"
    title='Mapping Import Variant'
    minRequiredFields=$attributeMinRequiredFields
    prestashopFields=$attributePrestashopFields
    mappings=$mappingAttribute
    inputSuffix='attribute'
    submitName='submitMappingAttributes'
}

{include file="./mappingForm.tpl"
    title='Mapping Import Attributes'
    minRequiredFields=$featureMinRequiredFields
    prestashopFields=$featurePrestashopFields
    mappings=$mappingFeature
    inputSuffix='feature'
    submitName='submitMappingFeatures'
}

{include file="./mappingForm.tpl"
    title='Mapping Import Product'
    minRequiredFields=$productMinRequiredFields
    prestashopFields=$productPrestashopFields
    mappings=$mappingProduct
    inputSuffix='product'
    submitName='submitMappingProducts'
}