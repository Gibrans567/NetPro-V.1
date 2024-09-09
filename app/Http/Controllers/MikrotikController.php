<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Order;
use Carbon\Carbon;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Label\Font\NotoSans;
use RouterOS\Client;
use RouterOS\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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

    public function addHotspotUser(Request $request)
    {
        // Validasi input
        $request->validate([
            'no_hp' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'menu_ids' => 'required|array' // ID menu yang dipesan
        ]);

        $no_hp = $request->input('no_hp');
        $name = $request->input('name');
        $menu_ids = $request->input('menu_ids');

        // Hitung total harga dan expiry_time dari menu yang dipesan
        $orderDetails = $this->calculateOrderDetails($menu_ids);

        if (!$orderDetails || $orderDetails->total_expiry_time === 0) {
            return response()->json(['message' => 'No valid menu items found.'], 400);
        }

        // Simpan pesanan
        foreach ($menu_ids as $menu_id) {
            Order::create([
                'no_hp' => $no_hp,
                'menu_id' => $menu_id
            ]);
        }

        // Hitung waktu kadaluarsa berdasarkan total expiry_time
        $expiry_time = Carbon::now()->addMinutes($orderDetails->total_expiry_time)->format('Y/m/d H:i:s');

        try {
            $client = $this->getClient();

            // Cek apakah user sudah ada
            $checkQuery = (new Query('/ip/hotspot/user/print'))
                ->where('name', $no_hp);

            $existingUsers = $client->query($checkQuery)->read();

            if (!empty($existingUsers)) {
                return response()->json(['message' => 'User already exists'], 409);
            }

            // Tambahkan user baru
            $query = (new Query('/ip/hotspot/user/add'))
                ->equal('name', $no_hp)
                ->equal('password', $no_hp)
                ->equal('profile', 'default')
                ->equal('comment', "Name: {$name}, Status: inactive, Expiry: {$expiry_time}");

            $client->query($query)->read();

            return response()->json([
                'message' => 'User added successfully with expiry time of ' . $orderDetails->total_expiry_time . ' minutes',
                'total_price' => $orderDetails->total_harga
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function calculateOrderDetails($menu_ids)
    {
        return Order::whereIn('menu_id', $menu_ids)
            ->join('menus', 'orders.menu_id', '=', 'menus.id')
            ->selectRaw('SUM(menus.expiry_time) as total_expiry_time, SUM(menus.price) as total_harga')
            ->first();
    }


public function loginHotspotUser(Request $request)
{
    // Validasi input
    $request->validate([
        'no_hp' => 'required|string|max:20',
        'password' => 'required|string|max:20',
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

        // Cek apakah user sudah aktif
        if (strpos($mikrotikUser['comment'], 'Status: active') !== false) {
            return response()->json(['message' => 'User already active. Cannot extend session.'], 403); // User sudah aktif
        }

        // Jika belum aktif, set waktu expiry time baru (contoh: 30 menit dari sekarang)
        $expiry_time = Carbon::now()->addMinutes(2)->format('Y/m/d H:i:s');

        // Update status dan waktu kadaluwarsa di MikroTik
        $updateQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $mikrotikUser['.id']) // ID dari user di MikroTik
            ->equal('name', $mikrotikUser['name']) // Pastikan 'name' tetap ada
            ->equal('comment', "Status: active, Expiry: {$expiry_time}"); // Ubah status menjadi aktif dan tambahkan waktu kadaluwarsa

        $client->query($updateQuery)->read();

        return response()->json(['message' => 'Login successful. Session will expire at ' . $expiry_time]);
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

        // Siapkan array untuk menyimpan user data dalam format string "username:password"
        $formattedUsers = [];

        foreach ($users as $user) {
            if (isset($user['name']) && isset($user['password'])) {
                $formattedUsers[] = [
                    'id' => $user['.id'],
                    'username' => $user['name'],
                    'password' => $user['password'],
                    'qr_string' => $user['name'] . ':' . $user['password'] // Menggabungkan username dan password
                ];
            }
        }

        return response()->json(['users' => $formattedUsers]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function getHotspotUsers1()
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
                            Log::info("Deleting hotspot user {$user['.id']} with status extending due to expiry time: $expiryTime");

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

public function createOrder(Request $request)
{
    // Validasi input
    $request->validate([
        'user_id' => 'required|exists:users,id',
        'menu_id' => 'required|exists:menus,id',
    ]);

    // Ambil menu yang dipesan
    $menu = Menu::find($request->input('menu_id'));

    // Hitung waktu kadaluwarsa berdasarkan waktu sekarang + expiry_duration dari menu
    $expiry_time = Carbon::now()->addMinutes($menu->expiry_duration);

    // Simpan pesanan
    $order = new Order();
    $order->user_id = $request->input('user_id');
    $order->menu_id = $menu->id;
    $order->expiry_time = $expiry_time;
    $order->save();

    return response()->json(['message' => 'Order created successfully', 'expiry_time' => $expiry_time]);
}




}
