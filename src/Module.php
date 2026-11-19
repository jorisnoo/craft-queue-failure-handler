<?php

namespace Noo\CraftQueueFailureHandler;

use Craft;
use craft\queue\Queue;
use InvalidArgumentException;
use Throwable;
use yii\base\Event;
use yii\base\Module as BaseModule;
use yii\queue\ExecEvent;

class Module extends BaseModule
{
    /** @var array<array-key, callable(ExecEvent): mixed> */
    private array $rules = [];

    public function init(): void
    {
        Craft::setAlias('@Noo/CraftQueueFailureHandler', __DIR__);

        parent::init();

        static::setInstance($this);
        /** @var \craft\web\Application|\craft\console\Application $app */
        $app = Craft::$app;
        $config = $app->getConfig()->getConfigFromFile('queue-failure-handler');
        if (!is_array($config) || (array_key_exists('rules', $config) && !is_array($config['rules']))) {
            Craft::error('Expected an array of queue failure rules.', __METHOD__);
            return;
        }
        foreach ($config['rules'] ?? [] as $key => $rule) {
            try {
                $this->rules[$key] = $this->prepareRule($rule);
            } catch (Throwable $e) {
                Craft::error("Skipping queue failure rule $key: " . $e->getMessage(), __METHOD__);
            }
        }

        if ($this->rules === []) {
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
        foreach ($this->rules as $key => $matches) {
            try {
                $matched = $matches($event);
            } catch (Throwable $e) {
                Craft::error("Queue failure rule $key threw: " . $e->getMessage(), __METHOD__);
                continue;
            }

            if ($matched && $event->sender instanceof Queue && $event->id !== null) {
                $event->sender->release($event->id);
                $event->retry = false;
                return;
            }
        }
    }

    /** @return callable(ExecEvent): mixed */
    private function prepareRule(mixed $rule): callable
    {
        if (is_callable($rule)) {
            return $rule;
        }
        if (!is_array($rule) || $rule === [] || array_diff_key($rule, ['jobClass' => true, 'message' => true, 'messageContains' => true])) {
            throw new InvalidArgumentException('Expected a callable or a rule with jobClass, message or messageContains.');
        }
        foreach ($rule as $key => $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException("$key must be a nonempty string.");
            }
        }
        if (isset($rule['message'], $rule['messageContains'])) {
            throw new InvalidArgumentException('Use either message or messageContains, not both.');
        }

        $class = $rule['jobClass'] ?? null;
        $pattern = $rule['message'] ?? $rule['messageContains'] ?? null;
        $regex = isset($rule['message']) && str_starts_with($rule['message'], '/');
        if ($regex) {
            // PCRE signals a malformed pattern with a warning. Capture its diagnostic
            // even when Craft suppresses warnings, then restore the host's handler.
            set_error_handler(static function(int $level, string $message): never {
                throw new InvalidArgumentException("Invalid regular expression: $message");
            }, E_WARNING);
            try {
                if (preg_match($pattern, '') === false) {
                    throw new InvalidArgumentException('Invalid regular expression in message.');
                }
            } finally {
                restore_error_handler();
            }
        }

        return static function(ExecEvent $event) use ($class, $pattern, $regex): bool {
            if ($class !== null && !($event->job instanceof $class)) {
                return false;
            }
            if ($pattern === null) {
                return true;
            }
            $message = $event->error?->getMessage() ?? '';
            if (!$regex) {
                return str_contains($message, $pattern);
            }
            $result = preg_match($pattern, $message);
            if ($result === false) {
                throw new \RuntimeException('Regular expression failed: ' . preg_last_error_msg());
            }
            return $result === 1;
        };
    }
}
