<!-- imports -->
{if $import|@count > 0}
    <div class="panel parts">
        <h3><i class="icon-list-ul"></i> {l s='Import' mod=$mod_sync_name}
            <span class="panel-heading-action">
	    </span>
        </h3>
        {foreach $import as $key=>$item}
            <div class="row panel part col-md-5 {$key}">
                <div class="row">
                    <div class="col-md-8">
                        <i class="icon-{$item.icon} top"></i> <b>{l s='Import of ' mod=$mod_sync_name} {$item.name}</b>
                        <br /><br />
                        {l s='Last status : ' mod=$mod_sync_name}

                        {if $item.status->id_status}
                            {if $item.status->status}
                                <span class="badge badge-success">{l s='Success -' mod=$mod_sync_name} {dateFormat date=$item.status->date_add full=1}</span>
                            {else}
                                <span class="badge badge-critical">{l s='Error -' mod=$mod_sync_name} {dateFormat date=$item.status->date_add full=1}</span>
                            {/if}
                        {else}
                            <span class="badge badge-warning">{l s='Not run' mod=$mod_sync_name}</span>
                        {/if}

                        <br /><br />
                        {if isset($item.folder)}
                            {l s='File folder : ' mod=$mod_sync_name} {$item.folder}/
                            <br /><br />
                        {/if}
                        {l s='Open log : ' mod=$mod_sync_name} <a target="_blank" href="{$path}/import/{$item.name}/log.txt">log.txt</a>
                        <br /><br />
                        {l s='Call URL : ' mod=$mod_sync_name} <a target="_blank" id="url-cron-import-{$key}" href="{$cronpath}?action={$item.name}&type=import{if isset($from)}&from={$from}{/if}{if isset($to)}&to={$to}{/if}">
                            {$cronpath}?action={$item.name}&type=import{if isset($from)}&from={$from}{/if}{if isset($to)}&to={$to}{/if}
                        </a>
                    </div>
                    <div class="col-md-4">
                        <div class="btn-group-action pull-right">
                            {if isset($from) || isset($to)}
                                <a class="btn btn-default" href="">
                                    <i class="icon-download"></i>
                                    {l s='Clear dates' mod=$mod_sync_name}
                                </a>
                            {/if}
                            <a class="btn btn-default" href="{$link->getAdminLink('AdminSyncImports')}&configure={$mod_sync_name}&launch={$item.name}&type=import{if isset($from)}&from={$from}{/if}{if isset($to)}&to={$to}{/if}">
                                <i class="icon-download"></i>
                                {l s='Launch' mod=$mod_sync_name}
                            </a>
                            {if $mod_cron_enabled}
                                <a class="btn btn-default cron-btn" id="list-cron-list-import-{$key}" data-key="import-{$key}">
                                    <i class="icon-download"></i>
                                    {l s='List Cron Tasks' mod=$mod_sync_name} ({$item.crons|count})
                                </a>
                            {/if}
                        </div>
                        <div class="form-group col-md-8 pull-right" style="padding-right: 0;" >
                            {assign var='input_name' value="file_import_{$item.name}"}
                            <form method="post" id="{$input_name}_{$language.id_lang}-form" action="{$link->getAdminLink('AdminModules')}&configure={$mod_sync_name}&post_launch={$item.name}&type=import{if isset($from)}&from={$from}{/if}{if isset($to)}&to={$to}{/if}" enctype="multipart/form-data" class="item-form defaultForm  form-horizontal">
                                <input id="{$input_name}_{$language.id_lang}" type="file" name="{$input_name}_{$language.id_lang}" class="hide" />
                                <input type="hidden" id="{$input_name}_{$language.id_lang}-lang" name="id_lang" class="hide sync_language" value="{$language.id_lang}"/>
                                <div class="dummyfile input-group pull-right">
                                    <span class="input-group-addon"><i class="icon-file"></i></span>
                                    <input id="{$input_name}_{$language.id_lang}-name" type="text" class="disabled" name="filename" readonly />
                            <span class="input-group-btn">
                                <button id="{$input_name}_{$language.id_lang}-selectbutton" type="button" name="submitAddAttachments" class="btn btn-default">
                                    <i class="icon-folder-open"></i> {l s='Choose a file'}
                                </button>
                                {if $languages|count > 0}
                                    <button type="button" class="btn btn-default dropdown-toggle" tabindex="-1" data-toggle="dropdown">
                                        {$language.iso_code}
                                        <span class="caret"></span>
                                    </button>
                                    <ul class="dropdown-menu" style="float: right; right: 0;">
                                        {foreach from=$languages item=lang}
                                            <li><a href="javascript:changeSyncImportLanguage($(this), {$lang.id_lang});" tabindex="-1">{$lang.name}</a></li>
                                        {/foreach}
                                    </ul>
                                {/if}
                                <button id="{$input_name}_{$language.id_lang}-sendbutton" type="button" name="submitAttachments" class="btn btn-default">
                                    <span class="icon-upload"></span>
                                </button>
                            </span>
                                </div>
                                <script>
                                    $(document).ready(function(){
                                        $('#{$input_name}_{$language.id_lang}-selectbutton').click(function(e){
                                            $('#{$input_name}_{$language.id_lang}').trigger('click');
                                        });
                                        $('#{$input_name}_{$language.id_lang}').change(function(e){
                                            var val = $(this).val();
                                            var file = val.split(/[\\/]/);
                                            $('#{$input_name}_{$language.id_lang}-name').val(file[file.length-1]);
                                        });
                                        $('#{$input_name}_{$language.id_lang}-sendbutton').click(function(e){
                                            $('#{$input_name}_{$language.id_lang}-form').submit();
                                        });
                                    });
                                </script>
                            </form>
                        </div>
                    </div>
                </div>
                {if $mod_cron_enabled}
                    <div id="list-cron-table-import-{$key}" class="row panel cron-table">
                        <div class="panel-heading">
                            <div class="title-cron-list">
                                <i class="icon-list-ul"></i>
                                {l s='Cron List' mod=$mod_sync_name}
                            </div>

                    <span class="panel-heading-action" >
                        <a class="btn btn-default add-cron-btn" id="add-cron-button-import-{$key}" data-key="import-{$key}">
                            <i class="icon-plus-square"></i>
                            {l s='Add Cron' mod=$mod_sync_name}
                        </a>
                    </span>
                        </div>
                        <table style="width:100%">
                            {if $item.crons}
                                {foreach $item.crons as $cronKey=>$cron}
                                    <tr>
                                        <td>{$cron.id_cronjob}</td>
                                        <td>{$cron.description}</td>
                                        <td style="text-align: right;">
                                            <a class="btn btn-default" href="{$link->getAdminLink('AdminModules')}&configure=cronjobs">
                                                <i class="icon-download"></i>
                                                {l s='Configure' mod=$mod_sync_name}
                                            </a>
                                        </td>
                                    </tr>
                                {/foreach}
                            {/if}
                        </table>
                    </div>
                    <div id="create-form-cron-import-{$key}" class="cron-form">
                        {$form_cron_create}
                    </div>
                    <input id="name-item-import-{$key}" type="hidden" value="{$item.name}"/>
                {/if}
            </div> {* <br /><br /> *}
            {if $key%2 == 0}
                <div class="col-md-2">

                </div>
            {/if}
        {/foreach}
    </div>
{/if}