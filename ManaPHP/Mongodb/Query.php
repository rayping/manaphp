<?php
namespace ManaPHP\Mongodb;

use ManaPHP\Di;
use ManaPHP\Exception\InvalidArgumentException;
use ManaPHP\Exception\InvalidFormatException;
use ManaPHP\Exception\InvalidValueException;
use ManaPHP\Exception\MisuseException;
use ManaPHP\Model\Expression\Increment;
use ManaPHP\Model\ExpressionInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;

class Query extends \ManaPHP\Query
{
    /**
     * @var \ManaPHP\MongodbInterface|string
     */
    protected $_db;

    /**
     * @var string
     */
    protected $_source;

    /**
     * @var array
     */
    protected $_types;

    /**
     * @var array
     */
    protected $_projection;

    /**
     * @var array
     */
    protected $_aggregate = [];

    /**
     * @var array
     */
    protected $_filters = [];

    /**
     * @var string
     */
    protected $_order;

    /**
     * @var array
     */
    protected $_group;

    /**
     * @var string|callable
     */
    protected $_index;

    /**
     * Query constructor.
     *
     * @param \ManaPHP\MongodbInterface|string $db
     */
    public function __construct($db = null)
    {
        $this->_db = $db;
    }

    /**
     * @param \ManaPHP\MongodbInterface|string $db
     *
     * @return static
     */
    public function setDb($db)
    {
        $this->_db = $db;

        return $this;
    }

    /**
     * @return string
     */
    public function getSource()
    {
        if ($this->_model) {
            return $this->_model->getSource();
        } else {
            return $this->_source;
        }
    }

    /**
     * @return \ManaPHP\MongodbInterface
     */
    public function getDb()
    {
        return $this->_di->getShared($this->_db);
    }

    /**
     * @param array $types
     *
     * @return static
     */
    public function setTypes($types)
    {
        $this->_types = $types;

        return $this;
    }

    /**
     * Sets SELECT DISTINCT / SELECT ALL flag
     *
     * @param string $field
     *
     * @return array
     */
    public function values($field)
    {
        $source = $this->getSource();
        if ($pos = strpos($source, '.')) {
            $db = substr($source, 0, $source);
            $collection = substr($source, $pos + 1);
        } else {
            $db = null;
            $collection = $source;
        }

        $mongodb = $this->getConnection();

        $cmd = ['distinct' => $collection, 'key' => $field];
        if ($this->_filters) {
            $filters = [];
            foreach ($this->_filters as $filter) {
                $key = key($filter);
                $value = current($filter);
                if (isset($filters[$key]) || count($filter) !== 1) {
                    $filters = ['$and' => $this->_filters];
                    break;
                }
                $filters[$key] = $value;
            }
            $cmd['query'] = $filters;
        }

        $r = $mongodb->command($cmd, $db)[0];
        if (!$r['ok']) {
            throw new InvalidFormatException([
                '`:distinct` distinct for `:collection` collection failed `:code`: `:msg`',
                'distinct' => $field,
                'code' => $r['code'],
                'msg' => $r['errmsg'],
                'collection' => $source
            ]);
        }

        return $this->_limit ? array_slice($r['values'], $this->_offset, $this->_limit) : $r['values'];
    }

    /**
     * @param string|array $fields
     *
     * @return static
     */
    public function select($fields)
    {
        if (!$fields) {
            return $this;
        }

        if (is_string($fields)) {
            $fields = (array)preg_split('#[\s,]+#', $fields, -1, PREG_SPLIT_NO_EMPTY);
        }

        if ($fields) {
            if (isset($fields[count($fields) - 1])) {
                $this->_projection = array_fill_keys($fields, 1);
            } else {
                $projection = [];
                foreach ($fields as $k => $v) {
                    if (is_int($k)) {
                        $projection[$v] = 1;
                    } else {
                        $projection[$k] = $v;
                    }
                }
                $this->_projection = $projection;
            }
        }

        return $this;
    }

