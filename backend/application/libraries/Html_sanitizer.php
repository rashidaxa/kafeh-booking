<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Allow-list HTML sanitizer for admin-authored rich text that is shown on
 * client websites (the Terms & Conditions popup). Even though only admins
 * write it, it ends up inside third-party pages, so anything that could
 * run script or load remote content is removed:
 *   - dangerous elements (script, style, iframe, object, form, svg …) are
 *     dropped together with their contents;
 *   - other unknown elements are unwrapped (their text is kept);
 *   - only a few harmless attributes survive: a[href|target|rel] (http,
 *     https, mailto, tel only), td/th[colspan|rowspan], and a text-align
 *     style on block elements. Event handlers, classes, ids and inline
 *     styles are all stripped.
 */
class Html_sanitizer
{
    protected $allowed = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup',
        'ul', 'ol', 'li', 'blockquote',
        'a', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
    ];
    protected $drop = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'select', 'textarea', 'option', 'meta', 'link', 'base', 'svg', 'math', 'noscript',
        'template', 'audio', 'video', 'source', 'canvas', 'img', 'picture', 'head', 'title',
    ];
    protected $align_ok = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'td', 'th', 'blockquote'];

    public function clean($html)
    {
        $html = (string)$html;
        if (trim($html) === '') return '';

        $prev = libxml_use_internal_errors(TRUE);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="kfb-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementById('kfb-root');
        if (!$root) return '';
        $this->_walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) $out .= $dom->saveHTML($child);
        return trim($out);
    }

    protected function _walk(DOMNode $node)
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            $tag = strtolower($child->nodeName);
            if (in_array($tag, $this->drop, TRUE)) { $node->removeChild($child); continue; }

            $this->_walk($child); // clean descendants first

            if (!in_array($tag, $this->allowed, TRUE)) { // unwrap: keep the (already clean) children
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $this->_clean_attributes($child, $tag);
        }
    }

    protected function _clean_attributes(DOMElement $el, $tag)
    {
        $keep = [];
        if ($tag === 'a') {
            $href = trim((string)$el->getAttribute('href'));
            if (preg_match('#^(https?://|mailto:|tel:)#i', $href)) {
                $keep['href'] = $href;
                if (preg_match('#^https?://#i', $href)) { $keep['target'] = '_blank'; $keep['rel'] = 'noopener noreferrer'; }
            }
        }
        if (in_array($tag, ['td', 'th'], TRUE)) {
            foreach (['colspan', 'rowspan'] as $a) {
                $v = (int)$el->getAttribute($a);
                if ($v > 1 && $v <= 20) $keep[$a] = (string)$v;
            }
        }
        if (in_array($tag, $this->align_ok, TRUE) && preg_match('/text-align\s*:\s*(left|right|center|justify)/i', (string)$el->getAttribute('style'), $m)) {
            $keep['style'] = 'text-align: ' . strtolower($m[1]);
        }

        foreach (iterator_to_array($el->attributes) as $attr) $el->removeAttribute($attr->nodeName);
        foreach ($keep as $k => $v) $el->setAttribute($k, $v);
    }
}
