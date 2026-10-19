<?php

/**
 * An invalid install configuration: the default basemap does not exist.
 *
 * Used to check that the renderer answers HTTP 500 on a configuration error.
 */

return [
    'defaults' => [
        'basemap' => 'no-such-basemap',
    ],
];
