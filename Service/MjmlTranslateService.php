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
     * Translate MJML content in-place:
     * - inner text of <mj-text>, <mj-button>
     * - <mj-image alt="">, <mj-button title="">
     * - <mj-preview>…</mj-preview>
     *
     * Protects tokens, Twig, HTML tags via placeholders so DeepL only sees human text.
     */
    public function translateMjml(string $mjml, string $targetLangApi): array
    {
        $original = $mjml;
        $samples  = [];

        // Skip translating anything inside <mj-raw>…</mj-raw>
        $rawBlocks = [];
        $mjml = $this->extractAndShieldMjRaw($mjml, $rawBlocks);

        // 1) <mj-preview>
        $mjml = preg_replace_callback('/<mj-preview>(.*?)<\/mj-preview>/si', function ($m) use ($targetLangApi, &$samples) {
            $inner = $m[1];
            $translated = $this->translateRichText($inner, $targetLangApi, $samples);
            return '<mj-preview>' . $translated . '</mj-preview>';
        }, $mjml);

        // 2) <mj-text>…</mj-text>
        $mjml = preg_replace_callback('/<mj-text\b[^>]*>(.*?)<\/mj-text>/si', function ($m) use ($targetLangApi, &$samples) {
            $inner = $m[1];
            $translated = $this->translateRichText($inner, $targetLangApi, $samples);
            return str_replace($m[1], $translated, $m[0]);
        }, $mjml);

        // 3) <mj-button>…</mj-button> (label) + title=""
        $mjml = preg_replace_callback('/<mj-button\b([^>]*)>(.*?)<\/mj-button>/si', function ($m) use ($targetLangApi, &$samples) {
            $attrs = $m[1];
            $inner = $m[2];

            // translate the visible label
            $innerTr = $this->translateRichText($inner, $targetLangApi, $samples);

            // translate title="…"
            $attrsTr = preg_replace_callback('/\btitle="([^"]*)"/i', function ($mm) use ($targetLangApi, &$samples) {
                $t = $this->translateRichText($mm[1], $targetLangApi, $samples);
                return 'title="'.htmlspecialchars($t, ENT_QUOTES).'"';
            }, $attrs);

            return '<mj-button' . $attrsTr . '>' . $innerTr . '</mj-button>';
        }, $mjml);

        // 4) <mj-image alt="">
        $mjml = preg_replace_callback('/<mj-image\b([^>]*)>/si', function ($m) use ($targetLangApi, &$samples) {
            $attrs = $m[1];
            $attrsTr = preg_replace_callback('/\balt="([^"]*)"/i', function ($mm) use ($targetLangApi, &$samples) {
                $t = $this->translateRichText($mm[1], $targetLangApi, $samples);
                return 'alt="'.htmlspecialchars($t, ENT_QUOTES).'"';
            }, $attrs);

            return '<mj-image' . $attrsTr . '>';
        }, $mjml);

        // Put back <mj-raw> blocks
        $mjml = $this->unshieldMjRaw($mjml, $rawBlocks);

        $changed = ($mjml !== $original);

        $this->log('[AiTranslate][MJML] translateMjml done', [
            'changed'    => $changed,
            'samples'    => array_slice($samples, 0, 2), // log only first couple
        ]);

        return [
            'changed' => $changed,
            'mjml'    => $mjml,
            'samples' => $samples,
        ];
    }

    /**
     * Translate a plain/rich text segment with token/tag shielding.
     */
    public function translateRichText(string $text, string $targetLangApi, array &$samples = []): string
    {
        $original = $text;

        // 1) Shield HTML tags so DeepL doesn’t touch them
        $map = [];
        $text = $this->shieldPattern($text, '/<[^>]+>/', $map, '__TAG_%d__');

        // 2) Shield Twig {{ … }} and {% … %}
        $text = $this->shieldPattern($text, '/\{\{.*?\}\}|\{%.*?%\}/s', $map, '__TWIG_%d__');

        // 3) Shield Mautic/legacy tokens like {unsubscribe_url}, {contactfield=…}
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

        // Re-entity just in case
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
        // Replace longer placeholders first to avoid partial collisions
        uksort($map, fn($a, $b) => strlen($b) <=> strlen($a));
        return strtr($text, $map);
    }

    private function extractAndShieldMjRaw(string $mjml, array &$blocks): string
    {
        return preg_replace_callback('/<mj-raw>(.*?)<\/mj-raw>/si', function ($m) use (&$blocks) {
            $key = '__MJRAW_' . count($blocks) . '__';
            $blocks[$key] = $m[0]; // entire block
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
        // keep it simple; emails are sensitive
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
