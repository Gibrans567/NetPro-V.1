<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use RouterOS\Client;
use RouterOS\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MikrotikController extends Controller
{
    protected function getClient()
    {
        $config = [
            'host' => '192.168.2.1',  // Ganti dengan IP Mikrotik kamu
            'user' => 'admin',         // Ganti dengan username Mikrotik kamu
            'pass' => '',              // Ganti dengan password Mikrotik kamu
            'port' => 8728,            // Port API Mikrotik (default 8728)
        ];

        return new Client($config);
    }

    public function connectToMikrotik()
    {
        try {
            $client = $this->getClient();
            $query = new Query('/interface/print');
            $response = $client->query($query)->read();
            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function addIpAddress(Request $request)
    {
        try {
            $client = $this->getClient();
            $query = (new Query('/ip/address/add'))
                        ->equal('address', $request->input('address'))
                        ->equal('interface', $request->input('interface'));
            $response = $client->query($query)->read();
            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function checkConnection()
    {
        try {
            $client = $this->getClient();
            return response()->json(['message' => 'Connection to Mikrotik successful']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function addUser(Request $request)
{
    // Validasi input
    $request->validate([
        'no_hp' => 'required|string|max:20',
        'name' => 'required|string|max:255',
    ]);

    // Ambil data dari request
    $no_hp = $request->input('no_hp');
    $name = $request->input('name');

    // Gunakan no_hp sebagai username dan password
    $username = $no_hp;
    $password = $no_hp;

    // Set waktu mulai koneksi
    $startTime = now();

    // Hitung waktu kedaluwarsa (30 menit dari waktu mulai koneksi)
    $expiryDate = $startTime->copy()->addMinutes(30)->format('Y/m/d H:i:s');

    try {
        $client = $this->getClient();

        // Query untuk menambahkan user dengan waktu kedaluwarsa
        $query = (new Query('/ppp/secret/add'))
                    ->equal('name', $username)       // Username di MikroTik
                    ->equal('password', $password)   // Password di MikroTik
                    ->equal('service', 'pppoe')      // Tipe layanan, bisa disesuaikan
                    ->equal('comment', $name)        // Menambahkan nama sebagai komentar
                    ->equal('validity', $expiryDate); // Set waktu kedaluwarsa

        $response = $client->query($query)->read();

        // Simpan informasi ke database (opsional, jika ingin menyimpan waktu koneksi)
        DB::table('users')->insert([
            'no_hp' => $no_hp,
            'name' => $name,
            'start_time' => $startTime,             // Waktu mulai koneksi
            'expiry_time' => $expiryDate,           // Waktu kedaluwarsa
        ]);

        // Dispatch job untuk menghapus user setelah 30 menit
        \App\Jobs\RemoveUserJob::dispatch($username)->delay($startTime->addMinutes(30));

        return response()->json($response);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}




    public function getUsers()
{
    try {
        $client = $this->getClient();

        // Query untuk mendapatkan daftar pengguna PPP
        $query = new Query('/ppp/secret/print');
        $response = $client->query($query)->read();

        return response()->json($response);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function deleteUser(Request $request)
    {
        // Validasi input
        $request->validate([
            'id' => 'required|string',
        ]);

        $userId = $request->input('id');

        try {
            $client = $this->getClient();

            // Query untuk menghapus user berdasarkan ID
            $deleteQuery = (new Query('/ppp/secret/remove'))
                            ->equal('.id', $userId);
            $response = $client->query($deleteQuery)->read();

            return response()->json(['message' => 'User deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function extendUserTime(Request $request)
{
    $request->validate([
        'username' => 'required|string|max:20',
        'additional_minutes' => 'required|integer|min:1',
    ]);

    $username = $request->input('username');
    $additional_minutes = $request->input('additional_minutes');

    try {
        $client = $this->getClient();

        // Cari pengguna berdasarkan username
        $query = (new Query('/ppp/secret/print'))
                    ->where('name', $username);
        $user = $client->query($query)->read();

        if (empty($user)) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Ambil informasi komentar yang ada
        $comment = $user[0]['comment'];

        // Parsing waktu kadaluarsa dari komentar
        $parts = explode(', ', $comment);
        $expiryTime = Carbon::parse(substr($parts[1], strlen('Expiry: ')));

        // Hitung waktu baru kedaluwarsa
        $newExpiryTime = $expiryTime->addMinutes($additional_minutes)->format('Y/m/d H:i:s');

        // Update komentar dengan waktu kedaluwarsa yang baru
        $newComment = "Start: {$parts[0]}, Expiry: $newExpiryTime";

        $updateQuery = (new Query('/ppp/secret/set'))
                        ->equal('name', $username)
                        ->equal('comment', $newComment);

        $client->query($updateQuery)->read();

        return response()->json(['message' => 'User time extended successfully']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}


}
