
# DataTables engine for PHP ActiveRecord

A generic implementation of DataTables(1) server-side processing for use with the
PHP ActiveRecord(2) library and Roy framework(3).

(1) http://datatables.net  
(2) http://www.phpactiverecord.org  
(3) https://github.com/mkrause/roy  

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
            'username', // Maps to: $user->username
            'join_date' // Maps to: $user->join_date
        ),
    ));
    
    // Output JSON reponse
    echo $dt->output_json();

More elaborate functionality:

    $dt = Engine_Datatables::standard(array(
        'model_class' => 'User',
        'columns' => array(
            // Maps to: $user->username
            'username',
            // Maps to: $user->join_date with custom display
            array(
                'field' => 'join_date',
                'display' => function($user) {
                    return $user->join_date->format('Y-m-d');
                }
            ),
            // Maps to: custom SQL expression, aliased as $user->full_name
            array(
                'name' => 'full_name',
                'expression' => 'CONCAT(first_name, ' ', last_name)',
                'display' => function($user) {
                    // E.g. "JOHN DOE"
                    $name_uppercase = strtoupper($user->full_name);
                    return html::encode($name_uppercase);
                }
            ),
        ),
    ));
    
    // Output JSON reponse
    echo $dt->output_json();

## Security note

The DataTables library will *not* encode any HTML contained in the data
sent by the server. By default, Engine_Datatables will encode data using
htmlentities().

If you override the display function, make sure your output is properly
escaped.
