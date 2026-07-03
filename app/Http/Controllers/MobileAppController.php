<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MobileAppController extends Controller
{
    public function index()
    {
        return view('mobile.app');
    }
}