    /**
     * @param string $table
     * @param string $alias
     *
     * @return static
     */
    public function from($table, $alias = null)
    {
        if ($table) {
            if (!$this->_model && strpos($table, '\\') !== false) {
                $this->_model = $this->_di->getShared($table);
            } else {
                $this->_source = $table;
            }
        }

        return $this;
    }

    /**
     * @param string $expr
     *
     * @return array
     */
    protected function _compileCondExpression($expr)
    {
        if (strpos($expr, ',') !== false) {
            $parts = explode(',', $expr);
            $cond = trim($parts[0]);
            $true = trim($parts[1]);
            $false = isset($parts[2]) ? trim($parts[2]) : 0;

            $true = is_numeric($true) ? (double)$true : '$' . $true;
            $false = is_numeric($false) ? (double)$false : '$' . $false;
        } else {
            $cond = $expr;
            $true = 1;
            $false = 0;
        }

        if (preg_match('#^(.+)\s*([<>=]+)\s*(.+)$#', $cond, $match)) {
            $op1 = $match[1];
            $op2 = $match[2];
            $op3 = $match[3];
            $alg = ['=' => '$eq', '>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', '!=' => '$neq', '<>' => '$neq'];
            return ['$cond' => [[$alg[$op2] => [is_numeric($op1) ? (double)$op1 : '$' . $op1, is_numeric($op3) ? (double)$op3 : '$' . $op3]], $true, $false]];
        } else {
            return null;
        }
    }

    /**
     * @param array $expr
     *
     * @return array
     */
    public function aggregate($expr)
    {
        foreach ($expr as $k => $v) {
            if (is_array($v)) {
                if (isset($v['$count_if'])) {
                    unset($v['$count_if'][0]);
                    $v = ['$sum' => ['$cond' => [$v['$count_if'], 1, 0]]];
                } elseif (isset($v['$sum_if'])) {
                    $field = isset($v['$sum_if'][0]) ? $v['$sum_if'][0] : 1;
                    unset($v['$sum_if'][0]);
                    $v = ['$sum' => ['$cond' => [$v['$sum_if'], is_numeric($field) ? (double)$field : '$' . $field, 0]]];
                } elseif (isset($v['$avg_if'])) {
                    $field = isset($v['$avg_if'][0]) ? $v['$avg_if'][0] : 1;
                    unset($v['$avg_if'][0]);
                    $v = ['$avg' => ['$cond' => [$v['$avg_if'], is_numeric($field) ? (double)$field : '$' . $field, 0]]];
                }
                $this->_aggregate[$k] = $v;
                continue;
            }

            if (preg_match('#^(\w+)\((.*)\)$#', $v, $match) !== 1) {
                throw new MisuseException(['`:aggregate` aggregate is invalid.', 'aggregate' => $v]);
            }

            $accumulator = strtolower($match[1]);
            $normalizes = ['group_concat' => 'push',
                'std' => 'stdDevPop',
                'stddev' => 'stdDevPop',
                'stddev_pop' => 'stdDevPop',
                'stddev_samp' => 'stdDevSamp',
                'addtoset' => 'addToSet',
                'stddevpop' => 'stdDevPop',
                'stddevsamp' => 'stdDevSamp'];
            if (isset($normalizes[$accumulator])) {
                $accumulator = $normalizes[$accumulator];
            }
            $operand = $match[2];
            if ($accumulator === 'count') {
                $this->_aggregate[$k] = ['$sum' => 1];
            } elseif ($accumulator === 'count_if') {
                if ($cond = $this->_compileCondExpression($operand)) {
                    $this->_aggregate[$k] = ['$sum' => $cond];
                } else {
                    throw new MisuseException(['unknown COUNT_IF expression: `:expression`', 'expression' => $operand]);
                }
            } elseif ($accumulator === 'sum_if') {
                if ($cond = $this->_compileCondExpression($operand)) {
                    $this->_aggregate[$k] = ['$sum' => $cond];
                } else {
                    throw new MisuseException(['unknown SUM_IF expression: `:expression`', 'expression' => $operand]);
                }
            } elseif ($accumulator === 'avg_if') {
                if ($cond = $this->_compileCondExpression($operand)) {
                    $this->_aggregate[$k] = ['$avg' => $cond];
                } else {
                    throw new MisuseException(['unknown AVG_IF expression: `:expression`', 'expression' => $operand]);
                }
            } elseif (in_array($accumulator, ['avg', 'first', 'last', 'max', 'min', 'push', 'addToSet', 'stdDevPop', 'stdDevSamp', 'sum'], true)) {
                if (preg_match('#^[\w\.]+$#', $operand) === 1) {
                    $this->_aggregate[$k] = ['$' . $accumulator => '$' . $operand];
                } elseif (preg_match('#^([\w\.]+)\s*([\+\-\*/%])\s*([\w\.]+)$#', $operand, $match2) === 1) {
                    $operator_map = ['+' => '$add', '-' => '$subtract', '*' => '$multiply', '/' => '$divide', '%' => '$mod'];
                    $sub_operand = $operator_map[$match2[2]];
                    $sub_operand1 = is_numeric($match2[1]) ? (double)$match2[1] : ('$' . $match2[1]);
                    $sub_operand2 = is_numeric($match2[3]) ? (double)$match2[3] : ('$' . $match2[3]);
                    $this->_aggregate[$k] = ['$' . $accumulator => [$sub_operand => [$sub_operand1, $sub_operand2]]];
                } elseif ($cond = $this->_compileCondExpression($operand)) {
                    $this->_aggregate[$k] = ['$' . $accumulator => $this->_compileCondExpression($operand)];
                } else {
                    throw new MisuseException(['unknown `:operand` operand of `:aggregate` aggregate', 'operand' => $operand, 'aggregate' => $v]);
                }
            } else {
                throw new MisuseException([
                    'unknown `:accumulator` accumulator of `:aggregate` aggregate',
                    'accumulator' => $accumulator,
                    'aggregate' => $v
                ]);
            }
        }

        return $this->execute();
    }

