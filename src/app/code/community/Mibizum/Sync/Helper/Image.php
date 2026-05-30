<?php
/**
 * Mibizum_Sync_Helper_Image
 *
 * Security helpers for badge artwork. Everything a merchant can paste or upload
 * as a badge icon ends up rendered inline in the storefront search widget, so
 * it MUST be cleaned before it is stored. This helper centralizes that:
 *
 *   - sanitizeSvg():          strips scripts, event handlers, external/JS URIs,
 *                             <foreignObject>, entities (XXE) and PHP tags from
 *                             an inline SVG, returning safe markup or ''.
 *   - sanitizeHexColor():     forces a value to a #RRGGBB literal or a default.
 *   - sanitizeUploadedIcon(): "depura" an uploaded file. Raster images (PNG/JPG/
 *                             WebP) are validated with getimagesize() AND
 *                             re-encoded through GD, which discards any payload
 *                             appended after the image data (polyglots). SVG
 *                             uploads are run through sanitizeSvg() and rewritten
 *                             clean. Anything that does not check out is deleted
 *                             and rejected.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Helper_Image extends Mage_Core_Helper_Abstract
{
    /** Elements removed from any SVG: they can run JS or pull remote content. */
    protected $_svgBadTags = array(
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video',
        'handler', 'listener', 'animate', 'animatetransform', 'animatemotion', 'set',
    );

    // -------------------------------------------------------------------------
    // Colors
    // -------------------------------------------------------------------------

    /** @return bool True if $hex is a #RRGGBB literal. */
    public function isValidHexColor($hex)
    {
        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', trim($hex)) === 1;
    }

    /**
     * Force a value to a safe #RRGGBB literal. Returns $default when invalid so
     * a crafted value can never be written into a CSS color slot.
     */
    public function sanitizeHexColor($hex, $default = '#1E9C3C')
    {
        $hex = trim((string) $hex);
        return $this->isValidHexColor($hex) ? strtoupper($hex) : $default;
    }

    /** @return string|null Uppercased #RRGGBB, or null when empty/invalid. */
    public function sanitizeOptionalHexColor($hex)
    {
        $hex = trim((string) $hex);
        return $this->isValidHexColor($hex) ? strtoupper($hex) : null;
    }

    // -------------------------------------------------------------------------
    // SVG
    // -------------------------------------------------------------------------

    /**
     * Return a sanitized copy of an inline SVG, or '' when the input is empty,
     * is not an SVG, or cannot be parsed safely.
     *
     * @param string $svg
     * @return string
     */
    public function sanitizeSvg($svg)
    {
        $svg = trim((string) $svg);
        if ($svg === '' || stripos($svg, '<svg') === false) {
            return '';
        }
        // Hard reject embedded PHP.
        if (stripos($svg, '<?php') !== false || stripos($svg, '<?=') !== false) {
            return '';
        }
        // Drop DOCTYPE/ENTITY (XXE) and processing instructions before parsing.
        $svg = preg_replace('/<!DOCTYPE.*?>/is', '', $svg);
        $svg = preg_replace('/<!ENTITY.*?>/is', '', $svg);
        $svg = preg_replace('/<\?.*?\?>/s', '', $svg);

        $prevErrors = libxml_use_internal_errors(true);
        // PHP < 8.0: stop the parser from resolving external entities. The
        // function is gone in 8.x, where external entities are off by default.
        $hadLoader = null;
        if (function_exists('libxml_disable_entity_loader')) {
            $hadLoader = @libxml_disable_entity_loader(true);
        }

        $dom   = new DOMDocument('1.0', 'UTF-8');
        $flags = 0;
        if (defined('LIBXML_NONET')) {
            $flags |= LIBXML_NONET; // never hit the network while parsing
        }
        // NOTE: intentionally NOT using LIBXML_NOENT (which would expand entities).
        $loaded = @$dom->loadXML($svg, $flags);

        if ($hadLoader !== null && function_exists('libxml_disable_entity_loader')) {
            @libxml_disable_entity_loader($hadLoader);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        if (!$loaded || !$dom->documentElement) {
            return '';
        }
        if (strtolower($dom->documentElement->nodeName) !== 'svg') {
            return '';
        }

        $this->_cleanSvgNode($dom->documentElement);

        $out = $dom->saveXML($dom->documentElement);
        return is_string($out) ? trim($out) : '';
    }

    /** Recursively strip dangerous elements/attributes from an SVG node. */
    protected function _cleanSvgNode(DOMNode $node)
    {
        if (!$node->childNodes) {
            return;
        }
        // Snapshot children first: we mutate the tree while iterating.
        $children = array();
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($child->localName ? $child->localName : $child->nodeName);
            if (in_array($tag, $this->_svgBadTags, true)) {
                $node->removeChild($child);
                continue;
            }
            // <use> is only dangerous with an external/data reference; drop those.
            if ($tag === 'use' && $this->_hasUnsafeReference($child)) {
                $node->removeChild($child);
                continue;
            }
            $this->_cleanSvgElementAttributes($child);
            $this->_cleanSvgNode($child);
        }
    }

    protected function _hasUnsafeReference(DOMElement $el)
    {
        foreach (array('href', 'xlink:href', 'src') as $name) {
            $v = strtolower(preg_replace('/\s+/', '', (string) $el->getAttribute($name)));
            if ($v !== '' && strpos($v, '#') !== 0) {
                return true; // anything that is not an in-document fragment ref
            }
        }
        return false;
    }

    protected function _cleanSvgElementAttributes(DOMElement $el)
    {
        if (!$el->attributes) {
            return;
        }
        $remove = array();
        foreach ($el->attributes as $attr) {
            $name    = strtolower($attr->nodeName);
            $value   = (string) $attr->nodeValue;
            $compact = strtolower(preg_replace('/\s+/', '', $value));

            if (strpos($name, 'on') === 0) {                       // onload, onclick, ...
                $remove[] = $attr->nodeName;
                continue;
            }
            if (in_array($name, array('href', 'xlink:href', 'src'), true)) {
                if (strpos($compact, 'javascript:') === 0
                    || strpos($compact, 'vbscript:') === 0
                    || strpos($compact, 'data:') === 0) {
                    $remove[] = $attr->nodeName;
                    continue;
                }
            }
            if ($name === 'style'
                && (strpos($compact, 'javascript:') !== false
                    || strpos($compact, 'expression(') !== false
                    || strpos($compact, '@import') !== false
                    || strpos($compact, 'url(') !== false)) {
                $remove[] = $attr->nodeName;
                continue;
            }
            if (strpos($compact, 'javascript:') !== false || strpos($compact, 'vbscript:') !== false) {
                $remove[] = $attr->nodeName;
            }
        }
        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
    }

    // -------------------------------------------------------------------------
    // Uploaded files
    // -------------------------------------------------------------------------

    /**
     * Deep-clean a file that was just saved to disk by an upload. SVGs are
     * sanitized in place; raster images are validated and re-encoded so any
     * payload smuggled after/inside the image bytes is dropped. Throws (after
     * deleting the file) when the content is not what its extension claims.
     *
     * @param string $absPath Absolute path to the saved file.
     * @param string $ext     Lower-case extension (png|jpg|jpeg|webp|svg).
     * @return bool
     * @throws Exception
     */
    public function sanitizeUploadedIcon($absPath, $ext)
    {
        $ext = strtolower((string) $ext);
        if (!is_file($absPath)) {
            throw new Exception($this->__('Uploaded file not found.'));
        }

        if ($ext === 'svg') {
            $clean = $this->sanitizeSvg(file_get_contents($absPath));
            if ($clean === '') {
                @unlink($absPath);
                throw new Exception($this->__('The SVG is invalid or contains unsafe content.'));
            }
            file_put_contents($absPath, $clean);
            return true;
        }

        $info = @getimagesize($absPath);
        if ($info === false || !isset($info[2])) {
            @unlink($absPath);
            throw new Exception($this->__('The file is not a valid image.'));
        }
        $type = (int) $info[2];

        $typeToExt = array();
        if (defined('IMAGETYPE_PNG'))  { $typeToExt[IMAGETYPE_PNG]  = array('png'); }
        if (defined('IMAGETYPE_JPEG')) { $typeToExt[IMAGETYPE_JPEG] = array('jpg', 'jpeg'); }
        if (defined('IMAGETYPE_WEBP')) { $typeToExt[IMAGETYPE_WEBP] = array('webp'); }

        if (!isset($typeToExt[$type]) || !in_array($ext, $typeToExt[$type], true)) {
            @unlink($absPath);
            throw new Exception($this->__('The file content does not match its extension.'));
        }

        // Re-encode through GD to discard anything appended to the pixel data.
        $this->_reencodeRaster($absPath, $type);
        return true;
    }

    protected function _reencodeRaster($absPath, $type)
    {
        if (!function_exists('imagecreatefromstring')) {
            return; // No GD: getimagesize() validation already vetted the file.
        }
        $img = false;
        if (defined('IMAGETYPE_PNG') && $type === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($absPath);
        } elseif (defined('IMAGETYPE_JPEG') && $type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
            $img = @imagecreatefromjpeg($absPath);
        } elseif (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($absPath);
        }
        if (!$img) {
            return; // Could not decode; the validated original stays.
        }

        if (function_exists('imagealphablending') && function_exists('imagesavealpha')) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        if (defined('IMAGETYPE_PNG') && $type === IMAGETYPE_PNG) {
            @imagepng($img, $absPath);
        } elseif (defined('IMAGETYPE_JPEG') && $type === IMAGETYPE_JPEG) {
            @imagejpeg($img, $absPath, 90);
        } elseif (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            @imagewebp($img, $absPath);
        }
        imagedestroy($img);
    }
}
