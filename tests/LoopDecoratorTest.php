<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Inspector\EventLoop;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use WyriHaximus\React\Inspector\EventLoop\InfoProvider;
use WyriHaximus\React\Inspector\EventLoop\LoopDecorator;
use WyriHaximus\React\Inspector\GlobalState;

class LoopDecoratorTest extends TestCase
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
        GlobalState::bootstrap();
        $this->loop = new LoopDecorator(new StreamSelectLoop());
    }

    public function tearDown()
    {
        $this->loop = null;
        GlobalState::clear();
        parent::tearDown();
    }

    public function testReset()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.ticks.future.total']);

        $this->loop->futureTick(function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.ticks.future.total']);

        $this->loop->run();

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.ticks.future.ticks']);

        GlobalState::reset();

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.ticks.future.total']);
        $this->assertSame(0, $counters['eventloop.ticks.future.ticks']);
    }

    public function testFutureTick()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.ticks.future.current']);
        $this->assertSame(0, $counters['eventloop.ticks.future.total']);
        $this->assertSame(0, $counters['eventloop.ticks.future.ticks']);

        $this->loop->futureTick(function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.ticks.future.current']);
        $this->assertSame(1, $counters['eventloop.ticks.future.total']);
        $this->assertSame(0, $counters['eventloop.ticks.future.ticks']);

        $this->loop->run();

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.ticks.future.current']);
        $this->assertSame(1, $counters['eventloop.ticks.future.total']);
        $this->assertSame(1, $counters['eventloop.ticks.future.ticks']);
    }

    public function testTimer()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.timers.once.current']);
        $this->assertSame(0, $counters['eventloop.timers.once.total']);
        $this->assertSame(0, $counters['eventloop.timers.once.ticks']);

        $this->loop->addTimer(0.0001, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.timers.once.current']);
        $this->assertSame(1, $counters['eventloop.timers.once.total']);
        $this->assertSame(0, $counters['eventloop.timers.once.ticks']);

        $this->loop->run();

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.timers.once.current']);
        $this->assertSame(1, $counters['eventloop.timers.once.total']);
        $this->assertSame(1, $counters['eventloop.timers.once.ticks']);
    }

    public function testTimerCanceledBeforeCalled()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.timers.once.current']);
        $this->assertSame(0, $counters['eventloop.timers.once.total']);
        $this->assertSame(0, $counters['eventloop.timers.once.ticks']);

        $timer = $this->loop->addTimer(1, function () {
        });

        $this->loop->futureTick(function () use ($timer) {
            $this->loop->cancelTimer($timer);
            $this->loop->cancelTimer($timer);
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.timers.once.current']);
        $this->assertSame(1, $counters['eventloop.timers.once.total']);
        $this->assertSame(0, $counters['eventloop.timers.once.ticks']);

        $this->loop->run();

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.timers.once.current']);
        $this->assertSame(1, $counters['eventloop.timers.once.total']);
        $this->assertSame(0, $counters['eventloop.timers.once.ticks']);
    }

    public function testPeriodicTimer()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.timers.periodic.current']);
        $this->assertSame(0, $counters['eventloop.timers.periodic.total']);
        $this->assertSame(0, $counters['eventloop.timers.periodic.ticks']);

        $i = 1;
        $this->loop->addPeriodicTimer(0.0001, function (TimerInterface $timer) use (&$i) {
            if ($i === 3) {
                $this->loop->cancelTimer($timer);
            }
            $i++;
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.timers.periodic.current']);
        $this->assertSame(1, $counters['eventloop.timers.periodic.total']);
        $this->assertSame(0, $counters['eventloop.timers.periodic.ticks']);

        $this->loop->run();

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.timers.periodic.current']);
        $this->assertSame(1, $counters['eventloop.timers.periodic.total']);
        $this->assertSame(3, $counters['eventloop.timers.periodic.ticks']);
    }

    public function testAddReadStream()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(0, $counters['eventloop.streams.read.total']);
        $this->assertSame(0, $counters['eventloop.streams.write.total']);
        $this->assertSame(0, $counters['eventloop.streams.total.total']);

        $stream = $this->createStream(self::STREAM_READ);

        $this->loop->addReadStream($stream, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(1, $counters['eventloop.streams.total.current']);
        $this->assertSame(1, $counters['eventloop.streams.read.total']);
        $this->assertSame(0, $counters['eventloop.streams.write.total']);
        $this->assertSame(1, $counters['eventloop.streams.total.total']);

        $this->loop->removeReadStream($stream);

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(1, $counters['eventloop.streams.read.total']);
        $this->assertSame(0, $counters['eventloop.streams.write.total']);
        $this->assertSame(1, $counters['eventloop.streams.total.total']);
    }

    public function testAddWriteStream()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(0, $counters['eventloop.streams.read.total']);
        $this->assertSame(0, $counters['eventloop.streams.write.total']);
        $this->assertSame(0, $counters['eventloop.streams.total.total']);

        $stream = $this->createStream(self::STREAM_WRITE);

        $this->loop->addWriteStream($stream, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(1, $counters['eventloop.streams.write.current']);
        $this->assertSame(1, $counters['eventloop.streams.total.current']);
        $this->assertSame(0, $counters['eventloop.streams.read.total']);
        $this->assertSame(1, $counters['eventloop.streams.write.total']);
        $this->assertSame(1, $counters['eventloop.streams.total.total']);

        $this->loop->removeWriteStream($stream);

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(0, $counters['eventloop.streams.read.total']);
        $this->assertSame(1, $counters['eventloop.streams.write.total']);
        $this->assertSame(1, $counters['eventloop.streams.total.total']);
    }

    public function testComplexReadWriteDuplexStreams()
    {
        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(0, $counters['eventloop.streams.read.total']);
        $this->assertSame(0, $counters['eventloop.streams.write.total']);
        $this->assertSame(0, $counters['eventloop.streams.total.total']);

        $streamRead   = $this->createStream(self::STREAM_READ);
        $streamWrite  = $this->createStream(self::STREAM_WRITE);
        $streamDuplex = $this->createStream(self::STREAM_DUPLEX);

        $this->loop->addReadStream($streamRead, function () {
        });
        $this->loop->addWriteStream($streamWrite, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.streams.read.current']);
        $this->assertSame(1, $counters['eventloop.streams.write.current']);
        $this->assertSame(2, $counters['eventloop.streams.total.current']);
        $this->assertSame(1, $counters['eventloop.streams.read.total']);
        $this->assertSame(1, $counters['eventloop.streams.write.total']);
        $this->assertSame(2, $counters['eventloop.streams.total.total']);

        $this->loop->addReadStream($streamDuplex, function () {
        });
        $this->loop->addWriteStream($streamDuplex, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(2, $counters['eventloop.streams.read.current']);
        $this->assertSame(2, $counters['eventloop.streams.write.current']);
        $this->assertSame(3, $counters['eventloop.streams.total.current']);
        $this->assertSame(2, $counters['eventloop.streams.read.total']);
        $this->assertSame(2, $counters['eventloop.streams.write.total']);
        $this->assertSame(3, $counters['eventloop.streams.total.total']);

        $this->loop->removeReadStream($streamRead, function () {
        });
        $this->loop->removeWriteStream($streamWrite, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(1, $counters['eventloop.streams.read.current']);
        $this->assertSame(1, $counters['eventloop.streams.write.current']);
        $this->assertSame(1, $counters['eventloop.streams.total.current']);
        $this->assertSame(2, $counters['eventloop.streams.read.total']);
        $this->assertSame(2, $counters['eventloop.streams.write.total']);
        $this->assertSame(3, $counters['eventloop.streams.total.total']);

        $this->loop->removeReadStream($streamDuplex, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(1, $counters['eventloop.streams.write.current']);
        $this->assertSame(1, $counters['eventloop.streams.total.current']);
        $this->assertSame(2, $counters['eventloop.streams.read.total']);
        $this->assertSame(2, $counters['eventloop.streams.write.total']);
        $this->assertSame(3, $counters['eventloop.streams.total.total']);

        $this->loop->removeWriteStream($streamDuplex, function () {
        });

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(2, $counters['eventloop.streams.read.total']);
        $this->assertSame(2, $counters['eventloop.streams.write.total']);
        $this->assertSame(3, $counters['eventloop.streams.total.total']);

        $signalFunc = function () {
        };
        $this->loop->addSignal(SIGUSR1, $signalFunc);

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(2, $counters['eventloop.streams.read.total']);
        $this->assertSame(2, $counters['eventloop.streams.write.total']);
        $this->assertSame(3, $counters['eventloop.streams.total.total']);
        $this->assertSame(1, $counters['eventloop.signals.current']);
        $this->assertSame(1, $counters['eventloop.signals.total']);
        $this->assertSame(0, $counters['eventloop.signals.ticks']);

        $this->loop->removeSignal(SIGUSR1, $signalFunc);

        $counters = GlobalState::get();
        $this->assertSame(0, $counters['eventloop.streams.read.current']);
        $this->assertSame(0, $counters['eventloop.streams.write.current']);
        $this->assertSame(0, $counters['eventloop.streams.total.current']);
        $this->assertSame(2, $counters['eventloop.streams.read.total']);
        $this->assertSame(2, $counters['eventloop.streams.write.total']);
        $this->assertSame(3, $counters['eventloop.streams.total.total']);
        $this->assertSame(0, $counters['eventloop.signals.current']);
        $this->assertSame(1, $counters['eventloop.signals.total']);
        $this->assertSame(0, $counters['eventloop.signals.ticks']);
    }

    protected function createStream($mode)
    {
        return fopen('php://temp', $mode);
    }
}
