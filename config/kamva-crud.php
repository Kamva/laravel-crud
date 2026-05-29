<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API pagination size
    |--------------------------------------------------------------------------
    |
    | Number of records per page returned by the API list endpoint. Reading
    | this from config (rather than env() at runtime) keeps it working under
    | `php artisan config:cache`, where env() returns null outside config
    | files. The CRUD_PAGINATE_SIZE env var is still honoured for backwards
    | compatibility.
    |
    */

    'paginate_size' => (int) env('CRUD_PAGINATE_SIZE', 15),

];
