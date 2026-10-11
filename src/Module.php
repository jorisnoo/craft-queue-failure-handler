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
                $event->sender->release($event->id);
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
                ? $this->matchesRegex($pattern, $message)
                : str_contains($message, $pattern);

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function matchesRegex(string $pattern, string $message): bool
    {
        $result = @preg_match($pattern, $message);

        if ($result === false) {
            $reason = error_get_last()['message'] ?? preg_last_error_msg();
            Craft::error("Invalid queue failure rule pattern {$pattern}: {$reason}", __METHOD__);
            return false;
        }

        return $result === 1;
    }
}
