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
     * Translate MJML in-place, respecting LOCKED markers and mj-raw blocks.
     *
     * LOCKED regions:
     *   <!-- LOCKED_START --> ... <!-- LOCKED_END -->
     * Everything inside remains exactly as-is (including comments/tags).
     * Outside of LOCKED, we translate text of:
     *   - <mj-preview>, <mj-text>, <mj-button> (inner text + title="")
     *   - <mj-image alt="">
     * Comments and markup are never translated.
     *
     * @return array{changed: bool, mjml: string, samples: array<int,array{from:string,to:string}>, lockedMode: bool, lockedPairs: int}
     */
    public function translateMjml(string $mjml, string $targetLangApi): array
    {
        $original = $mjml;
        $samples  = [];

        // Split whole doc by LOCKED markers. If none, fall back to simple pass.
        $segments = $this->splitByLockMarkers($mjml);

        // If there were no markers at all, keep existing single-pass behavior
        if ($segments['pairs'] === 0 && !$segments['sawAnyMarker']) {
            $out = $this->translateMjmlSegmentCore($mjml, $targetLangApi, $samples);
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
     * Core translator for a single (unlocked) MJML fragment:
     * - shields <mj-raw>
     * - translates mj-preview, mj-text, mj-button (inner + title), mj-image alt
     * - restores <mj-raw>
     */
    private function translateMjmlSegmentCore(string $frag, string $targetLangApi, array &$samples): string
    {
        $original = $frag;

        // Skip translating anything inside <mj-raw>…</mj-raw>
        $rawBlocks = [];
        $frag = $this->extractAndShieldMjRaw($frag, $rawBlocks);

        // 1) <mj-preview>
        $frag = preg_replace_callback('/<mj-preview>(.*?)<\/mj-preview>/si', function ($m) use ($targetLangApi, &$samples) {
            $inner = $m[1];
            $translated = $this->translateRichText($inner, $targetLangApi, $samples);
            return '<mj-preview>' . $translated . '</mj-preview>';
        }, $frag);

        // 2) <mj-text>…</mj-text>
        $frag = preg_replace_callback('/<mj-text\b[^>]*>(.*?)<\/mj-text>/si', function ($m) use ($targetLangApi, &$samples) {
            $inner = $m[1];
            $translated = $this->translateRichText($inner, $targetLangApi, $samples);
            return str_replace($m[1], $translated, $m[0]);
        }, $frag);

        // 3) <mj-button>…</mj-button> (label) + title=""
        $frag = preg_replace_callback('/<mj-button\b([^>]*)>(.*?)<\/mj-button>/si', function ($m) use ($targetLangApi, &$samples) {
            $attrs = $m[1];
            $inner = $m[2];

            $innerTr = $this->translateRichText($inner, $targetLangApi, $samples);

            $attrsTr = preg_replace_callback('/\btitle="([^"]*)"/i', function ($mm) use ($targetLangApi, &$samples) {
                $t = $this->translateRichText($mm[1], $targetLangApi, $samples);
                return 'title="'.htmlspecialchars($t, ENT_QUOTES).'"';
            }, $attrs);

            return '<mj-button' . $attrsTr . '>' . $innerTr . '</mj-button>';
        }, $frag);

        // 4) <mj-image alt="">
        $frag = preg_replace_callback('/<mj-image\b([^>]*)>/si', function ($m) use ($targetLangApi, &$samples) {
            $attrs = $m[1];
            $attrsTr = preg_replace_callback('/\balt="([^"]*)"/i', function ($mm) use ($targetLangApi, &$samples) {
                $t = $this->translateRichText($mm[1], $targetLangApi, $samples);
                return 'alt="'.htmlspecialchars($t, ENT_QUOTES).'"';
            }, $attrs);

            return '<mj-image' . $attrsTr . '>';
        }, $frag);

        // Put back <mj-raw> blocks
        $frag = $this->unshieldMjRaw($frag, $rawBlocks);

        return $frag;
    }

    /**
     * Split a document into segments (locked/unlocked + marker nodes).
     * Handles unbalanced/nested markers gracefully by treating everything
     * after the first START until next END as locked (no nesting).
     *
     * @return array{
     *   pairs:int,
     *   unbalanced:bool,
     *   sawAnyMarker:bool,
     *   segments: array<int, array{type:'text'|'marker', locked?:bool, text:string}>
     * }
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

        $segments   = [];
        $locked     = false;
        $pairs      = 0;
        $sawMarker  = false;

        foreach ($parts as $chunk) {
            if ($chunk === '') continue;

            if (preg_match('/^<!--\s*LOCKED_START\s*-->$/i', $chunk)) {
                $sawMarker = true;
                $segments[] = ['type' => 'marker', 'text' => $chunk];
                $locked = true;
                continue;
            }
            if (preg_match('/^<!--\s*LOCKED_END\s*-->$/i', $chunk)) {
                $sawMarker = true;
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

    /**
     * Translate a plain/rich text segment with token/tag/comment shielding.
     * Comments and tags are protected and reinstated 1:1.
     */
    public function translateRichText(string $text, string $targetLangApi, array &$samples = []): string
    {
        $original = $text;

        // Shield HTML comments so DeepL never sees them (and markers remain intact if present here)
        $map = [];
        $text = $this->shieldPattern($text, '/<!--.*?-->/s', $map, '__CMT_%d__');

        // Shield HTML tags
        $text = $this->shieldPattern($text, '/<[^>]+>/', $map, '__TAG_%d__');

        // Shield Twig {{ … }} and {% … %}
        $text = $this->shieldPattern($text, '/\{\{.*?\}\}|\{%.*?%\}/s', $map, '__TWIG_%d__');

        // Shield Mautic/legacy tokens like {unsubscribe_url}, {contactfield=…}
        $text = $this->shieldPattern($text, '/\{[a-z0-9_:.%-]+(?:=[^}]+)?\}/i', $map, '__TOK_%d__');

        // Trim edges only
        $forApi = trim(html_entity_decode($text, ENT_QUOTES));
        if ($forApi === '') {
            return $original; // nothing to translate
        }

        $resp = $this->deepl->translate($forApi, $targetLangApi);
        if (!($resp['success'] ?? false)) {
            $this->log('[AiTranslate][MJML] segment translation failed', ['error' => $resp['error'] ?? 'unknown']);
            return $original; // keep original on error
        }

        $tr = $resp['translation'] ?? $forApi;

        // Unshield placeholders
        $tr = $this->unshield($tr, $map);

        // Re-entity (conservative)
        $tr = $this->reentity($tr);

        if ($original !== $tr) {
            $samples[] = ['from' => $this->preview($original), 'to' => $this->preview($tr)];
        }

        return $tr;
    }

    // --- helpers -------------------------------------------------------------

    private function shieldPattern(string $text, string $regex, array &$map, string $tpl): string
    {
        return preg_replace_callback($regex, function ($m) use (&$map, $tpl) {
            $key = sprintf($tpl, count($map));
            $map[$key] = $m[0];
            return $key;
        }, $text);
    }

    private function unshield(string $text, array $map): string
    {
        if (!$map) return $text;
        uksort($map, fn($a, $b) => strlen($b) <=> strlen($a));
        return strtr($text, $map);
    }

    private function extractAndShieldMjRaw(string $mjml, array &$blocks): string
    {
        return preg_replace_callback('/<mj-raw>(.*?)<\/mj-raw>/si', function ($m) use (&$blocks) {
            $key = '__MJRAW_' . count($blocks) . '__';
            $blocks[$key] = $m[0];
            return $key;
        }, $mjml);
    }

    private function unshieldMjRaw(string $mjml, array $blocks): string
    {
        if (!$blocks) return $mjml;
        return strtr($mjml, $blocks);
    }

    private function reentity(string $s): string
    {
        return $s;
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
