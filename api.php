<?php
// ─── Buffer output untuk strip inject HTML dari InfinityFree ─────────────────
ob_start();

// ─── Tampilkan Error (Penting untuk Debugging) ────────────────────────────────
ini_set('display_errors', 1); // Ubah jadi 1 untuk debug
error_reporting(E_ALL);      // Ubah agar semua error muncul

// ─── CORS & Headers ───────────────────────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, User-Agent");
header("Content-Type: application/json; charset=utf-8");

// Tangani Preflight Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

// ─── Koneksi Database ─────────────────────────────────────────────────────────
$host = "mysql.railway.internal";
$user = "root";
$pass = "UXNpvhqsTQnKfLdDtRltVKVhEqfyolUt";
$db   = "railway";
$port = 3306;

$conn = new mysqli($host, $user, $pass, $db, $port);

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Koneksi gagal: " . $conn->connect_error]);
    exit();
}
$conn->set_charset("utf8mb4");

// ─── Helper untuk URL Otomatis ────────────────────────────────────────────────
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseHost = $_SERVER['HTTP_HOST'];
$baseDir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($baseDir == '/') $baseDir = '';
$baseUrl  = $protocol . '://' . $baseHost . $baseDir;

$action = $_GET['action'] ?? '';

// ─── Fungsi untuk bersihkan output dari inject HTML InfinityFree ──────────────
function cleanAndOutput($data) {
    ob_end_clean();
    // Pastikan tidak ada karakter apapun sebelum JSON
    while (ob_get_level()) ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function cleanAndOutputRaw($json) {
    ob_end_clean();
    while (ob_get_level()) ob_end_clean();
    echo $json;
    exit();
}

switch ($action) {

    // ── GET VIDEO ─────────────────────────────────────────────────────────────
    case 'get_video':
        $result = $conn->query("SELECT * FROM youtube_232025 ORDER BY id DESC");
        $data   = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        cleanAndOutput($data);
        break;

    // ── TAMBAH VIDEO ──────────────────────────────────────────────────────────
    case 'tambah_video':
        $title = $_POST['title'] ?? '';

        if (empty($title)) {
            cleanAndOutput(["success" => false, "message" => "Title wajib diisi"]);
        }

        if (!isset($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
            cleanAndOutput(["success" => false, "message" => "File thumbnail wajib dipilih"]);
        }

        $thumbExt      = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
        $allowedImages = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($thumbExt, $allowedImages)) {
            cleanAndOutput(["success" => false, "message" => "Format thumbnail tidak didukung"]);
        }

        $thumbDir = __DIR__ . '/Thumbnail/';
        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

        $thumbFilename = 'thumb_' . time() . '_' . rand(1000, 9999) . '.' . $thumbExt;
        $thumbDest     = $thumbDir . $thumbFilename;

        if (!move_uploaded_file($_FILES['thumbnail']['tmp_name'], $thumbDest)) {
            cleanAndOutput(["success" => false, "message" => "Gagal simpan thumbnail di server"]);
        }

        if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
            @unlink($thumbDest);
            cleanAndOutput(["success" => false, "message" => "File video wajib dipilih"]);
        }

        $videoExt      = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
        $allowedVideos = ['mp4', 'mkv', 'avi', 'mov', 'webm'];
        if (!in_array($videoExt, $allowedVideos)) {
            @unlink($thumbDest);
            cleanAndOutput(["success" => false, "message" => "Format video tidak didukung"]);
        }

        $videoDir = __DIR__ . '/Video/';
        if (!is_dir($videoDir)) mkdir($videoDir, 0755, true);

        $videoFilename = 'video_' . time() . '_' . rand(1000, 9999) . '.' . $videoExt;
        $videoDest     = $videoDir . $videoFilename;

        if (!move_uploaded_file($_FILES['video']['tmp_name'], $videoDest)) {
            @unlink($thumbDest);
            cleanAndOutput(["success" => false, "message" => "Gagal simpan video di server"]);
        }

        $thumbUrl = $baseUrl . '/Thumbnail/' . $thumbFilename;
        $videoUrl = $baseUrl . '/Video/' . $videoFilename;

        $stmt = $conn->prepare("INSERT INTO youtube_232025 (title, thumbnail, video) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $title, $thumbUrl, $videoUrl);

        if ($stmt->execute()) {
            cleanAndOutput([
                "success"   => true,
                "message"   => "Data berhasil disimpan",
                "id"        => $stmt->insert_id,
                "thumbnail" => $thumbUrl,
                "video"     => $videoUrl,
            ]);
        } else {
            @unlink($thumbDest);
            @unlink($videoDest);
            cleanAndOutput(["success" => false, "message" => "Gagal simpan ke database: " . $conn->error]);
        }
        $stmt->close();
        break;

    // ── EDIT VIDEO ────────────────────────────────────────────────────────────
    case 'edit_video':
        $id    = intval($_POST['id'] ?? 0);
        $title = $_POST['title'] ?? '';

        if (!$id) {
            cleanAndOutput(["success" => false, "message" => "ID tidak valid"]);
        }
        if (empty($title)) {
            cleanAndOutput(["success" => false, "message" => "Title wajib diisi"]);
        }

        // Ambil data lama
        $res = $conn->query("SELECT thumbnail, video FROM youtube_232025 WHERE id=$id");
        if (!$row = $res->fetch_assoc()) {
            cleanAndOutput(["success" => false, "message" => "Data tidak ditemukan"]);
        }

        $thumbUrl = $_POST['thumbnail_url'] ?? $row['thumbnail'];
        $videoUrl = $_POST['video_url']     ?? $row['video'];

        // Ganti thumbnail jika ada upload baru
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $thumbExt      = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
            $allowedImages = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($thumbExt, $allowedImages)) {
                $thumbDir = __DIR__ . '/Thumbnail/';
                if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
                $thumbFilename = 'thumb_' . time() . '_' . rand(1000, 9999) . '.' . $thumbExt;
                $thumbDest     = $thumbDir . $thumbFilename;
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $thumbDest)) {
                    // Hapus thumbnail lama
                    $oldThumb = __DIR__ . '/Thumbnail/' . basename($row['thumbnail']);
                    if (file_exists($oldThumb)) @unlink($oldThumb);
                    $thumbUrl = $baseUrl . '/Thumbnail/' . $thumbFilename;
                }
            }
        }

        // Ganti video jika ada upload baru
        if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
            $videoExt      = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
            $allowedVideos = ['mp4', 'mkv', 'avi', 'mov', 'webm'];
            if (in_array($videoExt, $allowedVideos)) {
                $videoDir = __DIR__ . '/Video/';
                if (!is_dir($videoDir)) mkdir($videoDir, 0755, true);
                $videoFilename = 'video_' . time() . '_' . rand(1000, 9999) . '.' . $videoExt;
                $videoDest     = $videoDir . $videoFilename;
                if (move_uploaded_file($_FILES['video']['tmp_name'], $videoDest)) {
                    // Hapus video lama
                    $oldVideo = __DIR__ . '/Video/' . basename($row['video']);
                    if (file_exists($oldVideo)) @unlink($oldVideo);
                    $videoUrl = $baseUrl . '/Video/' . $videoFilename;
                }
            }
        }

        $stmt = $conn->prepare("UPDATE youtube_232025 SET title=?, thumbnail=?, video=? WHERE id=?");
        $stmt->bind_param("sssi", $title, $thumbUrl, $videoUrl, $id);

        if ($stmt->execute()) {
            cleanAndOutput([
                "success"   => true,
                "message"   => "Data berhasil diupdate",
                "thumbnail" => $thumbUrl,
                "video"     => $videoUrl,
            ]);
        } else {
            cleanAndOutput(["success" => false, "message" => "Gagal update: " . $conn->error]);
        }
        $stmt->close();
        break;

    // ── HAPUS VIDEO ───────────────────────────────────────────────────────────
    case 'hapus_video':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) {
            cleanAndOutput(["success" => false, "message" => "ID tidak valid"]);
        }

        $res = $conn->query("SELECT thumbnail, video FROM youtube_232025 WHERE id=$id");
        if ($row = $res->fetch_assoc()) {
            $thumbPath = __DIR__ . '/Thumbnail/' . basename($row['thumbnail']);
            $videoPath = __DIR__ . '/Video/' . basename($row['video']);
            if (file_exists($thumbPath)) @unlink($thumbPath);
            if (file_exists($videoPath)) @unlink($videoPath);
        }

        $stmt = $conn->prepare("DELETE FROM youtube_232025 WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            cleanAndOutput(["success" => true, "message" => "Data berhasil dihapus"]);
        } else {
            cleanAndOutput(["success" => false, "message" => "Gagal hapus: " . $conn->error]);
        }
        $stmt->close();
        break;

    default:
        ob_end_clean();
        http_response_code(404);
        echo json_encode(["error" => "Action tidak ditemukan"]);
        break;
}

$conn->close();