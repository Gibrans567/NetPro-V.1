<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Order;
use Carbon\Carbon;
use RouterOS\Client;
use RouterOS\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MikrotikController extends Controller
{
    protected function getClient()
    {
        $config = [
            'host' => '192.168.6.21',  // Ganti dengan IP Mikrotik kamu
            'user' => 'admin',         // Ganti dengan username Mikrotik kamu
            'pass' => 'admin2',        // Ganti dengan password Mikrotik kamu
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

        // Simpan pesanan
        foreach ($menu_ids as $menu_id) {
            Order::create([
                'no_hp' => $no_hp,
                'menu_id' => $menu_id
            ]);
        }

        // Waktu kadaluarsa tetap selama 6 jam (360 menit) dari sekarang
        $expiry_time = Carbon::now()->addMinutes(360)->format('Y/m/d H:i:s');

        try {
            $client = $this->getClient();

            // Cek apakah user sudah ada
            $checkQuery = (new Query('/ip/hotspot/user/print'))
                ->where('name', $no_hp);

            $existingUsers = $client->query($checkQuery)->read();

            if (!empty($existingUsers)) {
                return response()->json(['message' => 'User already exists'], 409);
            }

            // Tambahkan user baru dengan waktu kadaluarsa 6 jam
            $query = (new Query('/ip/hotspot/user/add'))
                ->equal('name', $no_hp)
                ->equal('password', $no_hp)
                ->equal('profile', 'default')
                ->equal('comment', "Name: {$name}, Status: inactive, Expiry: {$expiry_time}");

            $client->query($query)->read();

            return response()->json([
                'message' => 'User added successfully with a default expiry time of 6 hours',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function calculateOrderDetails(array $menu_ids)
    {
        // Query database untuk mendapatkan harga dan waktu expiry berdasarkan menu_ids
        $menus = Menu::whereIn('id', $menu_ids)->get();

        // Jika tidak ada data menu ditemukan, return null atau error
        if ($menus->isEmpty()) {
            return null;
        }

        // Hitung total harga dan expiry_time
        $total_harga = $menus->sum('price'); // Field 'price' harus ada di tabel Menu
        $total_expiry_time = $menus->sum('expiry_time'); // Field 'expiry_time' harus ada di tabel Menu

        // Kembalikan hasil dalam bentuk objek
        return (object)[
            'total_harga' => $total_harga,
            'total_expiry_time' => $total_expiry_time
        ];
    }



    public function loginHotspotUser(Request $request)
    {
        // Validation
        $request->validate([
            'no_hp' => 'required|string|max:20',
            'password' => 'required|string|max:20',
        ]);

        $username = $request->input('no_hp');
        $password = $request->input('password');

        try {
            // Connect to Mikrotik
            $client = $this->getClient();

            // Check if user exists in MikroTik
            $checkQuery = (new Query('/ip/hotspot/user/print'))
                ->where('name', $username);

            $existingUsers = $client->query($checkQuery)->read();

            // If user not found
            if (empty($existingUsers)) {
                return response()->json(['message' => 'User does not exist'], 404);
            }

            // If user found, check password
            $mikrotikUser = $existingUsers[0];
            if ($mikrotikUser['password'] !== $password) {
                return response()->json(['message' => 'Invalid password'], 401);
            }

            // Check if user is already active
            if (strpos($mikrotikUser['comment'], 'Status: active') !== false) {
                return response()->json(['message' => 'User already active. Cannot extend session.'], 403);
            }

            // Get all active orders based on user's phone number
            $activeOrders = Order::where('no_hp', $username)->get();

            if ($activeOrders->isEmpty()) {
                return response()->json(['message' => 'No active order found for this user'], 400);
            }

            // Calculate total expiry time from all orders
            $totalExpiryMinutes = 0;
            foreach ($activeOrders as $order) {
                $orderDetails = $this->calculateOrderDetails([$order->menu_id]);
                if ($orderDetails) {
                    $totalExpiryMinutes += $orderDetails->total_expiry_time;
                }
            }

            if ($totalExpiryMinutes <= 0) {
                return response()->json(['message' => 'Unable to calculate expiry time'], 500);
            }

            // Set expiry time based on total time from all orders
            $expiry_time = Carbon::now()->addMinutes($totalExpiryMinutes)->format('Y/m/d H:i:s');

            // Update status and expiry time in MikroTik, preserving the 'name' field
            // ... (bagian kode sebelumnya)

// Update status dan waktu kadaluarsa di MikroTik, pertahankan "Name"
            $updateQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $mikrotikUser['.id'])
            ->equal('comment', preg_replace('/(Status: .*, Expiry: .*)/', "Status: active, Expiry: {$expiry_time}", $mikrotikUser['comment']));

// ... (bagian kode selanjutnya)

            $client->query($updateQuery)->read();

            return response()->json([
                'message' => 'Login successful. Session will expire at ' . $expiry_time,
            ]);
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
        Log::info('Lock acquired for hotspot user operation');
        try {
            $client = $this->getClient();

            // Query untuk mendapatkan daftar pengguna hotspot
            $query = new Query('/ip/hotspot/user/print');
            $users = $client->query($query)->read();

            foreach ($users as $user) {
                // Ambil informasi waktu kadaluwarsa dari komentar
                if (isset($user['comment']) && strpos($user['comment'], 'Expiry:') !== false) {
                    $parts = explode(', ', $user['comment']);

                    // Pastikan array parts memiliki setidaknya dua elemen untuk menghindari error
                    if (isset($parts[1]) && strpos($parts[1], 'Expiry: ') === 0) {
                        try {
                            // Gunakan createFromFormat untuk parsing waktu yang lebih ketat
                            $expiryTime = Carbon::createFromFormat('Y/m/d H:i:s', substr($parts[1], strlen('Expiry: ')));
                        } catch (\Exception $e) {
                            Log::error("Error parsing expiry time for user {$user['.id']}: " . $e->getMessage());
                            continue;
                        }

                        // Debug log untuk waktu sistem dan waktu expiry pengguna
                        Log::info("Current time: " . Carbon::now());
                        Log::info("User {$user['.id']} expiry time: $expiryTime");

                        // Jika waktu kadaluwarsa telah tercapai, hapus pengguna terlepas dari statusnya
                        if (Carbon::now()->greaterThanOrEqualTo($expiryTime)) {
                            Log::info("Deleting hotspot user {$user['.id']} with expiry time: $expiryTime");

                            // Hapus pengguna jika waktu telah habis
                            $deleteQuery = (new Query('/ip/hotspot/user/remove'))
                                ->equal('.id', $user['.id']);
                            $client->query($deleteQuery)->read();
                            Log::info("User {$user['.id']} has been deleted.");
                        } else {
                            Log::info("Hotspot user {$user['.id']} is not expired yet. Expiry time: $expiryTime");
                        }
                    } else {
                        Log::warning("Expiry time not found or invalid for user {$user['.id']}");
                    }
                } else {
                    Log::warning("No expiry information found in comment for user {$user['.id']}");
                }
            }

            return response()->json(['message' => 'Expired hotspot users deleted successfully']);
        } catch (\Exception $e) {
            Log::error("Error deleting hotspot users: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            // Release lock after operation
            Cache::lock('mikrotik_hotspot_user_operation')->release();
            Log::info('Lock released after operation');
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
