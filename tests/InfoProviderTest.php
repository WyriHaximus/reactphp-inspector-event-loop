<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Inspector\EventLoop;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use WyriHaximus\React\Inspector\EventLoop\InfoProvider;
use WyriHaximus\React\Inspector\EventLoop\LoopDecorator;

class InfoProviderTest extends TestCase
{
    const STREAM_READ   = 'r+';
    const STREAM_WRITE  = 'w+';
    const STREAM_DUPLEX = 'a+';

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var InfoProvider
     */
    protected $infoProvider;

    public function setUp()
    {
        parent::setUp();
        $this->loop = new LoopDecorator(new StreamSelectLoop());
        $this->infoProvider = new InfoProvider($this->loop);
    }

    public function tearDown()
    {
        $this->infoProvider = null;
        $this->loop = null;
        parent::tearDown();
    }

    public function testResetTotals()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['ticks.future.total']);

        $this->loop->futureTick(function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['ticks.future.total']);

        $this->loop->run();

        $this->infoProvider->resetTotals();

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['ticks.future.total']);
    }

    public function testResetTicks()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['ticks.future.ticks']);

        $this->loop->futureTick(function () {
        });
        $this->loop->run();

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['ticks.future.ticks']);

        $this->infoProvider->resetTicks();

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['ticks.future.ticks']);
    }

    public function testFutureTick()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['ticks.future.current']);
        $this->assertSame(0, $counters['ticks.future.total']);
        $this->assertSame(0, $counters['ticks.future.ticks']);

        $this->loop->futureTick(function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['ticks.future.current']);
        $this->assertSame(1, $counters['ticks.future.total']);
        $this->assertSame(0, $counters['ticks.future.ticks']);

        $this->loop->run();

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['ticks.future.current']);
        $this->assertSame(1, $counters['ticks.future.total']);
        $this->assertSame(1, $counters['ticks.future.ticks']);
    }

    public function testTimer()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['timers.once.current']);
        $this->assertSame(0, $counters['timers.once.total']);
        $this->assertSame(0, $counters['timers.once.ticks']);

        $this->loop->addTimer(0.0001, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['timers.once.current']);
        $this->assertSame(1, $counters['timers.once.total']);
        $this->assertSame(0, $counters['timers.once.ticks']);

        $this->loop->run();

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['timers.once.current']);
        $this->assertSame(1, $counters['timers.once.total']);
        $this->assertSame(1, $counters['timers.once.ticks']);
    }

    public function testTimerCanceledBeforeCalled()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['timers.once.current']);
        $this->assertSame(0, $counters['timers.once.total']);
        $this->assertSame(0, $counters['timers.once.ticks']);

        $timer = $this->loop->addTimer(1, function () {
        });

        $this->loop->futureTick(function () use ($timer) {
            $this->loop->cancelTimer($timer);
            $this->loop->cancelTimer($timer);
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['timers.once.current']);
        $this->assertSame(1, $counters['timers.once.total']);
        $this->assertSame(0, $counters['timers.once.ticks']);

        $this->loop->run();

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['timers.once.current']);
        $this->assertSame(1, $counters['timers.once.total']);
        $this->assertSame(0, $counters['timers.once.ticks']);
    }

    public function testPeriodicTimer()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['timers.periodic.current']);
        $this->assertSame(0, $counters['timers.periodic.total']);
        $this->assertSame(0, $counters['timers.periodic.ticks']);

        $i = 1;
        $this->loop->addPeriodicTimer(0.0001, function (TimerInterface $timer) use (&$i) {
            if ($i === 3) {
                $this->loop->cancelTimer($timer);
            }
            $i++;
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['timers.periodic.current']);
        $this->assertSame(1, $counters['timers.periodic.total']);
        $this->assertSame(0, $counters['timers.periodic.ticks']);

        $this->loop->run();

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['timers.periodic.current']);
        $this->assertSame(1, $counters['timers.periodic.total']);
        $this->assertSame(3, $counters['timers.periodic.ticks']);
    }

    public function testAddReadStream()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(0, $counters['streams.read.total']);
        $this->assertSame(0, $counters['streams.write.total']);
        $this->assertSame(0, $counters['streams.total.total']);

        $stream = $this->createStream(self::STREAM_READ);

        $this->loop->addReadStream($stream, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(1, $counters['streams.total.current']);
        $this->assertSame(1, $counters['streams.read.total']);
        $this->assertSame(0, $counters['streams.write.total']);
        $this->assertSame(1, $counters['streams.total.total']);

        $this->loop->removeReadStream($stream);

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(1, $counters['streams.read.total']);
        $this->assertSame(0, $counters['streams.write.total']);
        $this->assertSame(1, $counters['streams.total.total']);
    }

    public function testAddWriteStream()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(0, $counters['streams.read.total']);
        $this->assertSame(0, $counters['streams.write.total']);
        $this->assertSame(0, $counters['streams.total.total']);

        $stream = $this->createStream(self::STREAM_WRITE);

        $this->loop->addWriteStream($stream, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(1, $counters['streams.write.current']);
        $this->assertSame(1, $counters['streams.total.current']);
        $this->assertSame(0, $counters['streams.read.total']);
        $this->assertSame(1, $counters['streams.write.total']);
        $this->assertSame(1, $counters['streams.total.total']);

        $this->loop->removeWriteStream($stream);

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(0, $counters['streams.read.total']);
        $this->assertSame(1, $counters['streams.write.total']);
        $this->assertSame(1, $counters['streams.total.total']);
    }

    public function testComplexReadWriteDuplexStreams()
    {
        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(0, $counters['streams.read.total']);
        $this->assertSame(0, $counters['streams.write.total']);
        $this->assertSame(0, $counters['streams.total.total']);

        $streamRead   = $this->createStream(self::STREAM_READ);
        $streamWrite  = $this->createStream(self::STREAM_WRITE);
        $streamDuplex = $this->createStream(self::STREAM_DUPLEX);

        $this->loop->addReadStream($streamRead, function () {
        });
        $this->loop->addWriteStream($streamWrite, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['streams.read.current']);
        $this->assertSame(1, $counters['streams.write.current']);
        $this->assertSame(2, $counters['streams.total.current']);
        $this->assertSame(1, $counters['streams.read.total']);
        $this->assertSame(1, $counters['streams.write.total']);
        $this->assertSame(2, $counters['streams.total.total']);

        $this->loop->addReadStream($streamDuplex, function () {
        });
        $this->loop->addWriteStream($streamDuplex, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(2, $counters['streams.read.current']);
        $this->assertSame(2, $counters['streams.write.current']);
        $this->assertSame(3, $counters['streams.total.current']);
        $this->assertSame(2, $counters['streams.read.total']);
        $this->assertSame(2, $counters['streams.write.total']);
        $this->assertSame(3, $counters['streams.total.total']);

        $this->loop->removeReadStream($streamRead, function () {
        });
        $this->loop->removeWriteStream($streamWrite, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(1, $counters['streams.read.current']);
        $this->assertSame(1, $counters['streams.write.current']);
        $this->assertSame(1, $counters['streams.total.current']);
        $this->assertSame(2, $counters['streams.read.total']);
        $this->assertSame(2, $counters['streams.write.total']);
        $this->assertSame(3, $counters['streams.total.total']);

        $this->loop->removeReadStream($streamDuplex, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(1, $counters['streams.write.current']);
        $this->assertSame(1, $counters['streams.total.current']);
        $this->assertSame(2, $counters['streams.read.total']);
        $this->assertSame(2, $counters['streams.write.total']);
        $this->assertSame(3, $counters['streams.total.total']);

        $this->loop->removeWriteStream($streamDuplex, function () {
        });

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(2, $counters['streams.read.total']);
        $this->assertSame(2, $counters['streams.write.total']);
        $this->assertSame(3, $counters['streams.total.total']);

        $signalFunc = function () {
        };
        $this->loop->addSignal(SIGUSR1, $signalFunc);

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(2, $counters['streams.read.total']);
        $this->assertSame(2, $counters['streams.write.total']);
        $this->assertSame(3, $counters['streams.total.total']);
        $this->assertSame(1, $counters['signals.current']);
        $this->assertSame(1, $counters['signals.total']);
        $this->assertSame(0, $counters['signals.ticks']);

        $this->loop->removeSignal(SIGUSR1, $signalFunc);

        $counters = $this->infoProvider->getCounters();
        $this->assertSame(0, $counters['streams.read.current']);
        $this->assertSame(0, $counters['streams.write.current']);
        $this->assertSame(0, $counters['streams.total.current']);
        $this->assertSame(2, $counters['streams.read.total']);
        $this->assertSame(2, $counters['streams.write.total']);
        $this->assertSame(3, $counters['streams.total.total']);
        $this->assertSame(0, $counters['signals.current']);
        $this->assertSame(1, $counters['signals.total']);
        $this->assertSame(0, $counters['signals.ticks']);
    }

    protected function createStream($mode)
    {
        return fopen('php://temp', $mode);
    }
}