    /**
     * @param string $field
     * @param mixed  $value
     *
     * @return bool|float|int|string|array|\MongoDB\BSON\ObjectID|\MongoDB\BSON\UTCDateTime
     */
    public function normalizeValue($field, $value)
    {
        if ($value === null || !$this->_types) {
            return $value;
        }

        $type = $this->_types[$field];

        if ($type === 'string') {
            return is_string($value) ? $value : (string)$value;
        } elseif ($type === 'integer') {
            return is_int($value) ? $value : (int)$value;
        } elseif ($type === 'double') {
            return is_float($value) ? $value : (double)$value;
        } elseif ($type === 'objectid') {
            return is_scalar($type) ? new ObjectId($value) : $value;
        } elseif ($type === 'boolean') {
            return is_bool($value) ? $value : (bool)$value;
        } elseif ($type === 'array') {
            return (array)$value;
        } else {
            throw new InvalidValueException(['normalize `:type` type value is not supported', 'type' => $type]);
        }
    }

    /**
     * @param string $field
     * @param array  $values
     *
     * @return array
     */
    public function normalizeValues($field, $values)
    {
        if (!$this->_types) {
            return $values;
        }

        $type = $this->_types[$field];

        $map = ['integer' => 'intval', 'double' => 'floatval', 'string' => 'strval', 'boolean' => 'boolval'];
        if (isset($map[$type])) {
            $values = array_map($map[$type], $values);
        } else {
            foreach ($values as $k => $value) {
                $values[$k] = $this->normalizeValue($field, $value);
            }
        }

        return $values;
    }

