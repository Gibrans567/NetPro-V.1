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

    // Atur waktu kadaluarsa (30 menit dari sekarang)
    $expiry_time = Carbon::now()->addMinutes(2)->format('Y/m/d H:i:s');

    try {
        $client = $this->getClient();

        // Query untuk memeriksa apakah user dengan username yang sama sudah ada
        $checkQuery = (new Query('/ppp/secret/print'))
            ->where('name', $username); // Cari berdasarkan username

        $existingUsers = $client->query($checkQuery)->read();

        // Jika user sudah ada, kembalikan pesan bahwa user sudah terdaftar
        if (!empty($existingUsers)) {
            return response()->json(['message' => 'User already exists']);
        }

        // Jika user belum ada, tambahkan user baru
        $query = (new Query('/ppp/secret/add'))
            ->equal('name', $username) // Username di MikroTik
            ->equal('password', $password) // Password di MikroTik
            ->equal('service', 'pppoe') // Tipe layanan, bisa disesuaikan
            ->equal('comment', "Name: {$name}, Expiry: {$expiry_time}"); // Tambahkan waktu kedaluwarsa ke komentar

        $response = $client->query($query)->read();

        return response()->json(['message' => 'User added successfully with expiry time of 30 minutes']);
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

public function deleteExpiredUsers()
{
    try {
        $client = $this->getClient();

        // Query untuk mendapatkan daftar pengguna PPP
        $query = new Query('/ppp/secret/print');
        $users = $client->query($query)->read();

        foreach ($users as $user) {
            // Ambil informasi waktu kadaluwarsa dari komentar
            if (isset($user['comment']) && strpos($user['comment'], 'Expiry:') !== false) {
                $parts = explode(', ', $user['comment']);

                // Pastikan array parts memiliki setidaknya dua elemen untuk menghindari error
                if (isset($parts[1]) && strpos($parts[1], 'Expiry: ') === 0) {
                    $expiryTime = Carbon::parse(substr($parts[1], strlen('Expiry: ')));

                    // Lanjutkan jika format tanggal valid
                    if (Carbon::now()->greaterThanOrEqualTo($expiryTime)) {
                        $deleteQuery = (new Query('/ppp/secret/remove'))
                            ->equal('.id', $user['.id']);
                        $client->query($deleteQuery)->read();
                    }
                }
            }
        }
        return response()->json(['message' => 'Expired users deleted successfully']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}



public function extendUserTime(Request $request)
{
    $request->validate([
        'id' => 'required|string', // validasi menggunakan id
        'additional_minutes' => 'required|integer|min:1',
    ]);

    $id = $request->input('id');
    $additional_minutes = $request->input('additional_minutes');

    try {
        $client = $this->getClient();

        // Cari pengguna berdasarkan ID
        $query = (new Query('/ppp/secret/print'))
                    ->where('.id', $id); // menggunakan id dalam query
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
        $newComment = "{$parts[0]}, Expiry: $newExpiryTime";

        $updateQuery = (new Query('/ppp/secret/set'))
                        ->equal('.id', $id) // update menggunakan id
                        ->equal('comment', $newComment);

        $client->query($updateQuery)->read();

        return response()->json(['message' => 'User time extended successfully']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}



}
