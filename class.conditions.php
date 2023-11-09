<?php
define( 'LOGICS', [ 'AND', 'OR', 'NONE', 'XOR' ] );
define( 'DEFAULT_LOGIC', 'OR' );

function make_id($sections = 5, $length = 5){
    $CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $range = strlen($CHARS);
    $id = '';
    for ($i = 0; $i < $sections; $i++){
        for ($j = 0; $j < $length; $j++){
            $char = substr($CHARS, rand(0, $range),1);
            $id = $id . $char;
        }
        if ( $i < $sections - 1) $id = $id . '-';
    }
    return $id;
}

function print_well( $obj, $is_die = false){
    echo "<pre>";
    print_r( $obj );
    echo "</pre>";
    if ( $is_die ) die;
}


class CONDITION_ITEM
{
    protected $_id;
    protected $_condition_parent_id;
    protected $test_condition_cb;

    public function __construct( callable $test_cb, $condition_parent_id = null){
        $this->_id = make_id(1,4);
        $this->_condition_parent_id = $condition_parent_id;
        $this->test_condition_cb = $test_cb;
    }

    protected function _update( $values ){
        foreach ( $values as $key => $value){
            if ( property_exists( $this, $key ) ) {
                if ( $key == 'logic') {
                    if ( array_in( $value, LOGICS ) ) $this->$key = $value;
                }
                else $this->$key = $value;
            }
        }
    }

    public function test_condition(){
        return call_user_func( $this->test_condition_cb );
    }

    public function id(){ return $this->_id; }
    public function condition_parent_id(){ return $this->_condition_parent_id; }
}

class CONDITION_LIST extends CONDITION_ITEM {
    protected $logic;
    protected $conditions = [];

    public function __construct($logic = null, $condition_parent_id = null)
    {
        parent::__construct( [ $this, 'test_all' ], $condition_parent_id);
        $this->logic = $logic && in_array( $logic, LOGICS )
            ? $logic
            : DEFAULT_LOGIC;
    }

    public function get_condition_by_id( $id ){
        if ( ! $id ) return null;
        if ( $this->_id === $id ) return $this;
        foreach ( $this->conditions as $condition ){
            if ( $condition instanceof CONDITION_LIST ){
                $res = $condition->get_condition_by_id( $id );
                if ( $res ) return $res;
            } elseif ( $condition->_id === $id ) return $condition;
        }
        return null;
    }

    protected function create_condition_list( $logic = DEFAULT_LOGIC, $index = -1, $condition_parent_id = null){
        $parent = $condition_parent_id
            ? $this->get_condition_by_id( $condition_parent_id )
            : $this;
        if ( ! ( $parent instanceof CONDITION_LIST ) ) return false;
        $new_condition_list = new CONDITION_LIST( $logic, $parent->_id );
        $conditions_count = count( $parent->conditions );
        if ( $index < 0 || $index >= $conditions_count ) $parent->conditions[] = $new_condition_list;
        else array_splice( $parent->conditions, $index, 0, [ $new_condition_list ] );
        return $new_condition_list;
    }

    public function add_condition_item( $condition, $options ){
        $condition_list_id = $options['condition_list_id'] ?? null;
        $index = $options[ 'index' ] ?? -1;
        $logic = $options [ 'logic' ] ?? null;
        $is_in_new_list = $options[ 'is_in_new_list' ] ?? false;
        $new_list_logic = $options[ 'new_list_logic' ] ?? null;
        $new_list_index = $options[ 'new_list_index' ] ?? -1;
        if ( ! ( $condition instanceof  CONDITION_ITEM ) ) return false;
        $condition_list = $condition_list_id
            ? $this->get_condition_by_id( $condition_list_id )
            : $this;
        if ( ! ( $condition_list instanceof CONDITION_LIST ) ) return false;
        if ( $logic && $logic !== $condition_list->logic &&
            in_array( $logic, LOGICS ) ) $condition_list->logic = $logic;
        if ( $is_in_new_list ) {
            $new_condition_list = $condition_list->create_condition_list( $new_list_logic, $new_list_index );
            $condition_list = $new_condition_list;
        }
        $conditions_count = count( $condition_list->conditions );
        if ( $index < 0 || $index >= $conditions_count ) $condition_list->conditions[] = $condition;
        else array_splice( $condition_list->conditions, $index, 0, [ $condition ] );
        $condition->_condition_parent_id = $condition_list->_id;
        return $condition;
    }

