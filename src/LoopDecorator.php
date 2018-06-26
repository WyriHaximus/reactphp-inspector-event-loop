<?php declare(strict_types=1);

namespace WyriHaximus\React\Inspector\EventLoop;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use WyriHaximus\React\Inspector\GlobalState;

final class LoopDecorator implements LoopInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var array
     */
    private $counters = [];

    /**
     * @var array
     */
    private $streamsRead = [];

    /**
     * @var array
     */
    private $streamsWrite = [];

    /**
     * @var array
     */
    private $streamsDuplex = [];

    /**
     * @var TimerInterface[]
     */
    private $timers = [];

    /**
     * @var int[]
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
        $key = (int) $stream;

        $this->streamsRead[$key] = $stream;
        $this->streamsDuplex[$key] = $stream;

        GlobalState::set('streams.read.current', count($this->streamsRead));
        GlobalState::set('streams.total.current', count($this->streamsDuplex));
        GlobalState::incr('streams.read.total');
        if (!isset($this->streamsWrite[$key])) {
            GlobalState::incr('streams.total.total');
        }

        $this->loop->addReadStream($stream, function ($stream) use ($listener) {
            GlobalState::incr('streams.read.ticks');
            GlobalState::incr('streams.total.ticks');

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
        $key = (int) $stream;

        $this->streamsWrite[$key] = $stream;
        $this->streamsDuplex[$key] = $stream;

        GlobalState::set('streams.write.current', count($this->streamsWrite));
        GlobalState::set('streams.total.current', count($this->streamsDuplex));
        GlobalState::incr('streams.write.total');

        if (!isset($this->streamsRead[$key])) {
            GlobalState::incr('streams.total.total');
        }

        $this->loop->addWriteStream($stream, function ($stream) use ($listener) {
            GlobalState::incr('streams.write.ticks');
            GlobalState::incr('streams.total.ticks');

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
        $key = (int) $stream;

        if (isset($this->streamsRead[$key])) {
            unset($this->streamsRead[$key]);
        }
        if (isset($this->streamsDuplex[$key]) && !isset($this->streamsWrite[$key])) {
            unset($this->streamsDuplex[$key]);
        }

        GlobalState::set('streams.read.current', count($this->streamsRead));
        GlobalState::set('streams.total.current', count($this->streamsDuplex));

        $this->loop->removeReadStream($stream);
    }

    /**
     * Remove the write event listener for the given stream.
     *
     * @param stream $stream The PHP stream resource.
     */
    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->streamsWrite[$key])) {
            unset($this->streamsWrite[$key]);
        }
        if (isset($this->streamsDuplex[$key]) && !isset($this->streamsRead[$key])) {
            unset($this->streamsDuplex[$key]);
        }

        GlobalState::set('streams.write.current', count($this->streamsWrite));
        GlobalState::set('streams.total.current', count($this->streamsDuplex));

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
            GlobalState::incr('signals.ticks');

            $listener(...$args);
        };

        GlobalState::set('signals.current', count($this->signals));
        GlobalState::incr('signals.total');

        $this->loop->addSignal($signal, $this->signals[$signal][$hash]);
    }

    public function removeSignal($signal, $listener)
    {
        $hash = $listener;
        if (!is_string($listener)) {
            $hash = spl_object_hash($listener);
        }

        $this->loop->removeSignal($signal, $this->signals[$signal][$hash]);

        unset($this->signals[$signal][$hash]);
        if (count($this->signals[$signal]) === 0) {
            unset($this->signals[$signal]);
        }

        GlobalState::set('signals.current', count($this->signals));
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
            GlobalState::decr('timers.once.current');
            GlobalState::incr('timers.once.ticks');

            $callback($loopTimer);

            unset($this->timers[spl_object_hash($loopTimer)]);
        };
        $loopTimer = $this->loop->addTimer(
            $interval,
            $wrapper
        );

        $this->timers[spl_object_hash($loopTimer)] = true;

        GlobalState::incr('timers.once.current');
        GlobalState::incr('timers.once.total');

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
                GlobalState::incr('timers.periodic.ticks');

                $callback($loopTimer);
            }
        );

        $this->timers[spl_object_hash($loopTimer)] = true;

        GlobalState::incr('timers.periodic.current');
        GlobalState::incr('timers.periodic.total');

        return $loopTimer;
    }

    /**
     * Cancel a pending timer.
     *
     * @param TimerInterface $timer The timer to cancel.
     */
    public function cancelTimer(TimerInterface $timer)
    {
        $isPeriodic = $timer->isPeriodic();
        $hash = spl_object_hash($timer);
        $this->loop->cancelTimer($timer);
        if (!isset($this->timers[$hash])) {
            return;
        }

        unset($this->timers[$hash]);

        if ($isPeriodic) {
            GlobalState::decr('timers.periodic.current');

            return;
        }

        GlobalState::decr('timers.once.current');
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
        GlobalState::incr('ticks.future.current');
        GlobalState::incr('ticks.future.total');

        return $this->loop->futureTick(function () use ($listener) {
            GlobalState::decr('ticks.future.current');
            GlobalState::incr('ticks.future.ticks');
            $listener($this);
        });
    }

    /**
     * Run the event loop until there are no more tasks to perform.
     */
    public function run()
    {
        $this->loop->run();
    }

    /**
     * Instruct a running event loop to stop.
     */
    public function stop()
    {
        $this->loop->stop();
    }
}
