# Mix Object Pool

基于 Swoole 协程的通用对象池、连接池，支持按需建连、连接复用、借用等待，以及客户端主动清理到期空闲连接。

## 安装与环境

```bash
composer require haoa/object-pool
```

PHP 版本要求为 `>= 7.2`，使用时需要安装 Swoole，并在协程内执行借用、归还和清理操作。本库不依赖应用框架、Hyperf、PDO 或 Redis 扩展。

## 创建连接池

具体池继承 `Haoa\ObjectPool\AbstractObjectPool`，拨号器实现 `DialerInterface::dial(): object`。返回对象使用 `ObjectTrait`，提供池关联和时间信息；有物理连接的对象应提供公开、无必填参数的 `close()` 方法。

```php
use Haoa\ObjectPool\AbstractObjectPool;

/** 业务使用的具体连接池。 */
class ConnectionPool extends AbstractObjectPool
{
}

// $dialer 为调用方提供的 DialerInterface 实例。
$pool = new ConnectionPool($dialer, 100, 10, 3600, 10, 0.1, 300);
```

构造参数按顺序如下：

| 参数 | 默认值 | 含义 |
| --- | --- | --- |
| `$dialer` | 必填 | 连接拨号器，按需创建对象 |
| `$maxOpen` | `-1` | 最大连接数，`-1` 取 CPU 数量，`0` 不限制；当前无锁实现为软上限 |
| `$maxIdle` | `-1` | 最大空闲连接数，`-1` 取 CPU 数量；应设置为正数，`0` 不代表禁用空闲池 |
| `$maxLifetime` | `0` | 自建立起的最大生命周期，单位秒，非正数不限制 |
| `$waitTimeout` | `0.0` | 等待借用的时间，单位秒，`0` 表示无限等待 |
| `$returnTimeout` | `0.1` | 放回空闲队列的等待时间，单位秒 |
| `$maxIdleTime` | `0` | 自最近一次归还起的最大空闲时间，单位秒，非正数不限制 |

`maxIdleTime` 是新增的第七个可选参数，原有第六个参数仍是 `returnTimeout`，原调用方式不变。库默认不启用空闲超时，示例中的 `300` 表示五分钟。时间信息以秒记录。

## 借用、归还与统计

在协程中执行：

```php
$connection = $pool->borrow();
try {
    // 使用连接完成业务操作。
} finally {
    $connection->__return();
}

$stats = $pool->stats(); // 包含 total、idle、active。
```

借用成功后清除空闲标记，每次归还重新记录 `lastReturnTime`，业务持有时长不算空闲。归还后不得继续使用该连接，也不能跨协程并发共享已借出的连接。

`__discard()` 用于原有的异常连接替换路径，会建立新连接补入池中；它与下面不补建连接的空闲清理接口不同。

## 主动清理空闲连接

```php
// 动态调整现有池，不重建连接，也不重置归还时间。
$pool->setConnMaxIdleTime(60);

// 在协程中调用，返回本轮移除的对象数量。
$removed = $pool->cleanupIdleConnections();
```

- 生命周期或空闲时间任一条件到期就回收；两者都禁用时直接返回 `0`。
- 只扫描空闲队列，不关闭借用中的连接，不中断正在执行的命令或事务。
- 先移出本轮到期对象，再逐条关闭；有 `close()` 方法时主动调用，普通对象则解除池关联并释放引用。
- 不立即补建，允许池缩到零，后续借用按需建立新连接。
- 扫描不会刷新未到期连接的归还时间，重复清理不会重复关闭已回收对象。
- 借用前也检查生命周期和空闲时间，扫描间隔内到期的连接会先替换再交给业务。
- 归还时检查生命周期；没有等待者时直接关闭，到期连接有等待者时交由借用检查替换。归还时不会把业务持有时间当成空闲时间。
- 清理有重入保护。一条关闭失败仍继续关闭本批其余对象，最后抛出首次异常，扫描状态在 `finally` 中恢复；失败对象不重新入池。
- 启用清理配置时，在协程外调用会抛出 `RuntimeException`。无清理条件时直接返回 `0`。

## 定时调度与退出

本库只提供清理方法，**不会自动注册定时器**。调用方可以使用已有的调度组件，每三十秒调用一次 `cleanupIdleConnections()`，并在应用退出时停止任务。固定间隔扫描通常会有一个扫描周期的回收延迟，借用前检查作为兜底。

例如在 Swoole 应用的 Worker 或 CLI 协程中注册：

```php
$timerId = Swoole\Timer::tick(30000, static function () use ($pool): void {
    try {
        $pool->cleanupIdleConnections();
    } catch (\Throwable $exception) {
        // 实际项目可替换为应用自己的异常记录方法。
        fwrite(STDERR, '连接池清理失败：' . $exception->getMessage() . PHP_EOL);
    }
});

// 在应用实际的退出清理回调中执行，不能紧接注册后立即清除。
// Swoole\Timer::clear($timerId);
```

每个进程独立创建池和调度任务，不在 fork 前创建并继承连接。不重复注册同一池的定时任务；重建池时停止旧任务或让回调明确访问当前池。原生 Swoole 定时器不识别应用自己的退出协调通知，需要调用方显式清理，尤其要避免 CLI 因后台任务未停止而持续运行。

## 与 Go 实现的关系

参考 [Go database/sql 源码](https://go.dev/src/database/sql/sql.go) 中的 `putConn`、`connectionCleaner` 和 `connectionCleanerRunLocked`：区分创建与归还时间，只处理空闲集合，先移出再关闭，归还时检查生命周期。

这是结合 Swoole Channel 的适配，没有逐行移植。Go 优先复用最近归还的连接，并动态调整扫描间隔；本库保留 FIFO 队列，调度频率由调用方决定，也没有增加最小保留连接数配置。

## 测试

测试直接加载当前库的 `src/`，不依赖 Composer 自动加载或 PHPUnit，不访问数据库、Redis 或其他网络服务。需要支持 `Swoole\Coroutine\run()` 的 Swoole 运行环境。

```bash
php tests/run.php
```

安装了 Composer 时也可运行：

```bash
composer test
```

覆盖生命周期与空闲超时、未到期和活跃连接保护、归还重新计时、扫描幂等、动态配置、旧六参数兼容、等待借用、关闭挂起与重入、批量关闭异常、替换建连失败恢复、普通对象回收、真实时间超时，以及协程外调用保护。通过时退出码为 `0`，任一失败或环境不支持时退出码为 `1`。

## 许可证

Apache License Version 2.0，http://www.apache.org/licenses/。
