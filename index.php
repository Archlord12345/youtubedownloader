<?php
// PHP Backend API Actions
$action = $_GET['action'] ?? '';

$downloadsFile = __DIR__ . '/downloads.json';
$downloadsDir = __DIR__ . '/downloads';

// Initialize local storage if it doesn't exist
if (!is_dir($downloadsDir)) {
    mkdir($downloadsDir, 0755, true);
}

// Initialize downloads file if it doesn't exist
if (!file_exists($downloadsFile)) {
    file_put_contents($downloadsFile, json_encode([]));
}

function getDownloads() {
    global $downloadsFile;
    if (file_exists($downloadsFile)) {
        $content = file_get_contents($downloadsFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    return [];
}

function saveDownload($videoInfo) {
    global $downloadsFile;
    $downloads = getDownloads();
    $found = false;

    foreach ($downloads as &$d) {
        if (($d['videoId'] ?? '') === ($videoInfo['videoId'] ?? '')) {
            $d = array_merge($d, $videoInfo);
            $found = true;
            break;
        }
    }
    unset($d);

    if (!$found) {
        array_unshift($downloads, $videoInfo);
    }

    file_put_contents($downloadsFile, json_encode(array_values($downloads), JSON_PRETTY_PRINT));
}

function getLocalDownloads() {
    global $downloadsDir;
    $files = glob($downloadsDir . '/*');
    if (!$files) {
        return [];
    }

    $items = [];
    foreach ($files as $filePath) {
        if (!is_file($filePath)) {
            continue;
        }

        $basename = basename($filePath);
        $items[] = [
            'videoId' => pathinfo($basename, PATHINFO_FILENAME),
            'title' => pathinfo($basename, PATHINFO_FILENAME),
            'thumbnail' => '',
            'url' => '',
            'downloadedAt' => date('c', filemtime($filePath)),
            'localFile' => $basename,
            'size' => filesize($filePath),
        ];
    }

    usort($items, function ($a, $b) {
        return strtotime($b['downloadedAt']) <=> strtotime($a['downloadedAt']);
    });

    return $items;
}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}

function sanitizeFileName($name) {
    $clean = preg_replace('/[^a-zA-Z0-9-_\. ]/', '', $name);
    $clean = trim($clean);
    return $clean !== '' ? $clean : 'video';
}

function runYtDlpDownload($url, $outputTemplate, $isAudio) {
    $binary = trim((string) shell_exec('command -v yt-dlp 2>/dev/null'));
    if ($binary === '') {
        return [
            'ok' => false,
            'error' => "yt-dlp is not installed on the server. Install it (e.g. apt install yt-dlp) and retry.",
        ];
    }

    $common = [
        escapeshellarg($binary),
        '--no-playlist',
        '--no-warnings',
        '--restrict-filenames',
        '--output', escapeshellarg($outputTemplate),
    ];

    if ($isAudio) {
        $common[] = '-x';
        $common[] = '--audio-format';
        $common[] = 'mp3';
    } else {
        $common[] = '-f';
        $common[] = escapeshellarg('bv*+ba/b');
        $common[] = '--merge-output-format';
        $common[] = 'mp4';
    }

    $common[] = escapeshellarg($url);
    $cmd = implode(' ', $common) . ' 2>&1';
    $output = shell_exec($cmd);

    $matches = glob(str_replace('%(ext)s', '*', $outputTemplate));
    $savedFile = '';
    if ($matches) {
        usort($matches, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $savedFile = $matches[0];
    }

    if ($savedFile && is_file($savedFile)) {
        return [
            'ok' => true,
            'path' => $savedFile,
            'output' => $output ?: '',
        ];
    }

    return [
        'ok' => false,
        'error' => trim((string) $output) ?: 'yt-dlp failed without output',
    ];
}


if ($action === 'videoinfo') {
    header('Content-Type: application/json');
    $url = $_GET['url'] ?? '';
    if (!$url) {
        echo json_encode(['error' => 'URL is required']);
        exit;
    }

    // Extract Video ID
    preg_match('/(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches);
    $videoId = $matches[1] ?? uniqid();

    // Fast fetching of title and thumbnail using Noembed
    $noembedUrl = 'https://noembed.com/embed?url=' . urlencode($url);
    $res = @file_get_contents($noembedUrl);
    if ($res) {
        $noembedData = json_decode($res, true);
        $title = $noembedData['title'] ?? 'YouTube Video';
        $thumbnail = $noembedData['thumbnail_url'] ?? "https://img.youtube.com/vi/$videoId/hqdefault.jpg";
    } else {
        $title = 'YouTube Video';
        $thumbnail = "https://img.youtube.com/vi/$videoId/hqdefault.jpg";
    }

    // Provide generic formats for Cobalt downloader
    // Cobalt handles the direct extraction when we actually request the download
    $formats = [
        [
            'itag' => 'max',
            'quality' => 'Best Quality (Video + Audio)',
            'format' => 'mp4'
        ],
        [
            'itag' => 'audio',
            'quality' => 'Audio Only',
            'format' => 'mp3'
        ]
    ];

    echo json_encode([
        'videoId' => $videoId,
        'title' => $title,
        'thumbnail' => $thumbnail,
        'formats' => $formats,
        'url' => $url
    ]);
    exit;
}

if ($action === 'download') {
    $url = $_GET['url'] ?? '';
    $itag = $_GET['itag'] ?? 'max';
    $title = $_GET['title'] ?? 'video';
    $thumbnail = $_GET['thumbnail'] ?? '';
    $videoId = $_GET['videoId'] ?? uniqid();

    if (!$url) {
        die('URL is required');
    }
    $isAudio = ($itag === 'audio');
    $safeTitle = sanitizeFileName($title ?: 'video');
    $outputTemplate = $downloadsDir . '/' . $safeTitle . '-' . $videoId . '.%(ext)s';

    $download = runYtDlpDownload($url, $outputTemplate, $isAudio);
    if (!$download['ok']) {
        die('Impossible de récupérer le lien de téléchargement.<br>Détail: ' . htmlspecialchars($download['error']));
    }

    $finalPath = $download['path'];
    if ($title && $thumbnail) {
        saveDownload([
            'videoId' => $videoId,
            'title' => $title,
            'thumbnail' => $thumbnail,
            'url' => $url,
            'downloadedAt' => date('c'),
            'localFile' => basename($finalPath),
            'size' => filesize($finalPath),
        ]);
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($finalPath) . '"');
    header('Content-Length: ' . filesize($finalPath));
    readfile($finalPath);
    exit;
}


if ($action === 'file') {
    $name = basename($_GET['name'] ?? '');
    if (!$name) {
        http_response_code(400);
        die('Missing file name');
    }

    $path = $downloadsDir . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        die('File not found');
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($action === 'gallery') {
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $videoId = $_GET['videoId'] ?? '';
        $localFile = basename($_GET['localFile'] ?? '');

        $downloads = getDownloads();
        $downloads = array_filter($downloads, function($d) use ($videoId, $localFile) {
            if ($videoId && ($d['videoId'] ?? '') === $videoId) return false;
            if ($localFile && ($d['localFile'] ?? '') === $localFile) return false;
            return true;
        });
        file_put_contents($downloadsFile, json_encode(array_values($downloads), JSON_PRETTY_PRINT));

        if ($localFile) {
            $path = $downloadsDir . '/' . $localFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    header('Content-Type: application/json');

    $history = getDownloads();
    $localByName = [];
    foreach (getLocalDownloads() as $local) {
        $localByName[$local['localFile']] = $local;
    }

    $merged = [];
    foreach ($history as $item) {
        $name = $item['localFile'] ?? '';
        if ($name && isset($localByName[$name])) {
            $item['size'] = $localByName[$name]['size'];
            $item['downloadedAt'] = $localByName[$name]['downloadedAt'];
            $item['localFile'] = $name;
            unset($localByName[$name]);
        }
        $merged[] = $item;
    }

    foreach ($localByName as $localItem) {
        $localItem['title'] = str_replace(['-', '_'], ' ', $localItem['title']);
        $merged[] = $localItem;
    }

    usort($merged, function ($a, $b) {
        return strtotime($b['downloadedAt'] ?? '1970-01-01') <=> strtotime($a['downloadedAt'] ?? '1970-01-01');
    });

    echo json_encode($merged);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Downloader</title>
    <style>
        :root { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        body { margin:0; background:linear-gradient(180deg,#0f172a,#111827); color:#e5e7eb; }
        #root { max-width:1100px; margin:0 auto; padding:2rem 1rem 3rem; }
        .container { background:rgba(17,24,39,.75); border:1px solid #374151; border-radius:20px; padding:1.5rem; backdrop-filter: blur(8px); }
        h1 { margin:0 0 1.5rem; text-align:center; color:#f9fafb; }
        .search-box { display:flex; gap:10px; margin-bottom:1rem; }
        .search-box input { flex:1; padding:12px 14px; border-radius:10px; border:1px solid #4b5563; background:#111827; color:#fff; }
        .search-box button,.download-btn,.delete-btn { border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:600; }
        .search-box button { background:linear-gradient(90deg,#ef4444,#dc2626); color:#fff; }
        .error { background:#7f1d1d; color:#fecaca; padding:10px; border-radius:10px; margin-bottom:1rem; }
        .video-info { background:#0b1220; border:1px solid #243042; border-radius:14px; padding:1rem; margin-bottom:1.5rem; }
        .thumbnail { width:100%; max-height:320px; object-fit:cover; border-radius:12px; }
        .formats { display:flex; flex-wrap:wrap; gap:10px; justify-content:center; margin-top:1rem; }
        .download-btn { background:#16a34a; color:#fff; }
        .delete-btn { background:#b91c1c; color:#fff; }
        .gallery { margin-top:2rem; }
        .gallery h2 { margin-bottom:1rem; }
        .gallery-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; }
        .gallery-item { background:#0b1220; border:1px solid #243042; border-radius:14px; padding:12px; }
        .gallery-item img { width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:10px; background:#1f2937; }
        .gallery-item h3 { margin:8px 0 4px; font-size:15px; color:#fff; }
        .gallery-item p { margin:0; color:#9ca3af; font-size:12px; }
        .gallery-actions { display:flex; gap:8px; margin-top:10px; }
        .muted { color:#9ca3af; }
    </style>
</head>
<body>
    <div id="root">
        <div class="container">
            <h1>YouTube Downloader</h1>
            
            <div class="search-box">
                <input
                    type="text"
                    id="url-input"
                    placeholder="Paste YouTube URL here..."
                />
                <button id="get-video-btn">Get Video</button>
            </div>

            <div id="error-container" class="error" style="display: none;"></div>

            <div id="video-info-container" class="video-info" style="display: none;">
                <img id="video-thumbnail" src="" alt="Thumbnail" class="thumbnail" />
                <h2 id="video-title"></h2>
                
                <div class="formats">
                    <h3>Available Qualities:</h3>
                    <div id="formats-container" style="display: flex; gap: 10px; justify-content: center; width: 100%; flex-wrap: wrap;"></div>
                </div>
            </div>

            <div class="gallery">
                <h2>Downloads (historique + fichiers locaux)</h2>
                <div id="gallery-container"></div>
            </div>
        </div>
    </div>

    <script>
        let currentVideoInfo = null;

        document.addEventListener('DOMContentLoaded', () => {
            fetchGallery();

            const urlInput = document.getElementById('url-input');
            const getBtn = document.getElementById('get-video-btn');

            urlInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') getVideoInfo();
            });
            getBtn.addEventListener('click', getVideoInfo);
        });

        async function fetchGallery() {
            try {
                const res = await fetch('?action=gallery');
                const data = await res.json();
                renderGallery(data);
            } catch (e) {
                console.error('Error fetching gallery', e);
            }
        }

        async function getVideoInfo() {
            const urlInput = document.getElementById('url-input');
            const url = urlInput.value.trim();
            if (!url) return;

            const btn = document.getElementById('get-video-btn');
            const errorContainer = document.getElementById('error-container');
            const videoContainer = document.getElementById('video-info-container');
            
            btn.disabled = true;
            btn.textContent = 'Loading...';
            errorContainer.style.display = 'none';
            videoContainer.style.display = 'none';
            currentVideoInfo = null;

            try {
                const response = await fetch(`?action=videoinfo&url=${encodeURIComponent(url)}`);
                const data = await response.json();
                
                if (data.error) throw new Error(data.error);
                
                currentVideoInfo = data;
                renderVideoInfo(data);
            } catch (err) {
                errorContainer.textContent = err.message;
                errorContainer.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Get Video';
            }
        }

        function renderVideoInfo(info) {
            const container = document.getElementById('video-info-container');
            document.getElementById('video-thumbnail').src = info.thumbnail;
            document.getElementById('video-title').textContent = info.title;
            
            const formatsContainer = document.getElementById('formats-container');
            formatsContainer.innerHTML = '';
            
            info.formats.forEach(format => {
                const btn = document.createElement('button');
                btn.className = 'download-btn';
                btn.textContent = `${format.quality} - ${format.format}`;
                btn.onclick = () => downloadVideo(format.itag, info.url || document.getElementById('url-input').value);
                formatsContainer.appendChild(btn);
            });
            
            container.style.display = 'block';
        }

        function downloadVideo(itag, urlStr) {
            if (!currentVideoInfo) return;
            const title = encodeURIComponent(currentVideoInfo.title);
            const thumbnail = encodeURIComponent(currentVideoInfo.thumbnail);
            const videoId = encodeURIComponent(currentVideoInfo.videoId);
            const url = encodeURIComponent(urlStr);
            
            window.open(`?action=download&url=${url}&itag=${itag}&title=${title}&thumbnail=${thumbnail}&videoId=${videoId}`, '_blank');
            
            setTimeout(fetchGallery, 2000);
        }

        function formatSize(bytes) {
            if (!bytes) return '';
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
            if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
            return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
        }

        function renderGallery(gallery) {
            const container = document.getElementById('gallery-container');
            if (!gallery || gallery.length === 0) {
                container.innerHTML = '<p class="no-videos">No videos downloaded yet</p>';
                return;
            }

            const grid = document.createElement('div');
            grid.className = 'gallery-grid';

            gallery.forEach(item => {
                const div = document.createElement('div');
                div.className = 'gallery-item';

                const safeTitle = item.title || 'video';
                const thumb = item.thumbnail || 'https://via.placeholder.com/640x360?text=Local+File';
                const when = item.downloadedAt ? new Date(item.downloadedAt).toLocaleString() : 'Unknown date';
                const meta = `${when}${item.size ? ` • ${formatSize(item.size)}` : ''}`;

                div.innerHTML = `
                    <img src="${thumb}" alt="${safeTitle.replace(/"/g, '&quot;')}" />
                    <h3>${safeTitle}</h3>
                    <p>${meta}</p>
                    <div class="gallery-actions"></div>
                `;

                const actions = div.querySelector('.gallery-actions');
                const openBtn = document.createElement('button');
                openBtn.className = 'download-btn';
                openBtn.textContent = 'Open';
                openBtn.addEventListener('click', () => downloadFromGallery(item));

                const deleteBtn = document.createElement('button');
                deleteBtn.className = 'delete-btn';
                deleteBtn.textContent = 'Delete';
                deleteBtn.addEventListener('click', () => deleteFromGallery(item.videoId || '', item.localFile || ''));

                actions.appendChild(openBtn);
                actions.appendChild(deleteBtn);
                grid.appendChild(div);
            });

            container.innerHTML = '';
            container.appendChild(grid);
        }

        function downloadFromGallery(item) {
            if (item.localFile) {
                window.open(`?action=file&name=${encodeURIComponent(item.localFile)}`, '_blank');
                return;
            }
            if (item.url) {
                document.getElementById('url-input').value = item.url;
                getVideoInfo();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        async function deleteFromGallery(videoId, localFile) {
            if (!confirm('Delete this download from history and local storage?')) return;
            try {
                const query = `?action=gallery&videoId=${encodeURIComponent(videoId || '')}&localFile=${encodeURIComponent(localFile || '')}`;
                await fetch(query, { method: 'DELETE' });
                fetchGallery();
            } catch (e) {
                console.error('Error deleting', e);
            }
        }
    </script>
</body>
</html>
