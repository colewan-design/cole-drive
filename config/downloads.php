<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Download Delivery
    |--------------------------------------------------------------------------
    |
    | How a stored file's bytes reach the client.
    |
    |   "php"    Laravel streams the file through the PHP worker. Portable, and
    |            the only option where there is no web server in front (local
    |            `artisan serve`, Apache without mod_xsendfile).
    |
    |   "xaccel" The response carries an X-Accel-Redirect header and nginx
    |            serves the file itself. The worker is released as soon as the
    |            header is sent, and Range requests — resumable downloads — come
    |            for free instead of being reimplemented in PHP.
    |
    | On a shared PHP-FPM pool the difference is not an optimisation. A single
    | 1 GB download under "php" holds one worker for the whole transfer.
    |
    */

    'delivery' => env('DOWNLOAD_DELIVERY', 'php'),

    /*
    |--------------------------------------------------------------------------
    | X-Accel Internal Prefix
    |--------------------------------------------------------------------------
    |
    | The internal nginx location that maps onto the local disk's root. This
    | must stay in step with the `location ^~ ...` block in
    | deploy/nginx/colewan-drive.conf — nginx returns 404 on a mismatch, with
    | nothing in the PHP log to explain it.
    |
    */

    'xaccel_prefix' => env('DOWNLOAD_XACCEL_PREFIX', '/internal-downloads/'),

];