    /**
     * Appends a condition to the current conditions using a AND operator
     *
     *<code>
     *    $builder->andWhere('name = "Peter"');
     *    $builder->andWhere('name = :name: AND id > :id:', array('name' => 'Peter', 'id' => 100));
     *</code>
     *
     * @param string|array           $filter
     * @param int|float|string|array $value
     *
     * @return static
     */
    public function where($filter, $value = null)
    {
        if ($filter === null) {
            return $this;
        } elseif (is_array($filter)) {
            /** @noinspection ForeachSourceInspection */
            foreach ($filter as $k => $v) {
                if (is_int($k)) {
                    $this->where($v, null);
                } else {
                    $this->where($k, $v);
                }
            }
        } elseif ($value === null) {
            if (is_string($filter)) {
                if (preg_match('#^\w+$#', $filter) === 1) {
                    $this->_filters[] = [$filter => null];
                } elseif (strpos($filter, 'this.') !== false) {
                    $this->_filters[] = ['$where' => $filter];
                } else {
                    $filter = preg_replace_callback('#\b\w+\b#', function ($match) {
                        return (is_numeric($match[0]) ? '' : 'this.') . $match[0];
                    }, $filter);
                    $this->_filters[] = ['$where' => $filter];
                }
            } else {
                $this->_filters[] = $filter;
            }
        } elseif (is_array($value)) {
            if (strpos($filter, '~=')) {
                if (count($value) === 2 && gettype($value[0]) === gettype($value[1])) {
                    $this->whereBetween(substr($filter, 0, -2), $value[0], $value[1]);
                } else {
                    $this->_filters[] = [substr($filter, 0, -2) => ['$in' => $value]];
                }
            } elseif (strpos($filter, '@=')) {
                $this->whereDateBetween(substr($filter, 0, -2), $value[0], $value[1]);
            } elseif (!$value || isset($value[0])) {
                if (strpos($filter, '!=') || strpos($filter, '<>')) {
                    $this->whereNotIn(substr($filter, 0, -2), $value);
                } elseif (in_array(null, $value, true)) {
                    $this->_filters[] = [$filter => ['$in' => $value]];
                } else {
                    $this->whereIn(rtrim($filter, '='), $value);
                }
            } else {
                $this->_filters[] = [$filter => $value];
            }
        } elseif (preg_match('#^([\w\.]+)\s*([<>=!^$*~,]*)$#', $filter, $matches) === 1) {
            list(, $field, $operator) = $matches;

            if ($operator === '' || $operator === '=') {
                $this->_filters[] = [$field => $this->normalizeValue($field, $value)];
            } elseif ($operator === '~=') {
                $field = substr($filter, 0, -2);
                if ($this->_types && !isset($this->_types[$field])) {
                    throw new InvalidArgumentException(['`:field` field is not exist in `:collection` collection',
                        'field' => $field,
                        'collection' => $this->getSource()
                    ]);
                }

                if (is_scalar($value)) {
                    if (is_int($value)) {
                        $this->_filters[] = [$field => ['$in' => [(string)$value, (int)$value]]];
                    } elseif (is_float($value)) {
                        $this->_filters[] = [$field => ['$in' => [(string)$value, (double)$value]]];
                    } else {
                        $this->_filters[] = [$field => ['$in' => [(string)$value, (int)$value, (double)$value]]];
                    }
                } else {
                    throw new InvalidValueException(['`:filter` filter is not  valid: value must be scalar value', 'filter' => $filter]);
                }
            } elseif ($operator === '^=') {
                $this->whereStartsWith($field, $value);
            } elseif ($operator === '$=') {
                $this->whereEndsWith($field, $value);
            } elseif ($operator === '*=') {
                $this->whereContains($field, $value);
            } elseif ($operator === ',=') {
                $this->whereInset($field, $value);
            } else {
                $operator_map = ['>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', '!=' => '$ne', '<>' => '$ne'];
                if (!isset($operator_map[$operator])) {
                    throw new InvalidValueException(['unknown `:where` where filter', 'where' => $filter]);
                }
                $this->_filters[] = [$field => [$operator_map[$operator] => $this->normalizeValue($field, $value)]];
            }
        } elseif (preg_match('#^([\w\.]+)%(\d+)=$#', $filter, $matches) === 1) {
            $this->_filters[] = [$matches[1] => ['$mod' => [(int)$matches[2], (int)$value]]];
        } else {
            throw new InvalidValueException(['unknown mongodb query `filter` filter', 'filter' => $filter]);
        }

        return $this;
    }

