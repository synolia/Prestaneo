<div class="clearfix"></div>

{if !empty($reports)}
    <!-- Data available -->
    <script type="text/javascript" src="http://www.google.com/jsapi"></script>
    
        <script language=javascript>
            // Loading Google Visualization Tools
            google.load("visualization", "1");
            google.setOnLoadCallback(drawTimeline);
            function drawTimeline() {
                // Create and populate a data table.
                var data = new google.visualization.DataTable();
                data.addColumn('datetime', 'start');
                data.addColumn('datetime', 'end');
                data.addColumn('string', 'content');
                data.addRows([
                     {foreach from=$reports item=report name=arr_report} 
                        [new Date({$report.date_add_year}, {$report.date_add_month}, {$report.date_add_day}, {$report.time_hour}, {$report.time_min}, {$report.time_sec}), new Date({$report.date_end_year}, {$report.date_end_month}, {$report.date_end_day}, {$report.time_hour_end}, {$report.time_min_end}, {$report.time_sec_end}),'{$report.type}' + '<br /> Début : ' + String("{$report.time_hour}") + "h" + String("{$report.time_min}") + "m" + String("{$report.time_sec}") + 's <br /> Fin : ' + String("{$report.time_hour_end}") + "h" + String("{$report.time_min_end}") + "m" + String("{$report.time_sec_end}") + 's <br /> ' +  {if $report.status}  'Statut <i class="icon-ok-sign"></i>'  {else}  'Statut <i class="icon-remove"></i>'  {/if}  ],
                     {/foreach} 
                 ]);
                // specify options
                var options = {
                    "locale":   "fr",
                    "width":    "100%",
                    "height":   "250px",
                    "style":    "box"
                };
                // Instantiate our timeline object.
                var timeline = new links.Timeline(document.getElementById('timelinereports'));
                // Draw our timeline with the created data and options
                timeline.draw(data, options);
            }
        </script>
    
    <section id="syncdashreport" class="panel widget{if $allow_push} allow_push{/if}">
        <header class="panel-heading">
            <i class="icon-bar-chart"></i> {$sync_name} {l s='Status Report' mod=$mod_sync_name}
            <span class="panel-heading-action">
		</span>
        </header>
        <div id="syncdashreport_row" class="row">
            <p>{l s='Total : ' mod=$mod_sync_name} {$nb_reports}</<p>
            <div id="timelinereports"></div>
            <div id="syncdashreport_legend">
                {if $show_status eq 1 OR $show_status eq 3} <i class="icon-ok-sign"></i> Success {/if}
                {if $show_status > 1}<i class="icon-remove"></i> Error {/if}
             </div>
        </div>
    </section>
{else}
    <!-- No data available -->
    <section id="syncdashreport" class="panel widget{if $allow_push} allow_push{/if}">
        <header class="panel-heading">
            <i class="icon-bar-chart"></i> {$sync_name} {l s='Report' mod=$mod_sync_name}
        </header>
        <div id="syncdashreport_row" class="row">
            <i class="icon-remove"></i> {l s='Sorry, no data available.' mod=$mod_sync_name}
        </div>
    </section>
{/if}