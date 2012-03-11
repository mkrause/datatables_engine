<?php
/**
 * Generic engine for DataTables server-side processing.
 * https://github.com/mkrause/datatables_engine
 */

class DataTables_Exception extends Exception {};

/**
 * DataTables engine main class.
 * 
 * @author mkrause
 */
class DataTables
{
    const VERSION = '0.2.2';
    
    private $_params = array();
    private $_options = array();
    private $_output = null;
    
    /**
     * Factory method. Create a DataTables instance using the given options.
     * Uses GET variables to retrieve the DataTables.js parameters.
     * 
     * @return DataTables
     */
    public static function standard(array $options)
    {
        return new self($_GET, $options);
    }
    
    /**
     * Constructor.
     * 
     * @param array $params DataTables.js parameters.
     *     See: http://datatables.net/usage/server-side
     * @param array $options Configuration for the DataTables processing.
     *     Available options:
     *     - model_class: (string) name of the PHP ActiveRecord model class
     *     - select: (array) fields to select
     *     - columns: (array) specification of each column, can include:
     *         - name: (string)
     *         - expression: (string)
     *         - display: (function) Function taking a record and returning
     *             the output to send to the browser. Overrides the 'link'
     *             option.
     *         - link: (string|function) Display as link (URL or function
     *             taking a record and returning a URL).
     * @throws DataTables_Exception
     */
    public function __construct(array $params, array $options = array())
    {
        $this->_params = $params;
        $this->_options = $this->_parse_options($options);
    }
    
    /**
     * Normalize and validate the given options array.
     * 
     * @param array $options_in
     * @return array Parsed options.
     * @throws DataTables_Exception
     */
    protected function _parse_options(array $options_in)
    {
        $options_default = array(
            'model_class' => null,
            'select' => array(),
            'columns' => array(),
        );
        
        $options = array_merge($options_default, $options_in);
        
        // Check if the model class given actually exists
        $model_cl = $options['model_class'];
        $model_exists = false;
        try {
            $model_exists = class_exists($model_cl);
        } catch (NotFoundException $e) {}
        
        if (!$model_exists) {
            throw new DataTables_Exception(
                "No such model class '" . $model_cl . "'.");
        }
        
        // Make sure we have at least the required amount of columns
        $num_columns = (int)$this->_params['iColumns'];
        while (count($options['columns']) < $num_columns) {
            $options['columns'][] = null;
        }
        
        foreach ($options['columns'] as $key => &$col) {
            if (!is_int($key)) {
                throw new DataTables_Exception(
                    'Invalid options: column keys need to be numeric');
            }
            $col = $this->_parse_column_options($key, $col);
        }
        
        return $options;
    }
    
    /**
     * Normalize and validate the given column options.
     * 
     * @param int $key
     * @param array $column_in
     * @return array Parsed column options.
     */
    protected function _parse_column_options($key, $column_in)
    {
        if (!$column_in) {
            $column_in = array();
        }
        
        // Short-cut: name only
        if (is_string($column_in)) {
            $column_in = array(
                'field' => $column_in,
            );
        }
        
        $column_default = array(
            'name' => null,
            'field' => null,
            'expression' => null,
            'display' => null,
            'link' => null,
        );
        
        $column = array_merge($column_default, $column_in);
        
        if (!is_string($column['name'])) {
            if (is_string($column['field'])) {
                $column['name'] = $column['field'];
            } else {
                $column['name'] = '__col' . $key;
            }
        }
        
        // Default display function
        if (!is_callable($column['display'])) {
            $column['display'] = function($record) use ($column) {
                $name = $column['name'];
                
                $value = null;
                if (is_string($name) and isset($record->$name)) {
                    $value = $record->$name;
                }
                
                $output = htmlentities((string)$value, ENT_QUOTES, 'UTF-8');
                
                if (!is_null($column['link'])) {
                    $url = $column['link'];
                    
                    if (is_callable($column['link'])) {
                        $url = $column['link']($record);
                    }
                    
                    $output = "<a href=\"{$url}\">{$output}</a>";
                }
                
                return $output;
            };
        }
        
        return $column;
    }
    
    /**
     * Get the value of the table cell for the column at $key and the
     * row corresponding to the given record.
     * 
     * @param Object $record
     * @param int $key
     * @return string The value of the cell.
     */
    protected function _get_cell($record, $key)
    {
        $options = $this->_options;
        
        if (!isset($options['columns'][$key])) {
            return ''; // Exception?
        }
        
        $column = &$options['columns'][$key];
        
        $value = '';
        
        $name = $column['name'];
        if (is_string($name) and isset($record->$name)) {
            $value = $record->$name;
        }
        
        // Post-processing
        if (is_callable($column['display'])) {
            $value = $column['display']($record);
        }
        
        return $value;
    }
    