    /**
     * @param array $filter
     *
     * @return static
     */
    public function whereRaw($filter)
    {
        $this->_filters[] = $filter;

        return $this;
    }

    /**
     * Appends a BETWEEN condition to the current conditions
     *
     *<code>
     *    $builder->betweenWhere('price', 100.25, 200.50);
     *</code>
     *
     * @param string           $field
     * @param int|float|string $min
     * @param int|float|string $max
     *
     * @return static
     */
    public function whereBetween($field, $min, $max)
    {
        if ($min === null || $min === '') {
            return $max === null || $max === '' ? $this : $this->where($field . '<=', $max);
        } elseif ($max === null || $max === '') {
            return $min === null || $min === '' ? $this : $this->where($field . '>=', $min);
        }

        $this->_filters[] = [$field => ['$gte' => $this->normalizeValue($field, $min), '$lte' => $this->normalizeValue($field, $max)]];

        return $this;
    }

    /**
     * Appends a NOT BETWEEN condition to the current conditions
     *
     *<code>
     *    $builder->notBetweenWhere('price', 100.25, 200.50);
     *</code>
     *
     * @param string           $field
     * @param int|float|string $min
     * @param int|float|string $max
     *
     * @return static
     */
    public function whereNotBetween($field, $min, $max)
    {
        if ($min === null || $min === '') {
            return $max === null || $max === '' ? $this : $this->where($field . '>', $max);
        } elseif ($max === null || $max === '') {
            return $min === null || $min === '' ? $this : $this->where($field . '<', $min);
        }

        $this->_filters[] = [$field => ['$not' => ['$gte' => $this->normalizeValue($field, $min), '$lte' => $this->normalizeValue($field, $max)]]];

        return $this;
    }

    /**
     * Appends an IN condition to the current conditions
     *
     *<code>
     *    $builder->inWhere('id', [1, 2, 3]);
     *</code>
     *
     * @param string $field
     * @param array  $values
     *
     * @return static
     */
    public function whereIn($field, $values)
    {
        $this->_filters[] = [$field => ['$in' => $this->normalizeValues($field, $values)]];

        return $this;
    }

    /**
     * Appends a NOT IN condition to the current conditions
     *
     *<code>
     *    $builder->notInWhere('id', [1, 2, 3]);
     *</code>
     *
     * @param string $field
     * @param array  $values
     *
     * @return static
     */
    public function whereNotIn($field, $values)
    {
        $this->_filters[] = [$field => ['$nin' => $this->normalizeValues($field, $values)]];

        return $this;
    }

    /**
     * @param string $field
     * @param string $value
     *
     * @return static
     */
    public function whereInset($field, $value)
    {
        return $this->whereRegex($field, '\b' . $value . '\b');
    }

    /**
     * @param string $field
     * @param string $value
     *
     * @return static
     */
    public function whereNotInset($field, $value)
    {
        return $this->whereNotRegex($field, '\b' . $value . '\b');
    }

    /**
     * @param string|array $expr
     * @param string       $like
     *
     * @return static
     */
    protected function _whereLike($expr, $like)
    {
        if ($like === '') {
            return $this;
        }

        if (is_array($expr)) {
            $or = [];
            /** @noinspection ForeachSourceInspection */
            foreach ($expr as $v) {
                $or[] = [$v => ['$regex' => $like, '$options' => 'i']];
            }
            $this->_filters[] = ['$or' => $or];
        } else {
            $this->_filters[] = [$expr => ['$regex' => $like, '$options' => 'i']];
        }

        return $this;
    }

    /**
     * @param string|array $expr
     * @param string       $like
     *
     * @return static
     */
    protected function _whereNotLike($expr, $like)
    {
        if ($like === '') {
            return $this;
        }

        if (is_array($expr)) {
            $and = [];
            /** @noinspection ForeachSourceInspection */
            foreach ($expr as $v) {
                $and[] = [$v => ['$not' => new Regex($like, 'i')]];
            }
            $this->_filters[] = ['$and' => $and];
        } else {
            $this->_filters[] = [$expr => ['$not' => new Regex($like, 'i')]];
        }

        return $this;
    }

