<?php

/**
 * The page shown for a request without parameters.
 */

namespace StaticMaps;

use RuntimeException;

/**
 * Serves the `home_page` HTML file, which identifies the service to someone
 * who opens its address in a browser.
 */
final class HomePage
{
    /**
     * Build the response for the home page.
     *
     * The file is sent as-is; it is never run as PHP.
     *
     * @param string $file Path to the HTML file (the `home_page` setting).
     *
     * @return Response 200 with the page as text/html.
     *
     * @throws RuntimeException If the file can't be read.
     */
    public static function response(string $file): Response
    {
        $html = is_file($file) ? file_get_contents($file) : false;
        if ($html === false) {
            throw new RuntimeException("Home page file can't be read: $file");
        }

        return new Response(['Content-Type: text/html; charset=utf-8'], $html);
    }
}
