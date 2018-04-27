<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Inspector\EventLoop;

use Phake;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use WyriHaximus\React\Inspector\EventLoop\LoopDecorator;

class LoopDecoratorTest extends TestCase
{
    public function testAddReadStream()
    {
        $loop = Phake::mock(LoopInterface::class);
        $decoratedLoop = new LoopDecorator($loop);

        $called = [
            'listener' => false,
            'addReadStream' => false,
            'readStreamTick' => false,
        ];

        $stream = 'abc';
        $listener = function ($passedStream, $passedLoop) use (&$called, $stream, $decoratedLoop) {
            $this->assertSame($stream, $passedStream);
            $this->assertSame($decoratedLoop, $passedLoop);
            $called['listener'] = true;
        };
        $decoratedLoop->on('addReadStream', function ($passedStream, $passedListener) use (&$called, $stream, $listener) {
            $this->assertSame($stream, $passedStream);
            $this->assertSame($listener, $passedListener);
            $called['addReadStream'] = true;
        });
        $decoratedLoop->on('readStreamTick', function ($passedStream, $passedListener) use (&$called, $stream, $listener) {
            $this->assertSame($stream, $passedStream);
            $this->assertSame($listener, $passedListener);
            $called['readStreamTick'] = true;
        });

        Phake::when($loop)->addReadStream($stream, $listener)->thenReturnCallback(function ($stream, $listener) use ($loop) {
            $listener($stream, $loop);
        });

        $decoratedLoop->addReadStream($stream, $listener);

        foreach ($called as $key => $call) {
            $this->assertTrue($call, $key);
        }

        Phake::verify($loop)->addReadStream($stream, $listener);
    }

    public function testAddWriteStream()
    {
        $loop = Phake::mock(LoopInterface::class);
        $decoratedLoop = new LoopDecorator($loop);

        $called = [
            'listener' => false,
            'addWriteStream' => false,
            'writeStreamTick' => false,
        ];

        $stream = 'abc';
        $listener = function ($passedStream, $passedLoop) use (&$called, $stream, $decoratedLoop) {
            $this->assertSame($stream, $passedStream);
            $this->assertSame($decoratedLoop, $passedLoop);
            $called['listener'] = true;
        };
        $decoratedLoop->on('addWriteStream', function ($passedStream, $passedListener) use (&$called, $stream, $listener) {
            $this->assertSame($stream, $passedStream);
            $this->assertSame($listener, $passedListener);
            $called['addWriteStream'] = true;
        });
        $decoratedLoop->on('writeStreamTick', function ($passedStream, $passedListener) use (&$called, $stream, $listener) {
            $this->assertSame($stream, $passedStream);
            $this->assertSame($listener, $passedListener);
            $called['writeStreamTick'] = true;
        });

        Phake::when($loop)->addWriteStream($stream, $listener)->thenReturnCallback(function ($stream, $listener) use ($loop) {
            $listener($stream, $loop);
        });

        $decoratedLoop->addWriteStream($stream, $listener);

        foreach ($called as $key => $call) {
            $this->assertTrue($call, $key);
        }

        Phake::verify($loop)->addWriteStream($stream, $listener);
    }

    public function testRemoveReadStream()
    {
        $loop = Phake::mock(LoopInterface::class);
        $decoratedLoop = new LoopDecorator($loop);

        $called = false;
        $stream = 'abc';
        $decoratedLoop->on('removeReadStream', function ($passedStream) use (&$called, $stream) {
            $this->assertSame($stream, $passedStream);
            $called = true;
        });

        $decoratedLoop->removeReadStream($stream);

        $this->assertTrue($called);
        Phake::verify($loop)->removeReadStream($stream);
    }

    public function testRemoveWriteStream()
    {
        $loop = Phake::mock(LoopInterface::class);
        $decoratedLoop = new LoopDecorator($loop);

        $called = false;
        $stream = 'abc';
        $decoratedLoop->on('removeWriteStream', function ($passedStream) use (&$called, $stream) {
            $this->assertSame($stream, $passedStream);
            $called = true;
        });

        $decoratedLoop->removeWriteStream($stream);

        $this->assertTrue($called);
        Phake::verify($loop)->removeWriteStream($stream);
    }

    public function provideSignalCallables()
    {
        yield [function () {
        }];
        yield ['spl_object_hash'];
        yield [new class() {
            public function __invoke()
            {
            }
        }];
    }

    /**
     * @dataProvider provideSignalCallables
     * @param mixed $func
     */
    public function testSignals($func)
    {
        $loop = $this->prophesize(LoopInterface::class);
        $loop->addSignal(SIGUSR1, Argument::type('callable'))->shouldBeCalled();

        $loop->removeSignal(SIGUSR1, Argument::type('callable'))->shouldBeCalled();

        $decoratedLoop = new LoopDecorator($loop->reveal());

        $decoratedLoop->addSignal(SIGUSR1, $func);
        $decoratedLoop->removeSignal(SIGUSR1, $func);
    }