    /**
     * @param string|array $field
     * @param string       $value
     *
     * @return static
     */
    public function whereContains($field, $value)
    {
        return $value === '' ? $this : $this->_whereLike($field, $value);
    }

    /**
     * @param string|array $field
     * @param string       $value
     *
     * @return static
     */
    public function whereNotContains($field, $value)
    {
        return $value === '' ? $this : $this->_whereNotLike($field, $value);
    }

    /**
     * @param string|array $field
     * @param string       $value
     * @param int          $length
     *
     * @return static
     */
    public function whereStartsWith($field, $value, $length = null)
    {
        if ($value === '') {
            return $this;
        }

        if ($length === null) {
            return $this->_whereLike($field, '^' . $value);
        } else {
            return $this->_whereLike($field, '^' . str_pad($value, $length, '.') . '$');
        }
    }

    /**
     * @param string|array $field
     * @param string       $value
     * @param int          $length
     *
     * @return static
     */
    public function whereNotStartsWith($field, $value, $length = null)
    {
        if ($value === '') {
            return $this;
        }

        if ($length === null) {
            return $this->_whereNotLike($field, '^' . $value);
        } else {
            return $this->_whereNotLike($field, '^' . str_pad($value, $length, '.') . '$');
        }
    }

    /**
     * @param string|array $field
     * @param string       $value
     *
     * @return static
     */
    public function whereEndsWith($field, $value)
    {
        return $value === '' ? $this : $this->_whereLike($field, $value . '$');
    }

    /**
     * @param string|array $field
     * @param string       $value
     *
     * @return static
     */
    public function whereNotEndsWith($field, $value)
    {
        return $value === '' ? $this : $this->_whereNotLike($field, $value . '$');
    }

    /**
     * @param string|array $expr
     * @param string       $value
     *
     * @return static
     */
    public function whereLike($expr, $value)
    {
        if ($value === '') {
            return $this;
        }

        if ($value[0] !== '%') {
            $value = '^' . $value;
        }

        if ($value[strlen($value) - 1] !== '%') {
            $value .= '$';
        }

        $value = strtr($value, ['%' => '.*', '_' => '.']);

        return $this->_whereLike($expr, $value);
    }

    /**
     * @param string|array $expr
     * @param string       $value
     *
     * @return static
     */
    public function whereNotLike($expr, $value)
    {
        if ($value === '') {
            return $this;
        }

        if ($value[0] !== '%') {
            $value = '^' . $value;
        }

        if ($value[strlen($value) - 1] !== '%') {
            $value .= '$';
        }

        $value = strtr($value, ['%' => '.*', '_' => '.']);

        return $this->_whereNotLike($expr, $value);
    }

    /**
     * @param string $field
     * @param string $regex
     * @param string $flags
     *
     * @return static
     */
    public function whereRegex($field, $regex, $flags = '')
    {
        $this->_filters[] = [$field => ['$regex' => $regex, '$options' => $flags]];

        return $this;
    }

    /**
     * @param string $field
     * @param string $regex
     * @param string $flags
     *
     * @return static
     */
    public function whereNotRegex($field, $regex, $flags = '')
    {
        $this->_filters[] = [$field => ['$not' => new Regex($regex, $flags)]];

        return $this;
    }

    /**
     * @param string $expr
     *
     * @return static
     */
    public function whereNull($expr)
    {
        $this->_filters[] = [$expr => ['$type' => 10]];

        return $this;
    }

    /**
     * @param string $expr
     *
     * @return static
     */
    public function whereNotNull($expr)
    {
        $this->_filters[] = [$expr => ['$ne' => null]];

        return $this;
    }

