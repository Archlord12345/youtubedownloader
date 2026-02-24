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
    $exists = false;
    foreach ($downloads as $d) {
        if ($d['videoId'] === $videoInfo['videoId']) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        array_unshift($downloads, $videoInfo);
        file_put_contents($downloadsFile, json_encode($downloads, JSON_PRETTY_PRINT));
    }
}


function sanitizeFileName($name) {
    $clean = preg_replace('/[^a-zA-Z0-9-_\. ]/', '', $name);
    $clean = trim($clean);
    return $clean !== '' ? $clean : 'video';
}

function guessExtensionFromContentType($contentType, $fallback = 'mp4') {
    $map = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/webm' => 'webm',
        'audio/mp4' => 'm4a',
    ];

    foreach ($map as $type => $ext) {
        if (stripos($contentType, $type) !== false) {
            return $ext;
        }
    }

    return $fallback;
}

function downloadRemoteFile($sourceUrl, $targetPath) {
    $fh = fopen($targetPath, 'wb');
    if (!$fh) {
        return ['ok' => false, 'error' => 'Cannot create local file'];
    }

    $ch = curl_init($sourceUrl);
    curl_setopt($ch, CURLOPT_FILE, $fh);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; YouTubeDownloader/1.0)');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if (!$ok || $httpCode < 200 || $httpCode >= 300) {
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        return [
            'ok' => false,
            'error' => $curlError ?: ('HTTP ' . $httpCode),
            'httpCode' => $httpCode,
            'contentType' => $contentType,
        ];
    }

    return [
        'ok' => true,
        'httpCode' => $httpCode,
        'contentType' => $contentType,
        'size' => filesize($targetPath),
    ];
}

function requestCobalt(array $payload) {
    $defaultEndpoint = 'https://api.cobalt.tools/api/json';
    $configured = getenv('COBALT_API_URL');

    $endpoints = [];
    if ($configured) {
        $endpoints[] = $configured;
    }
    $endpoints[] = $defaultEndpoint;

    $lastAttempt = [
        'httpCode' => 0,
        'curlError' => '',
        'raw' => '',
        'endpoint' => $defaultEndpoint
    ];

    foreach ($endpoints as $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; YouTubeDownloader/1.0; +https://example.com)'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        $curlError = curl_error($ch);
        curl_close($ch);

        $lastAttempt = [
            'httpCode' => $httpCode,
            'curlError' => $curlError,
            'raw' => $raw ?: '',
            'endpoint' => $endpoint,
            'contentType' => $contentType,
        ];

        if ($httpCode >= 200 && $httpCode < 300 && $raw) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return [
                    'ok' => true,
                    'data' => $decoded,
                    'meta' => $lastAttempt,
                ];
            }
        }
    }

    return [
        'ok' => false,
        'meta' => $lastAttempt,
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

    if ($title && $thumbnail) {
        saveDownload([
            'videoId' => $videoId,
            'title' => $title,
            'thumbnail' => $thumbnail,
            'url' => $url,
            'downloadedAt' => date('c')
        ]);
    }

    $isAudio = ($itag === 'audio');

    $payload = [
        'url' => $url,
        'vQuality' => '1080',
        'vCodec' => 'h264',
        'filenameStyle' => 'pretty'
    ];
    
    if ($isAudio) {
        $payload['isAudioOnly'] = true;
    }

    $cobaltResponse = requestCobalt($payload);
    $res = $cobaltResponse['meta']['raw'] ?? '';
    $httpCode = $cobaltResponse['meta']['httpCode'] ?? 0;
    $curlError = $cobaltResponse['meta']['curlError'] ?? '';
    $endpoint = $cobaltResponse['meta']['endpoint'] ?? 'https://api.cobalt.tools/api/json';
    $contentType = $cobaltResponse['meta']['contentType'] ?? '';
    
    if ($cobaltResponse['ok']) {
        $result = $cobaltResponse['data'];
        if (isset($result['url'])) {
            $directUrl = $result['url'];
            $safeTitle = sanitizeFileName($title ?: 'video');
            $fallbackExt = $isAudio ? 'mp3' : 'mp4';
            $provisionalPath = $downloadsDir . '/' . $safeTitle . '-' . $videoId . '.' . $fallbackExt;

            $downloadResult = downloadRemoteFile($directUrl, $provisionalPath);
            if (!$downloadResult['ok']) {
                die('Failed to store file in downloads folder: ' . htmlspecialchars($downloadResult['error']));
            }

            $realExt = guessExtensionFromContentType($downloadResult['contentType'] ?? '', $fallbackExt);
            $finalPath = $downloadsDir . '/' . $safeTitle . '-' . $videoId . '.' . $realExt;
            if ($finalPath !== $provisionalPath) {
                @rename($provisionalPath, $finalPath);
            }

            if (file_exists($finalPath)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($finalPath) . '"');
                header('Content-Length: ' . filesize($finalPath));
                readfile($finalPath);
                exit;
            }

            die('File downloaded but could not be served.');
        } elseif (isset($result['text'])) {
             die("Cobalt Error: " . $result['text']);
        }
    }
    
    $msg = "Failed to retrieve download link.<br>";
    $msg .= "Endpoint: " . htmlspecialchars($endpoint) . "<br>";
    if ($curlError) $msg .= "CURL Error: " . $curlError . "<br>";
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        $msg .= "The API returned HTML instead of JSON (often Cloudflare/WAF protection). "
              . "Set COBALT_API_URL to your own Cobalt instance or another reachable mirror.<br>";
    }
    if ($res) {
        $errorData = json_decode($res, true);
        if (isset($errorData['text'])) $msg .= "API Error: " . $errorData['text'];
        else if (isset($errorData['message'])) $msg .= "API Error: " . $errorData['message'];
        else $msg .= "Raw Response: " . htmlspecialchars(substr($res, 0, 200));
    } else {
        $msg .= "HTTP Code: " . $httpCode;
    }
    
    die($msg . "<br><br>You can try again later or check if the URL is correct.");
}

