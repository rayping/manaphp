<?php

namespace ManaPHP;

use ManaPHP\Exception\AuthenticationException;
use ManaPHP\Exception\MisuseException;

/**
 * Class ManaPHP\Identity
 */
abstract class Identity extends Component implements IdentityInterface
{
    /**
     * @var string
     */
    protected $_type;

    /**
     * @var array
     */
    protected $_claims = [];

    public function saveInstanceState()
    {
        return ['_type' => $this->_type, '_claims' => $this->_claims];
    }

    /**
     * @return bool
     */
    public function isGuest()
    {
        return !$this->_claims;
    }

    /**
     * @param int $default
     *
     * @return int
     */
    public function getId($default = null)
    {
        if (!$this->_claims) {
            if ($default === null) {
                throw new AuthenticationException();
            } else {
                return $default;
            }
        } elseif (!$this->_type) {
            throw new MisuseException('type is unknown');
        } else {
            $id = $this->_type . '_id';
            return isset($this->_claims[$id]) ? $this->_claims[$id] : 0;
        }
    }

    /**
     * @param string $default
     *
     * @return string
     */
    public function getName($default = null)
    {
        if (!$this->_claims) {
            if ($default === null) {
                throw new AuthenticationException();
            } else {
                return $default;
            }
        } elseif (!$this->_type) {
            throw new MisuseException('type is unknown');
        } else {
            $name = $this->_type . '_name';
            return isset($this->_claims[$name]) ? $this->_claims[$name] : '';
        }
    }

    /**
     * @param string $default
     *
     * @return string
     */
    public function getRole($default = 'guest')
    {
        if ($this->_claims) {
            if (isset($this->_claims['role'])) {
                return $this->_claims['role'];
            } else {
                return isset($this->_claims['admin_id']) && $this->_claims['admin_id'] === 1 ? 'admin' : 'user';
            }
        } else {
            return $default;
        }
    }

    /**
     * @param string $role
     *
     * @return static
     */
    public function setRole($role)
    {
        $this->_claims['role'] = $role;

        return $this;
    }

    /**
     * @param string     $claim
     * @param string|int $value
     *
     * @return static
     */
    public function setClaim($claim, $value)
    {
        $this->_claims[$claim] = $value;

        return $this;
    }

    /**
     * @param array $claims
     *
     * @return static
     */
    public function setClaims($claims)
    {
        if ($claims && (!$this->_type || !isset($claims[$this->_type]))) {
            foreach ($claims as $claim => $value) {
                if (strlen($claim) > 3 && strrpos($claim, '_id', -3) !== false) {
                    $this->_type = substr($claim, 0, -3);
                    break;
                }
            }
        }
        $this->_claims = $claims;

        return $this;
    }

    /**
     * @param string     $claim
     * @param string|int $default
     *
     * @return string|int
     */
    public function getClaim($claim, $default = null)
    {
        return isset($this->_claims[$claim]) ? $this->_claims[$claim] : $default;
    }

    /**
     * @return array
     */
    public function getClaims()
    {
        return $this->_claims;
    }

    /**
     * @param string $claim
     *
     * @return bool
     */
    public function hasClaim($claim)
    {
        return isset($this->_claims[$claim]);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    public function base64urlEncode($str)
    {
        return strtr(rtrim(base64_encode($str), '='), '+/', '-_');
    }

    /**
     * @param string $str
     *
     * @return bool|string
     */
    public function base64urlDecode($str)
    {
        return base64_decode(strtr($str, '-_', '+/'));
    }
}