<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Spec S2 (odd/tasks/in-app-release-form.md, T7): system email that
 * replaces EmailJS on release-form submit. Recipients mirror the original
 * EmailJS config: to info@, cc all artist emails, reply_to first artist.
 *
 * Body: structured placeholder with every form field — swap for the
 * user-provided template when it arrives (pending since L1).
 */
class ReleaseFormSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload)
    {
    }

    public function envelope(): Envelope
    {
        $artists = $this->payload['artists'] ?? [];
        $emails = array_values(array_unique(array_filter(array_column($artists, 'email'))));

        // All addresses go through the constructor so they are normalized
        // to Address objects (direct property assignment skips that).
        return new Envelope(
            to: ['info@sunsetgathering.com'],
            cc: $emails,
            replyTo: $emails !== [] ? [$emails[0]] : [],
            subject: 'Music Release Form: '.($this->payload['track_title'] ?? 'New submission'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->renderBody(),
        );
    }

    /**
     * Spec S7: the email carries every non-empty payload field — same
     * completeness as the song history / calendar detail views.
     */
    private function renderBody(): string
    {
        $releaseRows = '';
        foreach ($this->payload as $key => $value) {
            if (in_array($key, ['artists', 'artists_info'], true)) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $releaseRows .= '<tr><td style="padding:4px 12px 4px 0;vertical-align:top;"><strong>'
                .$this->labelFor((string) $key)
                .'</strong></td><td style="padding:4px 0;vertical-align:top;">'
                .$this->formatValue((string) $key, $value)
                .'</td></tr>';
        }

        $artistsHtml = '';
        foreach ($this->artists() as $index => $artist) {
            $artistsHtml .= '<h3 style="margin:18px 0 6px;">Artist '.($index + 1).'</h3><ul style="margin:0 0 8px;padding-left:18px;">';
            if (is_array($artist)) {
                foreach ($artist as $field => $value) {
                    if ($value === null || $value === '' || $value === []) {
                        continue;
                    }
                    if ($field === 'links' && is_array($value)) {
                        foreach ($value as $platform => $url) {
                            if (trim((string) $url) !== '') {
                                $artistsHtml .= '<li><strong>'.$this->labelFor((string) $platform).':</strong> '
                                    .htmlspecialchars((string) $url).'</li>';
                            }
                        }

                        continue;
                    }
                    $artistsHtml .= '<li><strong>'.$this->labelFor((string) $field).':</strong> '
                        .$this->formatValue((string) $field, $value).'</li>';
                }
            } else {
                $artistsHtml .= '<li><pre style="white-space:pre-wrap;margin:0;">'
                    .htmlspecialchars((string) $artist).'</pre></li>';
            }
            $artistsHtml .= '</ul>';
        }

        return '
        <div style="font-family:Arial,sans-serif;color:#333;">
            <h2 style="margin-bottom:4px;">Music Release Form — new submission</h2>
            <p style="margin-top:0;opacity:.7;">Full payload of the submit, identical to the song history.</p>
            <table cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
            .$releaseRows
            .'</table>
            '.$artistsHtml.'
        </div>';
    }

    /** Payload artists as arrays (new form) or the legacy text block. */
    private function artists(): array
    {
        $artists = $this->payload['artists'] ?? null;
        if (is_array($artists) && $artists !== []) {
            return array_values(array_filter($artists, 'is_array'));
        }

        $legacy = $this->payload['artists_info'] ?? null;

        return is_string($legacy) && $legacy !== '' ? [$legacy] : [];
    }

    private function labelFor(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private function formatValue(string $key, mixed $value): string
    {
        if ($key === 'publishing') {
            return $value === 'afrodise' ? 'Afrodise' : "I don't have this";
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
        }

        return nl2br(htmlspecialchars((string) $value));
    }
}
