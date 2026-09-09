<?php

namespace App\Services;

/**
 * Minimal RFC822/MIME parser for a raw email fetched via RawImapClient.
 * Pulls out just what the Communications Hub needs — From/To/Date/
 * Subject/Message-ID and a best-effort plain-text body — rather than
 * being a general-purpose MIME library.
 */
class RawMimeParser
{
    /** @return array{message_id: string, subject: string, from: string, to: string, date: string, body: string} */
    public function parse(string $raw): array
    {
        [$headerBlock, $body] = $this->splitHeadersBody($raw);
        $headers = $this->parseHeaders($headerBlock);

        $contentType = $headers['content-type'] ?? 'text/plain';
        $boundary = $this->extractBoundary($contentType);

        if ($boundary !== null) {
            $text = $this->extractFromMultipart($body, $boundary);
        } else {
            $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
            $text = $this->decodeByEncoding($body, $encoding);
            if (stripos($contentType, 'text/html') !== false) {
                $text = $this->htmlToText($text);
            }
        }

        return [
            'message_id' => trim($headers['message-id'] ?? ''),
            'subject' => $this->decodeHeaderValue($headers['subject'] ?? ''),
            'from' => $this->extractAddress($headers['from'] ?? ''),
            'to' => $this->extractAddress($headers['to'] ?? ''),
            'date' => trim($headers['date'] ?? ''),
            'body' => $this->stripQuotedReply(trim($text)),
        ];
    }

    private function splitHeadersBody(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return [$raw, ''];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string, string> lowercase header name => value (unfolded) */
    private function parseHeaders(string $block): array
    {
        $lines = explode("\n", $block);
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && !empty($unfolded)) {
                $unfolded[count($unfolded) - 1] .= ' ' . trim($line);
            } else {
                $unfolded[] = $line;
            }
        }

        $headers = [];
        foreach ($unfolded as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private function extractBoundary(string $contentType): ?string
    {
        if (stripos($contentType, 'multipart/') === false) {
            return null;
        }
        if (preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractFromMultipart(string $body, string $boundary): string
    {
        $delimiter = '--' . $boundary;
        $parts = explode($delimiter, $body);

        $plainFallback = null;
        $htmlFallback = null;

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || $part === "--" || strpos($part, '--') === 0) {
                continue;
            }
            [$partHeaderBlock, $partBody] = $this->splitHeadersBody($part);
            $partHeaders = $this->parseHeaders($partHeaderBlock);
            $partType = strtolower($partHeaders['content-type'] ?? 'text/plain');

            $nestedBoundary = $this->extractBoundary($partType);
            if ($nestedBoundary !== null) {
                $nested = $this->extractFromMultipart($partBody, $nestedBoundary);
                if ($nested !== '') {
                    return $nested;
                }
                continue;
            }

            $encoding = strtolower($partHeaders['content-transfer-encoding'] ?? '');
            $decoded = $this->decodeByEncoding($partBody, $encoding);

            if (strpos($partType, 'text/plain') !== false && $plainFallback === null) {
                $plainFallback = $decoded;
            } elseif (strpos($partType, 'text/html') !== false && $htmlFallback === null) {
                $htmlFallback = $decoded;
            }
        }

        if ($plainFallback !== null) {
            return $plainFallback;
        }
        if ($htmlFallback !== null) {
            return $this->htmlToText($htmlFallback);
        }
        return '';
    }

    private function decodeByEncoding(string $data, string $encoding): string
    {
        switch ($encoding) {
            case 'base64':
                return (string) base64_decode(preg_replace('/\s+/', '', $data));
            case 'quoted-printable':
                return quoted_printable_decode($data);
            default:
                return $data;
        }
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/div)\s*\/?>/i', "\n", $html);
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    private function decodeHeaderValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }
        return $value;
    }

    private function extractAddress(string $headerValue): string
    {
        if (preg_match('/<([^>]+)>/', $headerValue, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($headerValue));
    }

    /** Cut a reply off at the quoted "On ... wrote:" block so only the new text is stored. */
    private function stripQuotedReply(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^On .{5,80} wrote:\s*$/', trim($line)) || strpos(trim($line), '>') === 0) {
                break;
            }
            $out[] = $line;
        }
        return trim(implode("\n", $out));
    }
}
