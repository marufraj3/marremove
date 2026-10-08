<?php

namespace App\Services;

final class CommentTextNormalizer
{
    public function normalize(string $text): string
    {
        return $this->normalizeCanonical($this->canonicalText($text));
    }

    public function canonicalText(string $text): string
    {
        return $this->canonicalize($text);
    }

    /** @return list<string> */
    public function matchingVariants(string $text): array
    {
        $canonical = $this->canonicalize($text);
        $base = $this->normalizeCanonical($canonical);
        $variants = [$base];

        // Ignore punctuation used inside an obfuscated token (for example, "cho-r"),
        // but do not remove ordinary spaces between words.
        $punctuationCompacted = preg_replace(
            '/(?<=[\p{L}\p{M}\p{N}_])[\p{P}\p{S}]+(?=[\p{L}\p{M}\p{N}_])/u',
            '',
            $canonical,
        );
        if (is_string($punctuationCompacted)) {
            $variants[] = $this->normalizeCanonical($punctuationCompacted);
        }

        // A run of at least three single-letter tokens is a common spaced-out evasion,
        // while ordinary multi-letter words remain separate and are not concatenated.
        $spacedLettersCompacted = preg_replace_callback(
            '/(?<![\p{L}\p{N}_])((?:\p{L}\p{M}*\s+){2,}\p{L}\p{M}*)(?![\p{L}\p{N}_])/u',
            static fn (array $matches): string => preg_replace('/\s+/u', '', $matches[0]) ?? $matches[0],
            $base,
        );
        if (is_string($spacedLettersCompacted)) {
            $variants[] = $spacedLettersCompacted;
        }

        return array_values(array_unique(array_filter($variants, static fn (string $variant): bool => $variant !== '')));
    }

    private function canonicalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $unicodeNormalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($unicodeNormalized)) {
                $text = $unicodeNormalized;
            }
        }

        $text = str_replace(
            ["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"],
            '',
            $text,
        );

        // A narrowly scoped Bengali elongation normalization: চোওর -> চোর.
        $text = str_replace('োও', 'ো', $text);

        return function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
    }

    private function normalizeCanonical(string $text): string
    {
        $separated = preg_replace_callback(
            '/[\p{P}\p{S}]/u',
            static fn (array $matches): string => $matches[0] === '_' ? '_' : ' ',
            $text,
        );
        if (! is_string($separated)) {
            return '';
        }

        $collapsed = preg_replace('/[\p{Z}\s]+/u', ' ', $separated);

        return is_string($collapsed) ? trim($collapsed) : '';
    }
}
