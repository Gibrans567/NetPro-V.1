<?php

namespace App\Http\Controllers;

use App\Providers\RadiusService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected $radiusService;

    public function __construct(RadiusService $radiusService)
    {
        $this->radiusService = $radiusService;
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $authenticated = $this->radiusService->authenticate($request->username, $request->password);

        if ($authenticated) {
            return response()->json(['message' => 'Login successful']);
        }

        return response()->json(['message' => 'Login failed'], 401);
    }
}
