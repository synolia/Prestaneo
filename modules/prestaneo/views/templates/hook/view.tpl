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

<div class="panel">
    <h3><i class="icon-list-ul"></i> {l s='Actions' mod=$mod_sync_name}
        <span class="panel-heading-action">
	    </span>
    </h3>
        <div class="row panel">
            <div class="col-md-8">
                <b>{l s='Clean history files ' mod=$mod_sync_name}</b>
                <br /><br />
                {l s='Call URL : ' mod=$mod_sync_name} <a target="_blank" href="{$cronpath}?action=cleaner">
                    {$cronpath}?action=cleaner
                </a>
            </div>
        </div> <br />
</div>

