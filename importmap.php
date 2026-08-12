<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.23',
    ],
    // Vendored from the Able Pro theme (UMD — registers window.ApexCharts).
    'apexcharts' => [
        'path' => './assets/able-pro/js/apexcharts.min.js',
    ],
    // Able Pro's own datatable (UMD — registers window.simpleDatatables). The
    // theme's Tailwind demos use this, not the jQuery DataTables left over in
    // its plugins folder; assets/able-pro/css/style.css already styles its
    // .datatable-* markup.
    'simple-datatables' => [
        'path' => './assets/able-pro/js/simple-datatables.js',
    ],
    // Able Pro's drag-and-drop library (UMD), used by the signer-order list. Its
    // stylesheet is vendored alongside, as with every Able Pro plugin.
    'dragula' => [
        'path' => './assets/able-pro/js/dragula.js',
    ],
];
