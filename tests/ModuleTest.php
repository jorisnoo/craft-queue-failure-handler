<?php

use craft\console\Application;
use craft\queue\Queue;
use craft\services\Config;
use Noo\CraftQueueFailureHandler\Module;
use yii\base\Event;
use yii\log\Logger;
use yii\queue\ExecEvent;
use yii\queue\JobInterface;

beforeEach(function() {
    Craft::$app = $this->createStub(Application::class);
    Craft::setLogger(new Logger());
});

afterEach(function() {
    Event::off(Queue::class, Queue::EVENT_AFTER_ERROR);
    Craft::$app = null;
});

function loadQueueFailureModule(mixed $rules): Module
{
    $config = test()->createStub(Config::class);
    $config->method('getConfigFromFile')->willReturn(['rules' => $rules]);
    Craft::$app->method('getConfig')->willReturn($config);
    return new Module('custom-id');
}

function dispatchQueueFailure(bool $shouldRelease): ExecEvent
{
    $queue = test()->createMock(Queue::class);
    $queue->expects($shouldRelease ? test()->once() : test()->never())->method('release')->with('42');
    $event = new ExecEvent([
        'id' => '42',
        'job' => test()->createStub(JobInterface::class),
        'error' => new RuntimeException('Remote /3.0/lists/ account disabled'),
        'retry' => true,
    ]);
    Event::trigger($queue, Queue::EVENT_AFTER_ERROR, $event);
    return $event;
}

it('releases only matching jobs from the queue that raised the error', function(array $rule, bool $match) {
    loadQueueFailureModule([$rule]);
    expect(dispatchQueueFailure($match)->retry)->toBe(!$match);
})->with([
    'substring' => [['message' => 'disabled'], true],
    'regex' => [['message' => '/DISABLED$/i'], true],
    'literal leading slash' => [['messageContains' => '/3.0/lists/'], true],
    'class only' => [['jobClass' => JobInterface::class], true],
    'both match' => [['jobClass' => JobInterface::class, 'message' => 'disabled'], true],
    'wrong class' => [['jobClass' => stdClass::class, 'message' => 'disabled'], false],
    'wrong message' => [['jobClass' => JobInterface::class, 'message' => 'not found'], false],
]);

it('logs invalid rules without deleting the job', function(mixed $rules) {
    loadQueueFailureModule($rules);
    expect(dispatchQueueFailure(false)->retry)->toBeTrue();
    expect(Craft::getLogger()->messages)->toHaveCount(1);
})->with([
    'non-array rules setting' => ['wrong'],
    'null rule' => [[null]],
    'non-callable entry' => [['not a callable']],
    'bad regex' => [[['message' => '/unfinished']]],
    'empty message' => [[['message' => '', 'jobClass' => JobInterface::class]]],
    'null condition' => [[['message' => null, 'jobClass' => JobInterface::class]]],
    'non-string class' => [[['jobClass' => 7]]],
    'non-string message' => [[['message' => []]]],
    'unknown key' => [[['jobClass' => JobInterface::class, 'mesage' => 'typo']]],
    'ambiguous message' => [[['message' => 'disabled', 'messageContains' => 'enabled']]],
]);

it('continues past invalid and throwing rules and stops after the first match', function() {
    $calls = [];
    loadQueueFailureModule([
        null,
        static fn() => throw new RuntimeException('Bad predicate'),
        static function(ExecEvent $event) use (&$calls) {
            $calls[] = $event->id;
            return true;
        },
        static function() use (&$calls) { $calls[] = 'unexpected'; },
    ]);
    expect(dispatchQueueFailure(true)->retry)->toBeFalse();
    expect($calls)->toBe(['42']);
    expect(Craft::getLogger()->messages)->toHaveCount(2);
});

it('finds a loaded module under any registration ID', function() {
    expect(Module::getInstance())->toBeNull();
    $module = loadQueueFailureModule([]);
    expect(Module::getInstance())->toBe($module);
});