if ($action === 'gallery') {
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $videoId = $_GET['videoId'] ?? '';
        $downloads = getDownloads();
        $downloads = array_filter($downloads, function($d) use ($videoId) {
            return $d['videoId'] !== $videoId;
        });
        file_put_contents($downloadsFile, json_encode(array_values($downloads), JSON_PRETTY_PRINT));
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(getDownloads());
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
        :root {
            font-family: Inter, system-ui, Avenir, Helvetica, Arial, sans-serif;
            line-height: 1.5;
            font-weight: 400;
        }
        body {
            margin: 0;
            padding: 0;
            background-color: #ffffff;
            color: #213547;
        }
        #root {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }
        .container {
            text-align: center;
        }
        h1 {
            color: #ff0000;
            margin-bottom: 2rem;
        }
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 2rem;
        }
        .search-box input {
            flex: 1;
            padding: 12px 16px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 8px;
            outline: none;
            transition: border-color 0.2s;
        }
        .search-box input:focus {
            border-color: #ff0000;
        }
        .search-box button {
            padding: 12px 24px;
            font-size: 16px;
            background: #ff0000;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .search-box button:hover:not(:disabled) {
            background: #cc0000;
        }
        .search-box button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .error {
            color: #d32f2f;
            background: #ffebee;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .video-info {
            background: #f5f5f5;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        .thumbnail {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .video-info h2 {
            margin: 1rem 0;
            font-size: 1.2rem;
        }
        .formats {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-top: 1.5rem;
        }
        .download-btn {
            padding: 12px 20px;
            font-size: 14px;
            background: #4caf50;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .download-btn:hover {
            background: #388e3c;
        }
        .delete-btn {
            padding: 8px 16px;
            font-size: 12px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .delete-btn:hover {
            background: #d32f2f;
        }
        .gallery {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid #eee;
        }
        .gallery h2 {
            color: #333;
            margin-bottom: 1.5rem;
        }
        .no-videos {
            color: #888;
            font-style: italic;
        }
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        .gallery-item {
            background: white;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .gallery-item img {
            width: 100%;
            border-radius: 8px;
            aspect-ratio: 16/9;
            object-fit: cover;
        }
        .gallery-item h3 {
            font-size: 14px;
            margin: 10px 0 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .gallery-item p {
            font-size: 12px;
            color: #888;
            margin: 0 0 10px;
        }
        .gallery-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
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
                <h2>Downloaded Videos</h2>
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
                
                div.innerHTML = `
                    <img src="${item.thumbnail}" alt="${item.title.replace(/"/g, '&quot;')}" />
                    <h3>${item.title}</h3>
                    <p>${new Date(item.downloadedAt).toLocaleDateString()}</p>
                    <div class="gallery-actions">
                        <button class="download-btn" onclick="downloadFromGallery('${item.url}')">Download</button>
                        <button class="delete-btn" onclick="deleteFromGallery('${item.videoId}')">Delete</button>
                    </div>
                `;
                grid.appendChild(div);
            });

            container.innerHTML = '';
            container.appendChild(grid);
        }

        function downloadFromGallery(url) {
            document.getElementById('url-input').value = url;
            getVideoInfo();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        async function deleteFromGallery(videoId) {
            if (!confirm('Are you sure you want to delete this from your history?')) return;
            try {
                await fetch(`?action=gallery&videoId=${encodeURIComponent(videoId)}`, { method: 'DELETE' });
                fetchGallery();
            } catch (e) {
                console.error('Error deleting', e);
            }
        }
    </script>
</body>
</html>
