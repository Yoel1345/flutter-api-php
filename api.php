<?php
// ─── CORS & Headers ───────────────────────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ─── Koneksi Database ─────────────────────────────────────────────────────────
$host = "sql301.infinityfree.com";
$user = "if0_41820023";
$pass = "Yoeldavid033";
$db   = "if0_41820023_tugas9_232025";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Koneksi gagal: " . $conn->connect_error]);
    exit();
}
$conn->set_charset("utf8mb4");

// ─── Router: ambil action dari query string ───────────────────────────────────
// Contoh penggunaan:
//   GET  api.php?action=get_video
//   GET  api.php?action=hapus_video&id=1
//   GET  api.php?action=get_image&file=thumb_xxx.jpg
//   POST api.php?action=tambah_video   (multipart/form-data)
//   POST api.php?action=edit_video     (multipart/form-data)

$action = $_GET['action'] ?? '';

switch ($action) {

    // ── GET VIDEO ─────────────────────────────────────────────────────────────
    case 'get_video':
        $result = $conn->query("SELECT * FROM youtube_232025 ORDER BY id DESC");
        $data   = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        break;

    // ── TAMBAH VIDEO ──────────────────────────────────────────────────────────
    case 'tambah_video':
        $title = $_POST['title'] ?? '';

        if (empty($title)) {
            echo json_encode(["success" => false, "message" => "Title wajib diisi"]);
            break;
        }

        // Upload Thumbnail
        if (!isset($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(["success" => false, "message" => "File thumbnail wajib dipilih"]);
            break;
        }

        $thumbExt      = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
        $allowedImages = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($thumbExt, $allowedImages)) {
            echo json_encode(["success" => false, "message" => "Format thumbnail tidak didukung (jpg, png, webp)"]);
            break;
        }

        $thumbDir = __DIR__ . '/Thumbnail/';
        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

        $thumbFilename = 'thumb_' . time() . '_' . rand(1000, 9999) . '.' . $thumbExt;
        $thumbDest     = $thumbDir . $thumbFilename;

        if (!move_uploaded_file($_FILES['thumbnail']['tmp_name'], $thumbDest)) {
            echo json_encode(["success" => false, "message" => "Gagal upload thumbnail"]);
            break;
        }

        // Upload Video
        if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
            @unlink($thumbDest);
            echo json_encode(["success" => false, "message" => "File video wajib dipilih"]);
            break;
        }

        $videoExt      = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
        $allowedVideos = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'flv', '3gp'];
        if (!in_array($videoExt, $allowedVideos)) {
            @unlink($thumbDest);
            echo json_encode(["success" => false, "message" => "Format video tidak didukung (mp4, mkv, avi, mov, webm)"]);
            break;
        }

        $videoDir = __DIR__ . '/Video/';
        if (!is_dir($videoDir)) mkdir($videoDir, 0755, true);

        $videoFilename = 'video_' . time() . '_' . rand(1000, 9999) . '.' . $videoExt;
        $videoDest     = $videoDir . $videoFilename;

        if (!move_uploaded_file($_FILES['video']['tmp_name'], $videoDest)) {
            @unlink($thumbDest);
            echo json_encode(["success" => false, "message" => "Gagal upload video"]);
            break;
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseHost = $_SERVER['HTTP_HOST'];
        $dir      = dirname($_SERVER['SCRIPT_NAME']);
        $thumbUrl = $protocol . '://' . $baseHost . $dir . '/Thumbnail/' . $thumbFilename;
        $videoUrl = $protocol . '://' . $baseHost . $dir . '/Video/' . $videoFilename;

        $stmt = $conn->prepare("INSERT INTO youtube_232025 (title, thumbnail, video) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $title, $thumbUrl, $videoUrl);

        if ($stmt->execute()) {
            echo json_encode([
                "success"   => true,
                "message"   => "Data berhasil disimpan",
                "id"        => $stmt->insert_id,
                "thumbnail" => $thumbUrl,
                "video"     => $videoUrl,
            ]);
        } else {
            @unlink($thumbDest);
            @unlink($videoDest);
            echo json_encode(["success" => false, "message" => $conn->error]);
        }
        $stmt->close();
        break;

    // ── EDIT VIDEO ────────────────────────────────────────────────────────────
    case 'edit_video':
        $id    = intval($_POST['id'] ?? 0);
        $title = $_POST['title'] ?? '';

        if (!$id || empty($title)) {
            echo json_encode(["success" => false, "message" => "ID dan title wajib diisi"]);
            break;
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseHost = $_SERVER['HTTP_HOST'];
        $dir      = dirname($_SERVER['SCRIPT_NAME']);

        // Ambil data lama
        $res = $conn->query("SELECT thumbnail, video FROM youtube_232025 WHERE id=$id");
        if (!$res || $res->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "Data tidak ditemukan"]);
            break;
        }
        $old = $res->fetch_assoc();

        // Proses Thumbnail
        $thumbUrl = $_POST['thumbnail_url'] ?? $old['thumbnail'];
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $thumbExt      = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
            $allowedImages = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (!in_array($thumbExt, $allowedImages)) {
                echo json_encode(["success" => false, "message" => "Format thumbnail tidak didukung"]);
                break;
            }

            $thumbDir      = __DIR__ . '/Thumbnail/';
            if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
            $thumbFilename = 'thumb_' . time() . '_' . rand(1000, 9999) . '.' . $thumbExt;
            $thumbDest     = $thumbDir . $thumbFilename;

            if (!move_uploaded_file($_FILES['thumbnail']['tmp_name'], $thumbDest)) {
                echo json_encode(["success" => false, "message" => "Gagal upload thumbnail baru"]);
                break;
            }

            // Hapus thumbnail lama
            $oldThumbPath = __DIR__ . '/Thumbnail/' . basename($old['thumbnail']);
            if (file_exists($oldThumbPath)) @unlink($oldThumbPath);

            $thumbUrl = $protocol . '://' . $baseHost . $dir . '/Thumbnail/' . $thumbFilename;
        }

        // Proses Video
        $videoUrl = $_POST['video_url'] ?? $old['video'];
        if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
            $videoExt      = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
            $allowedVideos = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'flv', '3gp'];
            if (!in_array($videoExt, $allowedVideos)) {
                echo json_encode(["success" => false, "message" => "Format video tidak didukung"]);
                break;
            }

            $videoDir      = __DIR__ . '/Video/';
            if (!is_dir($videoDir)) mkdir($videoDir, 0755, true);
            $videoFilename = 'video_' . time() . '_' . rand(1000, 9999) . '.' . $videoExt;
            $videoDest     = $videoDir . $videoFilename;

            if (!move_uploaded_file($_FILES['video']['tmp_name'], $videoDest)) {
                echo json_encode(["success" => false, "message" => "Gagal upload video baru"]);
                break;
            }

            // Hapus video lama
            $oldVideoPath = __DIR__ . '/Video/' . basename($old['video']);
            if (file_exists($oldVideoPath)) @unlink($oldVideoPath);

            $videoUrl = $protocol . '://' . $baseHost . $dir . '/Video/' . $videoFilename;
        }

        // Update Database
        $stmt = $conn->prepare("UPDATE youtube_232025 SET title=?, thumbnail=?, video=? WHERE id=?");
        $stmt->bind_param("sssi", $title, $thumbUrl, $videoUrl, $id);

        if ($stmt->execute()) {
            echo json_encode([
                "success"   => true,
                "message"   => "Data berhasil diupdate",
                "thumbnail" => $thumbUrl,
                "video"     => $videoUrl,
            ]);
        } else {
            echo json_encode(["success" => false, "message" => $conn->error]);
        }
        $stmt->close();
        break;

    // ── HAPUS VIDEO ───────────────────────────────────────────────────────────
    case 'hapus_video':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(["success" => false, "message" => "ID tidak valid"]);
            break;
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
            echo json_encode(["success" => true, "message" => "Data dihapus"]);
        } else {
            echo json_encode(["success" => false, "message" => $conn->error]);
        }
        $stmt->close();
        break;

    // ── GET IMAGE (serve file thumbnail) ─────────────────────────────────────
    case 'get_image':
        // Override header khusus untuk endpoint ini (bukan JSON)
        header_remove("Content-Type");
        $file = basename($_GET['file'] ?? '');
        if (empty($file)) {
            http_response_code(400);
            exit();
        }
        $path = __DIR__ . '/Thumbnail/' . $file;
        if (!file_exists($path)) {
            http_response_code(404);
            exit();
        }
        $mime = mime_content_type($path);
        header("Content-Type: $mime");
        header("Content-Length: " . filesize($path));
        readfile($path);
        exit();

    // ── Action tidak dikenal ──────────────────────────────────────────────────
    default:
        http_response_code(400);
        echo json_encode([
            "error"            => "Action tidak valid",
            "available_action" => [
                "GET  api.php?action=get_video",
                "GET  api.php?action=hapus_video&id=1",
                "GET  api.php?action=get_image&file=nama_file.jpg",
                "POST api.php?action=tambah_video",
                "POST api.php?action=edit_video",
            ]
        ]);
        break;
}

$conn->close();
