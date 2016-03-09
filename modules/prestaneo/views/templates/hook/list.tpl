<div class="panel"><h3><i class="icon-list-ul"></i> {$listTitle}
    <span class="panel-heading-action">
	</span>
    </h3>
    <div id="slidesContent">
        <div id="slides">
            {foreach from=$items item=item}
                <div id="groups_{$item.id}" class="panel">
                    <div class="row">
                        <div class="col-lg-2">
                            <span>&nbsp;</span>
                        </div>
                        <div class="col-md-8">
                            <h4 class="pull-left">{$item.name}</h4>
                        </div>
                        <div class="col-md-2">
                            <div class="btn-group-action pull-right">
                                <a class="btn btn-default"
                                   href="{$link->getAdminLink('AdminModules')}&configure={$mod_sync}{$item.editParameters}">
                                    <i class="icon-edit"></i>
                                    {l s='Edit' mod=$mod_sync}
                                </a>
                                <a class="btn btn-default"
                                   href="{$link->getAdminLink('AdminModules')}&configure={$mod_sync}{$item.deleteParameters}">
                                    <i class="icon-trash"></i>
                                    {l s='Delete' mod=$mod_sync}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            {/foreach}
        </div>
    </div>
</div>