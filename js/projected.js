var calc_id = null;
var must_make_enabled = null;

$(document).ready(function() {
    must_make_enabled = $('#must_make_enabled').val() == '1' ? true : false;

    $('#must_make').hide();
    $('#must_make').css('position', 'absolute');

    $(':input[id^=calc_]').keyup(function(e) {
        var elem = e.currentTarget;
        var id = $(this)[0].id.split('_')[2];

        if (validInput(elem.value, e) && inRange(elem.id)) {
            hideError(id);
        } else if (e.keyCode > 64 && e.keyCode < 91) {
            showError(id);
        }

        if (errorsExist()) {
            hideTotals();
        } else {
            recalculate(elem);
        }
    });

    // Make one initial calculation for must make if there is one empty input
    var num_empty = null;
    var empty_elem = null;

    $('input:text').each(function() {
        if ($(this).val() == '') {
            num_empty++;
            empty_elem = $(this);
        }
    });

    if (num_empty == 1) {
        recalculate(empty_elem[0]);
    }
});

function showError(id) {
    $('#' + id + '_range').addClass('projected_error')
}

function hideError(id) {
    $('#' + id + '_range').removeClass('projected_error')
}

function hideTotals() {
    $('.categoryitem[id]').each(function(index) {
        $(this).html('<b>??</b>');
    });

    $('.courseitem[id]').each(function(index) {
        $(this).html('<b>??</b>');
    });
}

function errorsExist() {
    return !!$('.projected_error').length;
}

// Checks that the input value is within the range allowed by it's corresponding
// grade item.
function inRange(id) {
    var id = id.split('_')[2];

    var minmax = $('#minmax_' + id).attr('value').split('|');
    var value = $('#calc_grade_' + id).attr('value');

    var min = parseFloat(minmax[0]);
    var max = parseFloat(minmax[1]);

    if (value >= min && value <= max) {
        hideError(id);

        return true;
    } else {
        showError(id);

        return false;
    }
}

// Checks that value is either a number or backspace with no modifier keys down
function validInput(value, e) {
    var key = e.keyCode;

    // Integer
    if (isFinite(parseInt(String.fromCharCode(key)))) { return true }

    // Numpad keys
    if (key >= 96 && key <= 105) { return true }

    // Ignored keys: tab, backspace, etc
    if ($.inArray(key, [8, 9, 13, 46])) { return true }

    // Modifier Keys
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return false }

    return true;
}

function position_must_make(item_id) {
    var parent_cell = $('#calc_grade_' + item_id).parent().parent();

    var left_pos = parent_cell.position().left + parent_cell.width() + 20;
    var top_pos = parent_cell.position().top - parent_cell.height() - 35;

    $('#must_make').css('left', left_pos + 'px')
    $('#must_make').css('top', top_pos + 'px')
}

function update_must_make(data) {
    $('.must_make_table > tbody > tr > td').each(function(index) {
        var letter = data.shift();
        var limit = data.shift();

        $(this).html(letter + '  ' + limit);
    });
}

// Aggregates input data and makes the ajax request
function recalculate(elem) {
    var inputs = [];

    $('input:text[id^="calc_grade_"]').each(function(index) {
        fieldval = $(this).val() == '' ? 'switch_me' : $(this).val();
        inputs.push($(this).attr('id') + '=' + fieldval);
    });

    var num_empty = 0;
    var must_make_item_id = null;

    if (must_make_enabled) {
        $('input:text[id^="calc_grade_"]').each(function() {
            if ($(this).val() == '') {
                num_empty++;
                must_make_item_id = $(this).attr('id').split('_')[2];
                console.log($(this));
            }
        });

        if (num_empty == 1) {
            inputs.push('must_make=' + must_make_item_id);
            position_must_make(must_make_item_id);
        }
    }

    $.post('rpc.php', { inputdata: inputs.join('|') }, function(data) {
        var parts = data.split('|');

        if (must_make_enabled && num_empty == 1) {
            var must_make_data = parts.pop().split('=')[1].split(',');

            update_must_make(must_make_data);
        }

        for(var i = 0; i < parts.length; i++) {
            var tmp = parts[i].split('=');
            var elem = $('#' + tmp[0]);

            if(elem != null) {
                elem.html(tmp[1]);
            }
        }
    });

    if (must_make_enabled) {
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
