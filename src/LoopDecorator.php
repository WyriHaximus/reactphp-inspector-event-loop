<?php declare(strict_types=1);

namespace WyriHaximus\React\Inspector\EventLoop;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class LoopDecorator implements LoopInterface, EventEmitterInterface
{
    use EventEmitterTrait;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var callable[][]
     */
    private $signals = [];

    /**
     * @param LoopInterface $loop
     */
    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    /**
     * Register a listener to be notified when a stream is ready to read.
     *
     * @param stream   $stream   The PHP stream resource to check.
     * @param callable $listener Invoked when the stream is ready.
     */
    public function addReadStream($stream, $listener)
    {
        $this->emit('addReadStream', [$stream, $listener]);
        $this->loop->addReadStream($stream, function ($stream) use ($listener) {
            $this->emit('readStreamTick', [$stream, $listener]);
            $listener($stream, $this);
        });
    }

    /**
     * Register a listener to be notified when a stream is ready to write.
     *
     * @param stream   $stream   The PHP stream resource to check.
     * @param callable $listener Invoked when the stream is ready.
     */
    public function addWriteStream($stream, $listener)
    {
        $this->emit('addWriteStream', [$stream, $listener]);
        $this->loop->addWriteStream($stream, function ($stream) use ($listener) {
            $this->emit('writeStreamTick', [$stream, $listener]);
            $listener($stream, $this);
        });
    }

    /**
     * Remove the read event listener for the given stream.
     *
     * @param stream $stream The PHP stream resource.
     */
    public function removeReadStream($stream)
    {
        $this->emit('removeReadStream', [$stream]);
        $this->loop->removeReadStream($stream);
    }

    /**
     * Remove the write event listener for the given stream.
     *
     * @param stream $stream The PHP stream resource.
     */
    public function removeWriteStream($stream)
    {
        $this->emit('removeWriteStream', [$stream]);
        $this->loop->removeWriteStream($stream);
    }

    public function addSignal($signal, $listener)
    {
        $hash = $listener;
        if (!is_string($listener)) {
            $hash = spl_object_hash($listener);
        }

        if (!isset($this->signals[$signal])) {
            $this->signals[$signal] = [];
        }

        $this->signals[$signal][$hash] = function (...$args) use ($signal, $listener, $hash) {
            $this->emit('signalTick', [$signal, $this->signals[$signal][$hash]]);
            $listener(...$args);
        };
        $this->emit('addSignal', [$signal, $this->signals[$signal][$hash]]);
        $this->loop->addSignal($signal, $this->signals[$signal][$hash]);
    }

    public function removeSignal($signal, $listener)
    {
        $hash = $listener;
        if (!is_string($listener)) {
            $hash = spl_object_hash($listener);
        }

        $this->emit('removeSignal', [$signal, $this->signals[$signal][$hash]]);
        $this->loop->removeSignal($signal, $this->signals[$signal][$hash]);

        unset($this->signals[$signal][$hash]);
        if (count($this->signals[$signal]) === 0) {
            unset($this->signals[$signal]);
        }
    }

    /**
     * Enqueue a callback to be invoked once after the given interval.
     *
     * The execution order of timers scheduled to execute at the same time is
     * not guaranteed.
     *
     * @param numeric  $interval The number of seconds to wait before execution.
     * @param callable $callback The callback to invoke.
     *
     * @return TimerInterface
     */
    public function addTimer($interval, $callback)
    {
        $loopTimer = null;
        $wrapper = function () use (&$loopTimer, $callback, $interval) {
            $this->emit('timerTick', [$interval, $callback, $loopTimer]);
            $callback($loopTimer);
        };
        $loopTimer = $this->loop->addTimer(
            $interval,
            $wrapper
        );
        $this->emit('addTimer', [$interval, $callback, $loopTimer]);

        return $loopTimer;
    }

    /**
     * Enqueue a callback to be invoked repeatedly after the given interval.
     *
     * The execution order of timers scheduled to execute at the same time is
     * not guaranteed.
     *
     * @param numeric  $interval The number of seconds to wait before execution.
     * @param callable $callback The callback to invoke.
     *
     * @return TimerInterface
     */
    public function addPeriodicTimer($interval, $callback)
    {
        $loopTimer = $this->loop->addPeriodicTimer(
            $interval,
            function () use (&$loopTimer, $callback, $interval) {
                $this->emit('periodicTimerTick', [$interval, $callback, $loopTimer]);
                $callback($loopTimer);
            }
        );
        $this->emit('addPeriodicTimer', [$interval, $callback, $loopTimer]);

        return $loopTimer;
    }

    /**
     * Cancel a pending timer.
     *
     * @param TimerInterface $timer The timer to cancel.
     */
    public function cancelTimer(TimerInterface $timer)
    {
        $this->emit('cancelTimer', [$timer]);

        return $this->loop->cancelTimer($timer);
    }

    /**
     * Check if a given timer is active.
     *
     * @param TimerInterface $timer The timer to check.
     *
     * @return bool True if the timer is still enqueued for execution.
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return $this->loop->isTimerActive($timer);
    }

    /**
     * Schedule a callback to be invoked on a future tick of the event loop.
     *
     * Callbacks are guaranteed to be executed in the order they are enqueued.
     *
     * @param callable $listener The callback to invoke.
     */
    public function futureTick($listener)
    {
        $this->emit('futureTick', [$listener]);

        return $this->loop->futureTick(function () use ($listener) {
            $this->emit('futureTickTick', [$listener]);
            $listener($this);
        });
    }

    /**
     * Run the event loop until there are no more tasks to perform.
     */
    public function run()
    {
        $this->emit('runStart');
        $this->loop->run();
        $this->emit('runDone');
    }

    /**
     * Instruct a running event loop to stop.
     */
    public function stop()
    {
        $this->emit('stopStart');
        $this->loop->stop();
        $this->emit('stopDone');
    }
}
