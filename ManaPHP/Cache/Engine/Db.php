<?php
namespace ManaPHP\Cache\Engine;

use ManaPHP\Cache\EngineInterface;
use ManaPHP\Component;

/**
 * Class ManaPHP\Cache\Adapter\Db
 *
 * @package cache\adapter
 */
class Db extends Component implements EngineInterface
{
    /**
     * @var string
     */
    protected $_model = 'ManaPHP\Cache\Engine\Db\Model';

    /**
     * Db constructor.
     *
     * @param string|array $options
     */
    public function __construct($options = [])
    {
        if (is_string($options)) {
            $options = ['model' => $options];
        }

        if (isset($options['model'])) {
            $this->_model = $options['model'];
        }
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function exists($key)
    {
        /**
         * @var \ManaPHP\Cache\Engine\Db\Model $model
         */
        $model = new $this->_model;
        $model = $model::first(['hash' => md5($key)]);

        return $model && $model->expired_time >= time();
    }

    /**
     * @param string $key
     *
     * @return string|false
     */
    public function get($key)
    {
        /**
         * @var \ManaPHP\Cache\Engine\Db\Model $model
         */
        $model = new $this->_model;
        $model = $model::first(['hash' => md5($key)]);

        if ($model && $model->expired_time > time()) {
            return $model->value;
        } else {
            return false;
        }
    }

    /**
     * @param string $key
     * @param string $value
     * @param int    $ttl
     *
     * @return void
     */
    public function set($key, $value, $ttl)
    {
        /**
         * @var \ManaPHP\Cache\Engine\Db\Model $model
         */
        $modelClass = $this->_model;

        $hash = md5($key);
        if ($model = $modelClass::first(['hash' => $hash])) {
            $model->value = $value;
            $model->ttl = $ttl;
            $model->expired_time = time() + $ttl;
            $model->update();
        } else {
            $model = new $modelClass();
            $model->hash = $hash;
            $model->key = $key;
            $model->value = $value;
            $model->ttl = $ttl;
            $model->expired_time = time() + $ttl;
            $model->create();
        }
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function delete($key)
    {
        /**
         * @var \ManaPHP\Cache\Engine\Db\Model $model
         */
        $model = new $this->_model;

        $model::deleteAll(['hash' => md5($key)]);
    }
}