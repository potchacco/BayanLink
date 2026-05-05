<?php
require_once 'config/db.php';

// ========== HANDLE LOGIN / REGISTER / LOGOUT ==========
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['fullname']  = $user['fullname'];
        $_SESSION['role']      = $user['role'];
        // Clear any previous messages
        unset($_SESSION['login_error']);
        redirect('index.php');
    } else {
        $_SESSION['login_error'] = 'Invalid email or password.';
        redirect('index.php');
    }
}

    if (isset($_POST['action']) && $_POST['action'] === 'register') {
    // 1. Get and sanitize input
    $fullname = trim($_POST['fullname']);
    $email    = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);  // hash immediately
    $role     = $_POST['role'] ?? 'user';

    // 2. Validation
    $errors = [];
    if (empty($fullname)) $errors[] = 'Full name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($_POST['password']) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$fullname, $email, $password, $role])) {
            // Do NOT log in – just show success message
            $_SESSION['register_success'] = true;
            redirect('index.php');
        } else {
            $errors[] = 'Registration failed. Please try again.';
        }
    }
    // If there are errors, store them and reload
    $_SESSION['register_errors'] = $errors;
    redirect('index.php');
}

// ========== LOGOUT ==========
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    redirect('index.php');
}

// ========== CURRENT USER DATA ==========
$user_id   = $_SESSION['user_id']   ?? null;
$user_role = $_SESSION['role']      ?? null;
$user_name = $_SESSION['fullname']  ?? '';

// ========== NOTIFICATIONS ==========
if (!isGuest()) {
    $notifications = getNotifications($user_id);
    $unread_count  = getUnreadNotificationCount($user_id);
} else {
    $notifications = [];
    $unread_count  = 0;
}

