<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Rdap\Facades\Rdap;

class RadiusService extends ServiceProvider
{
    protected $host;
    protected $secret;
    protected $port;

    public function __construct()
    {
        $this->host = env('RADIUS_HOST');
        $this->port = env('RADIUS_PORT');
        $this->secret = env('RADIUS_SECRET');
    }

    public function authenticate($username, $password)
    {
        // Ganti lookup dengan metode yang benar
        $result = Rdap::search($username); // Gantilah 'search' dengan metode yang tepat dari paket

        // Proses verifikasi password (sesuaikan dengan metode server RADIUS)
        if ($result && $this->verifyPassword($password, $result['password'])) {
            return true;
        }

        return false;
    }

    protected function verifyPassword($password, $hashedPassword)
    {
        // Verifikasi password, sesuaikan dengan hashing yang digunakan
        return password_verify($password, $hashedPassword);
    }
}
