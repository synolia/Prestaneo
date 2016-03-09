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