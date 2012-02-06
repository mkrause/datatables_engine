
# DataTables engine for PHP ActiveRecord

A generic implementation of Datatables(1) server-side processing for use with the
PHP ActiveRecord(2) library.

(1) http://datatables.net
(2) http://www.phpactiverecord.org

## Usage

### Client-side

    $('#list').dataTable({
        'bProcessing': true,
        'bServerSide': true,
        'sAjaxSource': 'path/to/ajax_handler'
    });

### Server-side

Simple example:

    $dt = Engine_Datatables::standard(array(
        'model_class' => 'User',
        'columns' => array(
            'username',
            'join_date'
        ),
    ));

More elaborate functionality:

    $dt = Engine_Datatables::standard(array(
        'model_class' => 'User',
        'columns' => array(
            'username',
            array(
                'name' => 'join_date',
                'display' => function($user) {
                    return $user->join_date->format('Y-m-d');
                }
            ),
            array(
                'name' => 'full_name',
                'expression' => 'CONCAT(first_name, ' ', last_name)',
                'display' => function($user) {
                    return '"' . $user->full_name . '"';
                }
            ),
        ),
    ));
