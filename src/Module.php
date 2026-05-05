<?php

namespace Noo\CraftQueueFailureHandler;

use Craft;
use craft\queue\Queue;
use Throwable;
use yii\base\Event;
use yii\base\Module as BaseModule;
use yii\queue\ExecEvent;

class Module extends BaseModule
{
    private array $rules = [];

    public static function getInstance(): static
    {
        return Craft::$app->getModule('queue-failure-handler');
    }

    public function init(): void
    {
        Craft::setAlias('@Noo/CraftQueueFailureHandler', __DIR__);

        parent::init();

        $this->rules = Craft::$app->getConfig()->getConfigFromFile('queue-failure-handler')['rules'] ?? [];

        if (empty($this->rules)) {
            return;
        }

        Event::on(
            Queue::class,
            Queue::EVENT_AFTER_ERROR,
            fn(ExecEvent $event) => $this->handleError($event),
        );
    }

    private function handleError(ExecEvent $event): void
    {
        foreach ($this->rules as $rule) {
            if ($this->matches($rule, $event)) {
                $event->retry = false;
                Craft::$app->getQueue()->release($event->id);
                return;
            }
        }
    }

    private function matches(callable|array $rule, ExecEvent $event): bool
    {
        if (is_callable($rule)) {
            try {
                return (bool) $rule($event);
            } catch (Throwable $e) {
                Craft::error('Queue failure rule threw: ' . $e->getMessage(), __METHOD__);
                return false;
            }
        }

        $hasJobClass = isset($rule['jobClass']);
        $hasMessage = isset($rule['message']);

        if (! $hasJobClass && ! $hasMessage) {
            return false;
        }

        if ($hasJobClass && ! ($event->job instanceof $rule['jobClass'])) {
            return false;
        }

        if ($hasMessage) {
            $message = $event->error?->getMessage() ?? '';
            $pattern = $rule['message'];
            $matched = str_starts_with($pattern, '/')
                ? (bool) preg_match($pattern, $message)
                : str_contains($message, $pattern);

            if (! $matched) {
                return false;
            }
        }

        return true;
    }
}
