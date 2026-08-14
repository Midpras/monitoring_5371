<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class AdminUploadController extends Controller
{
    public function index(): View
    {
        return view('admin');
    }
}
