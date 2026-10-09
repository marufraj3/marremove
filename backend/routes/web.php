<?php

// Serve the built React app from the Laravel public directory on Webuzo.
// The redirect is cacheable and leaves API routes under routes/api.php unchanged.
\Illuminate\Support\Facades\Route::redirect('/', '/index.html');