// ========== HANDLE POST CREATION ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_type']) && !isGuest()) {
    $post_type = $_POST['post_type'];

    if ($post_type === 'looking_for' && ($user_role === 'user' || $user_role === 'business_owner')) {
        $title = trim($_POST['title']);
        $desc = trim($_POST['description']);
        $category = $_POST['category'] ?? null;
        $location = $_POST['location'] ?? null;
        $needed_date = !empty($_POST['needed_date']) ? $_POST['needed_date'] : null;
        $needed_time = !empty($_POST['needed_time']) ? $_POST['needed_time'] : null;

        $stmt = $pdo->prepare("INSERT INTO requests (user_id, title, description, poster_role, category, location, needed_date, needed_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $desc, $user_role, $category, $location, $needed_date, $needed_time]);
    }
    elseif ($post_type === 'service' && $user_role === 'business_owner') {
    $business = trim($_POST['business_name']);
    $title = trim($_POST['title']);
    $desc = trim($_POST['description']);
    $price = trim($_POST['price_range']);
    $image_path = null;

    // Handle image upload
    if (!empty($_FILES['service_image']['name'])) {
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        $file_name = time() . "_" . basename($_FILES['service_image']['name']);
        $target_file = $target_dir . $file_name;
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($imageFileType, $allowed) && $_FILES['service_image']['size'] < 5000000) {
            if (move_uploaded_file($_FILES['service_image']['tmp_name'], $target_file)) {
                $image_path = $target_file;
            }
        }
    }

    $stmt = $pdo->prepare("INSERT INTO services (business_owner_id, business_name, title, description, price_range, image_path) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $business, $title, $desc, $price, $image_path]);
}
    elseif ($post_type === 'event' && $user_role === 'admin') {
        $title = trim($_POST['title']);
        $desc = trim($_POST['description']);
        $event_date = $_POST['event_date'];
        $location = trim($_POST['location']);
        $stmt = $pdo->prepare("INSERT INTO events (admin_id, title, description, event_date, location) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $desc, $event_date, $location]);

        $users = $pdo->query("SELECT id FROM users WHERE role = 'user'")->fetchAll();
        foreach ($users as $u) {
            createNotification($u['id'], 'event', "New event: $title", "index.php#feed");
        }
    }
    elseif ($post_type === 'announcement' && $user_role === 'admin') {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $status = $_POST['status'] ?? 'Processing';
        $stmt = $pdo->prepare("INSERT INTO announcements (admin_id, title, content, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $content, $status]);

        $users = $pdo->query("SELECT id FROM users WHERE role IN ('user','business_owner')")->fetchAll();
        foreach ($users as $u) {
            createNotification($u['id'], 'announcement', "New announcement: $title", "index.php#feed");
        }
    }

    redirect('index.php');
}

// ========== HANDLE EVENT JOIN ==========
if (isset($_GET['join_event']) && !isGuest() && $user_role === 'user') {
    $event_id = (int)$_GET['join_event'];
    $check = $pdo->prepare("SELECT id FROM event_participants WHERE event_id = ? AND user_id = ?");
    $check->execute([$event_id, $user_id]);
    if (!$check->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO event_participants (event_id, user_id) VALUES (?, ?)");
        $stmt->execute([$event_id, $user_id]);
    }
    redirect('index.php#feed');
}

// ========== FETCH FEED ITEMS ==========
$feed_items = [];
if (!isGuest()) {
    $requests = $pdo->query("SELECT r.*, u.fullname as author, r.poster_role as author_role, 'looking_for' as type FROM requests r JOIN users u ON r.user_id = u.id ORDER BY r.created_at DESC")->fetchAll();
    foreach ($requests as $req) { $feed_items[] = $req; }

    $services = $pdo->query("SELECT s.*, u.fullname as author, 'service' as type FROM services s JOIN users u ON s.business_owner_id = u.id ORDER BY s.created_at DESC")->fetchAll();
    foreach ($services as $s) { $feed_items[] = $s; }

    $events = $pdo->query("SELECT e.*, u.fullname as author, 'event' as type, (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count FROM events e JOIN users u ON e.admin_id = u.id ORDER BY e.created_at DESC")->fetchAll();
    foreach ($events as $e) { $feed_items[] = $e; }

    $announcements = $pdo->query("SELECT a.*, u.fullname as author, 'announcement' as type FROM announcements a JOIN users u ON a.admin_id = u.id ORDER BY a.created_at DESC")->fetchAll();
    foreach ($announcements as $a) { $feed_items[] = $a; }

    usort($feed_items, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BayanLink - Smart Community Hub Platform</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Poppins', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd',
                            400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8',
                            800: '#1e40af', 900: '#1e3a8a',
                        },
                        accent: {
                            400: '#f472b6', 500: '#ec4899', 600: '#db2777',
                        }
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'shimmer': 'shimmer 2s linear infinite',
                    },
                    keyframes: {
                        float: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-20px)' } },
                        shimmer: { '0%': { backgroundPosition: '-200% 0' }, '100%': { backgroundPosition: '200% 0' } }
                    }
                }
            }
        }
    </script>

    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.3);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        }
        .dark {
            --glass-bg: rgba(17, 24, 39, 0.7);
            --glass-border: rgba(255, 255, 255, 0.1);
            --glass-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }
        .glass { background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--glass-border); box-shadow: var(--glass-shadow); }
        .glass-card { background: var(--glass-bg); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid var(--glass-border); box-shadow: var(--glass-shadow); transition: all 0.3s ease; }
        .glass-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(31, 38, 135, 0.25); }
        .floating-shape { position: absolute; border-radius: 50%; filter: blur(60px); opacity: 0.6; animation: float 8s ease-in-out infinite; }
        .gradient-text { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .dark .gradient-text { background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .device-mockup { border-radius: 40px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 8px solid #1f2937; overflow: hidden; position: relative; }
        .dark .device-mockup { border-color: #374151; }
        .status-pending { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); }
        .status-approved { background: linear-gradient(135deg, #34d399 0%, #10b981 100%); }
        .status-processing { background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%); }
        .navbar-scrolled { background: rgba(255, 255, 255, 0.9) !important; backdrop-filter: blur(20px) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .dark .navbar-scrolled { background: rgba(17, 24, 39, 0.9) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        .notification-dropdown { transform: scale(0.95); opacity: 0; visibility: hidden; transition: all 0.2s ease; }
        .notification-dropdown.active { transform: scale(1); opacity: 1; visibility: visible; }
        .settings-dropdown { transform: scale(0.95); opacity: 0; visibility: hidden; transition: all 0.2s ease; }
        .settings-dropdown.active { transform: scale(1); opacity: 1; visibility: visible; }
        .loader { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #fff; z-index: 9999; display: flex; justify-content: center; align-items: center; transition: opacity 0.5s ease; }
        .dark .loader { background: #111827; }
        .loader.hidden { opacity: 0; pointer-events: none; }

        /* Smooth modal appear/disappear */
        #loginModalContent, #registerModalContent {
            transition: transform 0.3s ease, opacity 0.3s ease;
        }
        #loginModal.active #loginModalContent,
        #registerModal.active #registerModalContent {
            transform: scale(1);
            opacity: 1;
        }
    </style>
</head>

<body class="font-sans antialiased bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300 overflow-x-hidden">

    <!-- LOGIN MODAL -->
<div id="loginModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm transition-opacity duration-300">
    <div class="glass-card rounded-2xl p-8 w-full max-w-md mx-4 relative transform transition-all duration-300 scale-95 opacity-0" id="loginModalContent">
        <button onclick="closeModal('loginModal')" class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"><i class="fas fa-times text-xl"></i></button>
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-gradient-to-br from-primary-500 to-accent-500 mb-4">
                <i class="fas fa-network-wired text-white text-2xl"></i>
            </div>
            <h2 class="text-2xl font-bold">Welcome Back</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Sign in to your account</p>
        </div>
        <div id="loginError" class="hidden bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4 text-sm"></div>
        <form method="POST" action="index.php" class="space-y-4">
            <input type="hidden" name="action" value="login">
            <!-- Email -->
            <div>
                <label class="block text-sm font-medium mb-2">Email</label>
                <div class="relative">
                    <i class="fas fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="email" name="email" required placeholder="your@email.com" class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800/50 focus:ring-2 focus:ring-primary-500 outline-none transition">
                </div>
            </div>
            <!-- Password with eye toggle -->
            <div>
                <label class="block text-sm font-medium mb-2">Password</label>
                <div class="relative">
                    <i class="fas fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="password" name="password" id="loginPassword" required placeholder="••••••••" class="w-full pl-10 pr-12 py-3 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800/50 focus:ring-2 focus:ring-primary-500 outline-none transition">
                    <button type="button" onclick="togglePassword('loginPassword', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="far fa-eye"></i>
                    </button>
                </div>
            </div>
            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center">
                    <input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                    <span class="ml-2 text-gray-600 dark:text-gray-400">Remember me</span>
                </label>
                <a href="#" onclick="alert('Password reset link will be sent to your email.')" class="text-primary-600 hover:underline font-medium">Forgot Password?</a>
            </div>
            <button type="submit" class="w-full py-3 bg-gradient-to-r from-primary-600 to-accent-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transition">
                Sign In
            </button>
        </form>
        <p class="mt-4 text-sm text-center text-gray-600 dark:text-gray-400">
            Don't have an account? <a href="#" onclick="switchModal('registerModal')" class="text-primary-600 font-medium hover:underline">Create one</a>
        </p>
    </div>
</div>

    <!-- REGISTER MODAL -->
<div id="registerModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm transition-opacity duration-300">
    <div class="glass-card rounded-2xl p-8 w-full max-w-md mx-4 relative transform transition-all duration-300 scale-95 opacity-0" id="registerModalContent">
        <button onclick="closeModal('registerModal')" class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"><i class="fas fa-times text-xl"></i></button>
        <div class="text-center mb-6">
            <h2 class="text-2xl font-bold">Create Account</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Join the community</p>
        </div>
        <div id="registerError" class="hidden bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4 text-sm"></div>
        <form method="POST" action="index.php" class="space-y-4">
            <input type="hidden" name="action" value="register">
            <div>
                <label class="block text-sm font-medium mb-2">Full Name</label>
                <div class="relative">
                    <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="fullname" required placeholder="Juan Dela Cruz" class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800/50 focus:ring-2 focus:ring-primary-500 outline-none transition">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">Email</label>
                <div class="relative">
                    <i class="fas fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="email" name="email" required placeholder="your@email.com" class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800/50 focus:ring-2 focus:ring-primary-500 outline-none transition">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">Password</label>
                <div class="relative">
                    <i class="fas fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="password" name="password" id="registerPassword" required placeholder="••••••••" class="w-full pl-10 pr-12 py-3 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800/50 focus:ring-2 focus:ring-primary-500 outline-none transition">
                    <button type="button" onclick="togglePassword('registerPassword', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="far fa-eye"></i>
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">Role</label>
                <select name="role" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800/50 focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="user">Community Member</option>
                    <option value="business_owner">Business Owner</option>
                    <!-- <option value="admin">Admin</option> -->
                </select>
            </div>
            <button type="submit" class="w-full py-3 bg-gradient-to-r from-primary-600 to-accent-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transition">
                Register
            </button>
        </form>
        <p class="mt-4 text-sm text-center text-gray-600 dark:text-gray-400">
            Already have an account? <a href="#" onclick="switchModal('loginModal')" class="text-primary-600 font-medium hover:underline">Sign in</a>
        </p>
    </div>
</div>

<?php
// Check for messages and pass them to JavaScript
$showLoginModal = !empty($_SESSION['login_error']);
$registerSuccess = !empty($_SESSION['register_success']);
$registerErrors = $_SESSION['register_errors'] ?? [];
// Clear them after reading
if ($showLoginModal) {
    $loginErrorMessage = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
if ($registerSuccess) {
    unset($_SESSION['register_success']);
}
if (!empty($registerErrors)) {
    $registerErrorList = $registerErrors;
    unset($_SESSION['register_errors']);
}
?>

    <!-- Loading Screen -->
    <div class="loader" id="loader">
        <div class="flex flex-col items-center">
            <div class="w-16 h-16 border-4 border-primary-200 border-t-primary-600 rounded-full animate-spin mb-4"></div>
            <p class="text-lg font-display font-semibold gradient-text">BayanLink</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Loading your community...</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="fixed w-full z-50 transition-all duration-300" id="navbar">
        <div class="glass mx-4 mt-4 rounded-2xl px-6 py-4" id="navContainer">
            <div class="flex items-center justify-between max-w-7xl mx-auto">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-accent-500 flex items-center justify-center">
                        <i class="fas fa-network-wired text-white text-lg"></i>
                    </div>
                    <span class="text-2xl font-display font-bold gradient-text">BayanLink</span>
                </div>

                <div class="hidden md:flex items-center space-x-8">
                    <a href="#feed" class="text-sm font-medium hover:text-primary-600 dark:hover:text-primary-400 nav-link">Community</a>
                    <a href="#businesses" class="text-sm font-medium hover:text-primary-600 dark:hover:text-primary-400 nav-link">Businesses</a>
                    <a href="#services" class="text-sm font-medium hover:text-primary-600 dark:hover:text-primary-400 nav-link">Services</a>
                    <a href="#features" class="text-sm font-medium hover:text-primary-600 dark:hover:text-primary-400 nav-link">Features</a>
                </div>

                <div class="flex items-center space-x-4">
                    <?php if (isGuest()): ?>
                        <button onclick="openModal('loginModal')" class="px-4 py-2 rounded-full glass-card text-sm font-medium hover:bg-primary-50 dark:hover:bg-gray-800 transition-colors">Login</button>
                        <button onclick="openModal('registerModal')" class="px-4 py-2 rounded-full bg-gradient-to-r from-primary-600 to-accent-600 text-white text-sm font-medium shadow-lg hover:shadow-xl transition-all">Register</button>
                    <?php else: ?>
                        <div class="hidden md:flex items-center space-x-2 glass-card px-4 py-2 rounded-full">
                            <i class="fas fa-user-circle text-primary-600"></i>
                            <span class="text-sm font-medium"><?= htmlspecialchars($user_name) ?></span>
                            <span class="text-xs px-2 py-0.5 rounded-full <?= $user_role === 'admin' ? 'bg-red-100 text-red-700' : ($user_role === 'business_owner' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700') ?>">
                                <?= ucfirst(str_replace('_', ' ', $user_role)) ?>
                            </span>
                        </div>

                        <div class="relative">
                            <button id="notificationBtn" class="w-10 h-10 rounded-full glass-card flex items-center justify-center hover:bg-primary-50 dark:hover:bg-gray-800 transition-colors relative">
                                <i class="fas fa-bell text-gray-600 dark:text-gray-300"></i>
                                <?php if ($unread_count > 0): ?>
                                    <span class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full animate-pulse"></span>
                                <?php endif; ?>
                            </button>
                            <div id="notificationDropdown" class="notification-dropdown absolute right-0 mt-3 w-80 glass-card rounded-2xl p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="font-semibold text-sm">Notifications</h3>
                                    <a href="mark_all_read.php" class="text-xs text-primary-600">Mark all read</a>
                                </div>
                                <div class="space-y-3 max-h-64 overflow-y-auto">
                                    <?php if (empty($notifications)): ?>
                                        <p class="text-xs text-gray-500 text-center py-4">No notifications</p>
                                    <?php else: ?>
                                        <?php foreach ($notifications as $n): ?>
                                            <div class="flex items-start space-x-3 p-2 rounded-lg <?= $n['is_read'] ? '' : 'bg-blue-50 dark:bg-blue-900/20' ?>">
                                                <div class="w-8 h-8 rounded-full bg-<?= $n['type'] === 'event' ? 'purple' : 'blue' ?>-100 flex items-center justify-center">
                                                    <i class="fas fa-<?= $n['type'] === 'event' ? 'calendar' : 'bullhorn' ?> text-xs"></i>
                                                </div>
                                                <div class="flex-1">
                                                    <p class="text-xs"><?= htmlspecialchars($n['message']) ?></p>
                                                    <p class="text-xs text-gray-500 mt-1"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="relative">
                            <button id="settingsBtn" class="w-10 h-10 rounded-full glass-card flex items-center justify-center hover:bg-primary-50 dark:hover:bg-gray-800 transition-colors">
                                <i class="fas fa-cog text-gray-600 dark:text-gray-300"></i>
                            </button>
                            <div id="settingsDropdown" class="settings-dropdown absolute right-0 mt-3 w-48 glass-card rounded-2xl p-2">
                                <button id="themeToggleDropdown" class="w-full text-left px-4 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-sm">
                                    <i class="fas fa-moon mr-2"></i><span id="themeText">Dark Mode</span>
                                </button>
                                <a href="profile.php" class="block px-4 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-sm">
                                    <i class="fas fa-user mr-2"></i>Profile Settings
                                </a>
                                <hr class="my-1 border-gray-200 dark:border-gray-700">
                                <a href="index.php?action=logout" class="block px-4 py-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-sm text-red-600">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <button id="mobileMenuBtn" class="md:hidden w-10 h-10 rounded-full glass-card flex items-center justify-center">
                        <i class="fas fa-bars text-gray-600 dark:text-gray-300"></i>
                    </button>
                </div>
            </div>
        </div>
        <div id="mobileMenu" class="hidden md:hidden mx-4 mt-2 glass-card rounded-2xl p-4">
            <div class="flex flex-col space-y-3">
                <a href="#feed" class="px-4 py-2 rounded-lg hover:bg-white/10">Community</a>
                <a href="#businesses" class="px-4 py-2 rounded-lg hover:bg-white/10">Businesses</a>
                <a href="#services" class="px-4 py-2 rounded-lg hover:bg-white/10">Services</a>
                <a href="#features" class="px-4 py-2 rounded-lg hover:bg-white/10">Features</a>
            </div>
        </div>
    </nav>

    <?php if (isGuest()): ?>

        <!-- Hero Section -->
        <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-24 pb-12 px-4">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="floating-shape w-96 h-96 bg-primary-400 top-20 -left-20"></div>
                <div class="floating-shape w-72 h-72 bg-accent-400 top-40 right-10" style="animation-delay: 2s;"></div>
                <div class="floating-shape w-64 h-64 bg-purple-400 bottom-20 left-1/3" style="animation-delay: 4s;"></div>
                <div class="absolute inset-0 bg-gradient-to-b from-transparent via-white/50 to-white dark:via-gray-900/50 dark:to-gray-900"></div>
            </div>
            <div class="relative z-10 max-w-7xl mx-auto grid lg:grid-cols-2 gap-12 items-center">
                <div class="text-center lg:text-left">
                    <div class="inline-flex items-center px-4 py-2 rounded-full glass-card mb-6 text-sm font-medium text-primary-700 dark:text-primary-300">
                        <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                        Now Serving 50+ Barangays
                    </div>
                    <h1 class="text-5xl md:text-6xl lg:text-7xl font-display font-bold leading-tight mb-6">
                        <span class="block text-gray-900 dark:text-white">Connect Your</span>
                        <span class="gradient-text typing-cursor" id="typingText">Community</span>
                    </h1>
                    <p class="text-lg md:text-xl text-gray-600 dark:text-gray-300 mb-8 max-w-2xl mx-auto lg:mx-0 leading-relaxed">
                        BayanLink is the next-generation platform that bridges residents, local businesses, and public services in one unified digital ecosystem.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                        <button onclick="openModal('registerModal')" class="px-8 py-4 rounded-2xl bg-gradient-to-r from-primary-600 to-accent-600 text-white font-semibold text-lg shadow-lg shadow-primary-500/30 hover:shadow-xl hover:shadow-primary-500/40 transform hover:-translate-y-1 transition-all duration-300 flex items-center justify-center space-x-2">
                            <span>Get Started</span>
                            <i class="fas fa-arrow-right"></i>
                        </button>
                        <button class="px-8 py-4 rounded-2xl glass-card font-semibold text-lg hover:bg-white/20 transition-all duration-300 flex items-center justify-center space-x-2">
                            <i class="fas fa-play-circle text-primary-600 dark:text-primary-400"></i>
                            <span>View Demo</span>
                        </button>
                    </div>
                    <div class="mt-12 flex flex-wrap items-center justify-center lg:justify-start gap-8">
                        <div class="text-center"><p class="text-3xl font-bold gradient-text counter" data-target="15000">0</p><p class="text-sm text-gray-500">Active Residents</p></div>
                        <div class="w-px h-12 bg-gray-300 dark:bg-gray-700 hidden sm:block"></div>
                        <div class="text-center"><p class="text-3xl font-bold gradient-text counter" data-target="500">0</p><p class="text-sm text-gray-500">Local Businesses</p></div>
                        <div class="w-px h-12 bg-gray-300 dark:bg-gray-700 hidden sm:block"></div>
                        <div class="text-center"><p class="text-3xl font-bold gradient-text counter" data-target="98">0</p><p class="text-sm text-gray-500">% Satisfaction</p></div>
                    </div>
                </div>
                <div class="relative">
                    <div class="device-mockup bg-gray-900 aspect-[4/3] relative overflow-hidden">
                        <div class="bg-gray-800 px-4 py-3 flex items-center justify-between border-b border-gray-700">
                            <div class="flex items-center space-x-2"><div class="w-3 h-3 rounded-full bg-red-500"></div><div class="w-3 h-3 rounded-full bg-yellow-500"></div><div class="w-3 h-3 rounded-full bg-green-500"></div></div>
                            <div class="text-xs text-gray-400">bayanlink.gov.ph</div>
                            <div class="w-16"></div>
                        </div>
                        <div class="p-6 grid grid-cols-3 gap-4">
                            <div class="col-span-1 space-y-3"><div class="h-8 bg-gray-700 rounded-lg w-full"></div><div class="h-4 bg-gray-700 rounded w-3/4"></div><div class="h-4 bg-gray-700 rounded w-1/2"></div></div>
                            <div class="col-span-2 space-y-4"><div class="grid grid-cols-2 gap-3"><div class="h-24 bg-gradient-to-br from-primary-600/20 to-accent-600/20 rounded-xl"></div><div class="h-24 bg-gradient-to-br from-green-600/20 to-blue-600/20 rounded-xl"></div></div><div class="h-32 bg-gray-800 rounded-xl"></div><div class="h-20 bg-gray-800 rounded-xl"></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce"><i class="fas fa-chevron-down text-gray-400 text-xl"></i></div>
        </section>

        <!-- Local Business Showcase (original static content preserved) -->
        <section id="businesses" class="py-24 px-4 relative bg-gradient-to-b from-transparent to-gray-100 dark:to-gray-800/50">
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-12 reveal">
                    <span class="inline-block px-4 py-1 rounded-full bg-accent-100 dark:bg-accent-900/30 text-accent-700 text-sm font-medium mb-4">Local Economy</span>
                    <h2 class="text-4xl md:text-5xl font-display font-bold mb-4">Discover Local <span class="gradient-text">Businesses</span></h2>
                    <p class="text-gray-600 dark:text-gray-400 max-w-2xl mx-auto">Support your community by exploring and patronizing local enterprises.</p>
                </div>
                <div class="flex flex-wrap justify-center gap-3 mb-12 reveal">
                    <button class="filter-btn active px-6 py-2 rounded-full glass-card text-sm font-medium" data-filter="all">All</button>
                    <button class="filter-btn px-6 py-2 rounded-full glass-card text-sm font-medium" data-filter="food">Food & Dining</button>
                    <button class="filter-btn px-6 py-2 rounded-full glass-card text-sm font-medium" data-filter="services">Services</button>
                    <button class="filter-btn px-6 py-2 rounded-full glass-card text-sm font-medium" data-filter="retail">Retail</button>
                    <button class="filter-btn px-6 py-2 rounded-full glass-card text-sm font-medium" data-filter="health">Health</button>
                </div>
                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6" id="businessGrid">
                    <!-- Business Cards (original) -->
                    <!-- Business Card 1 -->
                <div class="business-card glass-card rounded-2xl overflow-hidden reveal" data-category="food">
                    <div class="h-48 bg-gradient-to-br from-orange-400 to-red-500 relative overflow-hidden">
                        <div class="absolute inset-0 flex items-center justify-center">
                            <i class="fas fa-utensils text-white text-5xl opacity-80"></i>
                        </div>
                        <div class="absolute top-3 right-3">
                            <span class="px-3 py-1 rounded-full bg-white/90 dark:bg-gray-900/90 text-xs font-semibold text-orange-600">Open</span>
                        </div>
                    </div>
                    <div class="p-5">
                        <h3 class="font-display font-bold text-lg mb-1">Mang Inasal Express</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Filipino BBQ & Grill</p>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-1">
                                <i class="fas fa-star text-yellow-400 text-xs"></i>
                                <span class="text-sm font-medium">4.8</span>
                                <span class="text-xs text-gray-400">(124)</span>
                            </div>
                            <span class="text-sm font-semibold text-primary-600 dark:text-primary-400">₱150-300</span>
                        </div>
                    </div>
                </div>
                
                <!-- Business Card 2 -->
                <div class="business-card glass-card rounded-2xl overflow-hidden reveal" data-category="services" style="transition-delay: 0.1s;">
                    <div class="h-48 bg-gradient-to-br from-blue-400 to-indigo-500 relative overflow-hidden">
                        <div class="absolute inset-0 flex items-center justify-center">
                            <i class="fas fa-cut text-white text-5xl opacity-80"></i>
                        </div>
                        <div class="absolute top-3 right-3">
                            <span class="px-3 py-1 rounded-full bg-white/90 dark:bg-gray-900/90 text-xs font-semibold text-green-600">Open</span>
                        </div>
                    </div>
                    <div class="p-5">
                        <h3 class="font-display font-bold text-lg mb-1">Glamour Salon</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Hair & Beauty Services</p>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-1">
                                <i class="fas fa-star text-yellow-400 text-xs"></i>
                                <span class="text-sm font-medium">4.9</span>
                                <span class="text-xs text-gray-400">(89)</span>
                            </div>
                            <span class="text-sm font-semibold text-primary-600 dark:text-primary-400">₱200+</span>
                        </div>
                    </div>
                </div>
                
                <!-- Business Card 3 -->
                <div class="business-card glass-card rounded-2xl overflow-hidden reveal" data-category="retail" style="transition-delay: 0.2s;">
                    <div class="h-48 bg-gradient-to-br from-green-400 to-teal-500 relative overflow-hidden">
                        <div class="absolute inset-0 flex items-center justify-center">
                            <i class="fas fa-shopping-basket text-white text-5xl opacity-80"></i>
                        </div>
                        <div class="absolute top-3 right-3">
                            <span class="px-3 py-1 rounded-full bg-white/90 dark:bg-gray-900/90 text-xs font-semibold text-orange-600">Open</span>
                        </div>
                    </div>
                    <div class="p-5">
                        <h3 class="font-display font-bold text-lg mb-1">Tindahan ni Aling Nena</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Convenience Store</p>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-1">
                                <i class="fas fa-star text-yellow-400 text-xs"></i>
                                <span class="text-sm font-medium">4.7</span>
                                <span class="text-xs text-gray-400">(256)</span>
                            </div>
                            <span class="text-sm font-semibold text-primary-600 dark:text-primary-400">₱10-500</span>
                        </div>
                    </div>
                </div>
                
                <!-- Business Card 4 -->
                <div class="business-card glass-card rounded-2xl overflow-hidden reveal" data-category="health" style="transition-delay: 0.3s;">
                    <div class="h-48 bg-gradient-to-br from-pink-400 to-rose-500 relative overflow-hidden">
                        <div class="absolute inset-0 flex items-center justify-center">
                            <i class="fas fa-heartbeat text-white text-5xl opacity-80"></i>
                        </div>
                        <div class="absolute top-3 right-3">
                            <span class="px-3 py-1 rounded-full bg-white/90 dark:bg-gray-900/90 text-xs font-semibold text-green-600">24/7</span>
                        </div>
                    </div>
                    <div class="p-5">
                        <h3 class="font-display font-bold text-lg mb-1">Barangay Health Center</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Medical Services</p>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-1">
                                <i class="fas fa-star text-yellow-400 text-xs"></i>
                                <span class="text-sm font-medium">4.9</span>
                                <span class="text-xs text-gray-400">(312)</span>
                            </div>
                            <span class="text-sm font-semibold text-green-600">Free</span>
                        </div>
                    </div>
                </div>
            </div>
                </div>
            </div>
        </section>

        <!-- Service Request Preview (original static) -->
        <!-- Service Request Preview -->
<section id="services" class="py-24 px-4 relative">
    <div class="max-w-7xl mx-auto">
        <div class="grid lg:grid-cols-2 gap-16 items-center">
            <!-- Left Column -->
            <div class="reveal">
                <span class="inline-block px-4 py-1 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 text-sm font-medium mb-4">Public Services</span>
                <h2 class="text-4xl md:text-5xl font-display font-bold mb-6">Request Documents <span class="gradient-text">Online</span></h2>
                <p class="text-gray-600 dark:text-gray-400 mb-8 text-lg leading-relaxed">
                    Skip the long lines. Request barangay documents, report issues, and schedule appointments from the comfort of your home.
                </p>
                
                <div class="space-y-4">
                    <div class="flex items-start space-x-4 p-4 rounded-xl glass-card">
                        <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-file-alt text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <div>
                            <h4 class="font-semibold mb-1">Barangay Clearance</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Get your clearance in 24 hours</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-4 p-4 rounded-xl glass-card">
                        <div class="w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-home text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                        <div>
                            <h4 class="font-semibold mb-1">Certificate of Residency</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Proof of residence for official use</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-4 p-4 rounded-xl glass-card">
                        <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-xl"></i>
                        </div>
                        <div>
                            <h4 class="font-semibold mb-1">Report an Issue</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Report barangay concerns and emergencies</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Status Cards -->
            <div class="space-y-4 reveal">
                <div class="glass-card rounded-2xl p-6 border-l-4 border-green-500">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                <i class="fas fa-user text-gray-600 dark:text-gray-400"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-sm">Juan Dela Cruz</h4>
                                <p class="text-xs text-gray-500">Req. #2024-00123</p>
                            </div>
                        </div>
                        <span class="status-approved px-3 py-1 rounded-full text-xs font-semibold text-white">Approved</span>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">Barangay Clearance for employment purposes</p>
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span>Submitted: Jan 15, 2024</span>
                        <span>Processed: Jan 16, 2024</span>
                    </div>
                </div>
                
                <div class="glass-card rounded-2xl p-6 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                <i class="fas fa-user text-gray-600 dark:text-gray-400"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-sm">Ana Reyes</h4>
                                <p class="text-xs text-gray-500">Req. #2024-00124</p>
                            </div>
                        </div>
                        <span class="status-processing px-3 py-1 rounded-full text-xs font-semibold text-white">Processing</span>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">Business Permit renewal for Sari-Sari Store</p>
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span>Submitted: Jan 16, 2024</span>
                        <span>Est. completion: 2 days</span>
                    </div>
                </div>
                
                <div class="glass-card rounded-2xl p-6 border-l-4 border-yellow-500">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                <i class="fas fa-user text-gray-600 dark:text-gray-400"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-sm">Pedro Santos</h4>
                                <p class="text-xs text-gray-500">Req. #2024-00125</p>
                            </div>
                        </div>
                        <span class="status-pending px-3 py-1 rounded-full text-xs font-semibold text-white">Pending</span>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">Road repair request - Main Street potholes</p>
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span>Submitted: Jan 16, 2024</span>
                        <span>Awaiting review</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

        <!-- Features Section (original) -->
        <section id="features" class="py-24 px-4 relative bg-gradient-to-b from-gray-100 dark:from-gray-800/50 to-transparent">
            <div class="max-w-7xl mx-auto">
            <div class="text-center mb-16 reveal">
                <span class="inline-block px-4 py-1 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 text-sm font-medium mb-4">Platform Features</span>
                <h2 class="text-4xl md:text-5xl font-display font-bold mb-4">Why Choose <span class="gradient-text">BayanLink</span>?</h2>
                <p class="text-gray-600 dark:text-gray-400 max-w-2xl mx-auto">Experience the future of community management with our comprehensive suite of digital tools.</p>
            </div>
            
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="glass-card rounded-2xl p-8 text-center reveal group hover:scale-105 transition-transform duration-300">
                    <div class="w-16 h-16 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/30 group-hover:shadow-xl group-hover:shadow-blue-500/40 transition-shadow">
                        <i class="fas fa-users text-white text-2xl"></i>
                    </div>
                    <h3 class="font-display font-bold text-lg mb-3">Community Connection</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Connect with neighbors, join discussions, and stay updated with local events.</p>
                </div>
                
                <div class="glass-card rounded-2xl p-8 text-center reveal group hover:scale-105 transition-transform duration-300" style="transition-delay: 0.1s;">
                    <div class="w-16 h-16 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center shadow-lg shadow-green-500/30 group-hover:shadow-xl group-hover:shadow-green-500/40 transition-shadow">
                        <i class="fas fa-robot text-white text-2xl"></i>
                    </div>
                    <h3 class="font-display font-bold text-lg mb-3">Smart Services</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">AI-powered document processing and automated service request tracking.</p>
                </div>
                
                <div class="glass-card rounded-2xl p-8 text-center reveal group hover:scale-105 transition-transform duration-300" style="transition-delay: 0.2s;">
                    <div class="w-16 h-16 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center shadow-lg shadow-orange-500/30 group-hover:shadow-xl group-hover:shadow-orange-500/40 transition-shadow">
                        <i class="fas fa-store text-white text-2xl"></i>
                    </div>
                    <h3 class="font-display font-bold text-lg mb-3">Local Economy</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Support and discover local businesses. Boost your barangay's economy.</p>
                </div>
                
                <div class="glass-card rounded-2xl p-8 text-center reveal group hover:scale-105 transition-transform duration-300" style="transition-delay: 0.3s;">
                    <div class="w-16 h-16 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center shadow-lg shadow-purple-500/30 group-hover:shadow-xl group-hover:shadow-purple-500/40 transition-shadow">
                        <i class="fas fa-bolt text-white text-2xl"></i>
                    </div>
                    <h3 class="font-display font-bold text-lg mb-3">Real-Time Updates</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Instant notifications for announcements, emergencies, and service updates.</p>
                </div>
            </div>
        </div>
        </section>

        <!-- Dashboard Preview Section (original) -->
        <section class="py-24 px-4 relative overflow-hidden">
            <div class="max-w-7xl mx-auto">
            <div class="text-center mb-16 reveal">
                <h2 class="text-4xl md:text-5xl font-display font-bold mb-4">Powerful <span class="gradient-text">Dashboard</span></h2>
                <p class="text-gray-600 dark:text-gray-400 max-w-2xl mx-auto">Get insights into your barangay's activity with our comprehensive analytics dashboard.</p>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8 mb-12">
                <div class="glass-card rounded-2xl p-8 text-center reveal">
                    <div class="text-5xl font-bold gradient-text mb-2 counter" data-target="15000">0</div>
                    <p class="text-gray-600 dark:text-gray-400 font-medium">Total Residents</p>
                    <div class="mt-4 flex items-center justify-center text-green-500 text-sm">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>+12% this month</span>
                    </div>
                </div>
                
                <div class="glass-card rounded-2xl p-8 text-center reveal" style="transition-delay: 0.1s;">
                    <div class="text-5xl font-bold gradient-text mb-2 counter" data-target="500">0</div>
                    <p class="text-gray-600 dark:text-gray-400 font-medium">Active Businesses</p>
                    <div class="mt-4 flex items-center justify-center text-green-500 text-sm">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>+8% this month</span>
                    </div>
                </div>
                
                <div class="glass-card rounded-2xl p-8 text-center reveal" style="transition-delay: 0.2s;">
                    <div class="text-5xl font-bold gradient-text mb-2 counter" data-target="3250">0</div>
                    <p class="text-gray-600 dark:text-gray-400 font-medium">Requests Processed</p>
                    <div class="mt-4 flex items-center justify-center text-green-500 text-sm">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>+23% this month</span>
                    </div>
                </div>
            </div>
            
            <div class="relative reveal">
                <div class="glass-card rounded-3xl p-2 shadow-2xl">
                    <div class="bg-gray-900 rounded-2xl overflow-hidden">
                        <div class="flex">
                            <div class="w-64 bg-gray-800 p-6 hidden lg:block">
                                <div class="flex items-center space-x-3 mb-8">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-accent-500"></div>
                                    <span class="font-display font-bold text-white">Dashboard</span>
                                </div>
                                <nav class="space-y-2">
                                    <div class="h-10 bg-primary-600/30 rounded-lg"></div>
                                    <div class="h-10 bg-gray-700 rounded-lg"></div>
                                    <div class="h-10 bg-gray-700 rounded-lg"></div>
                                    <div class="h-10 bg-gray-700 rounded-lg"></div>
                                </nav>
                            </div>
                            <div class="flex-1 p-6">
                                <div class="grid grid-cols-3 gap-4 mb-6">
                                    <div class="h-24 bg-gradient-to-br from-blue-600/20 to-blue-800/20 rounded-xl border border-blue-500/20"></div>
                                    <div class="h-24 bg-gradient-to-br from-green-600/20 to-green-800/20 rounded-xl border border-green-500/20"></div>
                                    <div class="h-24 bg-gradient-to-br from-purple-600/20 to-purple-800/20 rounded-xl border border-purple-500/20"></div>
                                </div>
                                <div class="h-64 bg-gray-800 rounded-xl border border-gray-700 mb-6"></div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="h-40 bg-gray-800 rounded-xl border border-gray-700"></div>
                                    <div class="h-40 bg-gray-800 rounded-xl border border-gray-700"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </section>

        <!-- CTA Section (original) -->
        <section class="py-24 px-4 relative">
            <div class="max-w-5xl mx-auto glass-card rounded-3xl p-12 text-center relative overflow-hidden reveal">
                <div class="max-w-5xl mx-auto">
            <div class="glass-card rounded-3xl p-12 text-center relative overflow-hidden reveal">
                <div class="absolute inset-0 opacity-30">
                    <div class="absolute top-0 left-0 w-64 h-64 bg-primary-500 rounded-full filter blur-3xl transform -translate-x-1/2 -translate-y-1/2"></div>
                    <div class="absolute bottom-0 right-0 w-64 h-64 bg-accent-500 rounded-full filter blur-3xl transform translate-x-1/2 translate-y-1/2"></div>
                </div>
                
                <div class="relative z-10">
                    <h2 class="text-4xl md:text-5xl font-display font-bold mb-6">Ready to Transform Your <span class="gradient-text">Community</span>?</h2>
                    <p class="text-lg text-gray-600 dark:text-gray-400 mb-8 max-w-2xl mx-auto">
                        Join thousands of barangays already using BayanLink to connect, serve, and grow their communities.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                        <button class="px-8 py-4 rounded-2xl bg-gradient-to-r from-primary-600 to-accent-600 text-white font-semibold text-lg shadow-lg shadow-primary-500/30 hover:shadow-xl hover:shadow-primary-500/40 transform hover:-translate-y-1 transition-all duration-300">
                            Get Started Free
                        </button>
                        <button class="px-8 py-4 rounded-2xl glass-card font-semibold text-lg hover:bg-white/20 transition-all duration-300">
                            Schedule Demo
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Full Footer (guests only) -->
        <footer class="py-12 px-4 border-t border-gray-200 dark:border-gray-800">
            <div class="max-w-7xl mx-auto">
                <div class="grid md:grid-cols-4 gap-8 mb-8">
                    <div>
                        <div class="flex items-center space-x-3 mb-4"><div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-accent-500 flex items-center justify-center"><i class="fas fa-network-wired text-white"></i></div><span class="text-2xl font-display font-bold gradient-text">BayanLink</span></div>
                        <p class="text-sm text-gray-500 mb-4">Empowering communities through digital innovation.</p>
                        <div class="flex space-x-4"><a href="#" class="w-10 h-10 rounded-full glass-card flex items-center justify-center"><i class="fab fa-facebook-f"></i></a><a href="#" class="w-10 h-10 rounded-full glass-card flex items-center justify-center"><i class="fab fa-twitter"></i></a><a href="#" class="w-10 h-10 rounded-full glass-card flex items-center justify-center"><i class="fab fa-instagram"></i></a></div>
                    </div>
                    <div><h4 class="font-bold mb-4">Platform</h4><ul class="space-y-2 text-sm text-gray-500"><li><a href="#">Features</a></li><li><a href="#">Pricing</a></li><li><a href="#">Security</a></li></ul></div>
                    <div><h4 class="font-bold mb-4">Resources</h4><ul class="space-y-2 text-sm text-gray-500"><li><a href="#">Documentation</a></li><li><a href="#">API</a></li><li><a href="#">Help Center</a></li></ul></div>
                    <div><h4 class="font-bold mb-4">Contact</h4><ul class="space-y-2 text-sm text-gray-500"><li><i class="fas fa-envelope text-primary-600"></i> hello@bayanlink.ph</li><li><i class="fas fa-phone text-primary-600"></i> +63 (2) 8123 4567</li></ul></div>
                </div>
                <div class="pt-8 border-t border-gray-200 dark:border-gray-800 flex justify-between text-sm text-gray-500"><p>© 2024 BayanLink.</p><div class="flex space-x-6"><a href="#">Privacy</a><a href="#">Terms</a><a href="#">Cookies</a></div></div>
            </div>
        </footer>

    <?php else: ?>
        <!-- ========== LOGGED‑IN DASHBOARD ========== -->
        <?php if ($user_role === 'admin'): ?>
        <section class="pt-28 pb-4 px-4">
            <div class="max-w-7xl mx-auto">
                <div class="glass-card rounded-2xl p-4 flex flex-wrap gap-4">
                    <a href="#post-event" class="flex-1 min-w-[120px] text-center p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800"><i class="fas fa-calendar-plus text-blue-600"></i><br><span class="text-sm">New Event</span></a>
                    <a href="#post-announcement" class="flex-1 min-w-[120px] text-center p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800"><i class="fas fa-bullhorn text-purple-600"></i><br><span class="text-sm">Announcement</span></a>
                    <a href="#feed" class="flex-1 min-w-[120px] text-center p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800"><i class="fas fa-edit text-green-600"></i><br><span class="text-sm">Manage</span></a>
                    <a href="manage_users.php" class="flex-1 min-w-[120px] text-center p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800"><i class="fas fa-users-cog text-orange-600"></i><br><span class="text-sm">Users</span></a>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Post Creation Section (Role‑based forms) -->
        <section class="pt-28 pb-8 px-4">
            <div class="max-w-5xl mx-auto">
                <div class="glass-card rounded-2xl p-6 md:p-8">
                    <h2 class="text-2xl font-display font-bold mb-6"><i class="fas fa-pen-fancy mr-3 text-primary-600"></i>Share Something</h2>

                    <?php if ($user_role === 'user'): ?>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="post_type" value="looking_for">
                            <div><label class="block text-sm font-medium mb-2">What do you need? <span class="text-red-500">*</span></label><input type="text" name="title" required placeholder="e.g., Looking for a carpenter" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                            <div class="grid md:grid-cols-2 gap-4">
                                <div><label class="block text-sm font-medium mb-2">Category</label><select name="category" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"><option value="">Select</option><option>Carpentry</option><option>Electrical</option><option>Plumbing</option><option>Cleaning</option><option>Other</option></select></div>
                                <div><label class="block text-sm font-medium mb-2">Location</label><input type="text" name="location" placeholder="Where?" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                            </div>
                            <div class="grid md:grid-cols-2 gap-4">
                                <div><label class="block text-sm font-medium mb-2">Date Needed</label><input type="date" name="needed_date" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                <div><label class="block text-sm font-medium mb-2">Time</label><input type="time" name="needed_time" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                            </div>
                            <div><label class="block text-sm font-medium mb-2">Description <span class="text-red-500">*</span></label><textarea name="description" rows="3" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></textarea></div>
                            <button type="submit" class="px-6 py-3 bg-gradient-to-r from-primary-600 to-accent-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transition"><i class="fas fa-paper-plane mr-2"></i>Post Request</button>
                        </form>
                    <?php elseif ($user_role === 'business_owner'): ?>
                        <div class="grid md:grid-cols-2 gap-8">
                            <div class="border-r border-gray-200 dark:border-gray-700 pr-0 md:pr-6">
                                <h3 class="font-semibold text-lg mb-4"><i class="fas fa-store mr-2 text-green-600"></i>Promote Your Business</h3>
                                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                                    <input type="hidden" name="post_type" value="service">
                                    <div><label>Business Name <span class="text-red-500">*</span></label><input type="text" name="business_name" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <div><label>Service Title <span class="text-red-500">*</span></label><input type="text" name="title" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <div><label>Price Range</label><input type="text" name="price_range" placeholder="e.g., ₱200-500" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <div>
                                    <label class="block text-sm font-medium mb-2">Business Image (optional)</label>
                                    <input type="file" name="service_image" accept="image/*" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50">
                                    </div>
                                    <div><label>Description <span class="text-red-500">*</span></label><textarea name="description" rows="3" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></textarea></div>
                                    <button type="submit" class="w-full py-3 bg-gradient-to-r from-green-600 to-teal-600 text-white rounded-xl"><i class="fas fa-store mr-2"></i>Post Service</button>
                                </form>
                            </div>
                            <div>
                                <h3 class="font-semibold text-lg mb-4"><i class="fas fa-search mr-2 text-blue-600"></i>Looking For Help</h3>
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="post_type" value="looking_for">
                                    <div><label>What do you need? <span class="text-red-500">*</span></label><input type="text" name="title" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <div><label>Description <span class="text-red-500">*</span></label><textarea name="description" rows="3" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></textarea></div>
                                    <button type="submit" class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl"><i class="fas fa-hands-helping mr-2"></i>Post Request</button>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($user_role === 'admin'): ?>
                        <div class="grid md:grid-cols-2 gap-8">
                            <div>
                                <h3 class="font-semibold text-lg mb-4"><i class="fas fa-calendar-alt mr-2 text-blue-600"></i>Create Event</h3>
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="post_type" value="event">
                                    <div><label>Event Title <span class="text-red-500">*</span></label><input type="text" name="title" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <div><label>Description <span class="text-red-500">*</span></label><textarea name="description" rows="3" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></textarea></div>
                                    <div><label>Date & Time <span class="text-red-500">*</span></label><input type="datetime-local" name="event_date" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <div><label>Location</label><input type="text" name="location" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold">Publish Event</button>
                                </form>
                            </div>
                            <div>
                                <h3 class="font-semibold text-lg mb-4"><i class="fas fa-bullhorn mr-2 text-purple-600"></i>Post Announcement</h3>
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="post_type" value="announcement">
                                    <div><label>Title <span class="text-red-500">*</span></label><input type="text" name="title" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></div>
                                    <div><label>Content <span class="text-red-500">*</span></label><textarea name="content" rows="3" required class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"></textarea></div>
                                    <div><label>Status</label><select name="status" class="w-full px-4 py-3 rounded-xl border dark:bg-gray-800/50"><option>Processing</option><option>Approved</option><option>Ready for Claim</option><option>Completed</option></select></div>
                                    <button type="submit" class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-xl font-semibold">Publish</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Community Feed -->
        <section id="feed" class="py-12 px-4 relative">
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-10"><h2 class="text-3xl font-display font-bold">Community <span class="gradient-text">Feed</span></h2></div>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if (empty($feed_items)): ?>
                        <div class="col-span-full text-center py-12 text-gray-500"><i class="fas fa-newspaper text-4xl mb-3 opacity-50"></i><p>No posts yet. Be the first to share!</p></div>
                    <?php else: ?>
                        <?php foreach ($feed_items as $item): ?>
                            <div class="glass-card rounded-2xl p-6 hover:scale-[1.02] transition-transform duration-300">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php
                                        switch($item['type']) {
                                            case 'looking_for': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300'; break;
                                            case 'service': echo 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300'; break;
                                            case 'event': echo 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300'; break;
                                            case 'announcement': echo 'bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-300'; break;
                                        }
                                    ?>"><i class="fas fa-<?= $item['type'] === 'looking_for' ? 'search' : ($item['type'] === 'service' ? 'store' : ($item['type'] === 'event' ? 'calendar-check' : 'bullhorn')) ?> mr-1"></i><?= $item['type'] === 'looking_for' ? 'Looking For' : ucfirst($item['type']) ?></span>
                                    <span class="text-xs text-gray-400"><?= date('M j, g:i A', strtotime($item['created_at'])) ?></span>
                                </div>
                                <h4 class="font-bold text-lg mb-2"><?= htmlspecialchars($item['title']) ?></h4>
                                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4"><?= htmlspecialchars($item['description'] ?? $item['content'] ?? '') ?></p>
                                <?php if ($item['type'] === 'service'): ?>
                                    <?php if (!empty($item['image_path'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_path']) ?>" alt="<?= htmlspecialchars($item['business_name']) ?>" class="w-full h-32 object-cover rounded-lg mb-3">
                                <?php endif; ?>
                                    <div class="text-sm space-y-1 mb-3"><div><i class="fas fa-building mr-2 text-gray-400"></i><?= htmlspecialchars($item['business_name']) ?></div><?php if (!empty($item['price_range'])): ?><div><i class="fas fa-tag mr-2 text-gray-400"></i><?= htmlspecialchars($item['price_range']) ?></div><?php endif; ?></div>
                                <?php elseif ($item['type'] === 'event'): ?>
                                    <div class="text-sm space-y-1 mb-3"><div><i class="far fa-calendar mr-2 text-gray-400"></i><?= date('F j, Y - g:i A', strtotime($item['event_date'])) ?></div><div><i class="fas fa-map-marker-alt mr-2 text-gray-400"></i><?= htmlspecialchars($item['location']) ?></div><div><i class="fas fa-users mr-2 text-gray-400"></i><?= $item['participant_count'] ?> joined</div></div>
                                    <?php if ($user_role === 'user'): ?><a href="?join_event=<?= $item['id'] ?>#feed" class="mt-2 inline-block px-4 py-2 bg-primary-600 text-white text-sm rounded-lg transition"><i class="fas fa-check-circle mr-1"></i>Join Event</a><?php endif; ?>
                                <?php elseif ($item['type'] === 'announcement'): ?>
                                    <span class="inline-block px-3 py-1 text-xs rounded-full <?= $item['status'] === 'Ready' ? 'bg-green-100 text-green-800' : ($item['status'] === 'Processing' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800') ?>">Status: <?= $item['status'] ?></span>
                                <?php elseif ($item['type'] === 'looking_for'): ?>
                                    <span class="inline-block px-3 py-1 text-xs rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"><i class="fas fa-user-tag mr-1"></i>Posted by <?= ucfirst(str_replace('_', ' ', $item['author_role'] ?? 'user')) ?></span>
                                <?php endif; ?>
                                <div class="mt-4 pt-3 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 flex items-center"><i class="fas fa-user-circle mr-1"></i><?= htmlspecialchars($item['author']) ?></div>
                                <?php if ($user_role === 'admin'): ?>
                                    <div class="mt-3 flex space-x-2">
                                        <a href="edit_post.php?type=<?= $item['type'] ?>&id=<?= $item['id'] ?>" class="text-xs text-blue-600"><i class="fas fa-edit"></i> Edit</a>
                                        <a href="delete_post.php?type=<?= $item['type'] ?>&id=<?= $item['id'] ?>" class="text-xs text-red-600" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i> Delete</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <footer class="py-6 px-4 border-t border-gray-200 dark:border-gray-800">
            <div class="max-w-7xl mx-auto text-center text-sm text-gray-500">© 2024 BayanLink. All rights reserved.</div>
        </footer>
    <?php endif; ?> 

    <script>
(function(){
    "use strict";

    // ---------- LOADER ----------
    window.addEventListener('load', function() {
        const loader = document.getElementById('loader');
        if (loader) setTimeout(() => loader.classList.add('hidden'), 500);
        initCounters();
        initReveal();
    });

    // After DOM ready
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($showLoginModal): ?>
        openModal('loginModal');
        // Insert error into modal
        const loginErrorDiv = document.getElementById('loginError');
        if (loginErrorDiv) {
            loginErrorDiv.innerHTML = '<?= htmlspecialchars($loginErrorMessage) ?>';
            loginErrorDiv.classList.remove('hidden');
        }
    <?php endif; ?>
    
    <?php if ($registerSuccess): ?>
        alert('✅ Registration successful! Please log in.');
        openModal('loginModal');
    <?php endif; ?>
    
    <?php if (!empty($registerErrorList)): ?>
        openModal('registerModal');
        const regErrorDiv = document.getElementById('registerError');
        if (regErrorDiv) {
            regErrorDiv.innerHTML = '<?= implode('<br>', array_map('htmlspecialchars', $registerErrorList)) ?>';
            regErrorDiv.classList.remove('hidden');
        }
    <?php endif; ?>
});

    // ---------- DARK MODE DEFAULT ----------
    const html = document.documentElement;
    if (localStorage.getItem('theme') === 'dark') {
        html.classList.add('dark');
    } else {
        html.classList.remove('dark');
    }

    // ---------- MOBILE MENU ----------
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    if (mobileBtn) {
        mobileBtn.addEventListener('click', () => mobileMenu.classList.toggle('hidden'));
        document.querySelectorAll('.mobile-nav-link').forEach(link => {
            link.addEventListener('click', () => mobileMenu.classList.add('hidden'));
        });
    }

    // ---------- NOTIFICATION DROPDOWN ----------
    const notifBtn = document.getElementById('notificationBtn');
    const notifDropdown = document.getElementById('notificationDropdown');
    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('active');
        });
        document.addEventListener('click', (e) => {
            if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                notifDropdown.classList.remove('active');
            }
        });
    }

    // ---------- CHAT WIDGET ----------
    const chatToggle = document.getElementById('chatToggle');
    const chatWindow = document.getElementById('chatWindow');
    if (chatToggle) {
        const closeChat = document.getElementById('closeChat');
        chatToggle.addEventListener('click', () => {
            chatWindow.classList.toggle('scale-0');
            chatWindow.classList.toggle('opacity-0');
            chatWindow.classList.toggle('scale-100');
            chatWindow.classList.toggle('opacity-100');
        });
        if (closeChat) {
            closeChat.addEventListener('click', () => {
                chatWindow.classList.add('scale-0', 'opacity-0');
                chatWindow.classList.remove('scale-100', 'opacity-100');
            });
        }
    }

    // ---------- SMOOTH SCROLL ----------
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href === "#" || href === "") return;
            const target = document.querySelector(href);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // ---------- NAVBAR SCROLL EFFECT ----------
    const navContainer = document.getElementById('navContainer');
    if (navContainer) {
        window.addEventListener('scroll', () => {
            navContainer.classList.toggle('navbar-scrolled', window.scrollY > 20);
        });
    }

    // ---------- TYPING EFFECT ----------
    const typingElement = document.getElementById('typingText');
    if (typingElement) {
        const words = ['Community', 'Business', 'Services', 'Barangay'];
        let wordIndex = 0, charIndex = 0, isDeleting = false;
        function typeEffect() {
            const currentWord = words[wordIndex];
            if (isDeleting) {
                typingElement.textContent = currentWord.substring(0, charIndex - 1);
                charIndex--;
            } else {
                typingElement.textContent = currentWord.substring(0, charIndex + 1);
                charIndex++;
            }
            if (!isDeleting && charIndex === currentWord.length) {
                isDeleting = true;
                setTimeout(typeEffect, 1500);
            } else if (isDeleting && charIndex === 0) {
                isDeleting = false;
                wordIndex = (wordIndex + 1) % words.length;
                setTimeout(typeEffect, 200);
            } else {
                setTimeout(typeEffect, isDeleting ? 80 : 120);
            }
        }
        setTimeout(typeEffect, 1000);
    }

    // ---------- COUNTER ANIMATION ----------
    function animateCounter(el) {
        const target = parseInt(el.getAttribute('data-target'));
        const isPercentage = el.textContent.includes('%');
        let current = 0;
        const increment = target / 60;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                el.textContent = isPercentage ? target + '%' : target.toLocaleString();
                clearInterval(timer);
            } else {
                el.textContent = isPercentage ? Math.floor(current) + '%' : Math.floor(current).toLocaleString();
            }
        }, 16);
    }
    function initCounters() {
        const counters = document.querySelectorAll('.counter');
        if (!counters.length) return;
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    if (!el.classList.contains('counted')) {
                        animateCounter(el);
                        el.classList.add('counted');
                    }
                }
            });
        }, { threshold: 0.5 });
        counters.forEach(c => observer.observe(c));
    }

    // ---------- SCROLL REVEAL ----------
    function initReveal() {
        const reveals = document.querySelectorAll('.reveal');
        if (!reveals.length) return;
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) entry.target.classList.add('active');
            });
        }, { threshold: 0.15 });
        reveals.forEach(el => observer.observe(el));
    }

    // ---------- BUSINESS FILTER ----------
    const filterBtns = document.querySelectorAll('.filter-btn');
    const businessCards = document.querySelectorAll('#businessGrid .business-card');
    if (filterBtns.length && businessCards.length) {
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const filterValue = btn.getAttribute('data-filter');
                businessCards.forEach(card => {
                    card.style.display = (filterValue === 'all' || card.getAttribute('data-category') === filterValue) ? 'block' : 'none';
                });
            });
        });
    }

    // ---------- LIKE BUTTONS ----------
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const icon = this.querySelector('i');
            const countSpan = this.querySelector('.like-count');
            if (!icon || !countSpan) return;
            let count = parseInt(countSpan.textContent);
            if (icon.classList.contains('far')) {
                icon.classList.remove('far');
                icon.classList.add('fas');
                count++;
                this.classList.add('text-red-500');
            } else {
                icon.classList.remove('fas');
                icon.classList.add('far');
                count--;
                this.classList.remove('text-red-500');
            }
            countSpan.textContent = count;
        });
    });

    // ---------- GSAP & SCROLLTRIGGER ----------
    if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
        gsap.registerPlugin(ScrollTrigger);
        gsap.to('.floating-shape', {
            y: -30,
            scrollTrigger: {
                trigger: 'body',
                start: 'top top',
                end: 'bottom top',
                scrub: 1
            }
        });
        gsap.from('.device-mockup', {
            y: 100,
            opacity: 0,
            duration: 1.2,
            ease: 'power3.out'
        });
    }

    // ---------- SETTINGS DROPDOWN ----------
    const settingsBtn = document.getElementById('settingsBtn');
    const settingsDropdown = document.getElementById('settingsDropdown');
    if (settingsBtn) {
        settingsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            settingsDropdown.classList.toggle('active');
        });
        document.addEventListener('click', (e) => {
            if (!settingsBtn.contains(e.target) && !settingsDropdown.contains(e.target)) {
                settingsDropdown.classList.remove('active');
            }
        });
    }

    // ---------- THEME TOGGLE (via gear icon) ----------
    const themeToggleDropdown = document.getElementById('themeToggleDropdown');
    const themeText = document.getElementById('themeText');
    if (themeToggleDropdown) {
        const updateThemeUI = () => {
            const dark = html.classList.contains('dark');
            themeText.textContent = dark ? 'Light Mode' : 'Dark Mode';
            themeToggleDropdown.querySelector('i').className = dark ? 'fas fa-sun mr-2' : 'fas fa-moon mr-2';
        };
        updateThemeUI();
        themeToggleDropdown.addEventListener('click', () => {
            html.classList.toggle('dark');
            localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            updateThemeUI();
        });
    }

    // ---------- MODAL CONTROLS (global) ----------
    window.openModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex', 'active');
        }
    };
    window.closeModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex', 'active');
        }
    };
    window.switchModal = function(showId) {
        document.querySelectorAll('.fixed.inset-0.z-50').forEach(m => {
            m.classList.add('hidden');
            m.classList.remove('flex', 'active');
        });
        window.openModal(showId);
    };

    // Click outside the modal card to close
    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('fixed') && e.target.classList.contains('inset-0')) {
            window.closeModal(e.target.id);
        }
    });

    // ---------- RESIZE HANDLER ----------
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768 && mobileMenu) mobileMenu.classList.add('hidden');
    });

    // ---------- RE‑INIT REVEAL ON FULL LOAD ----------
    window.addEventListener('load', () => { initReveal(); });

        window.togglePassword = function(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    };
})();
</script>
</body>
</html>