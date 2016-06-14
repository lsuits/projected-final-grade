$(document).ready(function() {
    initializeMustMake();

    watchGradeValueInputChanges();
});

/**
 * Initialize the state of the "must make" table, and perform calculation if only one missing grade item
 */
function initializeMustMake() {
    $('#must_make').hide();
    $('#must_make').css('position', 'absolute');

    var sole_grade_element = getSoleEmptyGradeInputElement();

    recalculate(sole_grade_element);
}

/**
 * Returns an HTML input object of a single missing input if only one missing exists (false otherwise)
 */
function getSoleEmptyGradeInputElement() {
    var num_empty = null;
    var empty_elem = null;

    $(':input[id^=calc_]').each(function() {
        if ($(this).val() == '') {
            num_empty++;
            empty_elem = $(this);
        }
    });

    if (num_empty == 1) {
        return empty_elem[0];
    }

    return false;
}

/**
 * Watch for input grade values, recalculate and render output (or errors)
 */
function watchGradeValueInputChanges() {
    $(':input[id^=calc_]').keyup(function(e) {
        var updated_element = e.currentTarget;

        // get the grade item id
        var grade_item_id = $(this)[0].id.split('_')[2];

        // validate input, render line item error if necessary
        if (isValidInput(updated_element.value, e) && isInGradeRange(updated_element.id)) {
            hideError(grade_item_id);
        } else if (e.keyCode > 64 && e.keyCode < 91) {
            showError(grade_item_id);
        } else {
            return;
        }

        if (errorsExist()) {
            hideTotals();
        } else {
            recalculate(updated_element);
        }
    });
}

function mustMakeIsEnabled() {
    return $('#must_make_enabled').val() == '1' ? true : false;
}

/**
 * Checks that value is either a number or backspace with no modifier keys down
 */
function isValidInput(value, event) {
    var key = event.keyCode;

    // Integer
    if (isFinite(parseInt(String.fromCharCode(key)))) { return true }

    // Numpad keys
    if (key >= 96 && key <= 105) { return true }

    // Ignored keys: tab, backspace, etc
    if ($.inArray(key, [8, 46]) != -1) { return true }

    // Modifier Keys
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return false }

    return false;
}

/**
 * Determine if the input value is within the range allowed by it's corresponding grade item
 */
function isInGradeRange(input_element_id) {
    var grade_item_id = input_element_id.split('_')[2];

    var minmax = $('#minmax_' + grade_item_id).attr('value').split('|');
    var value = $('#' + input_element_id).val();
    var min = parseFloat(minmax[0]);
    var max = parseFloat(minmax[1]);

    if (value >= min && value <= max) {
        hideError(grade_item_id);

        return true;
    } else {
        showError(grade_item_id);

        return false;
    }
}

function showError(id) {
    $('#' + id + '_range').addClass('projected_error')
}

function hideError(id) {
    $('#' + id + '_range').removeClass('projected_error')
}

function errorsExist() {
    return !! $('.projected_error').length;
}

function hideTotals() {
    $('.categoryitem[id]').each(function(index) {
        $(this).html('<b>??</b>');
    });

    $('.courseitem[id]').each(function(index) {
        $(this).html('<b>??</b>');
    });
}

function getUngradedInputString() {
    var inputs = [];

    $('input:text[id^="calc_grade_"]').each(function(index) {
        fieldval = $(this).val() == '' ? 'switch_me' : $(this).val();
        inputs.push($(this).attr('id') + '=' + fieldval);
    });

    return inputs;
}



function positionMustMake(item_id) {
    var parent_cell = $('#calc_grade_' + item_id).parent().parent();

    var left_pos = parent_cell.position().left + parent_cell.width() + 20;

    $('#must_make').css('left', left_pos + 'px')
    $('#must_make').css('bottom', '20' + 'px')
}

function updateMustMake(data) {
    $('.must_make_table > tbody > tr > td').each(function(index) {
        var letter = data.shift();
        var limit = data.shift();

        $(this).html(letter + '  ' + limit);
    });
}

// Aggregates input data and makes the ajax request
function recalculate(elem) {
    if ( ! elem) {
        return;
    }

    var ungraded_inputs = getUngradedInputString();

    var num_empty = 0;
    var must_make_item_id = null;

    if (mustMakeIsEnabled()) {
        $('input:text[id^="calc_grade_"]').each(function() {
            if ($(this).val() == '') {
                num_empty++;
                must_make_item_id = $(this).attr('id').split('_')[2];
            }
        });

        if (num_empty == 1) {
            ungraded_inputs.push('must_make=' + must_make_item_id);
        }
    }

    $.post('rpc.php', { inputdata: ungraded_inputs.join('|') }, function(data) {
        var parts = data.split('|');

        // cleans the array to ignore any "strict" errors that are returned by the ajax post
        parts = cleanParts(parts);

        if (mustMakeIsEnabled() && num_empty == 1) {
            positionMustMake(must_make_item_id);

            var must_make_data = parts.pop().split('=')[1].split(',');

            updateMustMake(must_make_data);
        }

        for(var i = 0; i < parts.length; i++) {
            var tmp = parts[i].split('=');
            var elem = $('#' + tmp[0]);

            if(elem != null) {
                elem.html(tmp[1]);
            }
        }
    });

    if (mustMakeIsEnabled()) {
        $('.must_make_arrow').remove();

        if (num_empty == 1) {
            $('#must_make').show();
            $('#calc_grade_' + must_make_item_id).addClass('must_make_highlight');

            var s = '<span class="must_make_arrow">&rarr;</span>';

            $('#calc_grade_' + must_make_item_id).parent().parent().append(s);
        } else {
            $('#must_make').hide();
            $('input[id^=calc_grade_]').removeClass('must_make_highlight');
        }
    }
}

// removes any errors from the first element of a given 'parts' array
function cleanParts(parts) {
    var tmpIndex = parts[0].indexOf('calc_');

    if (tmpIndex > 0) {
        parts[0] = parts[0].substring(tmpIndex);
    }

    return parts;
}