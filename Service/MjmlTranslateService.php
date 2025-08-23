<?php

namespace MauticPlugin\AiTranslateBundle\Service;

use Psr\Log\LoggerInterface;

class MjmlTranslateService
{
    public function __construct(
        private DeeplClientService $deepl,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Translate MJML in-place, respecting LOCKED markers and <mj-raw>.
     * Uses DeepL HTML mode for inner HTML (no placeholder shielding).
     */
    public function translateMjml(string $mjml, string $targetLangApi): array
    {
        $original = $mjml;
        $samples  = [];

        // Split whole doc by LOCKED markers. If none, fall back to simple pass.
        $segments = $this->splitByLockMarkers($mjml);

        // If there were no markers at all, keep existing single-pass behavior
        if ($segments['pairs'] === 0 && !$segments['sawAnyMarker']) {
            $out     = $this->translateMjmlSegmentCore($mjml, $targetLangApi, $samples);
            $changed = ($out !== $original);

            $this->log('[AiTranslate][MJML] translateMjml done (no markers)', [
                'changed' => $changed,
                'samples' => array_slice($samples, 0, 2),
            ]);

            return [
                'changed'    => $changed,
                'mjml'       => $out,
                'samples'    => $samples,
                'lockedMode' => false,
                'lockedPairs'=> 0,
            ];
        }

        // We *did* see markers (even if unbalanced). Translate only unlocked parts.
        $rebuilt = '';
        foreach ($segments['segments'] as $seg) {
            if ($seg['type'] === 'marker') {
                // Keep LOCKED markers exactly as-is
                $rebuilt .= $seg['text'];
                continue;
            }

            if ($seg['locked']) {
                // Inside locked block: append verbatim
                $rebuilt .= $seg['text'];
                continue;
            }

            // Unlocked block: translate with the same core logic you already use
            $rebuilt .= $this->translateMjmlSegmentCore($seg['text'], $targetLangApi, $samples);
        }

        $changed = ($rebuilt !== $original);

        $this->log('[AiTranslate][MJML] translateMjml done (with markers)', [
            'changed'    => $changed,
            'pairs'      => $segments['pairs'],
            'unbalanced' => $segments['unbalanced'],
            'samples'    => array_slice($samples, 0, 2),
        ]);

        return [
            'changed'    => $changed,
            'mjml'       => $rebuilt,
            'samples'    => $samples,
            'lockedMode' => true,
            'lockedPairs'=> $segments['pairs'],
        ];
    }

    /**
     * Core pass over an unlocked MJML fragment.
     * - exclude <mj-raw>
     * - translate mj-preview, mj-text, mj-button (inner HTML)
     * - translate title/alt attributes with token-preserving logic
     */
    private function translateMjmlSegmentCore(string $frag, string $targetLangApi, array &$samples): string
    {
        // Exclude <mj-raw>…</mj-raw>
        $rawBlocks = [];
        $frag = $this->extractAndShieldMjRaw($frag, $rawBlocks);

        // <mj-preview>…</mj-preview>
        $frag = preg_replace_callback('/<mj-preview>(.*?)<\/mj-preview>/si', function ($m) use ($targetLangApi, &$samples) {
            $translated = $this->translateInnerHtml($m[1], $targetLangApi, $samples);
            return '<mj-preview>'.$translated.'</mj-preview>';
        }, $frag);

        // <mj-text>…</mj-text>
        $frag = preg_replace_callback('/<mj-text\b[^>]*>(.*?)<\/mj-text>/si', function ($m) use ($targetLangApi, &$samples) {
            $translated = $this->translateInnerHtml($m[1], $targetLangApi, $samples);
            return str_replace($m[1], $translated, $m[0]);
        }, $frag);

        // <mj-button ...>label</mj-button> + title=""
        $frag = preg_replace_callback('/<mj-button\b([^>]*)>(.*?)<\/mj-button>/si', function ($m) use ($targetLangApi, &$samples) {
            $attrs   = $m[1];
            $inner   = $m[2];

            // Translate the visible label as HTML
            $innerTr = $this->translateInnerHtml($inner, $targetLangApi, $samples);

            // Translate title="..." (plain text) but preserve tokens/Twig segments
            $attrsTr = preg_replace_callback('/\btitle="([^"]*)"/i', function ($mm) use ($targetLangApi, &$samples) {
                $t = $this->translateAttributePreserveTokens($mm[1], $targetLangApi, $samples);
                return 'title="'.htmlspecialchars($t, ENT_QUOTES).'"';
            }, $attrs);

            return '<mj-button'.$attrsTr.'>'.$innerTr.'</mj-button>';
        }, $frag);

        // <mj-image ... alt="..."/>  (preserve self-closing)
        $frag = preg_replace_callback('/<mj-image\b([^>]*?)(\s*\/?)>/si', function ($m) use ($targetLangApi, &$samples) {
            $attrs   = $m[1];
            $closing = $m[2] ?: '';
            $attrsTr = preg_replace_callback('/\balt="([^"]*)"/i', function ($mm) use ($targetLangApi, &$samples) {
                $t = $this->translateAttributePreserveTokens($mm[1], $targetLangApi, $samples);
                return 'alt="'.htmlspecialchars($t, ENT_QUOTES).'"';
            }, $attrs);

            return '<mj-image'.$attrsTr.$closing.'>';
        }, $frag);

        // Restore <mj-raw> blocks
        $frag = $this->unshieldMjRaw($frag, $rawBlocks);

        return $frag;
    }

    /**
     * DeepL HTML-mode translation for inner HTML chunks.
     * Records a sample if text changed.
     */
    private function translateInnerHtml(string $html, string $targetLangApi, array &$samples): string
    {
        $orig = $html;
        $resp = $this->deepl->translateHtml($html, $targetLangApi);
        if (!($resp['success'] ?? false)) {
            $this->log('[AiTranslate][MJML] translateInnerHtml failed', ['error' => $resp['error'] ?? 'unknown']);
            return $orig;
        }
        $translated = (string) ($resp['translation'] ?? $orig);
        if ($translated !== $orig) {
            $samples[] = ['from' => $this->preview($orig), 'to' => $this->preview($translated)];
        }
        return $translated;
    }

    /**
     * Subject / plain text helper (kept for subjects etc., no HTML).
     */
    public function translateRichText(string $text, string $targetLangApi, array &$samples = []): string
    {
        $orig = $text;
        $resp = $this->deepl->translate($text, $targetLangApi);
        if (!($resp['success'] ?? false)) {
            $this->log('[AiTranslate][MJML] translateRichText failed', ['error' => $resp['error'] ?? 'unknown']);
            return $orig;
        }
        $translated = (string) ($resp['translation'] ?? $orig);
        if ($translated !== $orig) {
            $samples[] = ['from' => $this->preview($orig), 'to' => $this->preview($translated)];
        }
        return $translated;
    }

    /**
     * Translate attribute values while preserving tokens/Twig exactly.
     * Splits text by token-like segments and only translates the non-token parts.
     */
    private function translateAttributePreserveTokens(string $value, string $targetLangApi, array &$samples): string
    {
        if ($value === '') {
            return $value;
        }

        $parts = $this->splitByTokens($value);
        $out   = '';

        foreach ($parts as $p) {
            if ($p['type'] === 'token') {
                $out .= $p['value']; // keep as-is
            } else {
                $resp = $this->deepl->translate($p['value'], $targetLangApi);
                $out .= ($resp['success'] ?? false) ? ($resp['translation'] ?? $p['value']) : $p['value'];
            }
        }

        if ($out !== $value) {
            $samples[] = ['from' => $this->preview($value), 'to' => $this->preview($out)];
        }

        return $out;
    }

    /**
     * Split a string into token vs non-token parts.
     * Tokens: Twig {{..}}, {%..%}, and Mautic-style {...}.
     * @return array<int,array{type:'token'|'text',value:string}>
     */
    private function splitByTokens(string $s): array
    {
        $re = '/(\{\{.*?\}\}|\{%.*?%\}|\{[a-z0-9_:.%-]+(?:=[^}]+)?\})/i';
        $chunks = preg_split($re, $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($chunks === false) {
            return [['type' => 'text', 'value' => $s]];
        }

        $parts = [];
        foreach ($chunks as $c) {
            if ($c === '') continue;
            if (preg_match($re, $c)) {
                $parts[] = ['type' => 'token', 'value' => $c];
            } else {
                $parts[] = ['type' => 'text', 'value' => $c];
            }
        }
        return $parts;
    }

    /**
     * LOCKED markers splitter (unchanged).
     */
    private function splitByLockMarkers(string $s): array
    {
        $pattern = '/(<!--\s*LOCKED_START\s*-->|<!--\s*LOCKED_END\s*-->)/i';
        $parts   = preg_split($pattern, $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            // On regex failure, treat as no markers
            return [
                'pairs'        => 0,
                'unbalanced'   => false,
                'sawAnyMarker' => false,
                'segments'     => [['type' => 'text', 'locked' => false, 'text' => $s]],
            ];
        }

        $segments  = [];
        $locked    = false;
        $pairs     = 0;
        $sawMarker = false;

        foreach ($parts as $chunk) {
            if ($chunk === '') continue;

            if (preg_match('/^<!--\s*LOCKED_START\s*-->$/i', $chunk)) {
                $sawMarker  = true;
                $segments[] = ['type' => 'marker', 'text' => $chunk];
                $locked     = true;
                continue;
            }
            if (preg_match('/^<!--\s*LOCKED_END\s*-->$/i', $chunk)) {
                $sawMarker  = true;
                $segments[] = ['type' => 'marker', 'text' => $chunk];
                if ($locked) { $pairs++; }
                $locked = false;
                continue;
            }

            // normal text block
            $segments[] = ['type' => 'text', 'locked' => $locked, 'text' => $chunk];
        }

        $unbalanced = $locked; // ended while still locked

        return [
            'pairs'        => $pairs,
            'unbalanced'   => $unbalanced,
            'sawAnyMarker' => $sawMarker,
            'segments'     => $segments,
        ];
    }

    private function extractAndShieldMjRaw(string $mjml, array &$blocks): string
    {
        return preg_replace_callback('/<mj-raw>(.*?)<\/mj-raw>/si', function ($m) use (&$blocks) {
            $key          = '__MJRAW_' . count($blocks) . '__';
            $blocks[$key] = $m[0]; // entire block
            return $key;
        }, $mjml);
    }

    private function unshieldMjRaw(string $mjml, array $blocks): string
    {
        if (!$blocks) return $mjml;
        uksort($blocks, fn($a, $b) => strlen($b) <=> strlen($a));
        return strtr($mjml, $blocks);
    }

    private function preview(string $s, int $len = 80): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s));
        return (mb_strlen($s) > $len) ? (mb_substr($s, 0, $len) . '…') : $s;
    }

    private function log(string $msg, array $ctx = []): void
    {
        if ($this->logger) {
            $this->logger->info($msg, $ctx);
        } else {
            @error_log($msg.' '.json_encode($ctx));
        }
    }
}
