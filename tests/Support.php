<?php

namespace Haoa\ObjectPool\Tests;

use Haoa\ObjectPool\AbstractObjectPool;
use Haoa\ObjectPool\DialerInterface;
use Haoa\ObjectPool\ObjectTrait;

/** 只记录关闭行为的内存连接，不访问外部服务。 */
class MemoryConnection
{
    use ObjectTrait;

    public $closeCalls = 0;
    public $onClose;

    /** 模拟关闭连接，并执行可选的异常或挂起场景。 */
    public function close(): void
    {
        ++$this->closeCalls;
        if ($this->onClose !== null) {
            call_user_func($this->onClose);
        }
    }
}

/** 统计建连次数的内存拨号器。 */
class MemoryDialer implements DialerInterface
{
    public $created = 0;
    public $failNext = false;

    /** 创建模拟连接，按需模拟建连失败。 */
    public function dial(): object
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('模拟建连失败');
        }
        ++$this->created;
        return new MemoryConnection();
    }
}

/** 测试用具体连接池，仅额外暴露等待人数。 */
class MemoryPool extends AbstractObjectPool
{
    /** 获取等待借用的人数，避免用固定休眠推测并发状态。 */
    public function waitingBorrowers(): int
    {
        return $this->queue->stats()['consumer_num'];
    }
}

/** 无 close 方法的普通对象，验证通用对象池兼容性。 */
class PlainObject
{
    use ObjectTrait;
}

/** 为普通对象提供拨号能力。 */
class PlainDialer implements DialerInterface
{
    /** 创建不需要显式关闭的对象。 */
    public function dial(): object
    {
        return new PlainObject();
    }
}
