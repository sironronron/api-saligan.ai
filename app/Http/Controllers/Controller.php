<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Case access moved from a bare `user_id === auth()->id` comparison in each
     * action to LegalCasePolicy once cases could be shared, and $this->authorize()
     * is how controllers reach it.
     */
    use AuthorizesRequests;
}
