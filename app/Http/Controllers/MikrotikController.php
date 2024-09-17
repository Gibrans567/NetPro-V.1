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

        // Cek order lama yang sudah kadaluarsa berdasarkan no_hp
        $now = Carbon::now();
        $expiredOrders = Order::where('no_hp', $no_hp)
            ->where('expiry_at', '<', $now) // Order yang sudah kadaluarsa
            ->get();

        // Hapus semua order yang sudah kadaluarsa
        foreach ($expiredOrders as $expiredOrder) {
            $expiredOrder->delete();
        }

         // Set expiry_time untuk database (2 menit)
        $db_expiry_time = Carbon::now()->addMinutes(2)->format('Y-m-d H:i:s');

        // Simpan pesanan baru dan hitung waktu expiry baru
        $expiry_time = Carbon::now()->addMinutes(1)->format('Y/m/d H:i:s'); // Waktu expiry tetap 6 jam

        foreach ($menu_ids as $menu_id) {
            Order::create([
                'no_hp' => $no_hp,
                'menu_id' => $menu_id,
                'expiry_at' => $db_expiry_time // Set waktu kadaluarsa untuk order baru
            ]);
        }

        try {
            $client = $this->getClient();

            // Cek apakah user sudah ada di MikroTik
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
            ], );
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
        // Validasi input
        $request->validate([
            'no_hp' => 'required|string|max:20',
            'password' => 'required|string|max:20',
        ]);

        $username = $request->input('no_hp');
        $password = $request->input('password');

        try {
            // Connect ke Mikrotik
            $client = $this->getClient();

            // Cek apakah user sudah ada di MikroTik
            $checkQuery = (new Query('/ip/hotspot/user/print'))
                ->where('name', $username);

            $existingUsers = $client->query($checkQuery)->read();

            // Jika user tidak ditemukan
            if (empty($existingUsers)) {
                return response()->json(['message' => 'User does not exist'], 404);
            }

            // Jika user ditemukan, cek password
            $mikrotikUser = $existingUsers[0];
            if ($mikrotikUser['password'] !== $password) {
                return response()->json(['message' => 'Invalid password'], 401);
            }

            // Cek apakah user sudah aktif
            if (strpos($mikrotikUser['comment'], 'Status: active') !== false) {
                return response()->json(['message' => 'User already active. Cannot extend session.'], 403);
            }

            // Ambil semua order yang masih aktif berdasarkan no_hp pengguna
            $now = Carbon::now();
            $activeOrders = Order::where('no_hp', $username)
                ->where('expiry_at', '>=', $now) // Hanya order yang belum kadaluarsa
                ->get();

            // Jika tidak ada order aktif
            if ($activeOrders->isEmpty()) {
                return response()->json(['message' => 'No active order found for this user'], 400);
            }

            // Hitung total waktu kadaluarsa berdasarkan semua order aktif
            $totalExpiryMinutes = 0;
            foreach ($activeOrders as $order) {
                // Ambil detail dari setiap menu_id dan hitung total expiry time
                $orderDetails = $this->calculateOrderDetails([$order->menu_id]);
                if ($orderDetails) {
                    $totalExpiryMinutes += $orderDetails->total_expiry_time;
                }
            }

            // Jika waktu kadaluarsa tidak dapat dihitung
            if ($totalExpiryMinutes <= 0) {
                return response()->json(['message' => 'Unable to calculate expiry time'], 500);
            }

            // Atur waktu kadaluarsa berdasarkan total waktu dari semua order
            $expiry_time = Carbon::now()->addMinutes($totalExpiryMinutes)->format('Y/m/d H:i:s');

            // Update status dan waktu kadaluarsa di MikroTik
            $updateQuery = (new Query('/ip/hotspot/user/set'))
                ->equal('.id', $mikrotikUser['.id'])
                ->equal('comment', preg_replace('/(Status: .*, Expiry: .*)/', "Status: active, Expiry: {$expiry_time}", $mikrotikUser['comment']));

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

            // URL API untuk login
            $loginUrlBase = "http://127.0.0.1:8000/api/mikrotik/login-hotspot-user"; // Endpoint API Anda

            // Siapkan array untuk menyimpan user data dalam format string URL login
            $formattedUsers = [];

            foreach ($users as $user) {
                if (isset($user['name']) && isset($user['password'])) {
                    // Membuat URL login dengan parameter username dan password
                    $loginUrl = $loginUrlBase . '?username=' . urlencode($user['name']) . '&password=' . urlencode($user['password']);

                    $formattedUsers[] = [
                        'id' => $user['.id'],
                        'username' => $user['name'],
                        'password' => $user['password'],
                        'login_url' => $loginUrl, // URL login langsung untuk user ini
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

            // Proses setiap pengguna untuk mengubah .id menjadi id
            $modifiedUsers = array_map(function($user) {
                $newUser = [];
                foreach ($user as $key => $value) {
                    // Ganti .id dengan id pada key
                    $newKey = str_replace('.id', 'id', $key);
                    $newUser[$newKey] = $value;
                }
                return $newUser;
            }, $users);

            return response()->json(['users' => $modifiedUsers]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function getHotspotUserByPhoneNumber($no_hp)
    {
        try {
            $client = $this->getClient();

            // Query untuk mendapatkan pengguna berdasarkan nomor telepon
            $query = new Query('/ip/hotspot/user/print');
            $query->where('name', $no_hp); // 'name' adalah field untuk username di MikroTik

            $users = $client->query($query)->read();

            if (empty($users)) {
                return response()->json(['message' => 'User not found'], 404);
            }

            // Ambil pengguna pertama (jika ada banyak)
            $user = $users[0];

            // Ubah .id menjadi id jika ada
            $modifiedUser = [];
            foreach ($user as $key => $value) {
                // Ganti .id dengan id pada key
                $newKey = str_replace('.id', 'id', $key);
                $modifiedUser[$newKey] = $value;
            }

            // Format response untuk mengembalikan data pengguna yang sudah diubah
            return response()->json(['user' => $modifiedUser]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }





public function deleteExpiredHotspotUsers()
{
    // Ambil instance lock dan gunakan di semua tempat
    $lock = Cache::lock('mikrotik_hotspot_user_operation', 10);

    if ($lock->get()) {
        Log::info('Lock acquired for hotspot user operation');
        try {
            $client = $this->getClient();

            // Query untuk mendapatkan daftar pengguna hotspot
            $query = new Query('/ip/hotspot/user/print');
            $users = $client->query($query)->read();

            foreach ($users as $user) {
                if (isset($user['comment']) && strpos($user['comment'], 'Expiry:') !== false) {
                    $parts = explode(', ', $user['comment']);
                    foreach ($parts as $part) {
                        if (strpos($part, 'Expiry:') === 0) {
                            $expiryTimeString = trim(substr($part, strlen('Expiry: ')));
                            try {
                                // Ubah format ke 'Y/m/d H:i:s' sesuai dengan format di komentar
                                $expiryTime = Carbon::createFromFormat('Y/m/d H:i:s', $expiryTimeString);
                            } catch (\Exception $e) {
                                Log::error("Error parsing expiry time for user {$user['.id']}: " . $e->getMessage());
                                continue;
                            }

                            // Cek apakah waktu sudah kadaluarsa
                            if (Carbon::now()->greaterThanOrEqualTo($expiryTime)) {
                                Log::info("Deleting hotspot user {$user['.id']} with expiry time: $expiryTime");

                                // Hapus pengguna jika waktu sudah habis
                                $deleteQuery = (new Query('/ip/hotspot/user/remove'))
                                    ->equal('.id', $user['.id']);
                                $client->query($deleteQuery)->read();
                                Log::info("User {$user['.id']} has been deleted.");
                            } else {
                                Log::info("Hotspot user {$user['.id']} is not expired yet. Expiry time: $expiryTime");
                            }
                        }
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
            // Release lock setelah operasi selesai
            $lock->release(); // Ini akan menggunakan instance yang sama untuk melepaskan lock
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
        'no_hp' => 'required|string|max:20', // validasi berdasarkan no_hp
        'menu_ids' => 'required|array' // menu_ids yang digunakan untuk menambah waktu
    ]);

    $no_hp = $request->input('no_hp');
    $menu_ids = $request->input('menu_ids');

    try {
        $client = $this->getClient();

        // Cari pengguna hotspot berdasarkan no_hp
        $query = (new Query('/ip/hotspot/user/print'))->where('name', $no_hp);
        $user = $client->query($query)->read();

        if (empty($user)) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Ambil informasi komentar yang ada di pengguna MikroTik
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

            // Jika waktu kadaluarsa ada dan belum habis, tambahkan waktu tambahan berdasarkan menu_id
            if ($expiryTime && $expiryTime->greaterThan(Carbon::now())) {
                $newExpiryTime = $expiryTime->addMinutes($this->calculateExpiryFromMenuIds($menu_ids))->format('Y-m-d H:i:s');
            } else {
                // Jika waktu kadaluarsa sudah lewat, mulai dari waktu sekarang
                $newExpiryTime = Carbon::now()->addMinutes($this->calculateExpiryFromMenuIds($menu_ids))->format('Y-m-d H:i:s');
            }
        } else {
            // Jika tidak ada waktu kadaluarsa, mulai dari waktu sekarang
            $newExpiryTime = Carbon::now()->addMinutes($this->calculateExpiryFromMenuIds($menu_ids))->format('Y-m-d H:i:s');
        }

        // Update komentar dengan waktu kadaluarsa yang baru di MikroTik
        $newComment = strpos($comment, 'Expiry:') !== false
            ? preg_replace('/Expiry: .*/', "Expiry: $newExpiryTime", $comment)
            : ($comment ? "$comment, Expiry: $newExpiryTime" : "Expiry: $newExpiryTime");

        $updateQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $user[0]['.id'])
            ->equal('comment', $newComment);

        Log::info("Updating hotspot user $no_hp with new expiry time: $newExpiryTime");
        $client->query($updateQuery)->read();

        // Set expiry_time untuk database (2 menit)
        $db_expiry_time = Carbon::now()->addMinutes(2)->format('Y-m-d H:i:s');

        // Simpan ke tabel order setiap kali dilakukan extend
        foreach ($menu_ids as $menu_id) {
            Order::create([
                'no_hp' => $no_hp,
                'menu_id' => $menu_id,
                'expiry_at' => $db_expiry_time // Set waktu kadaluarsa untuk order baru
            ]);
        }

        return response()->json(['message' => 'User time extended successfully']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

// Fungsi untuk menghitung waktu tambahan berdasarkan menu_ids
protected function calculateExpiryFromMenuIds(array $menu_ids)
{
    // Dapatkan detail dari semua menu_ids yang dikirimkan
    $menus = Menu::whereIn('id', $menu_ids)->get();

    // Hitung total waktu tambahan dari semua menu_ids
    $totalExpiryTime = $menus->sum('expiry_time'); // Asumsikan ada field 'expiry_time' di tabel 'menus'

    return $totalExpiryTime;
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

public function addMenu(Request $request)
{
    // Validasi input
    $request->validate([
        'name' => 'required|string|max:255',
        'price' => 'required|numeric|min:0',
        'expiry_time' => 'required|integer|min:1', // Assuming expiry_time is in minutes
    ]);

    try {
        // Membuat menu baru
        $menu = new Menu();
        $menu->name = $request->input('name');
        $menu->price = $request->input('price');
        $menu->expiry_time = $request->input('expiry_time'); // menyimpan dalam menit
        $menu->save();

        return response()->json(['message' => 'Menu added successfully', 'menu' => $menu], 201);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function editMenu(Request $request, $id)
{
    // Validasi input
    $request->validate([
        'name' => 'sometimes|required|string|max:255',
        'price' => 'sometimes|required|numeric|min:0',
        'expiry_time' => 'sometimes|required|integer|min:1', // Assuming expiry_time is in minutes
    ]);

    try {
        // Cari menu berdasarkan ID
        $menu = Menu::findOrFail($id);

        // Update menu berdasarkan input yang ada
        if ($request->has('name')) {
            $menu->name = $request->input('name');
        }
        if ($request->has('price')) {
            $menu->price = $request->input('price');
        }
        if ($request->has('expiry_time')) {
            $menu->expiry_time = $request->input('expiry_time');
        }

        $menu->save();

        return response()->json(['message' => 'Menu updated successfully', 'menu' => $menu]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function getAllMenus()
{
    try {
        // Retrieve all menus from the database
        $menus = Menu::all();

        // Check if there are menus
        if ($menus->isEmpty()) {
            return response()->json(['message' => 'No menus found'], 404);
        }

        return response()->json(['menus' => $menus]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function getAllOrders()
{
    try {
        // Retrieve all orders from the database
        $orders = Order::all();

        // Check if there are orders
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No orders found'], 404);
        }

        return response()->json(['orders' => $orders]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}


}
