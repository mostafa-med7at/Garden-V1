<?php
// modules/media/index.php — File Uploaders Module (g)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user = requireLogin();
$pageTitle = 'File Manager';
$db = getDB();

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Ensure database table for media exists
$db->exec("CREATE TABLE IF NOT EXISTS media_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    description TEXT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// ── Handle File Upload ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    $description = trim($_POST['description'] ?? '');
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['file']['tmp_name'];
        $fileName      = $_FILES['file']['name'];
        $fileSize      = $_FILES['file']['size'];
        $fileType      = $_FILES['file']['type'];
        
        // Sanitize and generate unique filename
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
        
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = uniqid('media_', true) . '.' . $fileExtension;
            $destPath = UPLOAD_DIR . $newFileName;
            
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $db->prepare("INSERT INTO media_files (user_id, file_name, original_name, mime_type, file_size, description) VALUES (?, ?, ?, ?, ?, ?)")
                   ->execute([$user['id'], $newFileName, $fileName, $fileType, $fileSize, $description]);
                
                auditLog('file_uploaded', 'media', 'media_files', (int)$db->lastInsertId(), "Uploaded $fileName");
                setFlash('success', 'File successfully uploaded.');
            } else {
                setFlash('danger', 'There was an error moving the uploaded file. Check directory permissions.');
            }
        } else {
            setFlash('danger', 'Upload failed. Allowed file types: ' . implode(', ', $allowedExtensions));
        }
    } else {
        setFlash('danger', 'No file uploaded or there was an upload error (Code: ' . ($_FILES['file']['error'] ?? 'unknown') . ').');
    }
    header('Location: index.php'); exit;
}

// ── Handle File Deletion ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    $fileId = (int)$_POST['file_id'];
    
    // Check permission (admin can delete any, user can only delete their own)
    if ($user['role_name'] === 'admin') {
        $stmt = $db->prepare("SELECT * FROM media_files WHERE id = ?");
        $stmt->execute([$fileId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM media_files WHERE id = ? AND user_id = ?");
        $stmt->execute([$fileId, $user['id']]);
    }
    
    $file = $stmt->fetch();
    
    if ($file) {
        $filePath = UPLOAD_DIR . $file['file_name'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        $db->prepare("DELETE FROM media_files WHERE id = ?")->execute([$fileId]);
        auditLog('file_deleted', 'media', 'media_files', $fileId, "Deleted {$file['original_name']}");
        setFlash('success', 'File deleted successfully.');
    } else {
        setFlash('danger', 'File not found or permission denied.');
    }
    header('Location: index.php'); exit;
}

// ── Fetch Files ─────────────────────────────────────────────
if ($user['role_name'] === 'admin' || $user['role_name'] === 'warden') {
    // Admins/wardens see all files
    $files = $db->query("SELECT m.*, u.full_name FROM media_files m JOIN users u ON m.user_id = u.id ORDER BY m.uploaded_at DESC")->fetchAll();
} else {
    // Regular users see their own files
    $stmt = $db->prepare("SELECT m.*, u.full_name FROM media_files m JOIN users u ON m.user_id = u.id WHERE m.user_id = ? ORDER BY m.uploaded_at DESC");
    $stmt->execute([$user['id']]);
    $files = $stmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📁 File Manager</h1>
    <p>Upload, view, and manage your garden documents and images.</p>
</div>

<!-- Upload Card -->
<div class="card mb-2">
    <div class="card-header">📤 Upload New File</div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start">
            <div class="form-group" style="flex:1;min-width:250px;margin:0">
                <label class="small fw-semibold">Select File <span class="text-muted">(JPG, PNG, PDF, DOC, TXT)</span></label>
                <input type="file" name="file" class="form-control" required style="padding: .375rem .75rem;">
            </div>
            <div class="form-group" style="flex:2;min-width:250px;margin:0">
                <label class="small fw-semibold">Description / Notes <span class="text-muted">(Optional)</span></label>
                <input type="text" name="description" class="form-control" placeholder="What is this file?">
            </div>
            <div style="margin-top:1.4rem">
                <button type="submit" name="upload_file" value="1" class="btn btn-primary" style="height:38px">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Files Gallery / Table -->
<div class="card">
    <div class="card-header">
        Uploaded Files <span class="badge badge-info" style="margin-left:.5rem"><?= count($files) ?> files</span>
    </div>
    
    <?php if ($files): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Preview</th>
                    <th>File Details</th>
                    <th>Description</th>
                    <th>Uploaded By</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $f): 
                    $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg','jpeg','png','gif']);
                    $fileUrl = APP_URL . '/assets/uploads/' . $f['file_name'];
                    $sizeKb = round($f['file_size'] / 1024, 1);
                ?>
                <tr>
                    <td style="width:80px;text-align:center">
                        <?php if ($isImage): ?>
                            <a href="<?= $fileUrl ?>" target="_blank">
                                <img src="<?= $fileUrl ?>" alt="preview" style="width:50px;height:50px;object-fit:cover;border-radius:4px;border:1px solid var(--gray-300)">
                            </a>
                        <?php else: ?>
                            <div style="width:50px;height:50px;background:var(--gray-200);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--gray-600)">
                                📄
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= $fileUrl ?>" target="_blank" style="font-weight:600;text-decoration:none;color:var(--green-dark)">
                            <?= e((strlen($f['original_name']) > 30) ? substr($f['original_name'], 0, 27) . '...' : $f['original_name']) ?>
                        </a>
                        <div class="text-sm text-muted mt-1"><?= strtoupper($ext) ?> &bull; <?= $sizeKb ?> KB</div>
                    </td>
                    <td><?= e($f['description'] ?: '—') ?></td>
                    <td><?= e($f['full_name']) ?></td>
                    <td class="text-sm text-muted"><?= date('d M Y, H:i', strtotime($f['uploaded_at'])) ?></td>
                    <td>
                        <a href="<?= $fileUrl ?>" download="<?= e($f['original_name']) ?>" class="btn btn-sm btn-secondary" title="Download">⬇️</a>
                        <?php if ($user['role_name'] === 'admin' || $user['id'] === $f['user_id']): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                            <button type="submit" name="delete_file" value="1" class="btn btn-sm btn-danger" data-confirm="Are you sure you want to delete this file permanently?" title="Delete">🗑️</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:3rem;text-align:center;color:var(--gray-500)">
        <div style="font-size:3rem;margin-bottom:1rem">📂</div>
        <p>No files uploaded yet.</p>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-confirm]');
    if (btn && !confirm(btn.dataset.confirm)) e.preventDefault();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
