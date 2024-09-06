<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use RouterOS\Client;
use RouterOS\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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

public function addHotspotUser(Request $request)
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

    // Atur waktu kadaluwarsa (contoh: 1 hari dari sekarang)
    $expiry_time = Carbon::now()->addDay()->format('Y/m/d H:i:s');

    try {
        // Koneksi ke Mikrotik
        $client = $this->getClient();

        // Query untuk memeriksa apakah user dengan username yang sama sudah ada
        $checkQuery = (new Query('/ip/hotspot/user/print'))
            ->where('name', $username);

        $existingUsers = $client->query($checkQuery)->read();

        // Jika user sudah ada, kembalikan pesan bahwa user sudah terdaftar
        if (!empty($existingUsers)) {
            return response()->json(['message' => 'User already exists'], 409);
        }

        // Jika user belum ada, tambahkan user baru dengan masa berlaku 1 hari
        $query = (new Query('/ip/hotspot/user/add'))
            ->equal('name', $username) // Username di MikroTik
            ->equal('password', $password) // Password di MikroTik
            ->equal('profile', 'default') // Profil pengguna hotspot
            ->equal('comment', "Name: {$name}, Status: inactive, Expiry: {$expiry_time}"); // Status inactive dan waktu kadaluwarsa

        $response = $client->query($query)->read();

        return response()->json(['message' => 'User added successfully with expiry time of 1 day']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function loginHotspotUser(Request $request)
{
    // Validasi input
    $request->validate([
        'no_hp' => 'required|string|max:20',  // Ini adalah username
        'password' => 'required|string|max:20', // Password pengguna
    ]);

    // Ambil username (no_hp) dan password dari request
    $username = $request->input('no_hp');
    $password = $request->input('password');

    try {
        // Koneksi ke Mikrotik
        $client = $this->getClient();

        // Query untuk mencari user berdasarkan username di MikroTik
        $checkQuery = (new Query('/ip/hotspot/user/print'))
            ->where('name', $username); // Mencari user dengan username yang sama

        // Ambil data user yang ada di MikroTik
        $existingUsers = $client->query($checkQuery)->read();

        // Jika user tidak ditemukan
        if (empty($existingUsers)) {
            return response()->json(['message' => 'User does not exist'], 404);
        }

        // Jika user ditemukan, periksa passwordnya
        $mikrotikUser = $existingUsers[0];
        if ($mikrotikUser['password'] !== $password) {
            return response()->json(['message' => 'Invalid password'], 401); // Password tidak cocok
        }

        // Atur waktu kadaluwarsa (contoh: 30 menit dari waktu login)
        $expiry_time = Carbon::now()->addMinutes(30)->format('Y/m/d H:i:s');

        // Update status dan waktu kadaluwarsa di MikroTik
        $updateQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $mikrotikUser['.id']) // ID dari user di MikroTik
            ->equal('comment', "Status: active, Expiry: {$expiry_time}"); // Ubah status menjadi aktif dan tambahkan waktu kadaluwarsa

        $client->query($updateQuery)->read();

        return response()->json(['message' => 'Login successful. Session will expire at ' . $expiry_time]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}


public function generateHotspotQrCode(Request $request)
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

    // URL login Mikrotik bisa bervariasi, sesuaikan dengan setup Anda.
    // Di sini kita anggap user akan diarahkan ke halaman login hotspot.
    $loginUrl = url("/hotspot/login?username={$username}&password={$password}");

    // Buat QR Code berdasarkan URL login Mikrotik
    $qrCode = QrCode::size(300)->generate($loginUrl);

    // Menampilkan QR Code di browser
    return response($qrCode, 200, ['Content-Type' => 'image/svg+xml']);
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

public function getHotspotUsers()
{
    try {
        $client = $this->getClient();

        // Query untuk mendapatkan daftar pengguna hotspot
        $query = new Query('/ip/hotspot/user/print');
        $users = $client->query($query)->read();

        return response()->json(['users' => $users]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}


public function deleteExpiredUsers()
{
    // Lock untuk mencegah race condition antara proses delete dan extend
    if (Cache::lock('mikrotik_user_operation', 10)->get()) {
        try {
            $client = $this->getClient();

            // Query untuk mendapatkan daftar pengguna PPP
            $query = new Query('/ppp/secret/print');
            $users = $client->query($query)->read();

            foreach ($users as $user) {
                // Ambil informasi waktu kadaluwarsa dari komentar
                if (isset($user['comment']) && strpos($user['comment'], 'Expiry:') !== false) {
                    $parts = explode(', ', $user['comment']);

                    // Ambil status jika ada
                    $isExtending = strpos($parts[0], 'Status: extending') !== false;

                    // Pastikan array parts memiliki setidaknya dua elemen untuk menghindari error
                    if (isset($parts[1]) && strpos($parts[1], 'Expiry: ') === 0) {
                        $expiryTime = Carbon::parse(substr($parts[1], strlen('Expiry: ')));

                        // Jika waktu kadaluwarsa telah tercapai, hapus pengguna meskipun sedang extending
                        if (Carbon::now()->greaterThanOrEqualTo($expiryTime)) {
                            Log::info("Deleting user {$user['.id']} with status extending due to expiry time: $expiryTime");

                            // Hapus pengguna jika waktu telah habis
                            $deleteQuery = (new Query('/ppp/secret/remove'))
                                ->equal('.id', $user['.id']);
                            $client->query($deleteQuery)->read();
                        } else {
                            Log::info("User {$user['.id']} is not expired yet. Expiry time: $expiryTime");
                        }
                    }
                }
            }

            return response()->json(['message' => 'Expired users deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            // Release lock after operation
            Cache::lock('mikrotik_user_operation')->release();
        }
    } else {
        Log::warning('Another user operation is in progress, skipping this run.');
        return response()->json(['message' => 'Another user operation is in progress'], 429);
    }
}

public function deleteExpiredHotspotUsers()
{
    // Lock untuk mencegah race condition antara proses delete dan extend
    if (Cache::lock('mikrotik_hotspot_user_operation', 10)->get()) {
        try {
            $client = $this->getClient();

            // Query untuk mendapatkan daftar pengguna hotspot
            $query = new Query('/ip/hotspot/user/print');
            $users = $client->query($query)->read();

            foreach ($users as $user) {
                // Ambil informasi waktu kadaluarsa dari komentar
                if (isset($user['comment']) && strpos($user['comment'], 'Expiry:') !== false) {
                    $parts = explode(', ', $user['comment']);

                    // Ambil status jika ada
                    $isInactive = strpos($parts[0], 'Status: inactive') !== false;

                    // Pastikan array parts memiliki setidaknya dua elemen untuk menghindari error
                    if ($isInactive && isset($parts[1]) && strpos($parts[1], 'Expiry: ') === 0) {
                        $expiryTime = Carbon::parse(substr($parts[1], strlen('Expiry: ')));

                        // Jika waktu kadaluwarsa telah tercapai, hapus pengguna
                        if (Carbon::now()->greaterThanOrEqualTo($expiryTime)) {
                            Log::info("Deleting hotspot user {$user['.id']} due to expiry time: $expiryTime");

                            // Hapus pengguna jika waktu telah habis
                            $deleteQuery = (new Query('/ip/hotspot/user/remove'))
                                ->equal('.id', $user['.id']);
                            $client->query($deleteQuery)->read();
                        } else {
                            Log::info("Hotspot user {$user['.id']} is not expired yet. Expiry time: $expiryTime");
                        }
                    }
                }
            }

            return response()->json(['message' => 'Expired hotspot users deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            // Release lock after operation
            Cache::lock('mikrotik_hotspot_user_operation')->release();
        }
    } else {
        Log::warning('Another hotspot user operation is in progress, skipping this run.');
        return response()->json(['message' => 'Another hotspot user operation is in progress'], 429);
    }
}



public function extendUserTime(Request $request)
{
    // Validasi input
    $request->validate([
        'id' => 'required|string', // validasi menggunakan id
        'additional_minutes' => 'required|integer|min:1', // menit tambahan harus integer dan minimal 1
    ]);

    $id = $request->input('id');
    $additional_minutes = $request->input('additional_minutes');

    try {
        $client = $this->getClient();

        // Cari pengguna berdasarkan ID
        $query = (new Query('/ppp/secret/print'))->where('.id', $id);
        $user = $client->query($query)->read();

        if (empty($user)) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Ambil informasi komentar yang ada
        $comment = $user[0]['comment'] ?? '';

        // Parsing waktu kadaluarsa dari komentar
        $expiryTime = null;
        if (strpos($comment, 'Expiry:') !== false) {
            $parts = explode(', ', $comment);
            foreach ($parts as $part) {
                if (strpos($part, 'Expiry:') === 0) {
                    $expiryTime = Carbon::parse(trim(substr($part, strlen('Expiry: '))));
                    break;
                }
            }

            // Jika waktu kadaluarsa ada dan belum habis, tambahkan ke waktu tersebut
            if ($expiryTime && $expiryTime->greaterThan(Carbon::now())) {
                $newExpiryTime = $expiryTime->addMinutes($additional_minutes)->format('Y-m-d H:i:s');
            } else {
                // Jika waktu kadaluarsa sudah lewat, mulai dari waktu sekarang
                $newExpiryTime = Carbon::now()->addMinutes($additional_minutes)->format('Y-m-d H:i:s');
            }
        } else {
            // Jika tidak ada waktu kadaluarsa, mulai dari waktu sekarang
            $newExpiryTime = Carbon::now()->addMinutes($additional_minutes)->format('Y-m-d H:i:s');
        }

        // Update komentar dengan waktu kadaluarsa yang baru
        $newComment = strpos($comment, 'Expiry:') !== false
            ? preg_replace('/Expiry: .*/', "Expiry: $newExpiryTime", $comment)
            : ($comment ? "$comment, Expiry: $newExpiryTime" : "Expiry: $newExpiryTime");

        $updateQuery = (new Query('/ppp/secret/set'))
            ->equal('.id', $id)
            ->equal('comment', $newComment);

        Log::info("Updating user $id with new expiry time: $newExpiryTime");
        $client->query($updateQuery)->read();

        return response()->json(['message' => 'User time extended successfully']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function extendHotspotUserTime(Request $request)
{
    // Validasi input
    $request->validate([
        'id' => 'required|string', // validasi menggunakan id
        'additional_minutes' => 'required|integer|min:1', // menit tambahan harus integer dan minimal 1
    ]);

    $id = $request->input('id');
    $additional_minutes = $request->input('additional_minutes');

    try {
        $client = $this->getClient();

        // Cari pengguna hotspot berdasarkan ID
        $query = (new Query('/ip/hotspot/user/print'))->where('.id', $id);
        $user = $client->query($query)->read();

        if (empty($user)) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Ambil informasi komentar yang ada
        $comment = $user[0]['comment'] ?? '';

        // Parsing waktu kadaluarsa dari komentar
        $expiryTime = null;
        if (strpos($comment, 'Expiry:') !== false) {
            $parts = explode(', ', $comment);
            foreach ($parts as $part) {
                if (strpos($part, 'Expiry:') === 0) {
                    $expiryTime = Carbon::parse(trim(substr($part, strlen('Expiry: '))));
                    break;
                }
            }

            // Jika waktu kadaluarsa ada dan belum habis, tambahkan ke waktu tersebut
            if ($expiryTime && $expiryTime->greaterThan(Carbon::now())) {
                $newExpiryTime = $expiryTime->addMinutes($additional_minutes)->format('Y-m-d H:i:s');
            } else {
                // Jika waktu kadaluarsa sudah lewat, mulai dari waktu sekarang
                $newExpiryTime = Carbon::now()->addMinutes($additional_minutes)->format('Y-m-d H:i:s');
            }
        } else {
            // Jika tidak ada waktu kadaluarsa, mulai dari waktu sekarang
            $newExpiryTime = Carbon::now()->addMinutes($additional_minutes)->format('Y-m-d H:i:s');
        }

        // Update komentar dengan waktu kadaluarsa yang baru
        $newComment = strpos($comment, 'Expiry:') !== false
            ? preg_replace('/Expiry: .*/', "Expiry: $newExpiryTime", $comment)
            : ($comment ? "$comment, Expiry: $newExpiryTime" : "Expiry: $newExpiryTime");

        $updateQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $id)
            ->equal('comment', $newComment);

        Log::info("Updating hotspot user $id with new expiry time: $newExpiryTime");
        $client->query($updateQuery)->read();

        return response()->json(['message' => 'User time extended successfully']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}






}
