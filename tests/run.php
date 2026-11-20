<?php

use Haoa\ObjectPool\Exception\WaitTimeoutException;
use Haoa\ObjectPool\Tests\MemoryDialer;
use Haoa\ObjectPool\Tests\MemoryPool;
use Haoa\ObjectPool\Tests\PlainDialer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

if (!extension_loaded('swoole') || !function_exists('Swoole\\Coroutine\\run')) {
    fwrite(STDERR, "运行测试需要安装 Swoole，并支持 Swoole\\Coroutine\\run()。\n");
    exit(1);
}

// 直接加载本库源码，不需要 Composer、PHPUnit 或其他项目的自动加载器。
foreach (['DialerInterface.php', 'ObjectTrait.php', 'IdleCleanupTrait.php', 'AbstractObjectPool.php', 'Exception/WaitTimeoutException.php'] as $file) {
    require_once __DIR__ . '/../src/' . $file;
}
require_once __DIR__ . '/Support.php';

/**
 * 验证条件成立。
 * @param bool $condition 是否满足预期
 * @param string $message 失败说明
 */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 验证两个值或对象严格相等。
 * @param mixed $expected 预期值
 * @param mixed $actual 实际值
 * @param string $message 失败说明
 */
function same($expected, $actual, string $message): void
{
    check($expected === $actual, $message);
}

/**
 * 验证指定操作抛出预期异常。
 * @param callable $operation 待验证操作
 * @param string $class 异常类型
 * @param string $message 预期的异常信息
 */
function expectException(callable $operation, string $class, string $message): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        check($exception instanceof $class, '实际异常类型不符合预期');
        same($message, $exception->getMessage(), '实际异常信息不符合预期');
        return;
    }
    throw new RuntimeException('操作没有抛出预期异常');
}

/**
 * 等待协程进入目标状态，超时后明确失败。
 * @param callable $condition 状态检查方法
 */
function awaitCondition(callable $condition): void
{
    $deadline = microtime(true) + 1;
    while (!$condition()) {
        check(microtime(true) < $deadline, '等待协程状态超时');
        Coroutine::sleep(0.001);
    }
}

/**
 * 独立运行测试，捕获异常并向命令行报告结果。
 * @param string $name 用例名称
 * @param callable $case 用例逻辑
 * @param bool $coroutine 是否使用独立协程调度器
 */
