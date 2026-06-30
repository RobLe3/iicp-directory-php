<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the 2026-06-06 faux pas: every operator-delegation register 500'd in
 * prod for weeks because OperatorDelegationVerifier referenced the global `SODIUM_CRYPTO_SIGN_*`
 * constants UNQUALIFIED inside the `App\Services` namespace. Local ext-sodium resolved them, so
 * tests passed; the prod PHP (sodium polyfill) did not define them, so the constant resolved into
 * the namespace and threw — silently leaving operator_pubkey unbound and the operators table empty.
 *
 * Lesson institutionalised: a global constant whose existence differs between native ext-sodium and
 * the prod polyfill MUST NOT be referenced bare from namespaced code. Use the hardcoded
 * protocol-fixed length, or a fully-qualified `\SODIUM_CRYPTO_*`. This test fails the build if any
 * namespaced app/ file reintroduces a bare reference — using the tokenizer so comments + strings
 * are ignored and only real constant tokens are flagged.
 */
class SodiumConstantGuardTest extends TestCase
{
    public function test_no_bare_sodium_constant_in_namespaced_app_code(): void
    {
        $appDir = dirname(__DIR__, 2).'/app';
        $violations = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = token_get_all(file_get_contents($file->getPathname()));
            foreach ($tokens as $i => $tok) {
                // A constant reference is a T_STRING token; comments/strings are other token types.
                if (! is_array($tok) || $tok[0] !== T_STRING) {
                    continue;
                }
                if (! str_starts_with($tok[1], 'SODIUM_CRYPTO_')) {
                    continue;
                }
                // Fully-qualified (`\SODIUM_CRYPTO_*`) is safe — preceding token is T_NS_SEPARATOR.
                $prev = $tokens[$i - 1] ?? null;
                $qualified = is_array($prev) ? ($prev[0] === T_NS_SEPARATOR) : ($prev === '\\');
                if (! $qualified) {
                    $violations[] = sprintf('%s: bare %s', str_replace($appDir.'/', '', $file->getPathname()), $tok[1]);
                }
            }
        }

        $this->assertSame([], $violations, "Bare SODIUM_CRYPTO_* constant(s) in namespaced code (500 on the prod sodium polyfill — hardcode the length or use \\SODIUM_…):\n".implode("\n", $violations));
    }

    /**
     * Second layer of the 2026-06-06 lesson: prod (DomainFactory shared hosting) has NO ext-sodium,
     * so the directory's ed25519 sign/verify only works because the paragonie/sodium_compat polyfill
     * is installed (its composer files-autoload defines the global sodium_* functions when the ext is
     * absent). If this dependency is ever dropped, EVERY signed path (operator delegation, event
     * signing) silently breaks in prod again. Pin it as a hard requirement.
     */
    public function test_sodium_compat_polyfill_is_a_required_dependency(): void
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
        $this->assertArrayHasKey(
            'paragonie/sodium_compat',
            $composer['require'] ?? [],
            'paragonie/sodium_compat must stay in composer require — prod has no ext-sodium, so without it all ed25519 sign/verify 500s.'
        );
    }
}
