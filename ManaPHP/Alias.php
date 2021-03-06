<?php

namespace ManaPHP;

use ManaPHP\Exception\InvalidArgumentException;
use ManaPHP\Exception\MisuseException;

/**
 * Class ManaPHP\Alias
 *
 * @package alias
 */
class Alias extends Component implements AliasInterface
{
    /**
     * @var array
     */
    protected $_aliases = [];

    /**
     * Alias constructor.
     *
     */
    public function __construct()
    {
        $this->set('@manaphp', __DIR__);
    }

    /**
     * @param string $name
     * @param string $path
     *
     * @return string
     */
    public function set($name, $path)
    {
        if ($name[0] !== '@') {
            throw new MisuseException(['`:name` must start with `@`', 'name' => $name]);
        }

        if ($path === '') {
            $this->_aliases[$name] = $path;
        } elseif ($path[0] !== '@') {
            $this->_aliases[$name] = (strpos($name, '@ns.') === 0 || DIRECTORY_SEPARATOR === '/') ? $path : strtr($path, '\\', '/');
        } else {
            $this->_aliases[$name] = $this->resolve($path);
        }

        return $this->_aliases[$name];
    }

    /**
     * @param string $name
     *
     * @return bool|string|array
     */
    public function get($name = null)
    {
        if ($name === null) {
            return $this->_aliases;
        }

        if ($name[0] !== '@') {
            throw new MisuseException(['`:name` must start with `@`', 'name' => $name]);
        }

        return isset($this->_aliases[$name]) ? $this->_aliases[$name] : false;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function has($name)
    {
        if ($name[0] !== '@') {
            throw new MisuseException(['`:name` must start with `@`', 'name' => $name]);
        }

        return isset($this->_aliases[$name]);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function resolve($path)
    {
        if (strpos($path, '{') !== false && preg_match_all('#{([^\}]+)}#', $path, $matches)) {
            foreach ((array)$matches[1] as $k => $match) {
                if (is_numeric($match)) {
                    $replaced = substr(function_exists('random_bytes') ? bin2hex(random_bytes($match / 2 + 1)) : md5(uniqid(mt_rand(), true)), 0, $match);
                } else {
                    /** @noinspection UnSafeIsSetOverArrayInspection */
                    $ts = isset($ts) ? $ts : time();
                    $replaced = date($match, $ts);
                }

                $path = str_replace($matches[0][$k], $replaced, $path);
            }
        }

        if ($path[0] !== '@') {
            return DIRECTORY_SEPARATOR === '/' ? $path : strtr($path, '\\', '/');
        }

        if (strpos($path, '@ns.') === 0) {
            $parts = explode('\\', $path, 2);
        } else {
            $path = strtr($path, '\\', '/');
            $parts = explode('/', $path, 2);
        }

        $alias = $parts[0];
        if (!isset($this->_aliases[$alias])) {
            throw new InvalidArgumentException(['`:alias` is not exists for `:path`', 'alias' => $alias, 'path' => $path]);
        }

        return str_replace($alias, $this->_aliases[$alias], $path);
    }

    /**
     * @param string $ns
     *
     * @return string
     */
    public function resolveNS($ns)
    {
        if ($ns[0] !== '@') {
            return $ns;
        }

        $parts = explode('\\', $ns, 2);

        $alias = $parts[0];
        if (!isset($this->_aliases[$alias])) {
            throw new InvalidArgumentException(['`:alias` is not exists for `:namespace`', 'alias' => $alias, 'namespace' => $ns]);
        }

        return $this->_aliases[$alias] . (isset($parts[1]) ? '\\' . $parts[1] : '');
    }
}