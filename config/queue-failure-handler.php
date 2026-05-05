<?php

/**
 * Example config for the Queue Failure Handler module.
 *
 * Copy to your project's `config/queue-failure-handler.php` and adjust the rules.
 *
 * Each rule is either:
 *   - a callable: fn(\yii\queue\ExecEvent $event): bool
 *   - an array with optional 'jobClass' and 'message' keys; both must match if both are present.
 *     'message' is matched as a substring against $event->error->getMessage(),
 *     unless it starts with '/' in which case it's treated as a regex.
 *
 * On match, the queue row is released (deleted) and retry is suppressed.
 * Reporting to Flare is left to webhubworks/craft-flare's own listener on the same event.
 */

return [
    'rules' => [
        // Example: drop Formie integration jobs whose Mailchimp account has been deactivated.
        // [
        //     'jobClass' => \verbb\formie\jobs\TriggerIntegration::class,
        //     'message' => '/User Disabled|account has been deactivated/i',
        // ],
    ],
];