    public function update_condition_item( $condition_id, $values ){
        $condition = $this->get_condition_by_id( $condition_id );
        if ( ! $condition ) return false;
        $condition->_update( $values );
        return $condition;
    }

    public function remove_condition( $condition_id ){
        $condition = $this->get_condition_by_id( $condition_id );
        if ( ! $condition->_condition_parent_id ?? false ) return false;
        $parent_condition_list = $this->get_condition_by_id( $condition->_condition_parent_id );
        if ( ! ( $parent_condition_list instanceof CONDITION_LIST ) ) return false;
        $condition_index = array_search( $condition, $parent_condition_list->conditions );
        if ( $condition_index === false ) return false;
        $condition = array_splice( $parent_condition_list->conditions, $condition_index, 1 )[0] ?? true;
        $this->remove_empty_condition_lists( $this, $parent_condition_list );
        return $condition;
    }

    protected function remove_empty_condition_lists( $top_list , $curr_list ){
        if ( ! ( $top_list instanceof CONDITION_LIST &&
            $curr_list instanceof CONDITION_LIST &&
            $top_list->_id != $curr_list->_id &&
            count( $curr_list->conditions ) == 0) ) return;

        $closest_parent = $top_list->get_condition_by_id( $curr_list->_condition_parent_id );
        if ( ! $closest_parent ) return;
        $condition_index = array_search( $curr_list, $closest_parent->conditions );
        if ( $condition_index === false ) return;
        array_splice( $closest_parent->conditions , $condition_index, 1 );
        $top_list->remove_empty_condition_lists( $top_list, $closest_parent );
    }

    public function move_condition( $condition_id, $new_parent_id, $index = -1 ){
        $condition_to_move = $this->get_condition_by_id( $condition_id );
        if ( ! ( $condition_to_move instanceof CONDITION_ITEM ) ) return false;

        $current_parent = $this->get_condition_by_id( $condition_to_move->_condition_parent_id );
        if ( ! ( $current_parent instanceof  CONDITION_LIST ) ) return false;
        $current_condition_list = array_slice( $current_parent->conditions, 0 );

        $remove_res = $this->remove_condition( $condition_id );
        if ( ! $remove_res ) return false;

        $add_res = $this->add_condition_item( $condition_to_move, [
            'condition_list_id' => $new_parent_id,
            'index' => $index
        ] );
        if ( $add_res ) return $add_res;

        $current_parent->_update( [ 'conditions' => $current_condition_list ] );
        return false;
    }

    protected function test_all(){
        if ( count( $this->conditions) == 0 ) return true;
        return call_user_func([ $this, $this->logic ]);
    }

    protected function AND(){
//        if ( count( $this->conditions ) == 0 ) return true;
        foreach ( $this->conditions as $condition ){
            if ( $condition instanceof CONDITION_LIST ) {
                if ( count( $condition->conditions ) > 0 && ! $condition->test_condition() ) return false;
            } elseif ( ! $condition->test_condition() ) return false;
        }
        return true;
    }

    protected function OR(){
//        if ( count( $this->conditions ) == 0 ) return false;
        foreach ( $this->conditions as $condition ) {
            if ( $condition instanceof CONDITION_LIST ){
                if ( count( $condition->conditions ) > 0 && $condition->test_condition() ) return true;
            } elseif ( $condition->test_condition() ) return true;
        }
        return false;
    }

    protected function NONE() {
        return ! $this->OR();
    }

    protected function XOR(){
        $count = 0;
        foreach ( $this->conditions as $condition ) {
            if ( $condition->test_condition() ) $count++;
            if ( $count > 1 ) return false;
        }
        return $count === 1 ;
    }
}

class EQUITY_CONDITION extends CONDITION_ITEM {
    protected $expected_values;
    protected $get_current_value;
    protected $logic;

