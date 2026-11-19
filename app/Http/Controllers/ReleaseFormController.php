<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app Music Release Form (spec S1) — replaces the WordPress/Elementor
 * form. Page only; submission is ReleaseFormController@store (spec T6).
 */
class ReleaseFormController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('ReleaseForm', [
            'authUser' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }
}
