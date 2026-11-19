# Craft Queue Failure Handler

A small Craft CMS module that releases matching queue jobs after they fail, instead of leaving them in the failed-jobs list. Pairs with [`webhubworks/craft-flare`](https://github.com/webhubworks/craft-flare) so failures are still reported, just not stuck in the queue.

Useful for queue jobs that won't recover on retry — revoked API credentials, deleted remote resources, deactivated accounts, malformed external state.

## Requirements

- PHP 8.2+
- Craft CMS 5
- (Optional but recommended) `webhubworks/craft-flare` for error reporting

## Installation

```bash
composer require jorisnoo/craft-queue-failure-handler
```

Register the module in your `config/app.php`:

```php
return [
    'modules' => [
        'queue-failure-handler' => \Noo\CraftQueueFailureHandler\Module::class,
    ],
    'bootstrap' => ['queue-failure-handler'],
];
```

## Configuration

Create `config/queue-failure-handler.php`:

```php
<?php

use verbb\formie\jobs\TriggerIntegration;

return [
    'rules' => [
        // Drop Formie Mailchimp jobs whose account has been deactivated.
        [
            'jobClass' => TriggerIntegration::class,
            'message' => '/User Disabled|account has been deactivated/i',
        ],
    ],
];
```

Each rule is either:

- A callable `fn(\yii\queue\ExecEvent $event): bool` — full control, do whatever you want with the event.
- An array with a `jobClass`, a message condition, or both. If both are present, **both** must match.
  - `jobClass` — fully qualified class or interface name, matched with `instanceof`. Used alone this releases **every** failure of that class, including timeouts or worker crashes. Add a message condition to keep transient failures retryable.
  - `message` — a nonempty substring of the exception message, or a regular expression if it starts with `/`.
  - `messageContains` — a nonempty literal substring, including paths such as `/3.0/lists/`. Use this instead of `message` when a leading slash is literal.

Malformed rules (including unknown keys, empty conditions, and invalid regexes) are logged and skipped. A throwing callable does not prevent later rules from matching.

When a rule matches, the queue row is deleted and `$event->retry` is set to `false`. Errors that don't match any rule keep Craft's default behaviour (retry until attempts are exhausted, then sit in the failed list).

## How It Works

The module listens on `craft\queue\Queue::EVENT_AFTER_ERROR`. It calls `$event->sender->release($event->id)` on the queue that raised the error, including custom Craft queue components.

It does **not** report errors itself. If `webhubworks/craft-flare` is installed, its own listener on the same event handles reporting — the report still lands in Flare even though the queue row is gone.

## License

MIT — see [LICENSE.md](LICENSE.md).

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
