<?php

class Datatables_Exception extends RoyException {};

/**
 * Generic engine for DataTables server-side processing.
 * 
 * @author mkrause
 */
class Engine_Datatables
{
    private $_options;
    private $_params = array();
    private $_output = null;
    
    public static function standard(array $options)
    {
        $dt = new self($options, $_GET);
        return $dt;
    }
    
    /**
     * Constructor.
     * 
     * @throws Datatables_Exception
     */
    public function __construct(array $options, array $params)
    {
        $this->_options = $this->_normalize_options($options);
        $this->_params = $params;
    }
    
    /**
     * Normalize and validate the given options array.
     * 
     * @throws Datatables_Exception
     */
    public function _normalize_options(array $options_in)
    {
        $options = array(
            'model_class' => isset($options_in['model_class'])
                ? (string)$options_in['model_class'] : null,
            'columns' => isset($options_in['columns'])
                ? (array)$options_in['columns'] : array(),
        );
        
        $model_cl = $options['model_class'];
        $model_exists = false;
        try {
            $model_exists = class_exists($model_cl);
        } catch (NotFoundException $e) {}
        
        if (!$model_exists) {
            throw new Datatables_Exception("No such model class '%s'.", $model_cl);
        }
        
        foreach ($options['columns'] as $key => &$col) {
            $col = $this->_normalize_column($key, $col);
        }
        
        return $options;
    }
    
    /**
     * Normalize and validate the given column array.
     * 
     * @throws Datatables_Exception
     */
    public function _normalize_column($key, $column_in)
    {
        // Short-cut: empty column
        if (!$column_in) {
            $column_in = array(
                'name' => '__col' . $key,
                'expression' => "''",
            );
        }
        
        // Short-cut: name only
        if (is_string($column_in)) {
            $column_in = array(
                'name' => $column_in
            );
        }
        
        $column = array(
            'name' => isset($column_in['name'])
                ? $column_in['name'] : null,
            'expression' => isset($column_in['expression'])
                ? $column_in['expression'] : null,
            'display' => isset($column_in['display'])
                ? $column_in['display'] : null,
        );
        
        return $column;
    }
    
    public function _column($record, $i)
    {
        $options = $this->_options;
        if (!isset($options['columns'][$i])) {
            return ''; // Exception?
        }
        
        $column = &$options['columns'][$i];
        
        $val = '';
        try {
            $name = $column['name'];
            $val = $record->$name;
        } catch (ActiveRecord\UndefinedPropertyException $e) {
            throw new Datatables_Exception(
                "Undefined property '$column'", $e);
        }
        
        if (is_callable($column['display'])) {
            $val = $column['display']($record);
        }
        
        return $val;
    }
    
    public function process()
    {
        $options = $this->_options;
        $params = $this->_params;
        
        $output = array();
        $output['sEcho'] = $params['sEcho'];
        
        $model_options = array(
            'limit' => (int)$params['iDisplayLength'],
            'offset' => (int)$params['iDisplayStart'],
            'order' => 'symbol asc',
        );
        
        // Select
        $select_fields = array();
        foreach ($options['columns'] as $idx => $col) {
            if (empty($col['expression'])) {
                $select_fields[] = $col['name'];
            } else {
                $select_fields[] = $col['expression'] . ' AS '. $col['name'];
            }
        }
        $model_options['select'] = implode(', ', $select_fields);
        
        // Ordering
        $order = "";
        for ($i = 0; $i < intval($params['iSortingCols']); $i++) {
            $col_idx = intval($params['iSortCol_' . $i]);
            $col_dir = strtolower($params['sSortDir_' . $i]) === 'asc'
                ? 'asc' : 'desc';
            if ($params['bSortable_' . $col_idx] == "true") {
                $col_name = $options['columns'][$col_idx]['name'];
                $order .= $col_name . " " . $col_dir . ", ";
            }
        }
        $order = substr($order, 0, -2);
        $model_options['order'] = $order;
        
        // Filtering
        if ($params['sSearch'] !== "") {
            $where = array('');
            for ($i = 0; $i < $params['iColumns']; $i++) {
                // Can't use WHERE on a custom expression, so skip it
                if (!empty($options['columns'][$i]['expression'])) {
                    continue;
                }
                
                $col_name = $options['columns'][$i]['name'];
                $where[0] .= $col_name . ' LIKE ? OR ';
                $where[] = '%' . $params['sSearch'] . '%';
            }
            $where[0] = substr($where[0], 0, -4);
            $model_options['conditions'] = $where;
            
            //TODO: implement a HAVING clause for columns using
            // custom expressions
        }
        
        //TODO: individual column filtering
        
        $model_cl = $options['model_class'];
        $records = call_user_func($model_cl . '::all', $model_options);
        
        // Get rid of the offset to get a correct count
        $model_options_count = $model_options;
        unset($model_options_count['offset']);
        $records_total = call_user_func($model_cl . '::count',
            $model_options_count);
        
        $data = array();
        foreach ($records as $record) {
            $record_data = array();
            
            for ($i = 0; $i < $params['iColumns']; $i++) {
                $record_data[$i] = $this->_column($record, $i);
            }
            
            $data[] = $record_data;
        }
        
        $output['iTotalRecords'] = $records_total;
        $output['iTotalDisplayRecords'] = $records_total;
        $output['aaData'] = $data;
        
        $this->_output = $output;
    }
    
    public function output()
    {
        if ($this->_output === null) {
            $this->process();
        }
        $output = $this->_output;
        
        return $output;
    }
    
    public function output_json()
    {
        $output = $this->output();
        return json_encode($output);
    }
}