    /**
     * Sets a ORDER BY condition clause
     *
     *<code>
     *    $builder->orderBy('Robots.name');
     *    $builder->orderBy(array('1', 'Robots.name'));
     *</code>
     *
     * @param string|array $orderBy
     *
     * @return static
     */
    public function orderBy($orderBy)
    {
        if (is_string($orderBy)) {
            foreach (explode(',', $orderBy) as $item) {
                if (preg_match('#^\s*([\w\.]+)(\s+asc|\s+desc)?$#i', $item, $match) !== 1) {
                    throw new MisuseException(['unknown `:order` order by for `:collection` collection', 'order' => $orderBy, 'collection' => get_class($this->getSource())]);
                }
                $this->_order[$match[1]] = (!isset($match[2]) || strtoupper(ltrim($match[2])) === 'ASC') ? 1 : -1;
            }
        } else {
            /** @noinspection ForeachSourceInspection */
            foreach ($orderBy as $field => $value) {
                if ((is_int($value) && $value === SORT_ASC) || (is_string($value) && strtoupper($value) === 'ASC')) {
                    $this->_order[$field] = 1;
                } else {
                    $this->_order[$field] = -1;
                }
            }
        }

        return $this;
    }

    /**
     * Sets a GROUP BY clause
     *
     * @param string|array $groupBy
     *
     * @return static
     */
    public function groupBy($groupBy)
    {
        if (is_string($groupBy)) {
            if (strpos($groupBy, '(') !== false) {
                if (preg_match('#^([\w\.]+)\((.*)\)$#', $groupBy, $match) === 1) {
                    $func = strtoupper($match[1]);
                    if ($func === 'SUBSTR') {
                        $parts = explode(',', $match[2]);

                        if ($parts[1] === '0') {
                            throw new MisuseException(['`:group` substr index is 1-based', 'group' => $groupBy]);
                        }
                        $this->_group[$parts[0]] = ['$substr' => ['$' . $parts[0], $parts[1] - 1, (int)$parts[2]]];
                    }
                } else {
                    throw new MisuseException(['`:group` group is not supported. ', 'group' => $groupBy]);
                }
            } else {
                foreach (explode(',', str_replace(' ', '', $groupBy)) as $field) {
                    $this->_group[$field] = '$' . $field;
                }
            }
        } elseif (is_array($groupBy)) {
            foreach ($groupBy as $k => $v) {
                if (is_int($k)) {
                    $this->_group[$v] = '$' . $v;
                } else {
                    $this->_group[$k] = $v;
                }
            }
        }

        return $this;
    }

    /**
     * @param callable|string|array $indexBy
     *
     * @return static
     */
    public function indexBy($indexBy)
    {
        $this->_index = $indexBy;

        return $this;
    }

    /**
     * @return \ManaPHP\MongodbInterface
     */
    public function getConnection()
    {
        if (!$this->_di) {
            $this->_di = Di::getDefault();
        }

        if (is_object($this->_db)) {
            return $this->_db;
        } elseif ($this->_model) {
            return $this->_di->getShared($this->_model->getDb());
        } else {
            return $this->_di->getShared($this->_db ?: 'mongodb');
        }
    }

