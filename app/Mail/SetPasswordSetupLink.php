<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Spec S2: emails the signed first-time-password setup link. Sent only to
 * the account's own address; the link expires in 60 minutes and dies for
 * good once a password exists.
 */
class SetPasswordSetupLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $setupUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->user->email],
            subject: 'Set your password — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '
            <div style="font-family:Arial,sans-serif;color:#333;max-width:560px;">
                <h2 style="margin-bottom:12px;">Set your password</h2>
                <p style="line-height:1.6;">
                    Hi '.$this->escape($this->user->name).', you signed up with a social
                    login and have not chosen a password yet. Use the button below
                    to set one — it will ask for your new password only (no current
                    password needed).
                </p>
                <p style="line-height:1.6;">
                    <a href="'.$this->escape($this->setupUrl).'"
                       style="display:inline-block;padding:12px 22px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:10px;font-weight:bold;">
                        Set my password
                    </a>
                </p>
                <p style="line-height:1.6;opacity:.75;">
                    This link expires in 60 minutes and can only be used once.
                    If you did not request it you can ignore this email.
                </p>
            </div>',
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