function runCase(string $name, callable $case, bool $coroutine = true): bool
{
    $failure = null;
    try {
        if ($coroutine) {
            $started = Swoole\Coroutine\run(static function () use ($case, &$failure): void {
                try {
                    $case();
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
            });
            check($started !== false, '协程调度器启动失败');
        } else {
            $case();
        }
        if ($failure !== null) {
            throw $failure;
        }
        echo '通过：' . $name . PHP_EOL;
        return true;
    } catch (Throwable $exception) {
        fwrite(STDERR, '失败：' . $name . '；' . $exception->getMessage() . PHP_EOL);
        return false;
    }
}

$tests = [
    '新连接按需建立且未到期连接复用' => static function (): void {
        $dialer = new MemoryDialer();
        $pool = new MemoryPool($dialer, 2, 2, 3600, 1, 0.1, 300);
        same(0, $dialer->created, '初始化不得预建连接');
        $connection = $pool->borrow();
        same(0, $connection->lastReturnTime, '借用中不能有空闲标记');
        $pool->return($connection);
        same($connection, $pool->borrow(), '应复用未到期连接');
        same(1, $dialer->created, '复用不应重新建连');
        $pool->return($connection);
    },
    '生命周期清理不补建且可缩到零' => static function (): void {
        $dialer = new MemoryDialer();
        $pool = new MemoryPool($dialer, 2, 2, 10, 1);
        $old = $pool->borrow();
        $pool->return($old);
        $old->createTime = time() - 20;
        same(1, $pool->cleanupIdleConnections(), '应回收生命周期到期连接');
        same(1, $old->closeCalls, '应关闭物理连接一次');
        same(null, $old->pool, '应解除旧连接的池关联');
        same(['total' => 0, 'idle' => 0, 'active' => 0], $pool->stats(), '池应缩到零');
        same(1, $dialer->created, '清理不应补建连接');
        $new = $pool->borrow();
        check($new !== $old, '下次借用应按需创建新连接');
        same(2, $dialer->created, '新借用应只建立一条连接');
        $pool->return($new);
    },
    '只启用空闲超时也能清理' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 2, 2, 0, 1, 0.1, 10);
        $connection = $pool->borrow();
        $pool->return($connection);
        $connection->lastReturnTime = time() - 20;
        same(1, $pool->cleanupIdleConnections(), '生命周期不限时仍应检查空闲时间');
        same(1, $connection->closeCalls, '空闲超时应关闭连接');
    },
    '两个期限任一到期就清理且不重复计数' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 3, 3, 10, 1, 0.1, 10);
        $life = $pool->borrow();
        $idle = $pool->borrow();
        $both = $pool->borrow();
        foreach ([$life, $idle, $both] as $connection) {
            $pool->return($connection);
        }
        $life->createTime = $both->createTime = time() - 20;
        $idle->lastReturnTime = $both->lastReturnTime = time() - 20;
        same(3, $pool->cleanupIdleConnections(), '应分别处理生命周期、空闲以及两者均到期');
        same(1, $both->closeCalls, '同时到期不能重复关闭');
    },
    '扫描不关闭使用中或未到期连接' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 3, 3, 10, 1, 0.1, 10);
        $active = $pool->borrow();
        $fresh = $pool->borrow();
        $old = $pool->borrow();
        $pool->return($fresh);
        $pool->return($old);
        $active->createTime = $old->createTime = time() - 20;
        same(1, $pool->cleanupIdleConnections(), '应仅清理到期空闲连接');
        same(0, $active->closeCalls, '不能关闭使用中连接');
        same(0, $fresh->closeCalls, '不能关闭未到期连接');
        same(['total' => 2, 'idle' => 1, 'active' => 1], $pool->stats(), '清理后统计不正确');
        $pool->return($active);
    },
    '扫描与重复扫描不刷新空闲起点' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 1, 0.1, 100);
        $connection = $pool->borrow();
        $pool->return($connection);
        $returnedAt = time() - 10;
        $connection->lastReturnTime = $returnedAt;
        same(0, $pool->cleanupIdleConnections(), '未到期不应回收');
        same(0, $pool->cleanupIdleConnections(), '重复扫描应保持连接');
        same($returnedAt, $connection->lastReturnTime, '扫描不能重置归还时间');
        $connection->lastReturnTime = time() - 200;
        same(1, $pool->cleanupIdleConnections(), '到期后应关闭');
        same(0, $pool->cleanupIdleConnections(), '重复清理应返回零');
    },
    '业务持有时长不算空闲且归还时重新计时' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 1, 0.1, 10);
        $connection = $pool->borrow();
        $connection->createTime = 1;
        $connection->lastReturnTime = time() - 100;
        same(0, $pool->cleanupIdleConnections(), '活跃连接不参与空闲清理');
        $before = time();
        $pool->return($connection);
        check($connection->lastReturnTime >= $before, '归还必须开始新一轮空闲计时');
        same(0, $pool->cleanupIdleConnections(), '不能因业务持有较久立即回收');
        same(0, $connection->closeCalls, '正常归还不应关闭');
    },
    '生命周期在使用期间到期则归还后关闭' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 10, 1);
        $connection = $pool->borrow();
        $connection->createTime = time() - 20;
        same(0, $pool->cleanupIdleConnections(), '不能中断使用中连接');
        same(true, $pool->return($connection), '归还应成功');
        same(1, $connection->closeCalls, '归还后应关闭到期连接');
        same(false, $pool->return($connection), '不能重复归还');
    },
    '扫描前借用会替换空闲超时连接' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 1, 0.1, 10);
        $old = $pool->borrow();
        $pool->return($old);
        $old->lastReturnTime = time() - 20;
        $new = $pool->borrow();
        check($new !== $old, '不能复用空闲超时连接');
        same(1, $old->closeCalls, '借用替换也应主动关闭旧连接');
        same(0, $new->lastReturnTime, '新借用应清除空闲标记');
        $pool->return($new);
    },
    '扫描前借用会替换生命周期到期连接' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 10, 1);
        $old = $pool->borrow();
        $pool->return($old);
        $old->createTime = time() - 20;
        $new = $pool->borrow();
        check($new !== $old, '不能复用生命周期到期连接');
        same(1, $old->closeCalls, '应关闭旧连接');
        $pool->return($new);
    },
    '动态缩短空闲时间保留池和计时' => static function (): void {
        $dialer = new MemoryDialer();
        $pool = new MemoryPool($dialer, 1, 1, 0, 1, 0.1, 100);
        $connection = $pool->borrow();
        $pool->return($connection);
        $connection->lastReturnTime = time() - 20;
        $pool->setConnMaxIdleTime(10);
        same(1, $pool->cleanupIdleConnections(), '缩短配置应对已有连接生效');
        same(1, $dialer->created, '动态配置不能重建连接');
    },
    '零和负数禁用空闲超时且不重置计时' => static function (): void {
        foreach ([0, -1] as $value) {
            $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 1, 0.1, $value);
            $connection = $pool->borrow();
            $pool->return($connection);
            $connection->lastReturnTime = 1;
            same(0, $pool->cleanupIdleConnections(), '非正数构造参数应禁用');
            $pool->setConnMaxIdleTime(10);
            $pool->setConnMaxIdleTime($value);
            same(0, $pool->cleanupIdleConnections(), '非正数动态配置应禁用');
            same(0, $connection->closeCalls, '禁用时不能关闭');
            $pool->setConnMaxIdleTime(10);
            same(1, $pool->cleanupIdleConnections(), '重新启用应保留原归还计时');
        }
    },
    '原六参数调用兼容且归还超时参数位置不变' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 1, 0.2);
        $connection = $pool->borrow();
        $pool->return($connection);
        $connection->lastReturnTime = 1;
        same(0, $pool->cleanupIdleConnections(), '旧调用默认不启用空闲超时');
        $property = new ReflectionProperty($pool, 'pushTimeout');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        same(0.2, $property->getValue($pool), '第六个参数仍应是归还等待时间');
    },
    '等待者能在归还到期连接后取得新连接' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 10, 1);
        $held = $pool->borrow();
        $held->createTime = time() - 20;
        $done = new Channel(1);
        Coroutine::create(static function () use ($pool, $done): void {
            try {
                $connection = $pool->borrow();
                $pool->return($connection);
                $done->push($connection);
            } catch (Throwable $exception) {
                $done->push($exception);
            }
        });
        awaitCondition(static function () use ($pool): bool {
            return $pool->waitingBorrowers() === 1;
        });
        same(0, $pool->cleanupIdleConnections(), '等待借用时不能关闭活跃连接');
        $pool->return($held);
        $new = $done->pop(1);
        check($new instanceof Haoa\ObjectPool\Tests\MemoryConnection && $new !== $held, '等待者必须取得新连接');
        same(1, $held->closeCalls, '旧连接应释放');
    },
    '关闭挂起期间仍可借用且清理不可重入' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 2, 2, 0, 1, 0.1, 10);
        $old = $pool->borrow();
        $pool->return($old);
        $old->lastReturnTime = time() - 20;
        $entered = new Channel(1);
        $release = new Channel(1);
        $done = new Channel(1);
        $old->onClose = static function () use ($pool, $entered, $release): void {
            same(0, $pool->cleanupIdleConnections(), '不能在关闭阶段重入扫描');
            $entered->push(true);
            same(true, $release->pop(1), '关闭等待应得到释放');
        };
        Coroutine::create(static function () use ($pool, $done): void {
            try {
                $done->push($pool->cleanupIdleConnections());
            } catch (Throwable $exception) {
                $done->push($exception);
            }
        });
        same(true, $entered->pop(1), '清理应进入关闭阶段');
        $active = $pool->borrow();
        check($active !== $old, '不能借用正在关闭的连接');
        $release->push(true);
        same(1, $done->pop(1), '应完成本轮清理');
        same(0, $active->closeCalls, '不能关闭新借出的连接');
        $pool->return($active);
    },
    '整批先移出且关闭异常不阻断其余连接' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 2, 2, 0, 1, 0.1, 10);
        $first = $pool->borrow();
        $second = $pool->borrow();
        $pool->return($first);
        $pool->return($second);
        $first->lastReturnTime = $second->lastReturnTime = time() - 20;
        $first->onClose = static function () use ($pool): void {
            same(0, $pool->stats()['idle'], '关闭之前应已移出整批到期连接');
            throw new RuntimeException('模拟首条关闭失败');
        };
        $second->onClose = static function (): void {
            throw new RuntimeException('模拟第二条关闭失败');
        };
        expectException(static function () use ($pool): void {
            $pool->cleanupIdleConnections();
        }, RuntimeException::class, '模拟首条关闭失败');
        same(1, $first->closeCalls, '首条应尝试关闭');
        same(1, $second->closeCalls, '其余连接也应尝试关闭');
        same(0, $pool->stats()['total'], '关闭失败不能重新入池');
        $next = $pool->borrow();
        $pool->return($next);
        $next->lastReturnTime = time() - 20;
        same(1, $pool->cleanupIdleConnections(), '异常之后应恢复清理状态');
    },
    '替换建连失败仍释放旧连接且后续可恢复' => static function (): void {
        $dialer = new MemoryDialer();
        $pool = new MemoryPool($dialer, 1, 1, 0, 1, 0.1, 10);
        $old = $pool->borrow();
        $pool->return($old);
        $old->lastReturnTime = time() - 20;
        $dialer->failNext = true;
        expectException(static function () use ($pool): void {
            $pool->borrow();
        }, RuntimeException::class, '模拟建连失败');
        same(1, $old->closeCalls, '替换失败仍须释放旧连接');
        same(0, $pool->stats()['total'], '建连失败不能泄漏活跃登记');
        $new = $pool->borrow();
        $pool->return($new);
        same(1, $pool->stats()['idle'], '后续借用应恢复');
    },
    '无关闭方法的普通对象也可回收' => static function (): void {
        $pool = new MemoryPool(new PlainDialer(), 1, 1, 0, 1, 0.1, 10);
        $object = $pool->borrow();
        $pool->return($object);
        $object->lastReturnTime = time() - 20;
        same(1, $pool->cleanupIdleConnections(), '普通对象应可回收');
        same(null, $object->pool, '应解除普通对象关联');
    },
    '真实时间流逝后空闲超时生效' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 1, 0.1, 1);
        $connection = $pool->borrow();
        $pool->return($connection);
        Coroutine::sleep(1.1);
        same(1, $pool->cleanupIdleConnections(), '真实等待超过期限后应回收');
    },
    '清理之后等待借用超时行为保持不变' => static function (): void {
        $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 0.01, 0.1, 10);
        $held = $pool->borrow();
        try {
            $pool->borrow();
            throw new RuntimeException('应触发借用等待超时');
        } catch (WaitTimeoutException $exception) {
            same(0, $held->closeCalls, '等待超时不能影响使用中连接');
        }
        $pool->return($held);
    },
];

$failed = 0;
foreach ($tests as $name => $case) {
    if (!runCase($name, $case)) {
        ++$failed;
    }
}

if (!runCase('启用清理时协程外调用明确报错', static function (): void {
    $pool = new MemoryPool(new MemoryDialer(), 1, 1, 0, 1, 0.1, 10);
    expectException(static function () use ($pool): void {
        $pool->cleanupIdleConnections();
    }, RuntimeException::class, '清理连接池必须在协程内执行');
}, false)) {
    ++$failed;
}

$total = count($tests) + 1;
echo '测试完成：共 ' . $total . ' 项，通过 ' . ($total - $failed) . ' 项，失败 ' . $failed . ' 项。' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
