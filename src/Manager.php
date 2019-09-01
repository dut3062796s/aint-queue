<?php

/*
 * This file is part of the littlesqx/aint-queue.
 *
 * (c) littlesqx <littlesqx@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace Littlesqx\AintQueue;

use Littlesqx\AintQueue\Driver\Redis\Queue;
use Littlesqx\AintQueue\Exception\RuntimeException;
use Littlesqx\AintQueue\Helper\EnvironmentHelper;
use Littlesqx\AintQueue\Logger\DefaultLogger;
use Littlesqx\AintQueue\Timer\TickTimerProcess;
use Psr\Log\LoggerInterface;
use Swoole\Process as SwooleProcess;
use Symfony\Component\Process\Process;

class Manager
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var QueueInterface|AbstractQueue|Queue
     */
    protected $queue;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var TickTimerProcess
     */
    protected $tickTimer;

    public function __construct(QueueInterface $driver, array $options = [])
    {
        $this->queue = $driver;
        $this->options = $options;

        $this->init();
    }

    protected function init()
    {
        $this->logger = new DefaultLogger();
    }

    /**
     * Get master pid file path.
     *
     * @return string
     */
    protected function getPidFile(): string
    {
        $root = $this->options['pid_path'] ?? '';
        return $root . "/{$this->getQueue()->getChannel()}-master.pid";
    }
    /**
     * Register signal handler.
     */
    protected function registerSignal(): void
    {
        // force exit
        SwooleProcess::signal(SIGTERM, function ($signo) {
            $this->tickTimer->stop();
            $this->exitMaster();
        });
        // force killed
        SwooleProcess::signal(SIGKILL, function ($signo) {
            $this->tickTimer->stop();
            $this->exitMaster();
        });
        // ctrl + c
        SwooleProcess::signal(SIGINT, function ($signo) {
            $this->tickTimer->stop();
            $this->exitMaster();
        });
        // custom signal - exit smoothly
        SwooleProcess::signal(SIGUSR1, function ($signo) {
            $this->tickTimer->stop();
            $this->exitMaster();
        });
        // custom signal - record process status
        SwooleProcess::signal(SIGUSR2, function ($signo) {
        });
    }

    /**
     * Register timer-process.
     */
    protected function registerTimer(): void
    {
        $this->tickTimer = new TickTimerProcess([
            // move expired job
            new Timer\TickTimer(1000, function () {
                $this->queue->migrateExpired();
            }),
            // check queue status
            new Timer\TickTimer(1000 * 60 * 5, function () {
                $this->queue->checkStatus();
            }),
        ]);

        $this->tickTimer->start();
    }

    /**
     * Set a logger.
     *
     * @param LoggerInterface $logger
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Get current work logger.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get current queue instance.
     *
     * @return QueueInterface
     */
    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    /**
     * Listen the queue, to distribute job.
     */
    public function listen(): void
    {
        $this->registerSignal();
        $this->registerTimer();

        $director = new WorkerDirector($this);

        file_put_contents($this->getPidFile(), getmypid());

        while (true) {
            try {
                [$id, , $job] = $this->queue->pop();

                if (null === $job) {
                    sleep($this->getSleepTime());
                    continue;
                }
                $director->dispatch($id, $job);
            } catch (\Throwable $t) {
                $this->getLogger()->error('Job execute error, '.$t->getMessage());
            }

            if ($this->memoryExceeded()) {
                $this->getLogger()->info('Memory exceeded, exit smoothly.');
                $director->wait();
                $this->tickTimer->stop();
                $this->exitMaster();
            }
        }
    }

    /**
     * Whether memory exceeded or not.
     *
     * @return bool
     */
    public function memoryExceeded(): bool
    {
        $usage = (memory_get_usage(true) / 1024 / 1024);

        return $usage >= $this->getMemoryLimit();
    }

    /**
     * Execute job in current process.
     *
     * @param $messageId
     */
    public function executeJob($messageId): void
    {
        $id = $attempts = $job = null;

        try {
            [$id, $attempts, $job] = $this->queue->get($messageId);

            if (null === $job) {
                $this->getLogger()->error('Unresolvable job.', [
                    'driver' => get_class($this->queue),
                    'channel' => $this->queue->getChannel(),
                    'message_id' => $id,
                ]);

                return;
            }

            if (is_callable($job)) {
                $job($this->queue);
                $this->queue->remove($id);
            } elseif ($job instanceof JobInterface) {
                $job->handle($this->queue);
                $this->queue->remove($id);
            } else {
                $type = is_object($job) ? get_class($job) : gettype($job);
                $this->getLogger()->error('Not supported job, type: '.$type.'.', [
                    'driver' => get_class($this->queue),
                    'channel' => $this->queue->getChannel(),
                    'message_id' => $id,
                ]);
            }
        } catch (\Throwable $t) {
            if ($job instanceof JobInterface && $job->canRetry($attempts, $t)) {
                $delay = max($job->getNextRetryTime($attempts) - time(), 0);
                $this->queue->release($id, $delay);
            }
            $this->getLogger()->error(get_class($t).': '.$t->getMessage(), [
                'driver' => get_class($this->queue),
                'channel' => $this->queue->getChannel(),
                'message_id' => $id,
            ]);
        }
    }

    /**
     * Execute job in a new process. (blocking).
     *
     * @param $messageId
     *
     * @throws RuntimeException
     */
    public function executeJobInProcess($messageId): void
    {
        $timeout = $this->options['worker']['process_worker']['max_execute_seconds'] ?? 60;
        try {
            [$id, , $job] = $this->queue->get($messageId);
            $job instanceof SyncJobInterface && $timeout = $job->getTtr();
        } catch (\Throwable $t) {
            $this->getLogger()->error(get_class($t).': '.$t->getMessage(), [
                'driver' => get_class($this->queue),
                'channel' => $this->queue->getChannel(),
                'message_id' => $id ?? 'null',
            ]);

            return;
        }

        $entry = EnvironmentHelper::getAppBinary();
        if (null === $entry) {
            throw new RuntimeException('Fail to get app entry file.');
        }

        $cmd = [
            EnvironmentHelper::getPhpBinary(),
            $entry,
            'queue:run',
            "--id={$messageId}",
            "--channel={$this->queue->getChannel()}",
        ];

        $process = new Process($cmd);

        // set timeout
        if ($timeout > 0) {
            $process->setTimeout($timeout);
        }

        $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                fwrite(\STDERR, $buffer);
            } else {
                fwrite(\STDOUT, $buffer);
            }
        });
    }

    /**
     * Get manager's memory limit.
     *
     * @return float
     */
    public function getMemoryLimit(): float
    {
        return (float) ($this->options['memory_limit'] ?? 1024);
    }

    /**
     * Get sleep time(s) after every pop.
     *
     * @return int
     */
    public function getSleepTime(): int
    {
        return (int) max($this->options['sleep_seconds'] ?? 0, 0);
    }

    /**
     * Exit master process.
     */
    public function exitMaster(): void
    {
        @unlink($this->getPidFile());
        exit(0);
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options ?? [];
    }

    /**
     * Whether current channel's master is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        $pidFile = $this->getPidFile();
        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
            return SwooleProcess::kill($pid, 0);
        }

        return false;
    }
}
