<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class MjmlCompileService
{
    public function __construct(
        private LoggerInterface $logger,
        private CoreParametersHelper $parametersHelper,
    ) {
    }

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
        if (null !== $cli) {
            $r = $this->compileViaCli($cli, $mjml);
            if ($r['success']) {
                return $r;
            }
            $this->log('[LeuchtfeuerTranslations][MJML] CLI compile failed, falling back', ['error' => $r['error'] ?? 'unknown']);
        }

        // B) Fallback: minimal tag mapping so preview shows translated text
        $html = $this->veryLightFallback($mjml);

        return ['success' => true, 'html' => $html];
    }

    private function findMjmlCli(): ?string
    {
        // Prefer absolute candidates first (fast path)
        $absoluteCandidates = ['/usr/bin/mjml', '/usr/local/bin/mjml', '/bin/mjml'];
        foreach ($absoluteCandidates as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        // Then look up in PATH
        $finder = new ExecutableFinder();
        $found  = $finder->find('mjml');

        return is_string($found) ? $found : null;
    }

    /**
     * Uses temporary files and the mjml CLI to compile.
     *
     * @return array{success: bool, html?: string, error?: string}
     */
    private function compileViaCli(string $cli, string $mjml): array
    {
        // Use only the configured Mautic tmp path (no fallback to system tmp)
        $tmpConf = $this->parametersHelper->get('tmp_path');
        $tmpDir  = is_string($tmpConf) ? $tmpConf : '';

        if ('' === $tmpDir || !is_dir($tmpDir) || !is_writable($tmpDir)) {
            return [
                'success' => false,
                'error'   => sprintf('Invalid tmp_path: "%s". Ensure it exists and is writable.', $tmpDir),
            ];
        }

        $in = tempnam($tmpDir, 'mjml_in_');
        if (false === $in) {
            return ['success' => false, 'error' => 'Unable to create input temp file'];
        }

        $bytes = file_put_contents($in, $mjml);
        if (false === $bytes) {
            // try to clean up input file
            if (is_file($in) && !unlink($in)) {
                $this->log('[LeuchtfeuerTranslations][MJML] Failed to delete temp file', ['file' => $in]);
            }

            return ['success' => false, 'error' => 'Failed to write MJML to temp file'];
        }

        $out = tempnam($tmpDir, 'mjml_out_');
        if (false === $out) {
            if (is_file($in) && !unlink($in)) {
                $this->log('[LeuchtfeuerTranslations][MJML] Failed to delete temp file', ['file' => $in]);
            }

            return ['success' => false, 'error' => 'Unable to create output temp file'];
        }

        $process = new Process([$cli, $in, '-o', $out]);
        $process->setTimeout(30);
        $this->log('[LeuchtfeuerTranslations][MJML] invoking CLI', ['cmd' => $process->getCommandLine()]);
        $process->run();

        $ok   = is_file($out) && filesize($out) > 0;
        $html = $ok ? file_get_contents($out) : false;

        // Cleanup temp files without silencing errors; log if deletion fails
        if (is_file($in) && !unlink($in)) {
            $this->log('[LeuchtfeuerTranslations][MJML] Failed to delete temp file', ['file' => $in]);
        }
        if (is_file($out) && !unlink($out)) {
            $this->log('[LeuchtfeuerTranslations][MJML] Failed to delete temp file', ['file' => $out]);
        }

        if (false === $ok || false === $html) {
            $errOut = $process->getErrorOutput();
            $stdOut = $process->getOutput();
            $chosen = '' !== $errOut ? $errOut : ('' !== $stdOut ? $stdOut : 'Unknown MJML CLI error');
            $err    = trim($chosen);

            return ['success' => false, 'error' => $err];
        }

        return ['success' => true, 'html' => (string) $html];
    }

    /**
     * Extremely light fallback so previews don't stay stale if CLI is missing.
     * Not a full MJML renderer—just unwraps key tags to reasonable HTML.
     *
     * @see https://mjml.io/documentation/
     * @see https://github.com/mjmlio/mjml
     */
    private function veryLightFallback(string $mjml): string
    {
        $html = $mjml;

        // Strip mjml/mj-head wrappers; keep <mj-preview> text in a hidden block
        $html = $this->rxReplace('/<\/?mjml[^>]*>/i', '', $html);
        $html = $this->rxReplace('/<\/?mj-head[^>]*>.*?<\/mj-head>/is', '', $html);

        // mj-preview -> hidden preview block
        $html = $this->rxReplace('/<mj-preview>(.*?)<\/mj-preview>/is', '<div style="display:none;visibility:hidden;">$1</div>', $html);

        // mj-text -> p
        $html = $this->rxReplace('/<mj-text\b[^>]*>(.*?)<\/mj-text>/is', '<p>$1</p>', $html);

        // mj-button -> <a>
        $html = $this->rxReplace('/<mj-button\b([^>]*)>(.*?)<\/mj-button>/is', '<p><a$1>$2</a></p>', $html);
        // strip mj*-style-like attributes from <a>
        $html = $this->rxReplace('/<a([^>]*)\bmj-?[a-z0-9_-]+="[^"]*"([^>]*)>/i', '<a$1$2>', $html);

        // mj-image -> <img>
        $html = $this->rxReplace('/<mj-image\b([^>]*)\/?>/is', '<img $1 />', $html);

        // unwrap sections/columns/body
        $html = $this->rxReplace('/<\/?mj-body[^>]*>/i', '', $html);
        $html = $this->rxReplace('/<\/?mj-section[^>]*>/i', '', $html);
        $html = $this->rxReplace('/<\/?mj-column[^>]*>/i', '', $html);

        // Remove mj-raw wrappers but keep inner HTML intact
        $html = $this->rxReplace('/<mj-raw>(.*?)<\/mj-raw>/is', '$1', $html);

        // Wrap if bare
        $hasHtmlTag = preg_match('/<html\b/i', $html);
        if (1 !== $hasHtmlTag) {
            $html = "<!doctype html>\n<html><body>\n".$html."\n</body></html>";
        }

        return $html;
    }

    /**
     * Safe regex replace that preserves string type if PCRE returns null.
     */
    private function rxReplace(string $pattern, string $replacement, string $subject): string
    {
        $res = preg_replace($pattern, $replacement, $subject);

        return is_string($res) ? $res : $subject;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function log(string $msg, array $ctx = []): void
    {
        $this->logger->info($msg, $ctx);
    }
}