    public function testAddTimer()
    {
        $loop = Factory::create();
        $decoratedLoop = new LoopDecorator($loop);

        $called = [
            'listener' => false,
            'addTimer' => false,
            'timerTick' => false,
        ];

        $interval = 0.123;
        $listener = function ($timer) use (&$called) {
            $this->assertInstanceOf(TimerInterface::class, $timer);
            $called['listener'] = true;
        };
        $decoratedLoop->on('addTimer', function ($passedInterval, $passedListener, $timer) use (&$called, $interval, $listener) {
            $this->assertSame($interval, $passedInterval);
            $this->assertSame($listener, $passedListener);
            $this->assertInstanceOf(TimerInterface::class, $timer);
            $called['addTimer'] = true;
        });
        $decoratedLoop->on('timerTick', function ($passedInterval, $passedListener, $timer) use (&$called, $interval, $listener) {
            $this->assertSame($interval, $passedInterval);
            $this->assertSame($listener, $passedListener);
            $this->assertInstanceOf(TimerInterface::class, $timer);
            $called['timerTick'] = true;
        });

        $decoratedLoop->addTimer($interval, $listener);

        $decoratedLoop->run();

        foreach ($called as $key => $call) {
            $this->assertTrue($call, $key);
        }
    }

    public function testAddPeriodicTimer()
    {
        $loop = Factory::create();
        $decoratedLoop = new LoopDecorator($loop);

        $called = [
            'listener' => false,
            'addPeriodicTimer' => false,
            'periodicTimerTick' => false,
        ];

        $interval = 0.123;
        $listener = function ($timer) use (&$called, $loop) {
            $this->assertInstanceOf(TimerInterface::class, $timer);
            $called['listener'] = true;
            $loop->cancelTimer($timer);
        };
        $decoratedLoop->on('addPeriodicTimer', function ($passedInterval, $passedListener, $passedTimer) use (&$called, $interval, $listener) {
            $this->assertSame($interval, $passedInterval);
            $this->assertSame($listener, $passedListener);
            $this->assertInstanceOf(TimerInterface::class, $passedTimer);
            $called['addPeriodicTimer'] = true;
        });
        $decoratedLoop->on('periodicTimerTick', function ($passedInterval, $passedListener, $passedTimer) use (&$called, $interval, $listener) {
            $this->assertSame($interval, $passedInterval);
            $this->assertSame($listener, $passedListener);
            $this->assertInstanceOf(TimerInterface::class, $passedTimer);
            $called['periodicTimerTick'] = true;
        });

        $decoratedLoop->addPeriodicTimer($interval, $listener);

        $decoratedLoop->run();

        foreach ($called as $key => $call) {
            $this->assertTrue($call, $key);
        }
    }

    public function testCancelTimer()
    {
        $loop = Phake::mock(LoopInterface::class);
        $timer = Phake::mock(TimerInterface::class);

        $decoratedLoop = new LoopDecorator($loop);

        $called = false;
        $decoratedLoop->on('cancelTimer', function ($passedTimer) use (&$called, $timer) {
            $this->assertSame($timer, $passedTimer);
            $called = true;
        });

        $decoratedLoop->cancelTimer($timer);

        $this->assertTrue($called);
        Phake::verify($loop)->cancelTimer($timer);
    }

    public function testFutureTick()
    {
        $loop = Phake::mock(LoopInterface::class);
        $decoratedLoop = new LoopDecorator($loop);

        $called = [
            'listener' => false,
            'futureTick' => false,
            'futureTickTick' => false,
        ];

        $listener = function () use (&$called) {
            $called['listener'] = true;
        };
        $decoratedLoop->on('futureTick', function ($passedListener) use (&$called, $listener) {
            $this->assertSame($listener, $passedListener);
            $called['futureTick'] = true;
        });
        $decoratedLoop->on('futureTickTick', function ($passedListener) use (&$called, $listener) {
            $this->assertSame($listener, $passedListener);
            $called['futureTickTick'] = true;
        });

        Phake::when($loop)->futureTick($listener)->thenReturnCallback(function ($listener) use ($loop) {
            $listener($loop);
        });

        $decoratedLoop->futureTick($listener);

        foreach ($called as $key => $call) {
            $this->assertTrue($call, $key);
        }

        Phake::verify($loop)->futureTick($listener);
    }

    public function testRun()
    {
        $loop = Phake::mock(LoopInterface::class);

        $decoratedLoop = new LoopDecorator($loop);

        $called = [
            'runStart' => false,
            'runDone' => false,
        ];

        foreach ($called as $key => $call) {
            $eventKey = $key;
            $decoratedLoop->on($eventKey, function () use (&$called, $eventKey) {
                $called[$eventKey]= true;
            });
        }

        $decoratedLoop->run();

        foreach ($called as $key => $call) {
            $this->assertTrue($call, $key);
        }

        Phake::verify($loop)->run();
    }

    public function testStop()
    {
        $loop = Phake::mock(LoopInterface::class);

        $decoratedLoop = new LoopDecorator($loop);

        $called = [
            'stopStart' => false,
            'stopDone' => false,
        ];

        foreach ($called as $key => $call) {
            $eventKey = $key;
            $decoratedLoop->on($eventKey, function () use (&$called, $eventKey) {
                $called[$eventKey]= true;
            });
        }

        $decoratedLoop->stop();

        foreach ($called as $key => $call) {
            $this->assertTrue($call, $key);
        }

        Phake::verify($loop)->stop();
    }
}