    /**
     * Process the datatable.
     * 
     * @throws DataTables_Exception
     */
    protected function _process()
    {
        $params = $this->_params;
        $options = $this->_options;
        
        $output = array();
        $output['sEcho'] = $params['sEcho'];
        
        $query_options = array(
            'select' => null,
            'conditions' => array(),
            'having' => '',
            'order' => null,
            'offset' => null,
            'limit' => null,
        );
        
        // Paging
        $query_options['offset'] = (int)$params['iDisplayStart'];
        $query_options['limit'] = (int)$params['iDisplayLength'];
        
        // Select
        $select_fields = $options['select'];
        foreach ($options['columns'] as $col) {
            if (is_string($col['field'])) {
                // Real table field
                $select_fields[] = $col['field'];
            } elseif (is_string($col['expression'])) {
                // Aliased expression
                $select_fields[] = $col['expression'] . ' AS '. $col['name'];
            } else {
                // Default
                $select_fields[] = '"" AS '. $col['name'];
            }
        }
        $select_fields = array_unique($select_fields);
        $query_options['select'] = implode(', ', $select_fields);
        
        // Ordering
        $order = "";
        for ($i = 0; $i < (int)$params['iSortingCols']; $i++) {
            $index = (int)$params['iSortCol_' . $i];
            if (!isset($options['columns'][$index])) {
                continue;
            }
            $col = $options['columns'][$index];
            
            $sort_dir = strtoupper($params['sSortDir_' . $i]) === 'ASC'
                ? 'ASC' : 'DESC';
            
            // Note: all params are strings, so check for the *string* "true"
            if ($params['bSortable_' . $index] === "true") {
                $col_name = $col['name'];
                $order .= "{$col_name} {$sort_dir}, ";
            }
        }
        $order = substr($order, 0, -2);
        $query_options['order'] = $order;
        
        /*
        // Filtering
        if ($params['sSearch'] !== "") {
            $where = array('');
            for ($i = 0; $i < $params['iColumns']; $i++) {
                // Can't use WHERE on a custom expression, so skip it
                if (!is_string($options['columns'][$i]['field'])) {
                    continue;
                }
                
                $col_name = $options['columns'][$i]['name'];
                $where[0] .= $col_name . ' LIKE ? OR ';
                $where[] = '%' . $params['sSearch'] . '%';
            }
            $where[0] = substr($where[0], 0, -4);
            $query_options['conditions'] = $where;
            
            //TODO: implement a HAVING clause for columns using
            // custom expressions (doesn't seem to support auto-escaping...)
            // Note: use Model::connection()->escape() instead of
            // mysql_real_escape_string()
        }
        */
        
        //TODO: individual column filtering
        
        $model_cl = $options['model_class'];
        $records = call_user_func($model_cl . '::all', $query_options);
        
        // Count the data set
        $query_options_count = array(
            'conditions' => $query_options['conditions'],
            'limit' => $query_options['limit'],
        );
        $records_total = call_user_func($model_cl . '::count',
            $query_options_count);
        
        $data = array();
        foreach ($records as $record) {
            $record_data = array();
            
            for ($i = 0; $i < $params['iColumns']; $i++) {
                $record_data[$i] = $this->_get_cell($record, $i);
            }
            
            $data[] = $record_data;
        }
        
        // Filter
        //XXX might want to do this using WHERE/HAVING in SQL instead,
        // but then we don't take the display function into account
        $search = $params['sSearch'];
        if (!empty($search)) {
            $data_filtered = array();
            foreach ($data as $row) {
                $matches = false;
                for ($i = 0; $i < $params['iColumns']; $i++) {
                    if (strpos($row[$i], $search) !== false) {
                        $matches = true;
                    }
                }
                
                if ($matches) {
                    $data_filtered[] = $row;
                }
            }
            $data = $data_filtered;
        }
        
        $output['iTotalRecords'] = $records_total;
        $output['iTotalDisplayRecords'] = $records_total;
        $output['aaData'] = $data;
        
        $this->_output = $output;
    }
    
    /**
     * Return the output values to be submitted to DataTables on the client.
     * 
     * @return array
     * @throws DataTables_Exception
     */
    public function output()
    {
        if ($this->_output === null) {
            $this->_process();
        }
        $output = $this->_output;
        
        return $output;
    }
    
    /**
     * Return the output in JSON format.
     * 
     * @return string
     * @throws DataTables_Exception
     */
    public function output_json()
    {
        $output = $this->output();
        return json_encode($output);
    }
}
