$(function () {

    var addNewMapping = (function () {
        //No need to keep one var per form, as it is only used to differentiate the ids of each switch for their respective labels
        var nextId = -1;

        return function () {
            var $table  = $(this).parents('.panel').find('.table_mapping>tbody');
            var $newRow = $table.find('.tr_clone').first().clone();

            $newRow.find('input, select').val('');
            $newRow.find('.remove').hide();

            var $checkboxes = $newRow.find('input[name^="required"]');
            var $labels     = $checkboxes.next();

            var beginId = $checkboxes.first().attr('name');
            beginId = beginId.substr(0, beginId.length - 2);

            $checkboxes.first().prop('checked', false).attr('id', beginId + nextId + '_on').val('1');
            $checkboxes.last().prop('checked', true).attr('id', beginId + nextId + '_off').val('0');

            $labels.first().attr('for', beginId + nextId + '_on');
            $labels.last().attr('for', beginId + nextId + '_off');

            $newRow.appendTo($table);

            nextId--;
        }
    })();

    // New entry = new mapping line
    $('button.addNewMapping').on('click', addNewMapping);

    // Tooltip info remove mapping
    $('form.form_mapping .removeMapping').tooltip();

    // Animation
    $('form.form_mapping .alerts .alert').delay(2000).fadeOut('slow');

    $('.table_mapping').on('change', '.akeneoField', function() {
        $(this).closest('tr').find('td:last-child p').toggleClass('hidden', $(this).val().indexOf('-') === -1);
    });

    $('.table_mapping').on('click', '.prestashop-switch input[type="checkbox"]', function (e) {
        if (!$(this).prop('checked')) {
            e.preventDefault();
            return false;
        }

        $(this).siblings('input').prop('checked', false);
    })
});