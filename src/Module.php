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
    public function init(): void
    {
        Craft::setAlias('@Noo/CraftQueueFailureHandler', __DIR__);

        parent::init();

        Event::on(
            Queue::class,
            Queue::EVENT_AFTER_ERROR,
            function (ExecEvent $event) {
                $rules = Craft::$app->getConfig()->getConfigFromFile('queue-failure-handler')['rules'] ?? [];

                foreach ($rules as $rule) {
                    if ($this->matches($rule, $event)) {
                        $event->retry = false;
                        Craft::$app->getQueue()->release($event->id);
                        return;
                    }
                }
            }
        );
    }

    private function matches(callable|array $rule, ExecEvent $event): bool
    {
        if (is_callable($rule)) {
            try {
                return (bool) $rule($event);
            } catch (Throwable) {
                return false;
            }
        }

        if (isset($rule['jobClass']) && ! ($event->job instanceof $rule['jobClass'])) {
            return false;
        }

        if (isset($rule['message'])) {
            $message = $event->error?->getMessage() ?? '';
            $pattern = $rule['message'];
            $matched = str_starts_with($pattern, '/')
                ? (bool) preg_match($pattern, $message)
                : str_contains($message, $pattern);

            if (! $matched) {
                return false;
            }
        }

        return isset($rule['jobClass']) || isset($rule['message']);
    }
}
