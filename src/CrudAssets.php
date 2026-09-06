<?php
declare(strict_types=1);

namespace FastCrud;

use RuntimeException;

/** @internal Shared browser runtime and per-table initialization. */
final class CrudAssets
{
    public static function renderInitializer(array $options): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR;
        $optionsJson = json_encode($options, $flags);
        $scriptUrl = trim(CrudConfig::$script_url ?? '');
        $runtime = '';
        if ($scriptUrl === '') {
            $source = file_get_contents(__DIR__ . '/../assets/fastcrud.js');
            if ($source === false) {
                throw new RuntimeException('Unable to read the FastCrud JavaScript asset.');
            }
            $runtime = "<script>\n" . $source . "</script>\n";
        } else {
            static $version = null;
            $version ??= hash_file('sha256', __DIR__ . '/../assets/fastcrud.js');
            if ($version === false) {
                throw new RuntimeException('Unable to version the FastCrud JavaScript asset.');
            }
            $fragment = '';
            $fragmentAt = strpos($scriptUrl, '#');
            if ($fragmentAt !== false) {
                $fragment = substr($scriptUrl, $fragmentAt);
                $scriptUrl = substr($scriptUrl, 0, $fragmentAt);
            }
            $scriptUrl .= (str_contains($scriptUrl, '?') ? '&' : '?') . 'v=' . substr($version, 0, 16) . $fragment;
        }
        $urlJson = json_encode($scriptUrl, $flags);

        return $runtime . <<<HTML
<script>
(function(options, url) {
    if (window.FastCrudRuntime) {
        window.FastCrudRuntime.init(options);
        return;
    }
    window.FastCrudQueue = window.FastCrudQueue || [];
    window.FastCrudQueue.push(options);
    if (!window.FastCrudScriptLoading && url) {
        window.FastCrudScriptLoading = true;
        var script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onerror = function() {
            window.FastCrudScriptLoading = false;
            if (window.console) {
                console.error('FastCrud script could not load. Check script_url and publish the assets.', url);
            }
        };
        document.head.appendChild(script);
    }
})({$optionsJson}, {$urlJson});
</script>
HTML;
    }
}
