<?php

declare(strict_types=1);

namespace App\Mailer\Service;

use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface;

/**
 * The plain-text part of every email, derived from the HTML by the body
 * renderer. Symfony's league-backed default, with two corrections: <img> is
 * dropped (the text part must not open with the logo's URL) and entities are
 * decoded, because the converter leaves "&amp;" in text nodes - which breaks
 * every URL with a query string.
 */
final class HtmlToTextConverter implements HtmlToTextConverterInterface
{
    private readonly HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'hard_break' => true,
            'strip_tags' => true,
            'remove_nodes' => 'head style img',
        ]);
    }

    public function convert(string $html, string $charset): string
    {
        return html_entity_decode($this->converter->convert($html), \ENT_QUOTES | \ENT_HTML5, $charset);
    }
}
