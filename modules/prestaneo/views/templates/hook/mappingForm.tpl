<div class="panel parts col-md-12">
    <h3><i class="icon-list-ul"></i> {l s={$title} mod={$mod_name}}
        <span class="panel-heading-action">
    </span>
    </h3>
    <div class="bootstrap">
        <div class="module_confirmation conf confirm alert alert-info">
            <button type="button" class="hidden close" data-dismiss="alert">&times;</button>
            <p> {l s='Minimum required fields :' mod='{$mod_name}'} </p>
            <ul>
                {foreach from=$minRequiredFields item=field}
                    <li> {$field} </li>
                {/foreach}
            </ul>
        </div>
    </div>
    <form class="form_mapping" method="post">
        <table class="table_mapping">
            <thead>
            <tr>
                <th> {l s='FIELD AKENEO' mod='{$mod_name}'} </th>
                <th> {l s='FIELD PRESTASHOP' mod='{$mod_name}'} </th>
                <th> {l s='REQUIRED' mod='{$mod_name}'} </th>
                <th>
                    <button title="Check checkboxes and save configuration to remove a mapping" type="button"
                            class="removeMapping"
                            data-placement="bottom">
                        <i class="icon-remove"></i>
                    </button>
                </th>
                <th> &nbsp; </th>
            </tr>
            </thead>
            <tbody>
                {if !$mappings}
                    {* Default values to display a new line if the mapping is empty *}
                    {$mappings = [
                        [
                            'id_mapping'       => 0,
                            'required'         => false,
                            'champ_akeneo'     => '',
                            'champ_prestashop' => ''
                        ]
                    ]}
                {/if}
                {foreach from=$mappings item=mapping}
                    <tr class="tr_clone">
                        <td>
                            <input type="text" name="akeneo_{$inputSuffix}[]" value="{$mapping.champ_akeneo}" class="akeneoField" />
                            <input type="hidden" name="ids_{$inputSuffix}[]" value="{$mapping.id_mapping}" />
                        </td>
                        <td>
                            <select class="selectMapping" name="prestashop_{$inputSuffix}[]">
                                {foreach from=$prestashopFields item=fieldGroup}
                                    <optgroup label="{$fieldGroup['label']}">
                                        {foreach from=$fieldGroup['input'] key=key item=field}
                                            <option value="{$key}" {if $field.field eq $mapping.champ_prestashop}selected="selected"{/if}> {if isset($field.title)}{$field.title}{else}{$key}{/if} </option>
                                        {/foreach}
                                    </optgroup>
                                {/foreach}
                            </select>
                        </td>
                        <td>
                            <span class="switch prestashop-switch fixed-width-sm">
                                <input type="checkbox" name="required_{$inputSuffix}[]" id="required_{$inputSuffix}_{$mapping.id_mapping}_on" value="1" {if $mapping.required}checked{/if}>
                                <label for="required_{$inputSuffix}_{$mapping.id_mapping}_on" class="radioCheck">
                                    Oui
                                </label>
                                <input type="checkbox" name="required_{$inputSuffix}[]" id="required_{$inputSuffix}_{$mapping.id_mapping}_off" value="0" {if !$mapping.required}checked{/if}>
                                <label for="required_{$inputSuffix}_{$mapping.id_mapping}_off" class="radioCheck">
                                    Non
                                </label>
                                <a class="slide-button btn"></a>
                            </span>
                        </td>
                        <td>
                            <span class="remove" {if $mapping.id_mapping == 0}style="display: none;" {/if}>
                                <input type="checkbox" id="chk{$mapping.id_mapping}" name="remove_{$inputSuffix}[{$mapping.id_mapping}]" value="{$mapping.id_mapping}" />
                                <label class="chk_remove" for="chk{$mapping.id_mapping}"> </label>
                            </span>
                        </td>
                        <td>
                            <p class="info hidden"> <i class="icon-info-sign"></i> {l s='You should avoid using \'-\' in Akeneo fields as they can be mistaken for language codes' mod='{$mod_name}'} </p>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        <input class="btn custom save" type="submit" name="{$submitName}" value="Save" />
        <input class="btn custom new addNewMapping" type="button" name="addNewMapping" value="Add a new matching fields" />
    </form>
</div>