    /**
     * @return array
     */
    public function execute()
    {
        $mongodb = $this->getConnection();
        if (!$this->_aggregate) {
            $options = [];

            if ($this->_projection !== null) {
                if (!isset($this->_projection['*'])) {
                    $options['projection'] = $this->_projection;
                }
            } elseif ($this->_model) {
                $options['projection'] = array_fill_keys($this->_model->getFields(), 1);
            }

            if ($this->_model && isset($options['projection']) && !isset($options['projection']['_id']) && $this->_model->getPrimaryKey() !== '_id') {
                $options['projection']['_id'] = false;
            }

            if ($this->_order !== null) {
                $options['sort'] = $this->_order;
            }

            if ($this->_offset !== null) {
                $options['skip'] = $this->_offset;
            }

            if ($this->_limit !== null) {
                $options['limit'] = $this->_limit;
            }

            $filters = [];
            foreach ($this->_filters as $filter) {
                $key = key($filter);
                $value = current($filter);
                if (isset($filters[$key]) || count($filter) !== 1) {
                    $filters = ['$and' => $this->_filters];
                    break;
                }
                $filters[$key] = $value;
            }

            $r = $mongodb->fetchAll($this->getSource(), $filters, $options, !$this->_forceUseMaster);
        } else {
            $pipeline = [];
            if ($this->_filters) {
                $pipeline[] = ['$match' => ['$and' => $this->_filters]];
            }

            $pipeline[] = ['$group' => ['_id' => $this->_group] + $this->_aggregate];

            if ($this->_order !== null) {
                $pipeline[] = ['$sort' => $this->_order];
            }

            if ($this->_offset !== null) {
                $pipeline[] = ['$skip' => $this->_offset];
            }

            if ($this->_limit !== null) {
                $pipeline[] = ['$limit' => $this->_limit];
            }

            $r = $mongodb->aggregate($this->getSource(), $pipeline);

            if ($this->_group !== null) {
                foreach ($r as $k => $row) {
                    if ($row['_id'] !== null) {
                        $row += $row['_id'];
                    }
                    unset($row['_id']);
                    $r[$k] = $row;
                }
            }
        }

        if ($this->_index === null) {
            return $r;
        }

        $indexBy = $this->_index;
        if (is_scalar($indexBy)) {
            $rows = [];
            foreach ($r as $row) {
                $rows[$row[$indexBy]] = $row;
            }
        } elseif (is_array($indexBy)) {
            $k = key($indexBy);
            $v = current($indexBy);

            $rows = [];
            foreach ($r as $row) {
                $rows[$row[$k]] = $row[$v];
            }
        } else {
            $rows = [];
            foreach ($r as $row) {
                $rows[$indexBy($row)] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param string $field
     *
     * @return int
     */
    public function count($field = '*')
    {
        $copy = clone $this;

        $copy->_limit = null;
        $copy->_offset = null;
        $copy->_order = null;
        $copy->_aggregate['count'] = ['$sum' => 1];
        $r = $copy->execute();

        return $r ? $r[0]['count'] : 0;
    }

    /**
     * @param int $size
     * @param int $page
     *
     * @return \ManaPHP\PaginatorInterface
     */
    public function paginate($size = null, $page = null)
    {
        $this->page($size, $page);

        $items = $this->all();

        if ($this->_limit === null) {
            $count = count($items);
        } elseif (count($items) % $this->_limit === 0) {
            $count = $this->count();
        } else {
            $count = $this->_offset + count($items);
        }

        $paginator = $this->paginator;

        $paginator->items = $items;

        if ($this->_with) {
            $paginator->items = $this->relationsManager->earlyLoad($this->_model, $paginator->items, $this->_with);
        }

        return $paginator->paginate($count, $this->_limit, (int)($this->_offset / $this->_limit) + 1);
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return (bool)$this->select(['_id'])->limit(1)->execute();
    }

    /**
     * @return int
     */
    public function delete()
    {
        $filters = [];
        foreach ($this->_filters as $filter) {
            $key = key($filter);
            $value = current($filter);
            if (isset($filters[$key]) || count($filter) !== 1) {
                $filters = ['$and' => $this->_filters];
                break;
            }
            $filters[$key] = $value;
        }

        return $this->getConnection()->delete($this->getSource(), $filters);
    }

    /**
     * @param $fieldValues
     *
     * @return int
     */
    public function update($fieldValues)
    {
        $filters = [];
        foreach ($this->_filters as $filter) {
            $key = key($filter);
            $value = current($filter);
            if (isset($filters[$key]) || count($filter) !== 1) {
                $filters = ['$and' => $this->_filters];
                break;
            }
            $filters[$key] = $value;
        }

        $expressions = [];
        foreach ($fieldValues as $field => $value) {
            if ($value instanceof ExpressionInterface) {
                $expressions[$field] = $value;
                unset($fieldValues[$field]);
            }
        }

        if ($expressions) {
            if ($fieldValues) {
                $fieldValues = ['$set' => $fieldValues];
            }

            foreach ($expressions as $field => $value) {
                if ($value instanceof Increment) {
                    $fieldValues['$inc'][$field] = $value->step;
                }
            }
        }

        return $this->getConnection()->update($this->getSource(), $filters, $fieldValues);
    }
}