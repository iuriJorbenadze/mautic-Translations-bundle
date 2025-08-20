<?php

namespace MauticPlugin\AiTranslateBundle\Service;

use Psr\Log\LoggerInterface;

class MjmlCompileService
{
    public function __construct(
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Compile MJML into HTML.
     *
     * Strategy:
     *  A) mjml CLI (if available)
     *  B) graceful fallback (very light tag mapping so preview shows translated text)
     *
     * @return array{success: bool, html?: string, error?: string}
     */
    public function compile(string $mjml, ?string $template = null): array
    {
        // A) Try mjml CLI
        $cli = $this->findMjmlCli();
        if ($cli !== null) {
            $r = $this->compileViaCli($cli, $mjml);
            if ($r['success']) {
                return $r;
            }
            $this->log('[AiTranslate][MJML] CLI compile failed, falling back', ['error' => $r['error'] ?? 'unknown']);
        }

        // B) Fallback: minimal tag mapping so preview shows translated text
        $html = $this->veryLightFallback($mjml);
        return ['success' => true, 'html' => $html];
    }

    private function findMjmlCli(): ?string
    {
        // Try common ways to detect CLI
        $candidates = ['mjml', '/usr/bin/mjml', '/usr/local/bin/mjml', '/bin/mjml'];
        foreach ($candidates as $bin) {
            $out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null') ?: '';
            if ($out !== '') {
                return trim($out);
            }
            if (is_executable($bin)) {
                return $bin;
            }
        }
        return null;
    }

    /**
     * Uses temporary files and the mjml CLI to compile.
     */
    private function compileViaCli(string $cli, string $mjml): array
    {
        $in  = tempnam(sys_get_temp_dir(), 'mjml_in_') ?: null;
        $out = tempnam(sys_get_temp_dir(), 'mjml_out_') ?: null;

        if (!$in || !$out) {
            return ['success' => false, 'error' => 'Unable to create temp files'];
        }

        file_put_contents($in, $mjml);

        $cmd = escapeshellcmd($cli) . ' ' . escapeshellarg($in) . ' -o ' . escapeshellarg($out) . ' 2>&1';
        $this->log('[AiTranslate][MJML] invoking CLI', ['cmd' => $cmd]);

        $output = @shell_exec($cmd);
        $ok     = is_file($out) && filesize($out) > 0;

        $html   = $ok ? file_get_contents($out) : null;

        @unlink($in);
        @unlink($out);

        if (!$ok || $html === false) {
            return ['success' => false, 'error' => trim((string) $output)];
        }

        return ['success' => true, 'html' => $html];
    }

    /**
     * Extremely light fallback so previews don't stay stale if CLI is missing.
     * Not a full MJML renderer—just unwraps key tags to reasonable HTML.
     */
    private function veryLightFallback(string $mjml): string
    {
        $html = $mjml;

        // Strip mjml/mj-head wrappers; keep <mj-preview> text in a meta-ish div
        $html = preg_replace('/<\/?mjml[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?mj-head[^>]*>.*?<\/mj-head>/is', '', $html);

        // mj-preview → hidden preview block
        $html = preg_replace('/<mj-preview>(.*?)<\/mj-preview>/is', '<div style="display:none;visibility:hidden;">$1</div>', $html);

        // mj-text → p
        $html = preg_replace('/<mj-text\b[^>]*>(.*?)<\/mj-text>/is', '<p>$1</p>', $html);

        // mj-button → <a>
        $html = preg_replace('/<mj-button\b([^>]*)>(.*?)<\/mj-button>/is', '<p><a$1>$2</a></p>', $html);
        // fix attributes like mjml-style on <a>
        $html = preg_replace('/<a([^>]*)\bmj-?[a-z0-9_-]+="[^"]*"([^>]*)>/i', '<a$1$2>', $html);

        // mj-image → <img>
        $html = preg_replace('/<mj-image\b([^>]*)\/?>/is', '<img $1 />', $html);

        // unwrap sections/columns/body
        $html = preg_replace('/<\/?mj-body[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?mj-section[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?mj-column[^>]*>/i', '', $html);

        // Remove mj-raw wrappers but keep inner HTML intact
        $html = preg_replace('/<mj-raw>(.*?)<\/mj-raw>/is', '$1', $html);

        // Wrap if bare
        if (!preg_match('/<html\b/i', $html)) {
            $html = "<!doctype html>\n<html><body>\n" . $html . "\n</body></html>";
        }

        return $html;
    }

    private function log(string $msg, array $ctx = []): void
    {
        if ($this->logger) {
            $this->logger->info($msg, $ctx);
        } else {
            @error_log($msg . ' ' . json_encode($ctx));
        }
    }
}