    public function __construct( $expected_values, callable $get_current_value_cb, $logic = 'OR', $condition_parent_id = null)
    {
        parent::__construct( [ $this, 'test'], $condition_parent_id);
        $this->expected_values = $expected_values;
        $this->get_current_value = $get_current_value_cb;
        $this->logic = in_array( $logic, [ 'OR', 'NONE' ] ) ? $logic : 'OR';
    }

    protected function test(){
        if ( count( $this->expected_values) == 0 ) return false;
        $curr_value = call_user_func($this->get_current_value);
        $include_res = in_array( $curr_value, $this->expected_values, true );
        return $this->logic === 'OR'
            ? $include_res
            : ! $include_res;
    }
}

class RANGE_CONDITION extends CONDITION_ITEM {
    protected $min;
    protected $is_min_inclusive;
    protected $max;
    protected $is_max_inclusive;
    protected $is_check_out_of_range;
    protected $get_current_value;

    public function __construct( $props, callable $get_current_value, $condition_parent_id = null){
        parent::__construct( [ $this, 'test' ], $condition_parent_id );
        $this->min = $props[ 'min' ];
        $this->max = $props[ 'max' ];
        $this->is_min_inclusive = $props[ 'is_min_inclusive' ] ?? false;
        $this->is_max_inclusive = $props[ 'is_max_inclusive' ] ?? false;
        $this->is_check_out_of_range = $props[ 'is_check_out_of_range' ] ?? false;
        $this->get_current_value = $get_current_value;
    }

    protected function test() {
        $current_value = call_user_func( $this->get_current_value );
        if ( $this->is_min_inclusive ) {
            if ( $this->is_max_inclusive ) $res = $current_value >= $this->min && $current_value <= $this->max;
            else $res = $current_value >= $this->min && $current_value < $this->max;
        } else {
            if ( $this->is_max_inclusive ) $res = $current_value > $this->min && $current_value <= $this->max;
            else $res = $current_value > $this->min && $current_value < $this->max;
        }
        return $this->is_check_out_of_range
            ? ! $res
            : $res;
    }
}

$values = [
    'equity1' => 102,
    'equity2' => 'aa',
    'equity3' => 'sht',
    'equity4' => 22,
    'range1' => 10,
    ];
$get_value_1 = function(){
    global $values;
    return $values['equity1'];
};
$get_value_2 = function(){
    global $values;
    return $values['equity2'];
};
$get_value_3 = function(){
    global $values;
    return $values['equity3'];
};
$get_value_4 = function(){
    global $values;
    return $values['equity4'];
};
$get_value_5 = function(){
    global $values;
    return $values['range1'];
};

$condition1 = new EQUITY_CONDITION([3,4,10,12], $get_value_1, 'OR'); // no
$condition2 = new EQUITY_CONDITION(['a','b','10',true], $get_value_2, 'OR'); // no
$condition3 = new EQUITY_CONDITION([1,2,3,4,'shit'], $get_value_3, 'OR'); // no
$condition4 = new EQUITY_CONDITION([44,11,2], $get_value_4, 'OR'); // no
$condition5 = new RANGE_CONDITION([
    'min'=> 4,
    'max' => 19,
], $get_value_5); // yes

$condition_tree = new CONDITION_LIST('XOR');
$condition_tree->add_condition_item($condition1, []);
$condition_tree->add_condition_item($condition2, [
    'is_in_new_list' => true,
    'new_list_logic' => 'OR'
]);
$condition_tree->add_condition_item($condition3, [
    'condition_list_id' => $condition2->condition_parent_id()
]);
$condition_tree->add_condition_item( $condition5, [
    'condition_list_id' => $condition2->condition_parent_id(),
    'is_in_new_list' => true,
    'new_list_logic' => 'NONE'
] );
$condition_tree->add_condition_item($condition4, [
    'condition_list_id' => null,
    'index' => 1
] );
$condition_tree->move_condition($condition4->id(), $condition5->condition_parent_id(), 0 );
//$condition_tree->move_condition( $condition5->id(), $condition2->condition_parent_id(), -3);
//$condition_tree->move_condition($condition4->id(), null, 30 );
//$condition_tree->remove_condition($condition5->id());

$res = $condition_tree->test_condition();
var_dump( $res );
print_well( $condition_tree, true );
