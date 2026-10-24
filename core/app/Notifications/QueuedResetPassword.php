<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(#[\SensitiveParameter] $token)
    {
        parent::__construct($token);
        $this->onQueue('mail');
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your KRATOS password')
            ->line('A password reset was requested for your KRATOS account.')
            ->action('Choose a new password', $url)
            ->line('The link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request this, you can ignore this email; your password is unchanged.');
    }
}
