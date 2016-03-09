String.prototype.ucfirst = function() {
    return this.charAt(0).toUpperCase() + this.slice(1);
}

$(document).ready(function() {

    function initCronsManagement()
    {

        /**
         * Start by hiding Cron Lists and Cron create Forms
         */
        $('.cron-table, .cron-form').hide();

        $('.cron-btn').click(function (event) {
            var currentKey = $(this).data('key');
            var currentTable = $('#list-cron-table-' + currentKey);

            if(!currentTable.is(":visible"))
                currentTable.show();
            else
                currentTable.hide();
        });

        $('.add-cron-btn').click(function (event) {
            var currentKey = $(this).data('key');
            var urlCron = $('#url-cron-' + currentKey).attr('href');
            var currentForm = $('#create-form-cron-' + currentKey);
            currentForm.find('#task').val(urlCron).prop("readonly", true);
            currentForm.find('#sync_cron_type').val(currentKey.substring(0, currentKey.indexOf('-')).ucfirst()).prop("readonly", true);
            currentForm.find('#sync_cron_action').val($('#name-item-' + currentKey).val().ucfirst()).prop("readonly", true);

            if(!currentForm.is(":visible")){
                currentForm.show();
            }
        });
    }

    initCronsManagement();
});