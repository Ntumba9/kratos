<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Verification email delivered by a queue worker so that SMTP latency or
 * failure never blocks registration. The signed link targets the API, which
 * verifies and then redirects the browser to KRATOS COMMAND.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct()
    {
        $this->onQueue('mail');
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your KRATOS account')
            ->line('Confirm this email address to activate your KRATOS account.')
            ->action('Verify email address', $url)
            ->line('The link expires in 60 minutes. If you did not create an account, no action is required.');
    }
}
