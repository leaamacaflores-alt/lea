<?php
session_start(); 

// --- 1. DEBUG & SETTINGS ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

// --- CRITICAL GD CHECK ---
if (!extension_loaded('gd') && !extension_loaded('gd2')) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#fff;'><h1 style='color:#d97706;'>? System Error</h1><p><strong>PHP GD Library not enabled.</strong></p></div>");
}

 $uploadDir = 'uploads/';
 $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

 $message = "";

// --- Helper Functions ---
function load_image($filepath) {
    if (!file_exists($filepath)) return false;
    $info = @getimagesize($filepath);
    if (!$info) return false;
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($filepath) : false;
        case 'image/png':  return function_exists('imagecreatefrompng') ? @imagecreatefrompng($filepath) : false;
        case 'image/gif':  return function_exists('imagecreatefromgif') ? @imagecreatefromgif($filepath) : false;
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filepath) : false;
        default: return false;
    }
}

function save_image($image, $filepath, $mime) {
    switch ($mime) {
        case 'image/jpeg': return @imagejpeg($image, $filepath, 90);
        case 'image/png':  return @imagepng($image, $filepath);
        case 'image/gif':  return @imagegif($image, $filepath);
        case 'image/webp': return @imagewebp($image, $filepath);
        default: return false;
    }
}

// --- 2. MAIN LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: Reset Session (Delete all images)
    if (isset($_POST['action']) && $_POST['action'] === 'reset_session') {
        $files = glob($uploadDir . '*');
        foreach($files as $file) {
            if(is_file($file)) @unlink($file);
        }
        exit('cleared');
    }

    // ACTION: Upload
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        try {
            if (!empty($_FILES['image']['tmp_name'])) {
                $fileTmp = $_FILES['image']['tmp_name'];
                $fileType = mime_content_type($fileTmp);
                
                if (in_array($fileType, $allowedTypes)) {
                    $fileName = basename($_FILES['image']['name']);
                    $newFileName = time() . "_" . preg_replace('/\s+/', '_', $fileName);
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $_SESSION['flash_msg'] = "<div class='toast success'>Upload successful! ?</div>";
                    } else {
                        $_SESSION['flash_msg'] = "<div class='toast error'>Upload failed.</div>";
                    }
                } else {
                    $_SESSION['flash_msg'] = "<div class='toast error'>Invalid file type.</div>";
                }
            }
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='toast error'>Error: " . $e->getMessage() . "</div>";
        }
    }

    // ACTION: Delete Single
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $targetPath = $uploadDir . basename($_POST['target_image']);
        if (file_exists($targetPath)) {
            @unlink($targetPath);
            $_SESSION['flash_msg'] = "<div class='toast success'>Image deleted.</div>";
        }
    }

    // ACTION: Edit
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        try {
            if (empty($_POST['target_image'])) throw new Exception("Please select an image.");
            $targetPath = $uploadDir . basename($_POST['target_image']);
            if (!file_exists($targetPath)) throw new Exception("File not found.");

            $saveAsCopy = isset($_POST['save_as_copy']);
            $finalSavePath = $targetPath;
            
            if ($saveAsCopy) {
                $pathInfo = pathinfo($targetPath);
                $finalSavePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_edited.' . $pathInfo['extension'];
            }

            $image = load_image($targetPath);
            if (!$image) throw new Exception("Failed to load image.");

            $info = getimagesize($targetPath);
            $mime = $info['mime'];
            $width = imagesx($image);
            $height = imagesy($image);
            $effectType = $_POST['effect_type'];
            $edited = false;

            switch ($effectType) {
                case 'grayscale': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_GRAYSCALE); $edited = true; } break;
                case 'invert': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_NEGATE); $edited = true; } break;
                case 'sepia': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_GRAYSCALE); imagefilter($image, IMG_FILTER_COLORIZE, 90, 60, 30); $edited = true; } break;
                case 'colorize': 
                    $hex = $_POST['tint_color'] ?? '#ff0000';
                    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
                    if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_COLORIZE, $r, $g, $b); $edited = true; } 
                    break;
                case 'brightness': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_BRIGHTNESS, intval($_POST['brightness_val'])); $edited = true; } break;
                case 'contrast': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_CONTRAST, intval($_POST['contrast_val'])); $edited = true; } break;
                case 'watermark':
                    $text = trim($_POST['wm_text'] ?? '');
                    if ($text) {
                        $color = imagecolorallocate($image, 255, 255, 255); 
                        $shadow = imagecolorallocate($image, 0, 0, 0);
                        $font = 5; $w = imagefontwidth($font) * strlen($text); $h = imagefontheight($font);
                        $pos = $_POST['wm_position'] ?? 'br';
                        $x=10; $y=10;
                        if($pos=='tr'||$pos=='br') $x=$width-$w-20;
                        if($pos=='bl'||$pos=='br') $y=$height-$h-20;
                        if($pos=='c'){$x=($width/2)-($w/2); $y=($height/2)-($h/2);}
                        imagestring($image, $font, $x+1, $y+1, $text, $shadow);
                        imagestring($image, $font, $x, $y, $text, $color);
                        $edited = true;
                    }
                    break;
                case 'rotate':
                    if(function_exists('imagerotate')) {
                        $image = imagerotate($image, intval($_POST['rotate_deg']), imagecolorallocatealpha($image, 0, 0, 0, 127));
                        imagesavealpha($image, true); $edited = true;
                    }
                    break;
                case 'resize':
                    $nw = intval($_POST['resize_width']); $nh = intval($nw * ($height/$width));
                    $newImg = imagecreatetruecolor($nw, $nh);
                    if($mime=='image/png'||$mime=='image/gif') { imagealphablending($newImg, false); imagesavealpha($newImg, true); $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127); imagefilledrectangle($newImg, 0, 0, $nw, $nh, $transparent); }
                    imagecopyresampled($newImg, $image, 0,0,0,0, $nw, $nh, $width, $height);
                    imagedestroy($image); $image = $newImg; $edited = true;
                    break;
            }

            if ($edited) {
                save_image($image, $finalSavePath, $mime);
                $_SESSION['flash_msg'] = "<div class='toast success'>Effect applied! ?</div>";
                imagedestroy($image);
            }

        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='toast error'>" . $e->getMessage() . "</div>";
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

 $message = isset($_SESSION['flash_msg']) ? $_SESSION['flash_msg'] : "";
