<?php

namespace Haoa\ObjectPool;

use Swoole\Coroutine;

/** 按生命周期和空闲时长安全清理空闲对象，不负责注册定时器。 */
trait IdleCleanupTrait
{
    /** @var int 最大空闲时间，单位秒，零表示不限 */
    protected $maxIdleTime = 0;

    /** @var bool 是否正在扫描空闲队列 */
    protected $cleaningIdle = false;

    /**
     * 调整最大空闲时间，不重建池或重置现有连接的计时。
     * @param int $maxIdleTime 最大空闲时间，单位秒，非正数表示不限
     */
    public function setConnMaxIdleTime(int $maxIdleTime): void
    {
        $this->maxIdleTime = max(0, $maxIdleTime);
    }

    /** 清理任一条件到期的空闲对象，返回移除数量，不创建替代连接。 */
    public function cleanupIdleConnections(): int
    {
        if (($this->maxLifetime <= 0 && $this->maxIdleTime <= 0) || $this->cleaningIdle) {
            return 0;
        }
        if (Coroutine::getCid() === -1) {
            throw new \RuntimeException('清理连接池必须在协程内执行');
        }

        $this->cleaningIdle = true;
        $closing = [];
        try {
            // 参考 Go database/sql：先从空闲集合移除，再执行可能挂起的关闭操作。
            // https://go.dev/src/database/sql/sql.go（connectionCleanerRunLocked）
            // 只检查本轮数量，保留未到期对象，回放时不能重置归还时间。
            $limit = $this->getIdleNumber();
            for ($i = 0; $i < $limit; ++$i) {
                $stats = $this->queue->stats();
                if ($stats['queue_num'] <= 0 || $stats['consumer_num'] > 0) {
                    break;
                }
                $connection = $this->queue->pop(0.000001);
                if ($connection === false) {
                    break;
                }
                if ($this->isLifetimeExpired($connection) || $this->isIdleExpired($connection)) {
                    $closing[] = $connection;
                } elseif (!$this->push($connection)) {
                    $closing[] = $connection;
                }
            }

            // 一条关闭失败也继续释放其余连接，最后向调用方报告首次异常。
            $failure = null;
            foreach ($closing as $connection) {
                try {
                    $this->closeIdleConnection($connection);
                } catch (\Throwable $exception) {
                    if ($failure === null) {
                        $failure = $exception;
                    }
                }
            }
            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            $this->cleaningIdle = false;
        }
        return count($closing);
    }

    /**
     * 判断连接是否超过配置的生命周期。
     * @param object $connection 待检查连接
     */
    protected function isLifetimeExpired(object $connection): bool
    {
        return $this->maxLifetime > 0 && $connection->createTime + $this->maxLifetime <= time();
    }

    /**
     * 判断连接从最近一次归还起是否空闲超时，借用中的连接不适用。
     * @param object $connection 待检查的空闲连接
     */
    protected function isIdleExpired(object $connection): bool
    {
        return $this->maxIdleTime > 0 && $connection->lastReturnTime > 0
            && $connection->lastReturnTime + $this->maxIdleTime <= time();
    }

    /**
     * 替换借用时发现的到期连接，并保证旧物理连接被释放。
     * @param object $connection 已登记但尚未交付业务的到期连接
     */
    protected function replaceExpiredConnection(object $connection): void
    {
        try {
            // 沿用原有替换路径，为已经等待借用的协程提供新连接。
            $this->discard($connection);
        } finally {
            $this->closeIdleConnection($connection);
        }
    }

    /**
     * 判断归还连接是否可以直接回收，优先满足正在等待的借用者。
     * @param object $connection 已归还连接
     */
    protected function shouldCleanupOnReturn(object $connection): bool
    {
        return Coroutine::getCid() !== -1
            && $this->isLifetimeExpired($connection)
            && $this->queue->stats()['consumer_num'] === 0;
    }

    /**
     * 释放已脱离队列的对象，有关闭接口时主动关闭物理连接。
     * @param object $connection 不再借出的空闲连接
     */
    protected function closeIdleConnection(object $connection): void
    {
        // 通用对象不强制实现 close；DB Connector 和 Redis Driver 均提供此方法。
        $connection->pool = null;
        if (is_callable([$connection, 'close'])) {
            $connection->close();
        }
    }
}
