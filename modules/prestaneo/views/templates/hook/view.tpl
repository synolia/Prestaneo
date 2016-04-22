{if (isset($form_errors)) && (count($form_errors) > 0)}
    <div class="alert alert-danger">
        <h4>{l s='Error!' mod='cronjobs'}</h4>
        <ul class="list-unstyled">
            {foreach from=$form_errors item='message'}
                <li>{$message|escape:'htmlall':'UTF-8'}</li>
            {/foreach}
        </ul>
    </div>
{/if}

<script>
    function changeSyncImportLanguage(elementId, idLang)
    {
        jQuery('input#'+elementId+'[name="id_lang"]').val(idLang);
        return hideOtherLanguage(idLang);
    }

</script>

<!-- imports -->
{if $import|@count > 0}
    {include file="./imports.tpl"}
{/if}

{include file="./actions.tpl"}