unset($_SESSION['flash_msg']);
 $images = glob($uploadDir . "*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE);
if ($images) usort($images, function($a, $b) { return filemtime($b) - filemtime($a); });
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leyah's Collection</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #b388ff; --accent: #7c4dff;
            --glass: rgba(255, 255, 255, 0.7); --glass-border: rgba(255, 255, 255, 0.8);
            --text-dark: #4a4a4a; --card-shadow: rgba(124, 77, 255, 0.15);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; outline: none; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%);
            min-height: 100vh; color: var(--text-dark); padding: 20px;
            display: flex; flex-direction: column; align-items: center; overflow-x: hidden;
        }

        .container { width: 100%; max-width: 1200px; z-index: 1; position: relative; }

        header { text-align: center; margin-bottom: 50px; animation: fadeInDown 1s ease-out; }
        h1 { 
            font-size: 3.5rem; font-weight: 800; color: transparent;
            background: linear-gradient(to right, #6a1b9a, #ab47bc);
            -webkit-background-clip: text;
            text-shadow: 0 4px 20px rgba(179, 136, 255, 0.4); 
            display: inline-flex; align-items: center; gap: 15px; 
        }
        .icon { color: var(--accent); display: inline-block; }
        p.subtitle { font-size: 1.2rem; color: #6a1b9a; font-weight: 300; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 20px; }

        /* Toast */
        .toast { position: fixed; top: 20px; right: 20px; padding: 18px 30px; border-radius: 50px; color: white; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 5000; transform: translateX(300%); transition: transform 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); border-left: 5px solid white; }
        .toast.show { transform: translateX(0); }
        .toast.success { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .toast.error { background: linear-gradient(135deg, #cb2d3e, #ef473a); }

        /* Glass Cards */
        .glass-card { 
            background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--glass-border);
            border-radius: 24px; padding: 40px; box-shadow: 0 10px 30px 0 var(--card-shadow);
            margin-bottom: 50px; transition: all 0.3s ease; 
        }

        .section-header { display: flex; align-items: center; gap: 15px; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 2px solid rgba(124, 77, 255, 0.2); }
        .section-header h2 { font-size: 1.8rem; color: #4a148c; font-weight: 700; }

        /* Forms */
        .form-group { display: flex; flex-direction: column; gap: 10px; background: rgba(255,255,255,0.5); padding: 20px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.6); transition: all 0.3s ease; }
        .form-group:hover { border-color: rgba(179, 136, 255, 0.4); }
        label { font-size: 0.85rem; font-weight: 700; color: #6a1b9a; text-transform: uppercase; letter-spacing: 1px; }
        
        input[type="text"], input[type="number"], select { 
            width: 100%; padding: 14px 18px; border-radius: 12px; border: 2px solid rgba(179, 136, 255, 0.3);
            background: rgba(255,255,255,0.9); font-family: 'Poppins', sans-serif; font-size: 1rem;
            transition: all 0.3s ease; color: #4a4a4a; 
        }
        input:focus, select:focus { background: white; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(124, 77, 255, 0.15); transform: translateY(-2px); }
        
        input[type=range] { -webkit-appearance: none; width: 100%; height: 6px; background: rgba(179, 136, 255, 0.3); border-radius: 10px; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 22px; height: 22px; border-radius: 50%; background: var(--accent); cursor: pointer; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        input[type="color"] { width: 100%; height: 50px; border-radius: 12px; cursor: pointer; background: none; border: none; padding: 0; }

        /* Buttons */
        .btn { padding: 16px 32px; border: none; border-radius: 50px; font-weight: 700; cursor: pointer; transition: all 0.3s ease; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; justify-content: center; gap: 10px; text-decoration: none; font-size: 0.95rem; }
        .btn-primary { background: linear-gradient(135deg, #7c4dff, #b388ff); color: white; box-shadow: 0 10px 20px rgba(124, 77, 255, 0.3); }
        .btn-primary:hover { transform: translateY(-2px) scale(1.02); box-shadow: 0 15px 30px rgba(124, 77, 255, 0.4); }
        .btn-sm { padding: 10px 20px; font-size: 0.85rem; }
        .btn-danger { background: linear-gradient(135deg, #ff416c, #ff4b2b); color: white; }
        .btn-success { background: linear-gradient(135deg, #11998e, #38ef7d); color: white; }
        .btn-ghost { background: rgba(124, 77, 255, 0.1); color: #6a1b9a; border: 1px solid rgba(124, 77, 255, 0.3); }
        .btn-ghost:hover { background: rgba(124, 77, 255, 0.2); }

        .checkbox-group { display: flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
        .checkbox-group input { width: 20px; height: 20px; accent-color: var(--accent); }

        /* Gallery */
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 30px; }
        
        .gallery-item {
            position: relative; border-radius: 20px; overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(179, 136, 255, 0.25);
            background: rgba(255,255,255,0.4); aspect-ratio: 4/3;
            transition: all 0.4s ease; border: 2px solid rgba(255,255,255,0.5);
        }
        .gallery-item:hover { transform: translateY(-10px) scale(1.02); box-shadow: 0 35px 70px -12px rgba(124, 77, 255, 0.3); border-color: var(--primary); }
        
        .img-box { width: 100%; height: 100%; overflow: hidden; cursor: zoom-in; }
        .gallery-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.7s ease; }
        .gallery-item:hover .gallery-img { transform: scale(1.1); }

        .overlay {
            position: absolute; bottom: 0; left: 0; width: 100%;
            background: linear-gradient(to top, rgba(106, 27, 154, 0.9), transparent);
            padding: 40px 20px 20px 20px; display: flex; flex-direction: column; gap: 15px; z-index: 2;
        }

        .file-name { 
            position: absolute; top: 20px; left: 20px; background: rgba(255, 255, 255, 0.9);
            padding: 8px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 600;
            color: #6a1b9a; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 3; 
        }

        .action-row { display: flex; gap: 10px; justify-content: center; }
        .hidden { display: none !important; }

        /* Modal Styles (Generic for all) */
        .modal { 
            display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; 
            background-color: rgba(106, 27, 154, 0.8); backdrop-filter: blur(10px); 
            opacity: 0; transition: opacity 0.3s ease; justify-content: center; align-items: center; 
            padding: 20px;
        }
        .modal.show { opacity: 1; }
        
        .modal-content-box {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 900px; /* Slightly wider for editor split view */
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.4);
            animation: zoomIn 0.4s ease;
        }

        /* Specific width for upload modal */
        #uploadModal .modal-content-box { max-width: 600px; }

        .close-modal { 
            position: absolute; top: 15px; right: 25px; color: white; font-size: 40px; 
            font-weight: 300; cursor: pointer; transition: 0.3s; z-index: 4001; line-height: 1; 
            background: rgba(0,0,0,0.2); border-radius: 50%; width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
        }
        .close-modal:hover { color: #fff; background: rgba(255, 71, 107, 0.8); transform: rotate(90deg); }

        .modal-img-content { max-width: 95%; max-height: 90vh; border-radius: 10px; box-shadow: 0 0 50px rgba(179, 136, 255, 0.3); }

        @keyframes zoomIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }

        /* Custom File Input */
        input[type="file"] { cursor: pointer; }
        input[type="file"]::file-selector-button {
            color: white; background: linear-gradient(135deg, #7c4dff, #b388ff);
            padding: 10px 20px; border: none; border-radius: 50px; cursor: pointer;
            margin-right: 15px; transition: all 0.3s; font-weight: 600;
        }
        input[type="file"]::file-selector-button:hover { transform: scale(1.05); box-shadow: 0 5px 15px rgba(124, 77, 255, 0.4); }

        /* Editor Layout inside Modal */
        .editor-container { display: flex; gap: 20px; align-items: flex-start; }
        .editor-left { flex: 1; display: flex; flex-direction: column; gap: 15px; }
        .editor-right { flex: 1; display: flex; flex-direction: column; gap: 15px; }
        .apply-btn-container { margin-top: 20px; text-align: right; border-top: 1px solid rgba(124, 77, 255, 0.2); padding-top: 20px; }

        @media (max-width: 768px) {
            .editor-container { flex-direction: column; }
            h1 { font-size: 2.2rem; }
            .gallery-grid { grid-template-columns: 1fr; }
            .glass-card { padding: 25px; }
        }
    </style>
</head>
<body>

<div class="container">
    <?= $message ?>

    <header>
        <h1>
            <span class="icon"></span> LEYAH'S COLLECTION <span class="icon"></span>
        </h1>
        <p class="subtitle">Where every page feels like home</p>
        
        <!-- BUTTON TO OPEN UPLOAD MODAL -->
        <button class="btn btn-primary" style="margin-top: 20px;" onclick="openUploadModal()">Upload Books</button>
    </header>

    <!-- Gallery (Main Content) -->
    <div class="gallery-grid">
        <?php if (empty($images)): ?>
            <div style="grid-column: 1/-1; text-align: center; padding: 60px; color: #7c4dff; background: rgba(255,255,255,0.5); border-radius: 24px; border: 1px dashed rgba(124, 77, 255, 0.3);">
                <h3>No books yet.</h3><p>Click "Upload Books" to start!</p>
            </div>
        <?php else: ?>
            <?php foreach($images as $img): $imgUrl = $img . '?v=' . filemtime($img); ?>
                <div class="gallery-item">
                    <span class="file-name"><?= htmlspecialchars(basename($img)) ?></span>
                    <!-- Click Image to ZOOM -->
                    <div class="img-box" onclick="zoomImage('<?= $imgUrl ?>')">
                        <img src="<?= $imgUrl ?>" class="gallery-img" alt="Image">
                    </div>
                    <!-- BUTTONS OVERLAY -->
                    <div class="overlay">
                        <div class="action-row">
                            <a href="<?= $img ?>" download class="btn btn-sm btn-success">Download</a>
                            <!-- Click Edit to OPEN EDITOR MODAL -->
                            <button class="btn btn-sm btn-ghost" onclick="openEditorModal('<?= basename($img) ?>')">Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?= basename($img) ?>')">Delete</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- 1. UPLOAD MODAL -->
<div id="uploadModal" class="modal">
    <div class="modal-content-box">
        <span class="close-modal" onclick="closeUploadModal()">&times;</span>
        <div class="section-header" style="margin-bottom: 20px; border:none;">
            <h2>Upload New Book</h2>
        </div>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <label>Select Image File</label>
                <input type="file" name="image" required accept="image/*">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Upload Now </button>
        </form>
    </div>
</div>

<!-- 2. EDITOR MODAL -->
<div id="editorModal" class="modal">
    <div class="modal-content-box">
        <span class="close-modal" onclick="closeEditorModal()">&times;</span>
        
        <div class="section-header" style="margin-bottom: 20px; border:none;">
            <h2>Photo Editor</h2>
        </div>

        <form action="" method="post">
            <input type="hidden" name="action" value="edit">
            
            <div class="editor-container">
                <!-- Left Column -->
                <div class="editor-left">
                    <div class="form-group">
                        <label>Select Image Source</label>
                        <select name="target_image" required id="imgSelect">
                            <option value="">-- Select from gallery --</option>
                            <?php if($images): foreach($images as $img): ?>
                                <option value="<?= basename($img) ?>"><?= htmlspecialchars(basename($img)) ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Effect Mode</label>
                        <select name="effect_type" id="effectSelect" onchange="toggleInputs()">
                            <option value="grayscale">Grayscale</option>
                            <option value="invert">Invert Colors</option>
                            <option value="sepia">Sepia (Vintage)</option>
                            <option value="colorize">Color Tint</option>
                            <option value="brightness">Brightness</option>
                            <option value="contrast">Contrast</option>
                            <option value="watermark">Watermark</option>
                            <option value="rotate">Rotate</option>
                            <option value="resize">Resize</option>
                        </select>
                    </div>

                    <div class="form-group" style="background: rgba(124, 77, 255, 0.05); border-style: dashed;">
                        <label class="checkbox-group"><input type="checkbox" name="save_as_copy" id="saveCopy"><span>Save as New Copy</span></label>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="editor-right">
                    <div class="form-group hidden" id="colorizeInput"><label>Tint Color</label><input type="color" name="tint_color" value="#b388ff"></div>
                    <div class="form-group hidden" id="brightnessInput"><label>Brightness Level</label><input type="range" name="brightness_val" min="-255" max="255" value="0"></div>
                    <div class="form-group hidden" id="contrastInput"><label>Contrast Level</label><input type="range" name="contrast_val" min="-100" max="100" value="0"></div>
                    
                    <div class="form-group hidden" id="wmInput"><label>Watermark Text</label><input type="text" name="wm_text" placeholder="Type watermark..."></div>
                    <div class="form-group hidden" id="wmPosInput"><label>Placement</label><select name="wm_position"><option value="tl">Top-Left</option><option value="tr">Top-Right</option><option value="c">Center</option><option value="bl">Bottom-Left</option><option value="br" selected>Bottom-Right</option></select></div>

                    <div class="form-group hidden" id="rotateInput"><label>Rotation Angle</label><select name="rotate_deg"><option value="90">90° CW</option><option value="180">180°</option><option value="-90">90° CCW</option></select></div>
                    
                    <div class="form-group hidden" id="resizeInput"><label>Width (px)</label><input type="number" name="resize_width" placeholder="e.g. 800" min="10"><small style="color:#888; font-size:0.75rem; margin-top:5px;">Height auto-calculated</small></div>
                </div>
            </div>
            
            <div class="apply-btn-container">
                 <button type="submit" class="btn btn-primary">Apply Effect</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. ZOOM MODAL -->
<div id="zoomModal" class="modal">
    <span class="close-modal" onclick="closeZoomModal()">&times;</span>
    <img class="modal-img-content" id="zoomedImg">
</div>

<!-- Hidden Forms -->
<form id="deleteForm" action="" method="post">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="target_image" id="deleteTarget">
</form>

<script>
// --- Logic: Clear Images on Tab Close ---
document.addEventListener("DOMContentLoaded", () => {
    const sessionKey = 'album_is_active';
    const dirtyKey = 'album_has_data';
    const isNewTab = !sessionStorage.getItem(sessionKey);
    const hadData = localStorage.getItem(dirtyKey) === 'true';
    const serverHasFiles = <?= empty($images) ? 'false' : 'true' ?>;

    if (isNewTab) {
        sessionStorage.setItem(sessionKey, 'true');
        if (hadData || serverHasFiles) {
            console.log("New session detected. Wiping files...");
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=reset_session'
            }).then(() => {
                localStorage.removeItem(dirtyKey);
                location.reload();
            });
        }
    }

    const uploadForm = document.querySelector('form[enctype="multipart-formdata"]');
    if (uploadForm) {
        uploadForm.addEventListener('submit', () => {
            localStorage.setItem(dirtyKey, 'true');
        });
    }

    const toast = document.querySelector('.toast');
    if(toast) { setTimeout(() => toast.classList.add('show'), 100); setTimeout(() => toast.classList.remove('show'), 4000); }
});

// --- Logic: Editor Inputs ---
function toggleInputs() {
    const effect = document.getElementById('effectSelect').value;
    const inputs = ['colorizeInput', 'brightnessInput', 'contrastInput', 'wmInput', 'wmPosInput', 'rotateInput', 'resizeInput'];
    inputs.forEach(id => document.getElementById(id).classList.add('hidden'));
    
    if (effect === 'colorize') document.getElementById('colorizeInput').classList.remove('hidden');
    if (effect === 'brightness') document.getElementById('brightnessInput').classList.remove('hidden');
    if (effect === 'contrast') document.getElementById('contrastInput').classList.remove('hidden');
    if (effect === 'watermark') { document.getElementById('wmInput').classList.remove('hidden'); document.getElementById('wmPosInput').classList.remove('hidden'); }
    if (effect === 'rotate') document.getElementById('rotateInput').classList.remove('hidden');
    if (effect === 'resize') document.getElementById('resizeInput').classList.remove('hidden');
}

// --- Logic: Upload Modal ---
function openUploadModal() {
    const modal = document.getElementById('uploadModal');
    modal.style.display = "flex";
    setTimeout(() => modal.classList.add('show'), 10);
}

function closeUploadModal() {
    const modal = document.getElementById('uploadModal');
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = "none", 300);
}

// --- Logic: Editor Modal ---
function openEditorModal(filename) {
    const modal = document.getElementById('editorModal');
    const select = document.getElementById('imgSelect');
    
    // Set the selected image
    select.value = filename;
    
    // Show modal
    modal.style.display = "flex";
    setTimeout(() => modal.classList.add('show'), 10);
    
    // Visual cue
    select.style.borderColor = '#7c4dff';
    select.style.boxShadow = '0 0 0 4px rgba(124, 77, 255, 0.2)';
    setTimeout(() => { select.style.borderColor = ''; select.style.boxShadow = ''; }, 1000);
}

function closeEditorModal() {
    const modal = document.getElementById('editorModal');
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = "none", 300);
}

// --- Logic: Zoom Modal ---
function zoomImage(src) {
    const modal = document.getElementById("zoomModal");
    const modalImg = document.getElementById("zoomedImg");
    modal.style.display = "flex";
    setTimeout(() => modal.classList.add('show'), 10);
    modalImg.src = src;
}

function closeZoomModal() {
    const modal = document.getElementById("zoomModal");
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = "none", 300);
}

// Close modals on outside click
window.onclick = function(event) {
    const uploadModal = document.getElementById("uploadModal");
    const editorModal = document.getElementById("editorModal");
    const zoomModal = document.getElementById("zoomModal");
    
    if (event.target == zoomModal) closeZoomModal();
    if (event.target == editorModal) closeEditorModal();
    if (event.target == uploadModal) closeUploadModal();
}

function confirmDelete(filename) {
    if(confirm("Delete this image?")) {
        document.getElementById('deleteTarget').value = filename;
        document.getElementById('deleteForm').submit();
    }
}
</script>

</body>
</html>