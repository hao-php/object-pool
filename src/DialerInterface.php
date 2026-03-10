<?php

namespace Haoa\ObjectPool;

/**
 * Interface DialerInterface
 * @package Haoa\ObjectPool
 */
interface DialerInterface
{

    /**
     * 拨号
     * @return object
     */
    public function dial(): object;

}
