<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Spec S2 + S12 + S13 (odd/tasks/form-delivery-and-scale.md): the system
 * email that replaces EmailJS. Recipients mirror the original config
 * (to info@, cc artist emails, reply_to first artist) and the body is
 * styled like the dashboard page — dark card, uppercase headings, gray
 * labels — carrying EVERY payload field with artist links as real
 * anchors, never JSON.
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
     * Spec S7 + S12 + S13: every non-empty payload field, styled like the
     * dashboard page (dark card, uppercase gray labels, accent links).
     */
    private function renderBody(): string
    {
        $submitted = now()->format('M j, Y · H:i');

        $rows = '';
        foreach ($this->payload as $key => $value) {
            if (in_array($key, ['artists', 'artists_info'], true)) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $rows .= '
                <tr>
                    <td class="afd-label" style="padding:9px 22px 9px 0;vertical-align:top;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#a1a1aa;">'
                        .$this->escape($this->labelFor((string) $key)).'</td>
                    <td style="padding:9px 0;vertical-align:top;font-size:14px;line-height:1.55;color:#ffffff;">'
                        .$this->formatValue((string) $key, $value).'</td>
                </tr>';
        }

        $artistsHtml = '';
        foreach ($this->artists() as $index => $artist) {
            $artistsHtml .= $this->renderArtist($index, $artist);
        }

        return '
        <style>
            @media only screen and (max-width:480px){
                .afd-card{padding:22px 16px !important;}
                .afd-label{display:block !important;white-space:normal !important;padding:0 0 2px !important;}
                .afd-h1{font-size:20px !important;letter-spacing:2px !important;}
                .afd-artist{padding:16px !important;}
            }
        </style>
        <div style="margin:0;padding:28px 12px;background-color:#000000;font-family:Arial,Helvetica,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#000000;">
                <tr>
                    <td align="center" style="padding:10px;">
                        <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0"
                               style="max-width:640px;width:100%;background-color:#0a0a0a;border:1px solid rgba(255,255,255,.12);border-radius:20px;">
                            <tr>
                                <td class="afd-card" style="padding:34px;">
                                    <h1 class="afd-h1" style="margin:0 0 8px;font-size:26px;font-weight:400;letter-spacing:3px;text-transform:uppercase;color:#ffffff;">Music Release Form</h1>
                                    <p style="margin:0 0 26px;font-size:14px;color:#a1a1aa;">New submission · '.$this->escape($submitted).'</p>
                                    <table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">'
                                        .$rows.'
                                    </table>
                                    '.$artistsHtml.'
                                    <p style="margin:28px 0 0;padding-top:18px;border-top:1px solid rgba(255,255,255,.12);font-size:12px;color:#a1a1aa;">Sunset Gathering · this email carries the full payload — the same data as the song history.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>';
    }

    /** One artist card: heading + field rows + platform links as anchors. */
    private function renderArtist(int $index, mixed $artist): string
    {
        if (! is_array($artist)) {
            // Legacy text block (artists_info).
            return '
            <div style="border:1px solid rgba(255,255,255,.12);border-radius:12px;background:rgba(255,255,255,.04);padding:18px 20px;margin-top:16px;">
                <h3 style="margin:0 0 10px;font-size:17px;font-weight:700;color:#ffffff;">Artist '.($index + 1).'</h3>
                <p style="margin:0;font-size:14px;line-height:1.55;color:#ffffff;white-space:pre-wrap;">'.$this->escape((string) $artist).'</p>
            </div>';
        }

        $name = 'Artist '.($index + 1);
        foreach (['name', 'artist_name'] as $nameKey) {
            if (! empty($artist[$nameKey])) {
                $name = (string) $artist[$nameKey];
                break;
            }
        }

        $fieldRows = '';
        $linkRows = '';
        foreach ($artist as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($field === 'links') {
                foreach ((array) $value as $platform => $url) {
                    $url = trim((string) $url);
                    if ($url === '') {
                        continue;
                    }
                    // Spec S13: platform links are anchors, never JSON.
                    $linkRows .= '
                    <tr>
                        <td style="padding:6px 0;font-size:14px;">
                            <span style="color:#a1a1aa;">'.$this->escape($this->labelFor((string) $platform)).'</span>
                            <span style="color:#a1a1aa;"> — </span>
                            <a href="'.$this->escape($url).'" style="color:#5b7fff;text-decoration:underline;word-break:break-all;">'.$this->escape($url).'</a>
                        </td>
                    </tr>';
                }

                continue;
            }

            if (in_array($field, ['name', 'artist_name'], true)) {
                continue; // the card heading carries the name
            }

            $fieldRows .= '
                <tr>
                    <td class="afd-label" style="padding:7px 20px 7px 0;vertical-align:top;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#a1a1aa;">'
                        .$this->escape($this->labelFor((string) $field)).'</td>
                    <td style="padding:7px 0;vertical-align:top;font-size:14px;line-height:1.55;color:#ffffff;">'
                        .$this->formatValue((string) $field, $value).'</td>
                </tr>';
        }

        $linksBlock = '';
        if ($linkRows !== '') {
            $linksBlock = '
                                    <p style="margin:16px 0 4px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#a1a1aa;">Platform links</p>
                                    <table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">'
                                        .$linkRows.'
                                    </table>';
        }

        return '
            <div class="afd-artist" style="border:1px solid rgba(255,255,255,.12);border-radius:12px;background:rgba(255,255,255,.04);padding:20px;margin-top:16px;">
                <h3 style="margin:0 0 12px;font-size:17px;font-weight:700;color:#ffffff;">'.$this->escape($name).'</h3>
                <table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">'
                    .$fieldRows.'
                </table>
                '.$linksBlock.'
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
            return $this->escape(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
        }

        $text = (string) $value;

        // Spec S13: URL values become accent anchors, like the history page.
        if (filter_var($text, FILTER_VALIDATE_URL)) {
            return '<a href="'.$this->escape($text).'" style="color:#5b7fff;text-decoration:underline;word-break:break-all;">'
                .$this->escape($text).'</a>';
        }

        return nl2br($this->escape($text));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
