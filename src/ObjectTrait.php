<?php

namespace Haoa\ObjectPool;

/**
 * Trait ObjectTrait
 * @package Haoa\ObjectPool
 */
trait ObjectTrait
{

    /**
     * @var AbstractObjectPool
     */
    public $pool;

    /**
     * @var int
     */
    public $createTime = 0;

    /** @var int 最近归还时间，借用中为零，单位秒 */
    public $lastReturnTime = 0;

    /**
     * 丢弃连接
     * @return bool
     */
    public function __discard(): bool
    {
        if (isset($this->pool)) {
            return $this->pool->discard($this);
        }
        return false;
    }

    /**
     * 归还连接
     * @return bool
     */
    public function __return(): bool
    {
        if (isset($this->pool)) {
            return $this->pool->return($this);
        }
        return false;
    }

}
