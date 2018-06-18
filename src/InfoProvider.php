<?php declare(strict_types=1);

namespace WyriHaximus\React\Inspector\EventLoop;

use React\EventLoop\TimerInterface;
use WyriHaximus\React\Inspector\GlobalState;

class InfoProvider
{
    /**
     * @var LoopDecorator
     */
    protected $loop;

    /**
     * @var array
     */
    protected $counters = [];

    /**
     * @var array
     */
    protected $streamsRead = [];

    /**
     * @var array
     */
    protected $streamsWrite = [];

    /**
     * @var array
     */
    protected $streamsDuplex = [];

    /**
     * @var TimerInterface[]
     */
    private $timers = [];

    /**
     * @var int[]
     */
    private $signals = [];

    /**
     * @param LoopDecorator $loop
     */
    public function __construct(LoopDecorator $loop)
    {
        $this->loop = $loop;
        $this->reset();

        $this->setupTicks($loop);
        $this->setupTimers($loop);
        $this->setupStreams($loop);
        $this->setupSignals($loop);
    }

    protected function setupTicks(LoopDecorator $loop)
    {
        $loop->on('futureTick', function () {
            GlobalState::incr('ticks.future.current');
            GlobalState::incr('ticks.future.total');
        });
        $loop->on('futureTickTick', function () {
            GlobalState::decr('ticks.future.current');
            GlobalState::incr('ticks.future.ticks');
        });
    }

    protected function setupTimers(LoopDecorator $loop)
    {
        $loop->on('addTimer', function ($_, $__, $timer) {
            $this->timers[spl_object_hash($timer)] = true;
            GlobalState::incr('timers.once.current');
            GlobalState::incr('timers.once.total');
        });
        $loop->on('timerTick', function ($_, $__, $timer) {
            GlobalState::decr('timers.once.current');
            GlobalState::incr('timers.once.ticks');

            $hash = spl_object_hash($timer);
            if (!isset($this->timers[$hash])) {
                return;
            }

            unset($this->timers[$hash]);
        });
        $loop->on('addPeriodicTimer', function ($_, $__, $timer) {
            $this->timers[spl_object_hash($timer)] = true;
            GlobalState::incr('timers.periodic.current');
            GlobalState::incr('timers.periodic.total');
        });
        $loop->on('periodicTimerTick', function () {
            GlobalState::incr('timers.periodic.ticks');
        });
        $loop->on('cancelTimer', function (TimerInterface $timer) {
            $hash = spl_object_hash($timer);
            if (!isset($this->timers[$hash])) {
                return;
            }

            unset($this->timers[$hash]);

            if ($timer->isPeriodic()) {
                GlobalState::decr('timers.periodic.current');

                return;
            }

            GlobalState::decr('timers.once.current');
        });
    }

    protected function setupStreams(LoopDecorator $loop)
    {
        $loop->on('addReadStream', function ($stream) {
            $key = (int) $stream;

            $this->streamsRead[$key] = $stream;
            $this->streamsDuplex[$key] = $stream;

            GlobalState::set('streams.read.current', count($this->streamsRead));
            GlobalState::set('streams.total.current', count($this->streamsDuplex));
            GlobalState::incr('streams.read.total');
            if (!isset($this->streamsWrite[$key])) {
                GlobalState::incr('streams.total.total');
            }
        });
        $loop->on('readStreamTick', function () {
            GlobalState::incr('streams.read.ticks');
            GlobalState::incr('streams.total.ticks');
        });
        $loop->on('removeReadStream', function ($stream) {
            $key = (int) $stream;

            if (isset($this->streamsRead[$key])) {
                unset($this->streamsRead[$key]);
            }
            if (isset($this->streamsDuplex[$key]) && !isset($this->streamsWrite[$key])) {
                unset($this->streamsDuplex[$key]);
            }

            GlobalState::set('streams.read.current', count($this->streamsRead));
            GlobalState::set('streams.total.current', count($this->streamsDuplex));
        });

        $loop->on('addWriteStream', function ($stream) {
            $key = (int) $stream;

            $this->streamsWrite[$key] = $stream;
            $this->streamsDuplex[$key] = $stream;

            GlobalState::set('streams.write.current', count($this->streamsWrite));
            GlobalState::set('streams.total.current', count($this->streamsDuplex));
            GlobalState::incr('streams.write.total');

            if (!isset($this->streamsRead[$key])) {
                GlobalState::incr('streams.total.total');
            }
        });
        $loop->on('writeStreamTick', function () {
            GlobalState::incr('streams.write.ticks');
            GlobalState::incr('streams.total.ticks');
        });
        $loop->on('removeWriteStream', function ($stream) {
            $key = (int) $stream;

            if (isset($this->streamsWrite[$key])) {
                unset($this->streamsWrite[$key]);
            }
            if (isset($this->streamsDuplex[$key]) && !isset($this->streamsRead[$key])) {
                unset($this->streamsDuplex[$key]);
            }

            GlobalState::set('streams.write.current', count($this->streamsWrite));
            GlobalState::set('streams.total.current', count($this->streamsDuplex));
        });
    }

    protected function setupSignals(LoopDecorator $loop)
    {
        $loop->on('signalTick', function () {
            GlobalState::incr('signals.ticks');
        });
        $loop->on('addSignal', function ($signal, $listener) {
            if (!isset($this->signals[$signal])) {
                $this->signals[$signal] = [];
            }

            $hash = spl_object_hash($listener);

            $this->signals[$signal][$hash] = $listener;

            GlobalState::set('signals.current', count($this->signals));
            GlobalState::incr('signals.total');
        });
        $loop->on('removeSignal', function ($signal, $listener) {
            $hash = spl_object_hash($listener);

            unset($this->signals[$signal][$hash]);
            if (count($this->signals[$signal]) === 0) {
                unset($this->signals[$signal]);
            }

            GlobalState::set('signals.current', count($this->signals));
        });
    }
}
