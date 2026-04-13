# @title 🚀 BACKEND SERVER V3.9 (Cloudflare Tunnel - No Ngrok)
!pip install flask flask-cors pydub pycryptodome requests --quiet
!wget -q https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -O /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared


import os, time, json, base64, io, hashlib, binascii, logging, re, threading, uuid
from math import ceil
from flask import Flask, request, jsonify
from flask_cors import CORS
import subprocess
from Crypto.Cipher import AES
from pydub import AudioSegment, effects
import requests
from datetime import timedelta
from math import ceil

# ================= CONFIGURATION =================
INTERNAL_SECRET = 'xi-labs-v2-secure-key-2026'
FIREBASE_API_KEY = 'AIzaSyBSsRE_1Os04-bxpd5JTLIniy3UK4OqKys'

# PHP Backend URL
PHP_BACKEND_URL = "https://11labs.id.vn"
UPDATE_SECRET = "5nl7gYxSm2XTqTGR"

MAX_CHUNK = 4500
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app)

# ===== JOB QUEUE: Chỉ 1 job chạy tại 1 thời điểm trên mỗi worker =====
job_semaphore = threading.Semaphore(1)  # Giới hạn 1 job concurrent
job_queue_count = 0                     # Số job đang chờ trong hàng
job_queue_lock = threading.Lock()
dubbing_in_progress = False             # Cờ: đang chạy dubbing → từ chối TTS

# ================= LOGGING HELPERS =================
def log_to_backend(message, job_id=None, level='info'):
    """Send a real-time log event to the PHP backend"""
    try:
        payload = {
            'secret': UPDATE_SECRET,
            'worker_uuid': WORKER_UUID,
            'worker_name': WORKER_NAME,
            'job_id': job_id,
            'message': message,
            'level': level
        }
        requests.post(f"{PHP_BACKEND_URL}/api/log_worker_event.php", json=payload, timeout=5, verify=False)
    except:
        pass

# ================= CORE LOGIC =================
def decrypt_key(text):
    text = text.strip()
    if not text.startswith('enc:'): return text
    try:
        parts = text.split(':')
        if len(parts) < 3: return text
        p1, p2 = parts[1], parts[2]
        if len(p1) == 32 and all(c in '0123456789abcdefABCDEF' for c in p1):
            iv_hex, ct_raw = p1, p2
        else:
            ct_raw, iv_hex = p1, p2
        iv = binascii.unhexlify(iv_hex)
        if all(c in '0123456789abcdefABCDEF' for c in ct_raw):
            ct = binascii.unhexlify(ct_raw)
        else:
            ct = base64.b64decode(ct_raw)
        key = hashlib.sha256(INTERNAL_SECRET.encode()).digest()
        cipher = AES.new(key, AES.MODE_CBC, iv)
        d = cipher.decrypt(ct)
        return d[:-d[-1]].decode('utf-8')
    except: return text

def login_with_firebase(email, password):
    url = f'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key={FIREBASE_API_KEY}'
    h = {
        'Content-Type': 'application/json',
        'Origin': 'https://elevenlabs.io',
        'Referer': 'https://elevenlabs.io',
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    }
    try:
        res = requests.post(url, json={'email': email, 'password': password, 'returnSecureToken': True}, headers=h, timeout=15, verify=False)
        if res.ok: return res.json().get('idToken')
        print(f"⚠️ Firebase login error for {email}: {res.status_code} {res.text[:200]}")
    except Exception as e:
        print(f"⚠️ Firebase login exception for {email}: {e}")
    return None

def call_api_tts(text, voice_id, api_key, model_id, previous_text=None, voice_settings=None):
    url = f"https://api.elevenlabs.io/v1/text-to-speech/{voice_id}/with-timestamps"
    headers = {"Content-Type": "application/json", "Accept": "*/*"}
    if api_key.startswith("ey") or len(api_key) > 100:
        headers["Authorization"] = f"Bearer {api_key}"
    else:
        headers["xi-api-key"] = api_key

    # V3 models benefit from higher stability to prevent accent drift
    is_v3 = model_id and 'v3' in model_id.lower()
    default_stability = 0.65 if is_v3 else 0.5
    settings = {"stability": default_stability, "similarity_boost": 0.75}
    if voice_settings and isinstance(voice_settings, dict):
        if 'stability' in voice_settings: settings['stability'] = voice_settings['stability']
        if 'similarity' in voice_settings: settings['similarity_boost'] = voice_settings['similarity']
        if 'speed' in voice_settings: settings['speed'] = max(0.7, min(1.2, float(voice_settings['speed'])))  # ElevenLabs: 0.7-1.2

    payload = {"text": text, "model_id": model_id, "voice_settings": settings}
    if previous_text: payload["previous_text"] = previous_text


    # Timeout 150s cho tất cả — ElevenLabs có thể chậm bất kể ngôn ngữ
    api_timeout = 150
    response = requests.post(url, json=payload, headers=headers, timeout=api_timeout, verify=False)
    if response.status_code == 200: return response.json()
    raise Exception(f"API Error {response.status_code}: {response.text}")

def call_api_isolation(audio_path, api_key):
    url = "https://api.elevenlabs.io/v1/audio-isolation"
    headers = {"Accept": "audio/mpeg"}
    if api_key.startswith("ey") or len(api_key) > 100:
        headers["Authorization"] = f"Bearer {api_key}"
    else:
        headers["xi-api-key"] = api_key
    
    with open(audio_path, 'rb') as f:
        files = {"audio": (os.path.basename(audio_path), f, "audio/mpeg")}
        response = requests.post(url, headers=headers, files=files, timeout=300, verify=False)
    
    if response.status_code == 200:
        return response.content
    raise Exception(f"Isolation API Error {response.status_code}: {response.text}")

def call_api_sfx(prompt, api_key, duration_seconds=None, loop=False, prompt_influence=0.3):
    """Call ElevenLabs Sound Effects API. Returns audio bytes (mp3)."""
    url = "https://api.elevenlabs.io/v1/sound-generation"
    headers = {"Accept": "audio/mpeg", "Content-Type": "application/json"}
    if api_key.startswith("ey") or len(api_key) > 100:
        headers["Authorization"] = f"Bearer {api_key}"
    else:
        headers["xi-api-key"] = api_key

    payload = {
        "text": prompt,
        "prompt_influence": prompt_influence,
    }
    # duration_seconds=0 means Auto (let ElevenLabs decide)
    if duration_seconds and duration_seconds > 0:
        payload["duration_seconds"] = duration_seconds
    if loop:
        payload["loop"] = True

    response = requests.post(url, json=payload, headers=headers, timeout=120, verify=False)
    if response.status_code == 200:
        return response.content
    raise Exception(f"SFX API Error {response.status_code}: {response.text[:300]}")

def call_api_stt(audio_path, api_key):
    """Call ElevenLabs Speech-to-Text API. Returns transcription dict."""
    url = "https://api.elevenlabs.io/v1/speech-to-text"
    headers = {}
    if api_key.startswith("ey") or len(api_key) > 100:
        headers["Authorization"] = f"Bearer {api_key}"
    else:
        headers["xi-api-key"] = api_key

    with open(audio_path, 'rb') as f:
        files = {"file": (os.path.basename(audio_path), f)}
        data = {"model_id": "scribe_v2", "tag_audio_events": "false", "diarize": "false", "timestamps_granularity": "word"}
        response = requests.post(url, headers=headers, files=files, data=data, timeout=600, verify=False)

    if response.status_code == 200:
        return response.json()
    raise Exception(f"STT API Error {response.status_code}: {response.text[:300]}")

def call_api_voice_changer(audio_path, voice_id, api_key):
    url = f"https://api.elevenlabs.io/v1/speech-to-speech/{voice_id}"
    headers = {"Accept": "audio/mpeg"}
    if api_key.startswith("ey") or len(api_key) > 100:
        headers["Authorization"] = f"Bearer {api_key}"
        headers["Origin"] = "https://elevenlabs.io"
        headers["Referer"] = "https://elevenlabs.io/"
    else:
        headers["xi-api-key"] = api_key

    with open(audio_path, 'rb') as f:
        files = {"audio": (os.path.basename(audio_path), f, "audio/mpeg")}
        data = {"model_id": "eleven_multilingual_sts_v2"}
        response = requests.post(url, headers=headers, files=files, data=data, timeout=300, verify=False)

    if response.status_code == 200:
        return response.content
    raise Exception(f"Voice Changer API Error {response.status_code}: {response.text[:300]}")

def call_api_music(prompt, duration_seconds, api_key):
    # Map to the LOWEST ElevenLabs tier that covers the request (save credits!)
    # ElevenLabs fixed tiers: 30s, 60s, 120s, 240s
    if duration_seconds <= 30:
        duration_ms = 30000
    elif duration_seconds <= 60:
        duration_ms = 60000
    elif duration_seconds <= 120:
        duration_ms = 120000
    else:
        duration_ms = 240000
    print(f"🎵 Duration mapping: {duration_seconds}s → tier {duration_ms//1000}s")

    # ─── Bearer token (Firebase login) → use internal /v1/music/chats ───
    if api_key.startswith("ey") or len(api_key) > 100:
        base_url = "https://api.us.elevenlabs.io"
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": f"Bearer {api_key}",
            "Origin": "https://elevenlabs.io",
            "Referer": "https://elevenlabs.io/app/music",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
        }

        # Step 1: Create music chat (exact website payload)
        chat_payload = {
            "model_id": "music_v1",
            "prompt": prompt,
            "song_length_ms": duration_ms,
            "n_variants": 1,
            "user_intent": {"type": "unknown"},
            "force_instrumental": False
        }
        print(f"📤 Creating music chat with song_length_ms={duration_ms} ({duration_seconds}s)")
        res = requests.post(f"{base_url}/v1/music/chats", json=chat_payload, headers=headers, timeout=60, verify=False)
        if res.status_code != 200:
            raise Exception(f"Music Chat Create Error {res.status_code}: {res.text[:300]}")

        chat_data = res.json()
        chat_id = chat_data.get("id")
        print(f"🎵 Music chat created: {chat_id}")

        # Step 2: Extract song_id from the response
        song_id = None
        # Try to get song_id from songs_updates or songs in the response
        songs_updates = chat_data.get("songs_updates", [])
        if not songs_updates:
            # Check messages → variants → songs
            for msg in chat_data.get("messages", []):
                if isinstance(msg, dict):
                    for v in msg.get("variants", []):
                        if isinstance(v, dict) and v.get("id"):
                            song_id = v["id"]
                            break
                if song_id:
                    break
            # Also check songs list
            for song in chat_data.get("songs", []):
                if isinstance(song, dict) and song.get("id"):
                    song_id = song["id"]
                    break
        else:
            for su in songs_updates:
                if isinstance(su, dict) and su.get("id"):
                    song_id = su["id"]
                    break

        if not song_id:
            # Fallback: search response for any id field
            response_str = json.dumps(chat_data)
            print(f"📋 Full response (looking for song_id): {response_str[:800]}")
            # Try regex for song IDs
            import re as _re
            ids = _re.findall(r'"id"\s*:\s*"([a-zA-Z0-9]+)"', response_str)
            if len(ids) >= 2:
                song_id = ids[1]  # First id is chat_id, second should be song_id
            elif ids:
                song_id = ids[0]

        print(f"🎶 Song ID: {song_id}")

        # Step 3: Poll song status via /v1/music/songs/{song_id}/status
        print(f"⏳ Waiting for music generation (duration={duration_seconds}s)...")
        max_polls = 90  # 90 * 5s = 450s = 7.5 min
        download_url = None
        
        for i in range(max_polls):
            time.sleep(5)
            try:
                if song_id:
                    # Use the correct song status endpoint (like website does)
                    status_res = requests.get(f"{base_url}/v1/music/songs/{song_id}/status",
                                              headers=headers, timeout=30, verify=False)
                else:
                    # Fallback to chat endpoint
                    status_res = requests.get(f"{base_url}/v1/music/chats/{chat_id}",
                                              headers=headers, timeout=30, verify=False)

                if i < 3 or i % 10 == 0:
                    print(f"📋 Poll #{i}: status={status_res.status_code}, body={status_res.text[:400]}")

                if not status_res.ok:
                    continue

                # Handle SSE format: "event: status_update\ndata: {...}"
                raw_text = status_res.text.strip()
                json_str = raw_text
                if 'data:' in raw_text:
                    # Extract JSON from SSE data: line
                    for line in raw_text.split('\n'):
                        line = line.strip()
                        if line.startswith('data:'):
                            json_str = line[5:].strip()
                            break

                try:
                    status_data = json.loads(json_str)
                except:
                    print(f"⚠️ Poll error #{i}: could not parse: {raw_text[:200]}")
                    continue
                
                if isinstance(status_data, dict):
                    state = status_data.get("status", "")
                    progress = status_data.get("progress", "")
                    
                    if state == "finished" or state == "completed":
                        print(f"✅ Song {song_id} is {state}!")
                        print(f"📋 Status data keys: {list(status_data.keys())}")
                        print(f"📋 Status data: {json.dumps(status_data)[:600]}")
                        
                        # Try to get from the song data directly
                        audio_url = status_data.get("download_url") or status_data.get("audio_url") or status_data.get("url")
                        if audio_url:
                            download_url = audio_url
                            break
                        
                        # Try construct URL from workspace_id (exact GCS pattern)
                        ws_id = status_data.get("workspace_id")
                        sid = status_data.get("id") or song_id
                        if ws_id and sid:
                            download_url = f"https://storage.googleapis.com/xi-backend/database/workspace/{ws_id}/music/{sid}/generated_task.mp4"
                            print(f"📥 Constructed GCS URL: {download_url}")
                            break
                        
                        # If no direct URL, fetch the full chat to find GCS links
                        chat_res = requests.get(f"{base_url}/v1/music/chats/{chat_id}",
                                                headers=headers, timeout=30, verify=False)
                        if chat_res.ok:
                            # Handle possible SSE format in chat response too
                            chat_text = chat_res.text.strip()
                            chat_json_str = chat_text
                            if 'data:' in chat_text:
                                for cline in chat_text.split('\n'):
                                    cline = cline.strip()
                                    if cline.startswith('data:'):
                                        chat_json_str = cline[5:].strip()
                                        break
                            try:
                                chat_full = json.loads(chat_json_str)
                            except:
                                chat_full = {}
                            
                            full_str = json.dumps(chat_full) if chat_full else chat_text
                            print(f"📋 Chat data keys: {list(chat_full.keys()) if isinstance(chat_full, dict) else 'not dict'}")
                            
                            # Search for GCS URLs
                            import re as _re
                            urls = _re.findall(r'https://storage\.googleapis\.com/[^\s"\']+', full_str)
                            if urls:
                                download_url = urls[0]
                                break
                            # Check audio_url in songs
                            if isinstance(chat_full, dict):
                                for song in chat_full.get("songs", []):
                                    if isinstance(song, dict):
                                        u = song.get("audio_url") or song.get("url") or song.get("media_url")
                                        if u:
                                            download_url = u
                                            break
                                # Check workspace_id from chat
                                if not download_url:
                                    for song in chat_full.get("songs", []):
                                        ws = song.get("workspace_id")
                                        sid = song.get("id")
                                        if ws and sid:
                                            download_url = f"https://storage.googleapis.com/xi-backend/database/workspace/{ws}/music/{sid}/generated_task.mp4"
                                            break
                            if download_url:
                                break
                            print(f"📋 Full chat: {full_str[:600]}")
                    
                    elif state == "failed" or state == "error":
                        raise Exception(f"Music generation failed: {status_data.get('error', state)}")
                    
                    elif i % 6 == 0:
                        print(f"⏳ Music generating... poll #{i+1}, state={state}, progress={progress}")

                # (GCS URL search is now only done when status==completed above)

            except Exception as poll_err:
                err_msg = str(poll_err)
                if "failed" in err_msg.lower() or "Music generation" in err_msg:
                    raise
                print(f"⚠️ Poll error #{i}: {poll_err}")

        if not download_url:
            raise Exception(f"Music generation timed out after {max_polls*5}s for chat {chat_id}")

        # Step 3: Download the generated audio
        logger.info(f"📥 Downloading music from signed URL...")
        dl_headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Referer": "https://elevenlabs.io/"
        }
        dl_res = requests.get(download_url, headers=dl_headers, timeout=120, stream=True, verify=False)
        if dl_res.status_code not in (200, 206):
            raise Exception(f"Download Error {dl_res.status_code}")

        audio_chunks = []
        for chunk in dl_res.iter_content(chunk_size=8192):
            if chunk:
                audio_chunks.append(chunk)
        audio_data = b"".join(audio_chunks)

        # Return raw audio data (MP4/AAC from ElevenLabs)
        # Conversion to MP3 + trimming is handled by music_worker() 
        return audio_data

    else:
        # ─── Developer xi-api-key (sk_...) → public API (paid only) ───
        url = "https://api.elevenlabs.io/v1/music/stream"
        headers = {
            "Content-Type": "application/json",
            "Accept": "audio/mpeg",
            "xi-api-key": api_key
        }
        payload = {
            "prompt": prompt,
            "music_length_ms": duration_ms,
            "model_id": "music_v1",
            "output_format": "mp3_44100_128"
        }
        response = requests.post(url, json=payload, headers=headers, timeout=600, stream=True, verify=False)
        if response.status_code == 200:
            audio_chunks = []
            for chunk in response.iter_content(chunk_size=8192):
                if chunk:
                    audio_chunks.append(chunk)
            return b"".join(audio_chunks)
        raise Exception(f"Music API Error {response.status_code}: {response.text[:300]}")

def get_api_credits(api_key):
    """Fetch actual character balance from ElevenLabs"""
    try:
        url = "https://api.elevenlabs.io/v1/user/subscription"
        headers = {"Accept": "application/json"}
        if api_key.startswith("ey") or len(api_key) > 100:
            headers["Authorization"] = f"Bearer {api_key}"
            headers["Origin"] = "https://elevenlabs.io"
            headers["Referer"] = "https://elevenlabs.io/"
        else:
            headers["xi-api-key"] = api_key
        
        res = requests.get(url, headers=headers, timeout=10, verify=False)
        if res.ok:
            data = res.json()
            return data['character_limit'] - data['character_count']
    except Exception as e:
        logger.error(f"Error fetching credits: {e}")
    return None

def smart_split(text, max_len=MAX_CHUNK):
    text = re.sub(r'\s+', ' ', text).strip()
    chunks, current = [], text
    while current:
        if len(current) <= max_len:
            chunks.append(current)
            break
        # V17.13: Support CJK punctuation for splitting (Japanese: 。！？, Korean: 。 etc)
        split_idx = -1
        # Try Latin period first
        split_idx = current.rfind('. ', 0, max_len)
        # Try CJK sentence-ending punctuation
        if split_idx == -1:
            for punct in ['。', '！', '？', '！', '？']:
                idx = current.rfind(punct, 0, max_len)
                if idx > split_idx:
                    split_idx = idx + 1  # Include the punctuation in current chunk
        # Try CJK comma as last resort before space
        if split_idx == -1:
            for punct in ['、', '，']:
                idx = current.rfind(punct, 0, max_len)
                if idx > split_idx:
                    split_idx = idx + 1
        if split_idx <= 0: split_idx = current.rfind(' ', 0, max_len)
        if split_idx <= 0: split_idx = max_len
        chunks.append(current[:split_idx].strip())
        current = current[split_idx:].strip()
    return [c for c in chunks if c]

def get_last_words(text, word_count=15):
    """Get last N words from text for V3 overlap context (matches exe tool's getLastSentence)"""
    if not text:
        return ''
    words = text.strip().split()
    if len(words) <= word_count:
        return text.strip()
    return ' '.join(words[-word_count:])

def is_tonal_language(text):
    """Detect if text is a non-Latin language that requires smaller chunks on ElevenLabs.
    Instead of whitelisting specific scripts (and missing some like Korean was),
    we flip the logic: if text is primarily Latin → fast, otherwise → heavy (cap 1500).

    Latin-based (fast): English, French, Spanish, German, Italian, Portuguese, Polish, etc.
    Non-Latin (heavy): Korean, Japanese, Chinese, Vietnamese, Arabic, Hindi, Russian,
                        Thai, Greek, Hebrew, Bengali, and ALL other non-Latin scripts.

    Vietnamese uses Latin script BUT its extended chars (ắ,ằ,ẩ,ế,ệ,ố...) fall in
    Unicode 0x1E00+ (outside our Latin range 0x0000-0x024F), so it's correctly detected as heavy."""
    if not text:
        return False
    # Sample first 500 chars for performance
    sample = text[:500]
    latin_count = 0
    total_alpha = 0
    for ch in sample:
        if ch.isalpha():
            total_alpha += 1
            cp = ord(ch)
            # Basic Latin (A-Z, a-z) + Latin Extended-A + Latin Extended-B
            # Covers: English, French, Spanish, German, Italian, Portuguese,
            #         Polish, Czech, Romanian, Turkish, Hungarian, etc.
            # Does NOT cover: Vietnamese Extended Additional (0x1E00+),
            #                 Cyrillic, Greek, Arabic, CJK, Hangul, Thai, etc.
            if cp <= 0x024F:
                latin_count += 1
    if total_alpha == 0:
        return False
    # If less than 85% of alphabetic chars are basic Latin → heavy language
    return (latin_count / total_alpha) < 0.85

def strip_audio_tags(text):
    """Remove audio/emotion tags like [pause], [laughs], [whispers], [excited] etc. from text for clean SRT output"""
    cleaned = re.sub(r'\[(?:pause|short pause|long pause|dramatic pause|laughs|whispers|sighs|coughs|happy|sad|angry|excited|calm|slowly|fast|soft|loud|breath|gasp|clears throat)[^\]]*\]', '', text, flags=re.IGNORECASE)
    # Clean up extra spaces left behind
    cleaned = re.sub(r'  +', ' ', cleaned).strip()
    return cleaned

def generate_srt(all_alignments):
    """Convert ElevenLabs alignment data to SRT subtitle format with sentence-aware grouping"""
    if not all_alignments:
        return ""

    srt_lines = []
    counter = 1

    # 1. Flatten all characters and timings into single lists
    full_chars = []
    full_starts = []
    full_ends = []

    for alignment in all_alignments:
        chars = alignment.get('characters', [])
        start_times = alignment.get('character_start_times_seconds', [])
        end_times = alignment.get('character_end_times_seconds', [])
        offset = alignment.get('offset', 0)

        if not chars: continue

        # Extend lists - ensuring equal lengths
        min_len = min(len(chars), len(start_times), len(end_times))
        full_chars.extend(chars[:min_len])
        full_starts.extend([t + offset for t in start_times[:min_len]])
        full_ends.extend([t + offset for t in end_times[:min_len]])

    full_text = "".join(full_chars)

    # 2. Split into sentences using regex (logic from queueService.ts)
    # Matches any character until a sentence ending punctuation (.!?) followed by space or end of string.
    # For CJK (Chinese/Japanese) punctuation (。！？), it doesn't require a trailing space.
    # We use m.span(1) to get the sentence part without the trailing space
    matches = list(re.finditer(r"(.+?(?:[.!?]+(?=\s|$)|[。！？]+))(\s*)", full_text, flags=re.DOTALL))

    last_idx = 0

    def fmt_time(t):
        h = int(t // 3600)
        m = int((t % 3600) // 60)
        s = int(t % 60)
        ms = int((t % 1) * 1000)
        return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"

    def add_srt_entry(text, start, end):
        nonlocal counter
        clean_text = strip_audio_tags(text)
        if not clean_text:
            return
        srt_lines.append(f"{counter}")
        srt_lines.append(f"{fmt_time(start)} --> {fmt_time(end)}")
        srt_lines.append(clean_text)
        srt_lines.append("")
        counter += 1

    # Process regex matches
    for match in matches:
        start_char_idx, end_char_idx = match.span(1)

        # If there's a gap between last match and this one (e.g. non-punctuated text), handle it
        if start_char_idx > last_idx:
            segment_text = full_text[last_idx:start_char_idx].strip()
            if segment_text:
                s_t = full_starts[last_idx]
                e_t = full_ends[start_char_idx - 1]
                add_srt_entry(segment_text, s_t, e_t)

        # Add the matched sentence
        sentence_text = match.group(1).strip()
        if sentence_text:
            s_t = full_starts[start_char_idx]
            # end_char_idx is exclusive in python slice, so last char is end_char_idx-1
            e_t = full_ends[end_char_idx - 1]
            add_srt_entry(sentence_text, s_t, e_t)

        last_idx = match.end() # Move past the full match including trailing space

    # Handle any remaining text after the last sentence
    if last_idx < len(full_chars):
        segment_text = full_text[last_idx:].strip()
        if segment_text:
            s_t = full_starts[last_idx]
            e_t = full_ends[-1]
            add_srt_entry(segment_text, s_t, e_t)

    # Fallback: If no matches were found at all (e.g. no punctuation), treat whole text as one line
    if not srt_lines and full_text.strip():
        add_srt_entry(full_text.strip(), full_starts[0], full_ends[-1])

    return "\n".join(srt_lines)

# ================= CONVERSATION JOB =================
def process_conversation_job(job_id, conv_data_json, valid_accounts, model_id, php_backend, voice_settings=None):
    """Process a multi-speaker conversation job.
    conv_data_json is a JSON string with: {lines: [{speaker, text}], speakers: {A: {voice_id}}, pause_duration: 0.5}
    """
    try:
        import json as _json
        conv_data = _json.loads(conv_data_json)
        lines = conv_data.get('lines', [])
        speakers = conv_data.get('speakers', {})
        pause_ms = int(float(conv_data.get('pause_duration', 0.5)) * 1000)

        audio_segments = []
        current_acc_idx = 0
        _last_logged_key_idx = -1

        def report_progress(processed, status="processing", total=None):
            if not job_id or not php_backend: return
            payload = {'action': 'update', 'job_id': job_id, 'processed_chunks': processed, 'status': status}
            if total: payload['total_chunks'] = total
            try: requests.post(f"{php_backend}/api/progress.php", json=payload, timeout=10, verify=False)
            except: pass

        report_progress(0, "processing", total=len(lines))
        first_key_id = valid_accounts[0].get('id', '?') if valid_accounts else '?'
        key_usage_counter[first_key_id] = key_usage_counter.get(first_key_id, 0) + 1
        log_to_backend(f"Bắt đầu Conversation Job {job_id} ({len(lines)} lượt nói) — Key: #{first_key_id} lần {key_usage_counter[first_key_id]} ({len(valid_accounts)} keys)", job_id=job_id)

        i = 0
        last_error = "Hết key khả dụng"
        while i < len(lines):
            if current_acc_idx >= len(valid_accounts):
                log_to_backend(f"🚫 Conversation {job_id}: Hết key. Nhả Job về hàng chờ...", job_id=job_id, level='error')
                report_progress(i, f"failed: {last_error}")
                return

            line = lines[i]
            speaker_id = line.get('speaker', 'A')
            line_text = line.get('text', '')
            speaker_voice_id = speakers.get(speaker_id, {}).get('voice_id', '')

            if not line_text or not speaker_voice_id or not re.sub(r'[^\w]', '', line_text):
                log_to_backend(f"⚠️ Skip line {i+1}: text is empty, punctuation-only, or no voice for {speaker_id}", job_id=job_id)
                audio_segments.append(AudioSegment.silent(duration=500))
                i += 1
                continue

            try:
                acc = valid_accounts[current_acc_idx]
                api_key = acc.get('key')
                if not api_key:
                    raise Exception(f"Account at index {current_acc_idx} has no 'key'")

                if current_acc_idx != _last_logged_key_idx:
                    cur_key_id = acc.get('id', '?')
                    key_usage_counter[cur_key_id] = key_usage_counter.get(cur_key_id, 0) + (1 if _last_logged_key_idx >= 0 else 0)
                    if _last_logged_key_idx >= 0:
                        log_to_backend(f"🔄 Đổi key: #{cur_key_id} lần {key_usage_counter[cur_key_id]} (key thứ {current_acc_idx+1}/{len(valid_accounts)})", job_id=job_id)
                    _last_logged_key_idx = current_acc_idx

                # Split long lines into chunks (max 2000 chars each)
                line_chunks = smart_split(line_text)

                line_audio = AudioSegment.empty()
                for chunk_text in line_chunks:
                    res_data = call_api_tts(chunk_text, speaker_voice_id, api_key, model_id, voice_settings=voice_settings)

                    if not isinstance(res_data, dict):
                        raise Exception(f"API returned {type(res_data)} instead of dict")

                    if "audio_base64" not in res_data:
                        raise Exception(f"API response missing audio_base64. Keys: {list(res_data.keys())}")

                    audio_bytes = base64.b64decode(res_data["audio_base64"])
                    seg = AudioSegment.from_mp3(io.BytesIO(audio_bytes))
                    line_audio += seg

                audio_segments.append(line_audio)
                log_to_backend(f"✅ Line {i+1}/{len(lines)}: {speaker_id}> {line_text[:40]}...", job_id=job_id)
                report_progress(i + 1)

                # Sync credits (async)
                def _sync(key, key_id, backend):
                    try:
                        bal = get_api_credits(key)
                        if bal is not None:
                            requests.post(f"{backend}/api/progress.php",
                                          json={'action': 'sync_key', 'key_id': key_id, 'credits': bal},
                                          timeout=5, verify=False)
                    except: pass
                threading.Thread(target=_sync, args=(api_key, acc.get('id'), php_backend), daemon=True).start()

                i += 1
                time.sleep(1.0)  # Delay giữa mỗi request để tránh bị block IP

            except Exception as e:
                import traceback
                logger.error(f"Conversation {job_id} line {i}: {traceback.format_exc()}")
                last_error = str(e).split('\n')[0][:200]

                # Voice requires paid tier — stop immediately
                if "free_users_not_allowed" in last_error.lower():
                    fail_msg = "failed: Giọng nói này yêu cầu tài khoản ElevenLabs trả phí (Creator tier). Vui lòng chọn giọng khác."
                    log_to_backend(f"🚫 Conversation {job_id}: Voice yêu cầu paid tier. Dừng ngay.", job_id=job_id, level='error')
                    report_progress(i, fail_msg)
                    return

                # Voice not found — stop immediately, all keys will get same 404
                if "voice_not_found" in last_error.lower():
                    fail_msg = "failed: Voice ID không tồn tại hoặc đã bị xóa. Vui lòng chọn giọng khác."
                    log_to_backend(f"🚫 Conversation {job_id}: Voice không tồn tại. Dừng ngay.", job_id=job_id, level='error')
                    report_progress(i, fail_msg)
                    return

                acc_id = valid_accounts[current_acc_idx].get('id')
                if acc_id:
                    real_credits = get_api_credits(api_key)
                    payload = {'action': 'sync_key', 'key_id': acc_id}
                    if real_credits is not None:
                        payload['credits'] = real_credits

                    if "quota_exceeded" in last_error.lower() or "not have enough character" in last_error.lower():
                        pass
                    elif "detected_unusual_activity" in last_error.lower() or "too_many_concurrent" in last_error.lower():
                        payload['action'] = 'flag_key'
                        payload['reason'] = last_error
                    try:
                        requests.post(f"{php_backend}/api/progress.php", json=payload, timeout=5, verify=False)
                    except: pass

                current_acc_idx += 1

        # === Merge all audio with pauses ===
        final_audio = AudioSegment.empty()
        for idx, seg in enumerate(audio_segments):
            final_audio += seg
            if idx < len(audio_segments) - 1:
                final_audio += AudioSegment.silent(duration=pause_ms)

        out_io = io.BytesIO()
        final_audio.export(out_io, format='mp3', bitrate='64k')
        out_io.seek(0)

        log_to_backend(f"Hoàn thành Conversation {job_id}. Upload kết quả...", job_id=job_id)

        # Sync all used keys before completing
        sync_limit = min(current_acc_idx + 1, len(valid_accounts))
        for idx in range(sync_limit):
            acc = valid_accounts[idx]
            if acc.get('key') and acc.get('id'):
                try:
                    real_bal = get_api_credits(acc['key'])
                    if real_bal is not None:
                        requests.post(f"{php_backend}/api/progress.php",
                                      json={'action': 'sync_key', 'key_id': acc['id'], 'credits': real_bal},
                                      timeout=5, verify=False)
                except: pass

        # Upload result via complete.php (same as regular TTS)
        requests.post(f"{php_backend}/api/complete.php", json={
            'job_id': job_id,
            'audio_base64': base64.b64encode(out_io.read()).decode('utf-8'),
            'srt_content': ''  # No SRT for conversation
        }, timeout=120, verify=False)

    except Exception as e:
        error_msg = str(e)[:200]
        try:
            requests.post(f"{php_backend}/api/progress.php",
                          json={'action': 'update', 'job_id': job_id, 'processed_chunks': 0, 'status': f"failed: {error_msg}"},
                          timeout=10, verify=False)
        except: pass
        try:
            requests.post(f"{php_backend}/api/progress.php",
                          json={'action': 'worker_failed', 'worker_uuid': WORKER_UUID},
                          timeout=5, verify=False)
        except: pass

def process_job(job_id, text, valid_accounts, voice_id, model_id, php_backend, chunks, voice_settings=None, resume_from_chars=0, partial_audio_url=None, previous_chunk_context=''):
    try:
        audio_segments, all_alignments, cumulative_duration, current_acc_idx = [], [], 0, 0
        _last_logged_key_idx = -1  # Track which key was last logged to avoid spam
        previous_chunk_text = previous_chunk_context or ""  # Context overlap for seamless voice (like tool exe)
        v2_last_checked_key = -1  # V2 Dynamic: track which key was last credit-checked

        partial_audio_base = None  # Pre-existing audio from a previous worker (resume)
        total_processed_chars = resume_from_chars  # Track chars processed across workers

        # === RESUME: Download partial audio from previous worker ===
        if resume_from_chars > 0 and partial_audio_url:
            try:
                log_to_backend(f"📥 Resume: Downloading partial audio ({resume_from_chars} chars already done)", job_id=job_id)
                resp = requests.get(partial_audio_url, timeout=60, verify=False)
                if resp.status_code == 200:
                    partial_audio_base = AudioSegment.from_mp3(io.BytesIO(resp.content))
                    cumulative_duration = len(partial_audio_base) / 1000.0
                    log_to_backend(f"✅ Resume: Partial audio loaded ({len(partial_audio_base)}ms)", job_id=job_id)
                else:
                    log_to_backend(f"⚠️ Resume: Failed to download partial audio (HTTP {resp.status_code}). Starting fresh.", job_id=job_id, level='warning')
                    partial_audio_base = None
                    total_processed_chars = 0
            except Exception as e:
                log_to_backend(f"⚠️ Resume: Error downloading partial audio: {e}. Starting fresh.", job_id=job_id, level='warning')
                partial_audio_base = None
                total_processed_chars = 0

        def report_progress(processed, status="processing", total=None):
            if not job_id or not php_backend: return
            payload = {'action': 'update', 'job_id': job_id, 'processed_chunks': processed, 'status': status, 'worker_uuid': WORKER_UUID}
            if total: payload['total_chunks'] = total
            try: requests.post(f"{php_backend}/api/progress.php", json=payload, timeout=10, verify=False)
            except: pass

        # V17.10: Show model name in log for admin visibility
        model_short = model_id.replace('eleven_', '').replace('multilingual_', 'v').replace('turbo_', 'Turbo ').replace('flash_', 'Flash ') if model_id else 'N/A'
        report_progress(0, "processing", total=len(chunks))
        first_key_id = valid_accounts[0].get('id', '?') if valid_accounts else '?'
        key_usage_counter[first_key_id] = key_usage_counter.get(first_key_id, 0) + 1
        log_to_backend(f"Bắt đầu Job TTS {job_id} ({len(chunks)} chunks) — Model: {model_short} — Key: #{first_key_id} lần {key_usage_counter[first_key_id]} ({len(valid_accounts)} keys)", job_id=job_id)

        i = 0
        last_error = "Hết key khả dụng"
        total_timeout_count = 0  # Track total timeouts across all keys
        while i < len(chunks):
            # Bỏ qua các chunk chỉ chứa dấu câu (ví dụ: "....") để tránh lỗi 0-byte audio từ ElevenLabs làm crash xử lý ffmpeg
            if not re.sub(r'[^\w]', '', chunks[i]):
                log_to_backend(f"⚠️ Bỏ qua chunk {i+1}: Chỉ chứa dấu câu '{chunks[i]}', không có chữ", job_id=job_id)
                audio_segments.append(AudioSegment.silent(duration=500))
                cumulative_duration += 0.5
                i += 1
                report_progress(i)
                continue
                
            if current_acc_idx >= len(valid_accounts):
                # === HANDOFF: Release job for another worker ===
                if len(audio_segments) > 0:
                    try:
                        # Build partial audio from completed segments
                        partial_audio = AudioSegment.empty()
                        if partial_audio_base:
                            partial_audio = partial_audio_base
                        for s in audio_segments:
                            partial_audio += s + AudioSegment.silent(duration=300)
                        
                        partial_io = io.BytesIO()
                        partial_audio.export(partial_io, format='mp3', bitrate='64k')
                        partial_io.seek(0)
                        
                        # Calculate chars processed by THIS worker
                        chars_done_here = sum(len(chunks[j]) for j in range(i))
                        total_chars_done = resume_from_chars + chars_done_here
                        
                        log_to_backend(f"🔄 Hết key! Chuyển job sang server khác ({total_chars_done} chars đã xong, {len(chunks)-i} chunks còn lại)", job_id=job_id, level='warning')
                        
                        requests.post(f"{php_backend}/api/progress.php", json={
                            'action': 'release_job',
                            'job_id': job_id,
                            'partial_audio_base64': base64.b64encode(partial_io.read()).decode('utf-8'),
                            'processed_chars': total_chars_done,
                            'previous_chunk_text': previous_chunk_text,
                            'worker_uuid': WORKER_UUID
                        }, timeout=120, verify=False)
                        return  # Job handed off successfully
                    except Exception as release_err:
                        log_to_backend(f"❌ Release job failed: {release_err}. Falling back to normal fail.", job_id=job_id, level='error')
                
                # Fallback: normal failure (V3 or no segments or release failed)
                log_to_backend(f"🚫 Đã thử hết {len(valid_accounts)} key nhưng thất bại. Nhả Job về hàng chờ...", job_id=job_id, level='error')
                report_progress(i, f"failed: {last_error}")
                return

            try:
                acc = valid_accounts[current_acc_idx]
                if not isinstance(acc, dict):
                    raise Exception(f"Account at index {current_acc_idx} is {type(acc)}, expected dict")

                api_key = acc.get('key')
                if not api_key:
                    raise Exception(f"Account at index {current_acc_idx} has no 'key'")

                # Log key switch (only when changing to a different key)
                cur_key_id = acc.get('id', '?')
                if current_acc_idx != _last_logged_key_idx:
                    key_usage_counter[cur_key_id] = key_usage_counter.get(cur_key_id, 0) + (1 if _last_logged_key_idx >= 0 else 0)
                    if _last_logged_key_idx >= 0:
                        log_to_backend(f"🔄 Đổi key: #{cur_key_id} lần {key_usage_counter[cur_key_id]} (key thứ {current_acc_idx+1}/{len(valid_accounts)})", job_id=job_id)
                    _last_logged_key_idx = current_acc_idx

                # Determine if this is a V3 model
                is_v3 = model_id and 'v3' in model_id.lower()
                
                # Global Tracking Log
                log_to_backend(f"▶️ Bắt đầu Chunk {i+1}/{len(chunks)} ({len(chunks[i])} chars ~ cần {len(chunks[i])} điểm) - Key #{cur_key_id}", job_id=job_id)

                # Non-Latin languages are heavier on ElevenLabs
                # JP/KR/CN/VI/AR etc. → cap at 1500 for all models to avoid timeout
                tonal_max = 1500
                if is_tonal_language(chunks[i]) and len(chunks[i]) > tonal_max:
                    remaining_text = ' '.join(chunks[i:])
                    new_chunks = smart_split(remaining_text, tonal_max)
                    if len(new_chunks) != len(chunks) - i:
                        chunks[i:] = new_chunks
                        report_progress(i, "processing", total=len(chunks))
                        log_to_backend(f"📐 Non-Latin: chunk quá dài → giảm xuống ≤{tonal_max} chars ({len(new_chunks)} chunks còn lại)", job_id=job_id)
                        
                # Check credits for non-V3 models (V3 untouched to preserve overlap logic)
                if not is_v3 and v2_last_checked_key != current_acc_idx:
                    v2_last_checked_key = current_acc_idx
                    v2_credits = get_api_credits(api_key)
                    if v2_credits is not None:
                        if v2_credits <= 1000:
                            log_to_backend(f"⏭️ Key {acc.get('id')} chỉ còn {v2_credits} credits, bỏ qua", job_id=job_id, level='warning')
                            current_acc_idx += 1
                            continue

                        current_chunk_len = len(chunks[i])
                        if v2_credits < current_chunk_len:
                            # Key ít credit → chia nhỏ chunks còn lại cho vừa
                            remaining_text = ' '.join(chunks[i:])
                            new_max = max(v2_credits - 100, 1000)
                            new_chunks = smart_split(remaining_text, new_max)
                            chunks[i:] = new_chunks
                            report_progress(i, "processing", total=len(chunks))
                            log_to_backend(f"📐 V2 Dynamic: Key {acc.get('id')} có {v2_credits} credits → chunk ≤{new_max} chars ({len(new_chunks)} chunks còn lại)", job_id=job_id)
                        elif v2_credits >= MAX_CHUNK and current_chunk_len < MAX_CHUNK - 500 and not is_tonal_language(chunks[i]):
                            # Key đủ credit, phục hồi chunk về kích thước bình thường
                            # KHÔNG phục hồi nếu là non-Latin language — giữ cap 1500
                            remaining_text = ' '.join(chunks[i:])
                            new_chunks = smart_split(remaining_text, MAX_CHUNK)
                            if len(new_chunks) < len(chunks) - i:
                                chunks[i:] = new_chunks
                                report_progress(i, "processing", total=len(chunks))
                                log_to_backend(f"📐 V2 Dynamic: Key {acc.get('id')} có {v2_credits} credits → phục hồi chunk {MAX_CHUNK} chars ({len(new_chunks)} chunks còn lại)", job_id=job_id)

                seg = None
                alignment = None

                # V3 Overlap for ALL languages to maintain voice consistency across chunks
                needs_overlap = is_v3

                if is_v3 and needs_overlap and (i > 0 or previous_chunk_context) and previous_chunk_text:
                    # ===== V3 OVERLAP & TRIM TECHNIQUE (from exe tool) =====
                    # V3 doesn't support previous_text param, so we use overlap:
                    #   1) Get last ~15 words of previous chunk as overlap
                    #   2) Generate TTS for overlap text alone → measure duration
                    #   3) Generate TTS for (overlap + current chunk) → combined audio
                    #   4) Trim the overlap portion from combined audio
                    smart_overlap = get_last_words(previous_chunk_text)

                    if smart_overlap and len(smart_overlap) > 5:
                        log_to_backend(f"V3 Overlap Fix chunk {i+1}/{len(chunks)} ({len(smart_overlap)} chars overlap)", job_id=job_id)

                        # Step 1: Generate overlap audio to measure its duration
                        overlap_data = call_api_tts(smart_overlap, voice_id, api_key, model_id, voice_settings=voice_settings)
                        if not isinstance(overlap_data, dict) or "audio_base64" not in overlap_data:
                            raise Exception(f"Overlap TTS failed: {str(overlap_data)[:100]}")
                        overlap_audio = AudioSegment.from_mp3(io.BytesIO(base64.b64decode(overlap_data["audio_base64"])))
                        overlap_duration_ms = len(overlap_audio)

                        # Step 2: Generate combined audio (overlap + current chunk)
                        # Also pass previous_text for V3 context (helps maintain accent consistency)
                        combined_text = f"{smart_overlap} {chunks[i]}"
                        combined_data = call_api_tts(combined_text, voice_id, api_key, model_id, previous_text=previous_chunk_text, voice_settings=voice_settings)
                        if not isinstance(combined_data, dict) or "audio_base64" not in combined_data:
                            raise Exception(f"Combined TTS failed: {str(combined_data)[:100]}")
                        combined_audio = AudioSegment.from_mp3(io.BytesIO(base64.b64decode(combined_data["audio_base64"])))
                        alignment = combined_data.get("alignment", {})

                        # Step 3: Trim - cut off overlap portion + 500ms padding
                        trim_point_ms = overlap_duration_ms + 500
                        if trim_point_ms < len(combined_audio):
                            seg = combined_audio[trim_point_ms:]

                            # Step 4: Also trim alignment to match trimmed audio
                            # The alignment contains chars/timing for the FULL combined text
                            # (overlap + current chunk). We must remove overlap chars and
                            # shift all timestamps back by the trim duration so SRT is correct.
                            if alignment and isinstance(alignment, dict):
                                overlap_char_count = len(smart_overlap) + 1  # +1 for space between overlap and chunk
                                trim_time_sec = trim_point_ms / 1000.0

                                a_chars = alignment.get('characters', [])
                                a_starts = alignment.get('character_start_times_seconds', [])
                                a_ends = alignment.get('character_end_times_seconds', [])

                                if len(a_chars) > overlap_char_count:
                                    alignment['characters'] = a_chars[overlap_char_count:]
                                    alignment['character_start_times_seconds'] = [
                                        max(0, t - trim_time_sec) for t in a_starts[overlap_char_count:]
                                    ]
                                    alignment['character_end_times_seconds'] = [
                                        max(0, t - trim_time_sec) for t in a_ends[overlap_char_count:]
                                    ]
                                else:
                                    # Edge case: alignment has fewer chars than overlap
                                    # Discard alignment to avoid broken SRT
                                    alignment = None
                                    logger.warning(f"V3 Overlap: alignment chars ({len(a_chars)}) <= overlap ({overlap_char_count}), skipping alignment")
                        else:
                            # Fallback: use combined audio as-is if trim would remove everything
                            seg = combined_audio
                            # Still strip overlap chars from alignment to avoid duplicate text in SRT
                            if alignment and isinstance(alignment, dict):
                                overlap_char_count = len(smart_overlap) + 1
                                a_chars = alignment.get('characters', [])
                                a_starts = alignment.get('character_start_times_seconds', [])
                                a_ends = alignment.get('character_end_times_seconds', [])
                                if len(a_chars) > overlap_char_count:
                                    alignment['characters'] = a_chars[overlap_char_count:]
                                    alignment['character_start_times_seconds'] = a_starts[overlap_char_count:]
                                    alignment['character_end_times_seconds'] = a_ends[overlap_char_count:]
                            logger.warning(f"V3 Overlap trim point ({trim_point_ms}ms) >= audio length ({len(combined_audio)}ms), using full audio")
                    else:
                        # Overlap text too short, generate normally (still use seed + previous_text)
                        res_data = call_api_tts(chunks[i], voice_id, api_key, model_id, previous_text=previous_chunk_text if is_v3 else None, voice_settings=voice_settings)
                        if not isinstance(res_data, dict) or "audio_base64" not in res_data:
                            raise Exception(f"API error: {str(res_data)[:100]}")
                        seg = AudioSegment.from_mp3(io.BytesIO(base64.b64decode(res_data["audio_base64"])))
                        alignment = res_data.get("alignment", {})
                else:
                    # ===== STANDARD GENERATION =====
                    # Covers: non-V3 models, V3 first chunk (no previous text yet)
                    # V3: use seed for voice consistency + previous_text for context
                    # Non-V3: use previous_text param for voice continuity (no seed needed, has request stitching)
                    prev_ctx = previous_chunk_text if previous_chunk_text and model_id else None
                    res_data = call_api_tts(chunks[i], voice_id, api_key, model_id, previous_text=prev_ctx, voice_settings=voice_settings)

                    if not isinstance(res_data, dict):
                        raise Exception(f"API returned {type(res_data)} instead of dict: {str(res_data)[:100]}")
                    if "audio_base64" not in res_data:
                        raise Exception(f"API response missing audio_base64. Keys: {list(res_data.keys())}")

                    seg = AudioSegment.from_mp3(io.BytesIO(base64.b64decode(res_data["audio_base64"])))
                    alignment = res_data.get("alignment", {})

                # Common post-processing for all models
                if alignment and isinstance(alignment, dict):
                    alignment['offset'] = cumulative_duration
                    all_alignments.append(alignment)

                cumulative_duration += (len(seg) / 1000.0)
                audio_segments.append(seg)
                log_to_backend(f"Hoàn thành chunk {i+1}/{len(chunks)}", job_id=job_id)
                report_progress(i + 1)
                
                # V17.16: Sync credits after each chunk (async, non-blocking)
                def _sync_chunk_credits(key, key_id, backend):
                    try:
                        bal = get_api_credits(key)
                        if bal is not None:
                            requests.post(f"{backend}/api/progress.php",
                                          json={'action': 'sync_key', 'key_id': key_id, 'credits': bal},
                                          timeout=5, verify=False)
                    except: pass
                threading.Thread(target=_sync_chunk_credits, args=(api_key, acc.get('id'), php_backend), daemon=True).start()
                
                previous_chunk_text = chunks[i]  # Save for next chunk context
                i += 1
                # V2 Dynamic: force re-check credits before next chunk
                if not is_v3:
                    v2_last_checked_key = -1
                time.sleep(1.0)  # Delay giữa mỗi chunk để tránh bị block IP
            except Exception as e:
                import traceback
                error_detail = traceback.format_exc()
                logger.error(f"Error Job {job_id} at chunk {i}: {error_detail}")
                # Truncate error to fit database column (max 200 chars)
                last_error = str(e).split('\n')[0][:200]

                # Voice requires paid tier — stop immediately, don't waste rotating keys
                if "free_users_not_allowed" in last_error.lower():
                    fail_msg = "failed: Giọng nói này yêu cầu tài khoản ElevenLabs trả phí (Creator tier). Vui lòng chọn giọng khác."
                    log_to_backend(f"🚫 Job {job_id}: Voice yêu cầu paid tier. Dừng ngay.", job_id=job_id, level='error')
                    report_progress(i, fail_msg)
                    return

                # Voice not found — stop immediately, all keys will get same 404
                if "voice_not_found" in last_error.lower():
                    fail_msg = "failed: Voice ID không tồn tại hoặc đã bị xóa. Vui lòng chọn giọng khác."
                    log_to_backend(f"🚫 Job {job_id}: Voice không tồn tại. Dừng ngay.", job_id=job_id, level='error')
                    report_progress(i, fail_msg)
                    return

                # Model doesn't support a feature we sent — stop immediately, not a key issue
                if "unsupported_model" in last_error.lower():
                    fail_msg = f"failed: Model không hỗ trợ tính năng này. Vui lòng thử model khác. ({last_error[:80]})"
                    log_to_backend(f"🚫 Job {job_id}: Model unsupported error. Dừng ngay.", job_id=job_id, level='error')
                    report_progress(i, fail_msg)
                    return

                # Content blocked by ElevenLabs TOS — stop immediately, all keys will get same error
                if "violate" in last_error.lower() or ("forbidden" in last_error.lower() and "blocked" in last_error.lower()):
                    fail_msg = "failed: Nội dung bị từ chối. ElevenLabs yêu cầu kiểm tra lại nội dung văn bản."
                    log_to_backend(f"🚫 Job {job_id}: Nội dung vi phạm TOS ElevenLabs. Dừng ngay.", job_id=job_id, level='error')
                    report_progress(i, fail_msg)
                    return
                # Report exhausted or blocked key to backend for real-time sync / cooldown
                acc_id = valid_accounts[current_acc_idx].get('id')
                if acc_id:
                    action = "sync_key"
                    payload = {'action': action, 'key_id': acc_id}

                    # V17.4: Try to get REAL credits even on other errors
                    real_credits = get_api_credits(api_key)
                    if real_credits is not None:
                        payload['credits'] = real_credits

                    # V17.6: Check quota_exceeded FIRST (it's also HTTP 401)
                    # These keys just need a credit sync, NOT a cooldown
                    if "quota_exceeded" in last_error.lower() or "not have enough character" in last_error.lower():
                        pass  # Keep action as sync_key — just sync real credits
                    elif "detected_unusual_activity" in last_error.lower():
                        payload['action'] = 'flag_key'
                        payload['reason'] = '401 Unusual Activity'
                    elif "too_many_concurrent_requests" in last_error.lower() or "429" in last_error:
                        payload['action'] = 'flag_key'
                        payload['reason'] = '429 Concurrency Limit'

                    try:
                        requests.post(f"{php_backend}/api/progress.php", json=payload, timeout=5, verify=False)
                    except: pass
                
                # V17.3: Detailed Error Log for Admin before rotating
                err_lower = last_error.lower()
                if "quota_exceeded" in err_lower or "402" in err_lower or "not have enough character" in err_lower:
                    log_to_backend(f"⚠️ Key {acc_id} hết hạn/không đủ điểm. Đang đổi key...", job_id=job_id, level='warning')
                elif "detected_unusual_activity" in err_lower or "401" in err_lower:
                    log_to_backend(f"❌ Key {acc_id} bị ElevenLabs chặn (401). Đang đổi key...", job_id=job_id, level='error')

                    # V2: IP Block Detection — 2 consecutive 401s = IP blocked → shutdown
                    if not hasattr(process_job, '_consecutive_401'):
                        process_job._consecutive_401 = 0
                    process_job._consecutive_401 += 1

                    if process_job._consecutive_401 >= 2:
                        log_to_backend(f"🚨 IP bị chặn! 2 key liên tiếp bị 401. Nhả Job {job_id} về hàng chờ. Worker shutdown...", job_id=job_id, level='error')
                        
                        # Try to release job with partial audio
                        _released = False
                        if len(audio_segments) > 0:
                            try:
                                _partial = AudioSegment.empty()
                                if partial_audio_base:
                                    _partial = partial_audio_base
                                for _s in audio_segments:
                                    _partial += _s + AudioSegment.silent(duration=300)
                                _pio = io.BytesIO()
                                _partial.export(_pio, format='mp3', bitrate='64k')
                                _pio.seek(0)
                                _chars = resume_from_chars + sum(len(chunks[j]) for j in range(i))
                                requests.post(f"{php_backend}/api/progress.php", json={
                                    'action': 'release_job',
                                    'job_id': job_id,
                                    'partial_audio_base64': base64.b64encode(_pio.read()).decode('utf-8'),
                                    'processed_chars': _chars,
                                    'previous_chunk_text': previous_chunk_text,
                                    'worker_uuid': WORKER_UUID
                                }, timeout=120, verify=False)
                                log_to_backend(f"✅ Job {job_id} released trước khi shutdown ({_chars} chars done)", job_id=job_id)
                                _released = True
                            except Exception as _re:
                                log_to_backend(f"⚠️ Release trước shutdown thất bại: {_re}", job_id=job_id, level='warning')
                        
                        # Fallback: report failed so auto-redispatch in progress.php handles it
                        if not _released:
                            try:
                                report_progress(i, f"failed: IP blocked (detected_unusual_activity)")
                            except: pass
                        
                        time.sleep(3)
                        os._exit(1)

                elif "too_many_concurrent_requests" in err_lower or "429" in err_lower:
                    log_to_backend(f"⏳ Key {acc_id} bị giới hạn tốc độ (429). Đang đổi key...", job_id=job_id, level='warning')
                    # Reset 401 counter — non-401 error
                    if hasattr(process_job, '_consecutive_401'):
                        process_job._consecutive_401 = 0
                else:
                    log_to_backend(f"❓ Lỗi Job {job_id} tại Key {acc_id}: {last_error}", job_id=job_id, level='warning')
                    # Reset 401 counter — non-401 error
                    if hasattr(process_job, '_consecutive_401'):
                        process_job._consecutive_401 = 0

                # V17.11: Timeout = network issue, NOT key issue. Retry same key before rotating.
                if 'timed out' in last_error.lower() or 'timeout' in last_error.lower() or 'connectionerror' in last_error.lower():
                    total_timeout_count += 1
                    
                    # Reduced to 2 timeouts → release job cho máy chủ khác
                    if total_timeout_count >= 2:
                        log_to_backend(f"🚨 Timeout {total_timeout_count} lần! Nhả Job {job_id} cho máy chủ khác. Worker tiếp tục nhận job mới.", job_id=job_id, level='error')
                        
                        # Notify admin via Telegram
                        try:
                            requests.post(f"{php_backend}/api/telegram.php", json={
                                'action': 'worker_alert',
                                'message': f"🚨 <b>{WORKER_NAME}</b>\n\nTimeout {total_timeout_count} lần liên tiếp!\nJob: {job_id}\n\n🔄 Đã nhả job cho máy chủ khác. Worker tiếp tục hoạt động."
                            }, timeout=5, verify=False)
                        except: pass
                        
                        _released = False
                        if len(audio_segments) > 0:
                            try:
                                _partial = AudioSegment.empty()
                                if partial_audio_base:
                                    _partial = partial_audio_base
                                for _s in audio_segments:
                                    _partial += _s + AudioSegment.silent(duration=300)
                                _pio = io.BytesIO()
                                _partial.export(_pio, format='mp3', bitrate='64k')
                                _pio.seek(0)
                                _chars = resume_from_chars + sum(len(chunks[j]) for j in range(i))
                                requests.post(f"{php_backend}/api/progress.php", json={
                                    'action': 'release_job',
                                    'job_id': job_id,
                                    'partial_audio_base64': base64.b64encode(_pio.read()).decode('utf-8'),
                                    'processed_chars': _chars,
                                    'previous_chunk_text': previous_chunk_text,
                                    'worker_uuid': WORKER_UUID
                                }, timeout=120, verify=False)
                                log_to_backend(f"✅ Job {job_id} released do timeout ({_chars} chars done)", job_id=job_id)
                                _released = True
                            except Exception as _re:
                                log_to_backend(f"⚠️ Release thất bại: {_re}", job_id=job_id, level='warning')
                        
                        if not _released:
                            try:
                                requests.post(f"{php_backend}/api/progress.php", json={
                                    'action': 'release_job',
                                    'job_id': job_id,
                                    'worker_uuid': WORKER_UUID
                                }, timeout=30, verify=False)
                                log_to_backend(f"✅ Job {job_id} released (no partial audio)", job_id=job_id)
                            except:
                                pass
                        
                        # Flag to skip completion logic
                        process_job._timeout_released = True
                        break
                    
                    if not hasattr(process_job, '_timeout_retries'):
                        process_job._timeout_retries = {}
                    retry_key = f"{job_id}_{i}_{current_acc_idx}"
                    process_job._timeout_retries[retry_key] = process_job._timeout_retries.get(retry_key, 0) + 1
                    
                    if process_job._timeout_retries[retry_key] <= 1:
                        log_to_backend(f"⏱️ Timeout tại Key {acc_id}, thử lại lần {process_job._timeout_retries[retry_key]}/1 sau 10s... (tổng: {total_timeout_count}/2)", job_id=job_id, level='warning')
                        time.sleep(10)
                        continue  # Retry same chunk with same key
                    else:
                        log_to_backend(f"⏱️ Key {acc_id} timeout 2 lần liên tiếp. Đổi key... (tổng: {total_timeout_count}/2)", job_id=job_id, level='warning')
                        process_job._timeout_retries.pop(retry_key, None)
                
                current_acc_idx += 1 # Rotate key on non-timeout errors or after 3 timeouts

        # Export & Complete (OUTSIDE THE WHILE LOOP)
        final_audio = AudioSegment.empty()
        # Skip completion if job was released due to timeout
        if getattr(process_job, '_timeout_released', False):
            process_job._timeout_released = False
            log_to_backend(f"⏭️ Job {job_id} đã nhả cho máy khác. Worker sẵn sàng nhận job mới.", job_id=job_id)
            return

        # Prepend partial audio from previous worker if resuming
        if partial_audio_base:
            final_audio = partial_audio_base
        for s in audio_segments: final_audio += s + AudioSegment.silent(duration=300)
        out_io = io.BytesIO()
        final_audio.export(out_io, format='mp3', bitrate='64k')
        out_io.seek(0)

        srt_content = generate_srt(all_alignments)
        log_to_backend(f"Hoàn thành Job TTS {job_id}. Đang sync credits...", job_id=job_id)

        # V17.6: Sync ALL keys BEFORE calling complete.php
        # complete.php dispatches next jobs, so DB must have real credits first
        sync_limit = min(current_acc_idx + 1, len(valid_accounts))
        for idx in range(sync_limit):
            acc = valid_accounts[idx]
            acc_key = acc.get('key')
            acc_id = acc.get('id')
            if acc_key and acc_id:
                try:
                    real_bal = get_api_credits(acc_key)
                    if real_bal is not None:
                        requests.post(f"{php_backend}/api/progress.php", 
                                      json={'action': 'sync_key', 'key_id': acc_id, 'credits': real_bal}, 
                                      timeout=5, verify=False)
                except:
                    pass

        log_to_backend(f"Đang upload kết quả Job {job_id}...", job_id=job_id)

        # Upload kết quả (complete.php will dispatch next jobs using updated credits)
        requests.post(f"{php_backend}/api/complete.php", json={
            'job_id': job_id,
            'audio_base64': base64.b64encode(out_io.read()).decode('utf-8'),
            'srt_content': srt_content
        }, timeout=120, verify=False)

    except Exception as e:
        error_msg = str(e)[:200]
        report_progress(0, f"failed: {error_msg}")
        # Tăng failed_jobs cho worker này
        try:
            requests.post(f"{php_backend}/api/progress.php",
                          json={'action': 'worker_failed', 'worker_uuid': WORKER_UUID},
                          timeout=5, verify=False)
        except:
            pass

@app.route('/api/firebase_login', methods=['POST'])
def firebase_login_endpoint():
    """
    Firebase login endpoint - allows backend to login using this worker's IP
    This helps bypass Firebase quota limits by distributing requests across multiple IPs
    """
    try:
        data = request.json
        email = data.get('email', '').strip()
        password = data.get('password', '').strip()

        if not email or not password:
            return jsonify({'error': 'Email and password required'}), 400

        logger.info(f"Firebase login request for: {email}")

        # Perform Firebase login using this worker's IP
        token = login_with_firebase(email, password)

        if token:
            logger.info(f"✅ Firebase login successful for: {email}")
            return jsonify({
                'success': True,
                'idToken': token,
                'worker_uuid': WORKER_UUID
            })
        else:
            logger.warning(f"❌ Firebase login failed for: {email}")
            return jsonify({
                'success': False,
                'error': 'Firebase login failed - invalid credentials or quota exceeded'
            }), 401

    except Exception as e:
        logger.error(f"Firebase login error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/api/check_credits', methods=['POST'])
def check_credits_endpoint():
    """
    Check ElevenLabs credits using this worker's IP.
    Accepts { token: "api_key_or_bearer_token" }
    Returns { credits: int, reset_at: str|null }
    """
    try:
        data = request.json
        token = data.get('token', '').strip()
        if not token:
            return jsonify({'error': 'token required'}), 400

        url = 'https://api.elevenlabs.io/v1/user/subscription'
        if token.startswith('ey') or len(token) > 100:
            headers = {
                'Authorization': f'Bearer {token}',
                'Content-Type': 'application/json',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
                'Origin': 'https://elevenlabs.io',
                'Referer': 'https://elevenlabs.io/'
            }
        else:
            headers = {
                'xi-api-key': token,
                'Content-Type': 'application/json',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            }

        res = requests.get(url, headers=headers, timeout=15, verify=False)
        if res.status_code == 200:
            d = res.json()
            credits = d.get('character_limit', 0) - d.get('character_count', 0)
            reset_unix = d.get('next_character_count_reset_unix')
            reset_at = None
            if reset_unix:
                from datetime import datetime
                reset_at = datetime.utcfromtimestamp(reset_unix).strftime('%Y-%m-%d %H:%M:%S')
            logger.info(f"✅ check_credits: {credits} credits remaining")
            return jsonify({'credits': credits, 'reset_at': reset_at})
        else:
            logger.warning(f"❌ check_credits ElevenLabs error: {res.status_code} - {res.text[:100]}")
            return jsonify({'error': f'ElevenLabs API error: {res.status_code}'}), res.status_code
    except Exception as e:
        logger.error(f"check_credits error: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/api/convert', methods=['POST'])
def convert():
    try:
        # Block TTS khi đang dubbing
        global dubbing_in_progress
        if dubbing_in_progress:
            logger.warning(f"🚫 Rejected TTS job: dubbing in progress")
            return jsonify({'error': 'Worker đang busy lồng tiếng, hãy thử worker khác'}), 503

        data = request.json
        job_id = data.get('job_id')
        logger.info(f"📥 Received Job Request: {job_id}")

        text, api_keys_raw = data.get('text', ''), data.get('api_keys', [])
        php_backend = data.get('php_backend', '').rstrip('/')
        voice_id, model_id = data.get('voice_id'), data.get('model_id')
        voice_settings = data.get('voice_settings')
        resume_from_chars = data.get('resume_from_chars', 0)
        partial_audio_url = data.get('partial_audio_url')
        previous_chunk_ctx = data.get('previous_chunk_text', '')

        valid_accounts = []
        for item in api_keys_raw:
            if isinstance(item, dict):
                raw_val = item.get('token', '')
                key_id = item.get('id')
            else:
                raw_val = item
                key_id = None

            k = decrypt_key(raw_val)
            if ':' in k and '@' in k:
                pts = k.split(':')
                tk = login_with_firebase(pts[0].strip(), pts[1].strip())
                if tk: valid_accounts.append({'id': key_id, 'key': tk})
            elif k.startswith('sk_'):
                # Skip raw sk_ keys for TTS — chỉ dùng cho SFX/STT
                continue
            else:
                # Accept Bearer tokens (eyJ...) already resolved by PHP dispatcher
                valid_accounts.append({'id': key_id, 'key': k})

        if not valid_accounts:
            logger.warning(f"❌ Aborted Job {job_id}: No valid accounts")
            return jsonify({'error': 'No valid accounts'}), 401

        # Dynamic chunk size based on model + language
        # Non-Latin languages (Korean, Japanese, Chinese, Vietnamese, Arabic, Hindi, Russian...)
        # are heavier on ElevenLabs → cap at 1500 to avoid timeout
        # V3 Latin uses 3000 (smaller than V2 due to overlap technique doubling API calls)
        # V2/Turbo/Flash Latin are fast → keep 4500
        is_v3_model = model_id and 'v3' in model_id.lower()
        if is_tonal_language(text):
            chunk_size = 1500
        elif is_v3_model:
            chunk_size = 3000
        else:
            chunk_size = MAX_CHUNK
        chunks = smart_split(text, chunk_size)

        # Tăng bộ đếm hàng chờ
        global job_queue_count
        with job_queue_lock:
            job_queue_count += 1
            queue_pos = job_queue_count

        logger.info(f"📥 Job {job_id} xếp hàng (vị trí #{queue_pos}), {len(chunks)} chunks")

        def run_sequential():
            global job_queue_count
            with job_semaphore:  # Chờ job trước xong mới chạy
                with job_queue_lock:
                    job_queue_count -= 1
                print(f"▶️  [QUEUE] Bắt đầu Job {job_id} ({len(chunks)} chunks) — đang giữ lock")
                log_to_backend(f"Job {job_id} ra khỏi hàng chờ, bắt đầu xử lý", job_id=job_id)
                process_job(job_id, text, valid_accounts, voice_id, model_id, php_backend, chunks, voice_settings, resume_from_chars, partial_audio_url, previous_chunk_ctx)
                print(f"✅ [QUEUE] Hoàn thành Job {job_id} — giải phóng lock")

        thread = threading.Thread(target=run_sequential, daemon=True)
        thread.start()

        return jsonify({
            'status': 'queued',
            'job_id': job_id,
            'total_chunks': len(chunks),
            'queue_position': queue_pos
        })
    except Exception as e:
        logger.error(f"💥 Critical error in /api/convert: {str(e)}")
        return jsonify({'error': str(e)}), 500

# ================= CONVERSATION ENDPOINT (Separate from /api/convert) =================
@app.route('/api/conversation', methods=['POST'])
def conversation_endpoint():
    """Separate endpoint for conversation jobs. Old workers without this will return 404."""
    try:
        # Block conversation khi đang dubbing
        if dubbing_in_progress:
            logger.warning(f"🚫 Rejected Conversation job: dubbing in progress")
            return jsonify({'error': 'Worker đang busy lồng tiếng'}), 503
        data = request.json
        job_id = data.get('job_id')
        logger.info(f"📥 Received Conversation Job: {job_id}")

        text = data.get('text', '')  # JSON-encoded conversation data
        api_keys_raw = data.get('api_keys', [])
        php_backend = data.get('php_backend', '').rstrip('/')
        model_id = data.get('model_id')
        voice_settings = data.get('voice_settings')

        valid_accounts = []
        for item in api_keys_raw:
            if isinstance(item, dict):
                raw_val = item.get('token', '')
                key_id = item.get('id')
            else:
                raw_val = item
                key_id = None

            k = decrypt_key(raw_val)
            if ':' in k and '@' in k:
                pts = k.split(':')
                tk = login_with_firebase(pts[0].strip(), pts[1].strip())
                if tk: valid_accounts.append({'id': key_id, 'key': tk})
            elif k.startswith('sk_'):
                # Skip raw sk_ keys for Conversation — chỉ dùng cho SFX/STT
                continue
            else:
                # Accept Bearer tokens (eyJ...) already resolved by PHP dispatcher
                valid_accounts.append({'id': key_id, 'key': k})

        if not valid_accounts:
            logger.warning(f"❌ Aborted Conversation {job_id}: No valid accounts")
            return jsonify({'error': 'No valid accounts'}), 401

        global job_queue_count
        with job_queue_lock:
            job_queue_count += 1
            queue_pos = job_queue_count

        logger.info(f"📥 Conversation {job_id} xếp hàng (vị trí #{queue_pos})")

        def run_conversation():
            global job_queue_count
            with job_semaphore:
                with job_queue_lock:
                    job_queue_count -= 1
                print(f"▶️  [QUEUE] Bắt đầu Conversation {job_id} — đang giữ lock")
                log_to_backend(f"Conversation {job_id} ra khỏi hàng chờ, bắt đầu xử lý", job_id=job_id)
                process_conversation_job(job_id, text, valid_accounts, model_id, php_backend, voice_settings)
                print(f"✅ [QUEUE] Hoàn thành Conversation {job_id} — giải phóng lock")

        thread = threading.Thread(target=run_conversation, daemon=True)
        thread.start()

        return jsonify({
            'status': 'queued',
            'job_id': job_id,
            'queue_position': queue_pos
        })
    except Exception as e:
        logger.error(f"💥 Critical error in /api/conversation: {str(e)}")
        return jsonify({'error': str(e)}), 500

# ===== SHUTDOWN: Kill ngrok sạch trước khi Colab disconnect =====
@app.route('/api/shutdown_ngrok', methods=['POST'])
def shutdown_ngrok():
    """Kill cloudflared tunnel cleanly.
    Called by backend before scheduling Colab disconnect.
    Endpoint name kept for backward compatibility."""
    try:
        data = request.json or {}
        if data.get('secret') != UPDATE_SECRET:
            return jsonify({'error': 'Unauthorized'}), 401

        logger.info("🛑 Shutdown tunnel requested — killing cloudflared...")
        log_to_backend(f"🛑 Nhận lệnh shutdown tunnel (IP block auto-restart)", level='warning')

        # Kill cloudflared process
        os.system("killall -9 cloudflared 2>/dev/null")
        logger.info("  killall cloudflared done")

        return jsonify({'status': 'success', 'message': 'Cloudflared killed cleanly'})
    except Exception as e:
        logger.error(f"Shutdown error: {e}")
        return jsonify({'error': str(e)}), 500

# Durable Worker ID: Unique per session to avoid clashes
WORKER_UUID_FILE = "worker_identity.txt"
if os.path.exists(WORKER_UUID_FILE):
    with open(WORKER_UUID_FILE, 'r') as f:
        WORKER_UUID = f.read().strip()
else:
    WORKER_UUID = uuid.uuid4().hex[:16]
    with open(WORKER_UUID_FILE, 'w') as f:
        f.write(WORKER_UUID)

WORKER_NAME = os.environ.get('WORKER_TARGET_NAME', 'Colab-Auto')  # Set in Colab cell

# Track how many times each key is used on this worker
key_usage_counter = {}  # {key_id: count}

# ===== Cloudflare Tunnel (Không cần token, không cần tài khoản) =====
def start_cloudflared_tunnel(port=5000, max_retries=5):
    """Start cloudflared quick tunnel and return the public URL.
    No account or token needed — completely free."""
    for attempt in range(1, max_retries + 1):
        try:
            # Kill any existing cloudflared process
            os.system("killall -9 cloudflared 2>/dev/null")
            time.sleep(1)

            logger.info(f"⏳ Đang tạo Cloudflare Tunnel... (lần {attempt}/{max_retries})")

            # Start cloudflared tunnel in background
            tunnel_process = subprocess.Popen(
                ["cloudflared", "tunnel", "--url", f"http://localhost:{port}"],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE
            )

            # Read stderr to find the tunnel URL
            tunnel_url = None
            start_time = time.time()

            while time.time() - start_time < 30:  # Wait max 30s
                line = tunnel_process.stderr.readline().decode("utf-8", errors="ignore")
                if "trycloudflare.com" in line:
                    match = re.search(r'(https://[^\s]+\.trycloudflare\.com)', line)
                    if match:
                        tunnel_url = match.group(1)
                        break

            if tunnel_url:
                logger.info(f"✅ Cloudflare Tunnel thành công: {tunnel_url}")
                return tunnel_url
            else:
                logger.warning(f"⚠️ Không lấy được URL tunnel (lần {attempt})")
                tunnel_process.terminate()
                time.sleep(3)

        except Exception as e:
            logger.error(f"❌ Lỗi tạo tunnel (lần {attempt}): {e}")
            time.sleep(3)

    return None

# Lấy worker name từ server (nếu có target_name)
def fetch_worker_name():
    global WORKER_NAME
    target_name = os.environ.get('WORKER_TARGET_NAME', '')
    if target_name:
        WORKER_NAME = target_name
    try:
        # Vẫn gọi get_ngrok_token.php để lấy worker_name (nhưng không cần token)
        res = requests.post(
            f"{PHP_BACKEND_URL}/api/get_ngrok_token.php",
            json={'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET, 'target_name': target_name},
            timeout=10, verify=False
        )
        data = res.json()
        if data.get('worker_name'):
            WORKER_NAME = data['worker_name']
    except:
        pass

fetch_worker_name()
public_url = start_cloudflared_tunnel(5000)

if not public_url:
    print("❌ Không tạo được Cloudflare Tunnel sau nhiều lần thử. Vui lòng chạy lại.")
    import sys; sys.exit(1)

print(f"🚀 SERVER URL: {public_url}")
print(f"🆔 WORKER ID: {WORKER_UUID}")
print(f"🏷️  WORKER NAME: {WORKER_NAME}")

# ================= DUBBING WORKER (Multi-Key, File Splitting) =================
def dubbing_worker():
    global dubbing_in_progress
    logger.info("🌍 Dubbing Worker Thread: Started")
    if not os.path.exists('temp_dubbing'): os.makedirs('temp_dubbing')

    while True:
        job_id = None
        try:
            # 1. Get pending dubbing jobs
            res = requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                json={'action': 'get_pending', 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)
            if not res.ok:
                time.sleep(30); continue

            data = res.json()
            jobs = data.get('jobs', [])
            if not jobs:
                time.sleep(15); continue

            for job in jobs:
                job_id = job['id']
                source_file = job.get('source_file')
                source_url = job.get('source_url')
                source_lang = job.get('source_lang', 'auto')
                target_lang = job.get('target_lang', 'en')
                original_filename = job.get('original_filename', job_id)

                # 2. Try to claim job
                start_res = requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                json={'action': 'start', 'job_id': job_id, 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)

                if start_res.ok:
                    start_data = start_res.json()
                    if not start_data.get('claimed', False):
                        logger.info(f"⏭️  Dubbing Job {job_id} already claimed, skipping")
                        continue

                log_to_backend(f"Bắt đầu Job Lồng tiếng {job_id}: {original_filename} ({source_lang} → {target_lang})", job_id=job_id)
                logger.info(f"🚀 Processing Dubbing Job {job_id}")

                # Set busy flag → block TTS/conversation on this worker
                dubbing_in_progress = True

                # Wait for any running TTS job to finish first
                logger.info("⏳ Waiting for TTS queue to drain...")
                job_semaphore.acquire()
                job_semaphore.release()
                logger.info("✅ TTS queue clear, starting dubbing")

                # 3. Download file (or use source_url)
                local_path = None
                if source_file:
                    file_url = f"{PHP_BACKEND_URL}/api/results/dubbing/uploads/{source_file}"
                    local_path = f"temp_dubbing/{source_file}"
                    with requests.get(file_url, stream=True, verify=False) as r:
                        r.raise_for_status()
                        with open(local_path, 'wb') as f:
                            for chunk in r.iter_content(chunk_size=8192): f.write(chunk)
                    logger.info(f"📥 Downloaded {source_file} ({os.path.getsize(local_path)/1024/1024:.1f} MB)")

                # 4. Get audio duration and charge
                if local_path:
                    audio = AudioSegment.from_file(local_path)
                    duration_sec = len(audio) / 1000.0
                    # Giới hạn: Tiếng Việt 3 phút, khác 10 phút
                    max_duration = 180 if target_lang == 'vi' else 600
                    if duration_sec > max_duration:
                        raise Exception(f"File quá dài ({duration_sec/60:.1f} phút). Tối đa {max_duration//60} phút")
                else:
                    # URL-based: estimate 1 minute (will be adjusted later)
                    duration_sec = 60
                    audio = None

                charge_res = requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                    json={'action': 'charge', 'job_id': job_id, 'duration': duration_sec, 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)

                if not charge_res.ok:
                    err = charge_res.json().get('error', 'Insufficient points')
                    logger.error(f"❌ Dubbing Job {job_id} charge failed: {err}")
                    requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                    json={'action': 'fail', 'job_id': job_id, 'error': err, 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                    continue

                # 5. Get API Keys (only keys assigned to this worker)
                keys_res = requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                        json={'action': 'get_keys', 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET},
                                        timeout=10, verify=False)
                keys = keys_res.json().get('keys', [])
                if not keys:
                    requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                    json={'action': 'fail', 'job_id': job_id, 'error': 'No active API keys', 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                    continue

                # 5.5. Pre-check: total key credits vs required credits
                # ElevenLabs dubbing: ~50 credits/second (~3000 credits/minute)
                DUBBING_CREDITS_PER_SEC = 50
                MIN_KEY_CREDITS = 2000  # Skip keys with less than this

                total_credits = sum(int(k.get('credits', 0)) for k in keys if int(k.get('credits', 0)) >= MIN_KEY_CREDITS)
                required_credits = int(duration_sec * DUBBING_CREDITS_PER_SEC)

                if total_credits < required_credits:
                    err_msg = f"Không đủ credits trên máy chủ ({total_credits:,}/{required_credits:,}). Chuyển máy chủ khác..."
                    logger.warning(f"⚠️ Insufficient credits ({total_credits:,}/{required_credits:,}), releasing job {job_id}")
                    log_to_backend(f"Credits không đủ, chuyển worker khác", job_id=job_id)
                    # Release job back to pending for another worker
                    requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                    json={'action': 'release', 'job_id': job_id, 'worker_uuid': WORKER_UUID,
                                          'reason': 'Hệ thống đang quá tải, vui lòng thử lại sau', 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                    dubbing_in_progress = False
                    continue

                logger.info(f"✅ Credit check OK: need ~{required_credits:,}, have ~{total_credits:,}")

                # 6. Dynamic split based on key credits (like isolation_worker)
                OVERLAP_MS = 5000  # 5s overlap between chunks for voice continuity
                CROSSFADE_MS = 500  # 500ms crossfade when merging
                dubbed_segments = []

                # Vietnamese: force single chunk (voice consistency)
                force_single_chunk = (target_lang == 'vi')

                if not audio:
                    # URL-based: send directly as single chunk (can't split without local file)
                    log_to_backend(f"Đang lồng tiếng từ URL (1 phần)...", job_id=job_id)
                    dubbed_chunk = _dub_single_file(
                        None, source_url, source_lang, target_lang,
                        original_filename, keys, 0, job_id
                    )
                    if dubbed_chunk:
                        dubbed_segments.append(dubbed_chunk)
                    else:
                        raise Exception("Lồng tiếng thất bại")
                elif force_single_chunk:
                    # Vietnamese: find a single key with enough credits for the whole file
                    best_key = None
                    for k in keys:
                        if int(k.get('credits', 0)) >= required_credits:
                            best_key = k
                            break

                    if not best_key:
                        # No single key has enough → release to another worker
                        err_msg = f"Tiếng Việt cần 1 key đủ credits ({required_credits:,}), không tìm thấy"
                        logger.warning(f"⚠️ {err_msg}")
                        log_to_backend("Đang xử lý, vui lòng chờ...", job_id=job_id)
                        requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                        json={'action': 'release', 'job_id': job_id, 'worker_uuid': WORKER_UUID,
                                              'reason': 'Hệ thống đang quá tải, vui lòng thử lại sau', 'secret': UPDATE_SECRET},
                                        timeout=10, verify=False)
                        dubbing_in_progress = False
                        continue

                    log_to_backend(f"Đang lồng tiếng (1 phần, tiếng Việt)...", job_id=job_id)
                    chunk_path = f"temp_dubbing/chunk_{job_id}_vi.mp3"
                    audio.export(chunk_path, format="mp3", bitrate="128k")
                    dubbed_chunk = _dub_single_file(
                        chunk_path, None, source_lang, target_lang,
                        original_filename, [best_key], 0, job_id
                    )
                    if os.path.exists(chunk_path): os.remove(chunk_path)
                    if dubbed_chunk:
                        dubbed_segments.append(dubbed_chunk)
                    else:
                        raise Exception("Lồng tiếng tiếng Việt thất bại")
                else:
                    current_ms = 0
                    key_idx = 0
                    keys_exhausted_count = 0

                    while current_ms < len(audio):
                        # Find a key with enough credits
                        found_key = False
                        for _try in range(len(keys)):
                            if key_idx >= len(keys):
                                key_idx = 0
                            key_data_chunk = keys[key_idx]
                            key_credits = int(key_data_chunk.get('credits', 0))
                            if key_credits >= MIN_KEY_CREDITS:
                                found_key = True
                                break
                            logger.info(f"⏭️ Skip Key {key_data_chunk['id']} (only {key_credits} credits)")
                            key_idx += 1

                        if not found_key:
                            raise Exception(f"Hết key khả dụng (tất cả key dưới {MIN_KEY_CREDITS} credits). Đã xong {len(dubbed_segments)} phần.")

                        # Max duration this key can handle (ms)
                        # Formula: credits / cost_per_sec * 1000ms * safety_factor
                        max_duration_ms = max(10000, int((key_credits / DUBBING_CREDITS_PER_SEC) * 1000 * 0.70))  # 70% safety margin
                        # Cap at 3 minutes per chunk for safety
                        max_duration_ms = min(180000, max_duration_ms)

                        chunk_end = min(current_ms + max_duration_ms, len(audio))
                        # Don't leave tiny tail chunks (< 5s)
                        if len(audio) - chunk_end < 5000:
                            chunk_end = len(audio)

                        # Add overlap: start chunk earlier to give dubbing API context
                        chunk_idx = len(dubbed_segments)
                        overlap_start = max(0, current_ms - OVERLAP_MS) if chunk_idx > 0 else current_ms

                        chunk_duration_s = (chunk_end - current_ms) / 1000
                        chunk_duration_ms = chunk_end - current_ms
                        total_chunks = ceil(len(audio) / max_duration_ms) if max_duration_ms > 0 else 1

                        if chunk_idx == 0:
                            log_to_backend(f"Chia ~{total_chunks} phần (Key {key_data_chunk['id']}: {key_credits} credits → max {max_duration_ms/1000:.0f}s/chunk)", job_id=job_id)

                        chunk_audio = audio[overlap_start:chunk_end]
                        chunk_path = f"temp_dubbing/chunk_{job_id}_{chunk_idx}.mp3"
                        chunk_audio.export(chunk_path, format="mp3")

                        log_to_backend(f"Lồng tiếng phần {chunk_idx+1}/{total_chunks} ({chunk_duration_s:.0f}s, Key {key_data_chunk['id']}, credits: {key_credits})", job_id=job_id)

                        # Dub this chunk
                        dubbed_chunk = _dub_single_file(
                            chunk_path, None, source_lang, target_lang,
                            f"{original_filename}_chunk{chunk_idx}",
                            keys, key_idx, job_id
                        )

                        if dubbed_chunk:
                            # Trim overlap from dubbed result (except first chunk)
                            if chunk_idx > 0 and len(dubbed_chunk) > OVERLAP_MS:
                                # The overlap was at the START of the source chunk
                                # So trim the corresponding dubbed portion from the start
                                overlap_ratio = (current_ms - overlap_start) / (chunk_end - overlap_start)
                                trim_ms = int(len(dubbed_chunk) * overlap_ratio)
                                trim_ms = min(trim_ms, len(dubbed_chunk) - 1000)  # Keep at least 1s
                                dubbed_chunk = dubbed_chunk[trim_ms:]
                            dubbed_segments.append(dubbed_chunk)
                        else:
                            raise Exception(f"Lồng tiếng thất bại ở phần {chunk_idx+1}/{total_chunks}")

                        # Sync credits: deduct used credits from this key (in memory + DB)
                        used_credits = int(chunk_duration_s * DUBBING_CREDITS_PER_SEC)
                        new_credits = max(0, key_credits - used_credits)
                        keys[key_idx]['credits'] = new_credits  # Update in-memory
                        try:
                            requests.post(f"{PHP_BACKEND_URL}/api/progress.php",
                                json={'action': 'sync_key', 'key_id': key_data_chunk['id'],
                                      'credits': new_credits, 'secret': UPDATE_SECRET},
                                timeout=5, verify=False)
                        except: pass
                        logger.info(f"💳 Key {key_data_chunk['id']}: {key_credits} → {new_credits} credits")

                        # Report progress to server
                        pct = int((len(dubbed_segments) / total_chunks) * 90)
                        try:
                            requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                json={'action': 'update_progress', 'job_id': job_id,
                                      'progress': pct, 'progress_message': f"Lồng tiếng {len(dubbed_segments)}/{total_chunks} phần",
                                      'secret': UPDATE_SECRET}, timeout=5, verify=False)
                        except: pass

                        # Cleanup chunk source
                        if os.path.exists(chunk_path): os.remove(chunk_path)

                        current_ms = chunk_end
                        key_idx += 1

                # 7. Merge dubbed segments
                if not dubbed_segments:
                    raise Exception("Không có đoạn audio nào được lồng tiếng thành công")

                if len(dubbed_segments) == 1:
                    final_audio = dubbed_segments[0]
                else:
                    # Update progress: merging
                    try:
                        requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                            json={'action': 'update_progress', 'job_id': job_id,
                                  'progress': 95, 'progress_message': f"Đang ghép {len(dubbed_segments)} phần...",
                                  'secret': UPDATE_SECRET}, timeout=5, verify=False)
                    except: pass
                    log_to_backend(f"Đang ghép {len(dubbed_segments)} phần...", job_id=job_id)
                    final_audio = dubbed_segments[0]
                    for seg in dubbed_segments[1:]:
                        final_audio = final_audio.append(seg, crossfade=CROSSFADE_MS)

                result_path = f"temp_dubbing/result_{job_id}.mp3"
                final_audio.export(result_path, format="mp3", bitrate="128k")
                logger.info(f"📦 Merged dubbing result: {os.path.getsize(result_path)/1024/1024:.1f} MB")

                # 8. Upload result
                with open(result_path, 'rb') as f:
                    up_res = requests.post(f"{PHP_BACKEND_URL}/api/dubbing/upload_result.php",
                                            data={'job_id': job_id, 'secret': UPDATE_SECRET},
                                            files={'file': (f"result_{job_id}.mp3", f, 'audio/mpeg')},
                                            timeout=300, verify=False)

                if up_res.ok:
                    requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                    json={'action': 'complete', 'job_id': job_id, 'result_file': up_res.json()['filename'], 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                    log_to_backend(f"Hoàn thành Job Lồng tiếng {job_id} ✅", job_id=job_id)
                    logger.info(f"✅ Dubbing Job {job_id} Completed")
                else:
                    raise Exception("Failed to upload dubbing result")

                # Cleanup
                if local_path and os.path.exists(local_path): os.remove(local_path)
                if os.path.exists(result_path): os.remove(result_path)

        except Exception as e:
            logger.error(f"💥 Dubbing Worker Error: {e}")
            import traceback
            traceback.print_exc()
            if job_id:
                err_str = str(e)
                if 'IP_BLOCKED' in err_str:
                    # IP blocked → release job to another worker (don't fail!)
                    log_to_backend(f"🚨 IP bị chặn! Nhả Job {job_id} cho máy chủ khác...", job_id=job_id, level='error')
                    try: requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                        json={'action': 'release', 'job_id': job_id, 'worker_uuid': WORKER_UUID,
                                              'reason': 'IP bị chặn, đang chuyển máy chủ khác', 'secret': UPDATE_SECRET},
                                        timeout=10, verify=False)
                    except: pass
                    logger.warning("⏸️ Dubbing tạm dừng 5 phút do IP bị chặn...")
                    dubbing_in_progress = False
                    time.sleep(300)  # Pause 5 minutes
                    continue
                else:
                    user_error = err_str[:200] if err_str else 'Lỗi hệ thống'
                    try: requests.post(f"{PHP_BACKEND_URL}/api/dubbing/progress.php",
                                        json={'action': 'fail', 'job_id': job_id, 'error': user_error, 'secret': UPDATE_SECRET},
                                        timeout=10, verify=False)
                    except: pass
        finally:
            dubbing_in_progress = False
            logger.info("🔓 Dubbing flag cleared, TTS có thể nhận job lại")

        time.sleep(10)


def _dub_single_file(file_path, source_url, source_lang, target_lang, name, keys, start_key_idx, job_id):
    """
    Send a single file/URL to ElevenLabs Dubbing API.
    Tries multiple keys. Returns AudioSegment of dubbed audio, or None on failure.
    """
    key_idx = start_key_idx
    max_attempts = min(len(keys), 5)
    consecutive_401 = 0

    for attempt in range(max_attempts):
        idx = (key_idx + attempt) % len(keys)
        key_data = keys[idx]

        raw_key = decrypt_key(key_data['key'])
        # Handle email:password keys
        if ':' in raw_key and '@' in raw_key:
            pts = raw_key.split(':')
            tk = login_with_firebase(pts[0].strip(), pts[1].strip())
            if tk:
                raw_key = tk
            else:
                continue

        # Build headers
        headers = {}
        if raw_key.startswith('ey') or len(raw_key) > 100:
            headers = {
                'Authorization': f'Bearer {raw_key}',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Origin': 'https://elevenlabs.io',
                'Referer': 'https://elevenlabs.io/'
            }
        else:
            headers = {'xi-api-key': raw_key}

        # POST /v1/dubbing
        form_data = {
            'source_lang': (None, source_lang),
            'target_lang': (None, target_lang),
            'name': (None, name),
            'watermark': (None, 'true'),
        }

        if file_path and os.path.exists(file_path):
            form_data['file'] = (os.path.basename(file_path), open(file_path, 'rb'), 'audio/mpeg')
        elif source_url:
            form_data['source_url'] = (None, source_url)
        else:
            return None

        try:
            logger.info(f"🌍 Dubbing with Key {key_data['id']} (attempt {attempt+1})")
            log_to_backend(f"Dub attempt {attempt+1} với Key {key_data['id']}...", job_id=job_id)
            res = requests.post('https://api.elevenlabs.io/v1/dubbing',
                              headers=headers, files=form_data,
                              timeout=120, verify=False)

            if res.status_code != 200:
                err = res.text[:200]
                logger.warning(f"⚠️ Dubbing Key {key_data['id']} failed ({res.status_code}): {err}")
                log_to_backend(f"⚠️ Key {key_data['id']} lỗi ({res.status_code}): {err[:100]}", job_id=job_id)
                if res.status_code == 401:
                    consecutive_401 += 1
                    if consecutive_401 >= 2:
                        # IP blocked — raise special exception to release job
                        raise Exception("IP_BLOCKED: 2 key liên tiếp bị 401, IP bị chặn")
                    continue  # Try next key
                if 'quota_exceeded' in err:
                    consecutive_401 = 0
                    continue  # Try next key
                # Other errors - still try next key
                consecutive_401 = 0
                continue

            dub_data = res.json()
            dubbing_id = dub_data.get('dubbing_id')
            if not dubbing_id:
                continue

            logger.info(f"📡 Dubbing ID: {dubbing_id}, polling...")
            log_to_backend(f"Đang xử lý lồng tiếng (ID: {dubbing_id[:8]}...)", job_id=job_id)

            # Poll for completion
            for poll in range(360):  # Max 30 minutes (5s intervals)
                time.sleep(5)
                poll_res = requests.get(f'https://api.elevenlabs.io/v1/dubbing/{dubbing_id}',
                                       headers=headers, timeout=15, verify=False)

                if poll_res.status_code != 200:
                    logger.warning(f"⚠️ Poll error {poll_res.status_code}")
                    continue

                poll_data = poll_res.json()
                status = poll_data.get('status', '')

                if status == 'dubbed':
                    # Download dubbed audio
                    dl_url = f'https://api.elevenlabs.io/v1/dubbing/{dubbing_id}/audio/{target_lang}'
                    dl_res = requests.get(dl_url, headers=headers, timeout=300, verify=False, stream=True)

                    if dl_res.status_code == 200:
                        dubbed_path = f"temp_dubbing/dubbed_{job_id}_{idx}.mp3"
                        with open(dubbed_path, 'wb') as f:
                            for chunk in dl_res.iter_content(chunk_size=8192):
                                if chunk: f.write(chunk)

                        dubbed_audio = AudioSegment.from_file(dubbed_path)
                        os.remove(dubbed_path)
                        logger.info(f"✅ Dubbed chunk downloaded ({len(dubbed_audio)/1000:.1f}s)")
                        return dubbed_audio
                    else:
                        logger.error(f"❌ Download failed: {dl_res.status_code}")
                        break

                elif status == 'failed':
                    err = poll_data.get('error', 'Dubbing failed')
                    logger.error(f"❌ Dubbing failed: {err}")
                    break

                elif poll % 12 == 0:
                    logger.info(f"⏳ Dubbing poll #{poll+1}, status={status}")

            # If we get here, polling timed out or failed — try next key
            logger.warning(f"⚠️ Dubbing with Key {key_data['id']} did not complete")

        except Exception as e:
            logger.error(f"❌ Dubbing attempt error: {e}")
            continue

    return None


def isolation_worker():
    logger.info("🛠️  Isolation Worker Thread: Started")
    if not os.path.exists('temp_isolation'): os.makedirs('temp_isolation')
    
    while True:
        job_id = None
        try:
            # 1. Get pending jobs
            res = requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                json={'action': 'get_pending', 'secret': UPDATE_SECRET}, 
                                timeout=10, verify=False)
            if not res.ok: 
                time.sleep(30); continue
                
            data = res.json()
            jobs = data.get('jobs', [])
            if not jobs:
                time.sleep(15); continue
            
            for job in jobs:
                job_id = job['id']
                source_file = job['source_file']
                
                # 2. Try to claim job (atomic — only one worker can claim)
                start_res = requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                json={'action': 'start', 'job_id': job_id, 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET}, 
                                timeout=10, verify=False)
                
                # Check if we actually claimed the job
                if start_res.ok:
                    start_data = start_res.json()
                    if not start_data.get('claimed', False):
                        logger.info(f"⏭️  Isolation Job {job_id} already claimed by another worker, skipping")
                        continue
                
                log_to_backend(f"Bắt đầu Job Tách giọng {job_id}: {source_file}", job_id=job_id)
                logger.info(f"🚀 Processing Isolation Job {job_id}")
                
                # 3. Download file
                file_url = f"{PHP_BACKEND_URL}/api/results/isolator/uploads/{source_file}"
                local_path = f"temp_isolation/{source_file}"
                
                with requests.get(file_url, stream=True, verify=False) as r:
                    r.raise_for_status()
                    with open(local_path, 'wb') as f:
                        for chunk in r.iter_content(chunk_size=8192): f.write(chunk)
                
                # 4. Get duration and charge
                audio = AudioSegment.from_file(local_path)
                duration_sec = len(audio) / 1000.0
                
                charge_res = requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                    json={'action': 'charge', 'job_id': job_id, 'duration': duration_sec, 'secret': UPDATE_SECRET}, 
                                timeout=10, verify=False)
                
                if not charge_res.ok:
                    err = charge_res.json().get('error', 'Insufficient points')
                    logger.error(f"❌ Job {job_id} failed charging: {err}")
                    requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                    json={'action': 'fail', 'job_id': job_id, 'error': err, 'secret': UPDATE_SECRET}, 
                                    timeout=10, verify=False)
                    continue

                # 5. Get API Keys
                keys_res = requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                        json={'action': 'get_keys', 'secret': UPDATE_SECRET}, 
                                        timeout=10, verify=False)
                keys = keys_res.json().get('keys', [])
                if not keys:
                     requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                    json={'action': 'fail', 'job_id': job_id, 'error': 'No active API keys available', 'secret': UPDATE_SECRET}, 
                                    timeout=10, verify=False)
                     continue

                # 6. Split and Process (Dynamic Splitting)
                cleaned_segments = []
                current_ms = 0
                key_idx = 0
                used_keys = set()
                
                while current_ms < len(audio):
                    if key_idx >= len(keys):
                        raise Exception("Out of API keys while processing")
                    
                    key_data = keys[key_idx]
                    used_keys.add(str(key_data['id']))
                    key_credits = int(key_data['credits'])
                    
                    # Max duration for this key (ms) = credits / 1000 * 60 * 1000
                    max_duration_ms = max(5000, (key_credits - 50) * 60) # Buffer 50 credits
                    
                    # Limit chunk to 5 mins (300,000ms) for stability
                    chunk_duration_ms = min(300000, max_duration_ms, len(audio) - current_ms)
                    
                    # Final chunk handling
                    if (len(audio) - (current_ms + chunk_duration_ms)) < 5000:
                        chunk_duration_ms = len(audio) - current_ms
                    
                    chunk = audio[current_ms : current_ms + chunk_duration_ms]
                    chunk_path = f"temp_isolation/chunk_{job_id}_{key_idx}.mp3"
                    chunk.export(chunk_path, format="mp3")
                    
                    logger.info(f"🎙️  Isolating chunk {key_idx} ({chunk_duration_ms/1000}s) with Key {key_data['id']}")
                    
                    # Call API
                    raw_key = decrypt_key(key_data['key'])
                    if ':' in raw_key and '@' in raw_key:
                         pts = raw_key.split(':')
                         tk = login_with_firebase(pts[0].strip(), pts[1].strip())
                         if tk: raw_key = tk
                    else:
                         # Skip sk_ keys for Isolation — chỉ dùng cho SFX
                         key_idx += 1
                         continue
                    
                    cleaned_bytes = call_api_isolation(chunk_path, raw_key)
                    cleaned_seg = AudioSegment.from_file(io.BytesIO(cleaned_bytes))
                    cleaned_segments.append(cleaned_seg)
                    
                    # Sync back credits
                    used_credits = ceil((chunk_duration_ms / 60000) * 1000)
                    requests.post(f"{PHP_BACKEND_URL}/api/progress.php", 
                                    json={'action': 'sync_key', 'key_id': key_data['id'], 'credits': key_credits - used_credits, 'secret': UPDATE_SECRET}, 
                                    timeout=5, verify=False)
                    
                    current_ms += chunk_duration_ms
                    key_idx += 1
                    if os.path.exists(chunk_path): os.remove(chunk_path)

                # 7. Merge
                if not cleaned_segments:
                    raise Exception("Process completed but no audio segments produced")
                
                final_audio = cleaned_segments[0]
                for seg in cleaned_segments[1:]:
                    final_audio = final_audio.append(seg, crossfade=100)
                
                result_path = f"temp_isolation/result_{job_id}.mp3"
                final_audio.export(result_path, format="mp3")
                
                # 8. Upload Result
                with open(result_path, 'rb') as f:
                    up_res = requests.post(f"{PHP_BACKEND_URL}/api/isolator/upload_result.php", 
                                            data={'job_id': job_id, 'secret': UPDATE_SECRET}, 
                                            files={'file': f}, timeout=300, verify=False)
                
                if up_res.ok:
                    api_keys_str = ", ".join(used_keys)
                    requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                    json={'action': 'complete', 'job_id': job_id, 'result_file': up_res.json()['filename'], 'api_key_ids': api_keys_str, 'secret': UPDATE_SECRET}, 
                                    timeout=10, verify=False)
                    log_to_backend(f"Hoàn thành Job Tách giọng {job_id}", job_id=job_id)
                    logger.info(f"✅ Job {job_id} Completed")
                else:
                    raise Exception("Failed to upload result")

                # Cleanup
                if os.path.exists(local_path): os.remove(local_path)
                if os.path.exists(result_path): os.remove(result_path)

        except Exception as e:
            logger.error(f"💥 Isolation Worker Error: {e}")
            if job_id:
                user_error = 'Lỗi hệ thống, vui lòng liên hệ admin'
                try: requests.post(f"{PHP_BACKEND_URL}/api/isolator/progress.php", 
                                    json={'action': 'fail', 'job_id': job_id, 'error': user_error, 'secret': UPDATE_SECRET}, 
                                    timeout=10, verify=False)
                except: pass
        
        time.sleep(10)

def music_worker():
    logger.info("🎵 Music Worker Thread: Started")
    if not os.path.exists('temp_music'): os.makedirs('temp_music')

    # Reset jobs stuck in 'processing' from a previous crashed worker
    try:
        r = requests.post(f"{PHP_BACKEND_URL}/api/music/progress.php",
                          json={'action': 'reset_stuck', 'secret': UPDATE_SECRET},
                          timeout=10, verify=False)
        if r.ok:
            n = r.json().get('reset_count', 0)
            if n: logger.info(f"🔄 Reset {n} stuck music job(s) → pending")
    except: pass


    while True:
        job_id = None
        try:
            # 1. Get pending jobs (music_version='v2' required — old workers send nothing and get empty list)
            res = requests.post(f"{PHP_BACKEND_URL}/api/music/progress.php",
                                json={'action': 'get_pending', 'music_version': 'v7', 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)
            if not res.ok: 
                time.sleep(30); continue
                
            data = res.json()
            jobs = data.get('jobs', [])
            if not jobs:
                time.sleep(20); continue
            
            for job in jobs:
                job_id = job['id']
                prompt = job['prompt']
                duration = int(job['duration'])
                
                # 2. Atomic acquire — check if this worker actually got the job
                start_res = requests.post(f"{PHP_BACKEND_URL}/api/music/progress.php",
                                json={'action': 'start', 'job_id': job_id, 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)
                if not start_res.ok or not start_res.json().get('acquired', False):
                    logger.info(f"⏭️ Job {job_id} already claimed by another worker — skipping")
                    continue

                log_to_backend(f"Bắt đầu Job Tạo nhạc {job_id}: {prompt[:30]}", job_id=job_id)
                logger.info(f"🚀 Processing Music Job {job_id}: {prompt[:30]}...")
                
                # 3. Get API Keys
                keys_res = requests.post(f"{PHP_BACKEND_URL}/api/music/progress.php", 
                                        json={'action': 'get_keys', 'secret': UPDATE_SECRET}, 
                                        timeout=10, verify=False)
                keys = keys_res.json().get('keys', [])
                if not keys:
                     raise Exception("No active API keys available")

                # Try keys one by one until success or all fail
                success = False
                last_error = "Hết key khả dụng"
                
                for selected_key in keys:
                    try:
                        # 4. Resolve API key — MUST be email:password for music (sk_ keys get 402 on public API)
                        raw_key = decrypt_key(selected_key['key'])
                        if ':' not in raw_key or '@' not in raw_key:
                            # sk_ keys don't work for music (402 paid_plan_required)
                            logger.info(f"⏭️ Skipping key {selected_key['id']} (not email:pass) — music needs Bearer token")
                            continue
                        if ':' in raw_key and '@' in raw_key:
                            pts = raw_key.split(':', 1)  # maxsplit=1 to handle passwords with colons
                            email_part  = pts[0].strip()
                            passwd_part = pts[1].strip()
                            print(f"🔑 Logging in Firebase: {email_part}")
                            time.sleep(2)  # Delay to avoid Firebase rate limiting
                            tk = login_with_firebase(email_part, passwd_part)
                            if not tk:
                                print(f"❌ Firebase login FAILED for {email_part} — skipping key")
                                last_error = f"Firebase login failed for {email_part}"
                                continue  # Don't use email:password as xi-api-key!
                            raw_key = tk
                            print(f"✅ Firebase login OK for {email_part}")

                            # Pre-check ElevenLabs credits before wasting time on generation
                            credits = get_api_credits(raw_key)
                            min_credits_needed = int((duration / 60) * 900)  # ~900 credits/min for music
                            if credits is not None and credits < min_credits_needed:
                                print(f"⏭️ Key {selected_key['id']} ({email_part}) has only {credits} credits, need {min_credits_needed} — skipping")
                                continue
                            elif credits is not None:
                                print(f"💰 Key {selected_key['id']} has {credits} credits (need {min_credits_needed}) — OK")
                        
                        music_bytes = call_api_music(prompt, duration, raw_key)
                        
                        # Check actual duration, trim if needed, and ALWAYS convert MP4→MP3
                        # (ElevenLabs returns MP4/AAC format, must convert to MP3 for browser playback)
                        try:
                            audio_seg = AudioSegment.from_file(io.BytesIO(music_bytes))
                            actual_duration_s = len(audio_seg) / 1000.0
                            min_acceptable = duration * 0.5  # At least 50% of requested duration
                            print(f"📏 Audio duration: {actual_duration_s:.1f}s (requested: {duration}s)")
                            
                            if actual_duration_s < min_acceptable:
                                print(f"⚠️ Key {selected_key['id']} produced only {actual_duration_s:.1f}s — insufficient credits, trying next key...")
                                last_error = f"Key {selected_key['id']} produced {actual_duration_s:.1f}s instead of {duration}s"
                                continue
                            
                            # Trim to requested duration if too long
                            if actual_duration_s > duration + 5:  # 5s tolerance
                                print(f"✂️ Trimming from {actual_duration_s:.1f}s to {duration}s")
                                audio_seg = audio_seg[:int(duration * 1000)]
                            
                            # ALWAYS convert to MP3 (source is MP4/AAC from ElevenLabs)
                            buf = io.BytesIO()
                            audio_seg.export(buf, format="mp3", bitrate="192k")
                            music_bytes = buf.getvalue()
                            print(f"🔄 Converted to MP3: {len(music_bytes)} bytes")
                        except Exception as dur_err:
                            print(f"⚠️ Could not process audio: {dur_err}")

                        temp_path = f"temp_music/music_{job_id}.mp3"
                        with open(temp_path, 'wb') as f:
                            f.write(music_bytes)
                        print(f"💾 Saved {len(music_bytes)} bytes to {temp_path}")
                        
                        # 5. Sync back credits
                        used_credits = ceil((duration / 60) * 1100)
                        requests.post(f"{PHP_BACKEND_URL}/api/progress.php", 
                                        json={'action': 'sync_key', 'key_id': selected_key['id'], 'credits': int(selected_key['credits']) - used_credits, 'secret': UPDATE_SECRET}, 
                                        timeout=5, verify=False)

                        # 6. Upload Result
                        with open(temp_path, 'rb') as f:
                            up_res = requests.post(f"{PHP_BACKEND_URL}/api/music/upload_result.php", 
                                                    data={'job_id': job_id, 'secret': UPDATE_SECRET}, 
                                                    files={'file': f}, timeout=120, verify=False)
                        
                        print(f"📤 Upload response: {up_res.status_code} {up_res.text[:300]}")
                        if up_res.ok:
                            requests.post(f"{PHP_BACKEND_URL}/api/music/progress.php", 
                                            json={'action': 'complete', 'job_id': job_id, 'result_file': up_res.json()['filename'], 'api_key_id': selected_key['id'], 'secret': UPDATE_SECRET}, 
                                            timeout=10, verify=False)
                            log_to_backend(f"Hoàn thành Job Tạo nhạc {job_id}", job_id=job_id)
                            logger.info(f"✅ Music Job {job_id} Completed")
                            success = True
                            if os.path.exists(temp_path): os.remove(temp_path)
                            break
                        else:
                            raise Exception("Failed to upload music result")

                    except Exception as e:
                        err_str = str(e)
                        logger.error(f"❌ Key {selected_key['id']} failed: {err_str}")
                        last_error = err_str

                        # Flag key based on error
                        flag_action = "sync_key"
                        reason = err_str
                        payload = {'action': 'flag_key', 'key_id': selected_key['id'], 'reason': reason, 'secret': UPDATE_SECRET}

                        if "402" in err_str or "paid_plan_required" in err_str.lower():
                            payload['reason'] = "402 Paid Plan Required (No Music Access)"
                            # We can set credits to something low or just flag it for admin review
                        elif "quota_exceeded" in err_str.lower():
                            payload['reason'] = "Quota Exceeded"
                        
                        try: requests.post(f"{PHP_BACKEND_URL}/api/progress.php", json=payload, timeout=5, verify=False)
                        except: pass
                        
                        continue # Try next key

                if not success:
                    raise Exception(last_error)

        except Exception as e:
            logger.error(f"💥 Music Worker Error: {e}")
            if job_id:
                # Sanitize error for customer - hide internal details
                user_error = 'Lỗi hệ thống, vui lòng liên hệ admin'
                try: requests.post(f"{PHP_BACKEND_URL}/api/music/progress.php", 
                                    json={'action': 'fail', 'job_id': job_id, 'error': user_error, 'secret': UPDATE_SECRET}, 
                                    timeout=10, verify=False)
                except: pass
        
        time.sleep(15)

def sfx_worker():
    logger.info("🔊 SFX Worker Thread: Started")
    if not os.path.exists('temp_sfx'): os.makedirs('temp_sfx')

    # Reset stuck jobs
    try:
        r = requests.post(f"{PHP_BACKEND_URL}/api/sfx/progress.php",
                          json={'action': 'reset_stuck', 'secret': UPDATE_SECRET},
                          timeout=10, verify=False)
        if r.ok:
            n = r.json().get('reset_count', 0)
            if n: logger.info(f"🔄 Reset {n} stuck SFX job(s) → pending")
    except: pass

    while True:
        job_id = None
        try:
            # 1. Get pending jobs
            res = requests.post(f"{PHP_BACKEND_URL}/api/sfx/progress.php",
                                json={'action': 'get_pending', 'sfx_version': 'v1', 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)
            if not res.ok:
                time.sleep(30); continue

            data = res.json()
            jobs = data.get('jobs', [])
            if not jobs:
                time.sleep(20); continue

            for job in jobs:
                job_id = job['id']
                prompt = job['prompt']
                duration = float(job.get('duration', 0))
                is_loop = bool(int(job.get('is_loop', 0)))
                prompt_influence = float(job.get('prompt_influence', 0.3))

                # 2. Atomic acquire
                start_res = requests.post(f"{PHP_BACKEND_URL}/api/sfx/progress.php",
                                json={'action': 'start', 'job_id': job_id, 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)
                if not start_res.ok or not start_res.json().get('acquired', False):
                    logger.info(f"⏭️ SFX Job {job_id} already claimed — skipping")
                    continue

                log_to_backend(f"Bắt đầu Job Hiệu ứng âm thanh {job_id}: {prompt[:30]}", job_id=job_id)
                logger.info(f"🔊 Processing SFX Job {job_id}: {prompt[:50]}...")

                # 3. Get API Keys
                keys_res = requests.post(f"{PHP_BACKEND_URL}/api/sfx/progress.php",
                                        json={'action': 'get_keys', 'secret': UPDATE_SECRET},
                                        timeout=10, verify=False)
                keys = keys_res.json().get('keys', [])
                if not keys:
                     raise Exception("No active API keys available")

                # Try keys one by one
                success = False
                last_error = "Hết key khả dụng"

                for selected_key in keys:
                    try:
                        # 4. Resolve API key — dùng email:password (Bearer token)
                        raw_key = decrypt_key(selected_key['key'])
                        if ':' in raw_key and '@' in raw_key:
                            pts = raw_key.split(':', 1)
                            tk = login_with_firebase(pts[0].strip(), pts[1].strip())
                            if not tk:
                                logger.info(f"⏭️ SFX: Firebase login failed for key {selected_key['id']}, skipping")
                                continue
                            raw_key = tk
                        elif raw_key.startswith('sk_'):
                            # Skip sk_ keys — chỉ dùng email:password
                            continue

                        # 5. Call SFX API
                        sfx_bytes = call_api_sfx(prompt, raw_key,
                                                  duration_seconds=duration if duration > 0 else None,
                                                  loop=is_loop,
                                                  prompt_influence=prompt_influence)

                        # Convert to MP3 if needed
                        try:
                            audio_seg = AudioSegment.from_file(io.BytesIO(sfx_bytes))
                            actual_duration_s = len(audio_seg) / 1000.0
                            print(f"📏 SFX duration: {actual_duration_s:.1f}s")

                            buf = io.BytesIO()
                            audio_seg.export(buf, format="mp3", bitrate="192k")
                            sfx_bytes = buf.getvalue()
                            print(f"🔄 Converted to MP3: {len(sfx_bytes)} bytes")
                        except Exception as conv_err:
                            print(f"⚠️ Could not convert SFX audio: {conv_err}")

                        temp_path = f"temp_sfx/sfx_{job_id}.mp3"
                        with open(temp_path, 'wb') as f:
                            f.write(sfx_bytes)
                        print(f"💾 Saved {len(sfx_bytes)} bytes to {temp_path}")

                        # 6. Sync credits (estimate: 40 credits per second on ElevenLabs side)
                        bill_duration = duration if duration > 0 else 5  # Auto = ~5s
                        used_credits = ceil(bill_duration * 40)
                        requests.post(f"{PHP_BACKEND_URL}/api/progress.php",
                                        json={'action': 'sync_key', 'key_id': selected_key['id'], 'credits': int(selected_key['credits']) - used_credits, 'secret': UPDATE_SECRET},
                                        timeout=5, verify=False)

                        # 7. Upload Result
                        with open(temp_path, 'rb') as f:
                            up_res = requests.post(f"{PHP_BACKEND_URL}/api/sfx/upload_result.php",
                                                    data={'job_id': job_id, 'secret': UPDATE_SECRET},
                                                    files={'file': f}, timeout=120, verify=False)

                        print(f"📤 Upload response: {up_res.status_code} {up_res.text[:300]}")
                        if up_res.ok:
                            requests.post(f"{PHP_BACKEND_URL}/api/sfx/progress.php",
                                            json={'action': 'complete', 'job_id': job_id, 'result_file': up_res.json()['filename'], 'api_key_id': selected_key['id'], 'secret': UPDATE_SECRET},
                                            timeout=10, verify=False)
                            log_to_backend(f"Hoàn thành Job Hiệu ứng âm thanh {job_id}", job_id=job_id)
                            logger.info(f"✅ SFX Job {job_id} Completed")
                            success = True
                            if os.path.exists(temp_path): os.remove(temp_path)
                            break
                        else:
                            raise Exception("Failed to upload SFX result")

                    except Exception as e:
                        err_str = str(e)
                        logger.error(f"❌ SFX Key {selected_key['id']} failed: {err_str}")
                        last_error = err_str

                        # Flag key based on error
                        payload = {'action': 'flag_key', 'key_id': selected_key['id'], 'reason': err_str[:200], 'secret': UPDATE_SECRET}
                        if "402" in err_str or "paid_plan_required" in err_str.lower():
                            payload['reason'] = "402 Paid Plan Required (No SFX Access)"
                        elif "quota_exceeded" in err_str.lower():
                            payload['reason'] = "Quota Exceeded"

                        try: requests.post(f"{PHP_BACKEND_URL}/api/progress.php", json=payload, timeout=5, verify=False)
                        except: pass

                        continue  # Try next key

                if not success:
                    raise Exception(last_error)

        except Exception as e:
            logger.error(f"💥 SFX Worker Error: {e}")
            if job_id:
                user_error = 'Lỗi hệ thống, vui lòng liên hệ admin'
                try: requests.post(f"{PHP_BACKEND_URL}/api/sfx/progress.php",
                                    json={'action': 'fail', 'job_id': job_id, 'error': user_error, 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                except: pass

        time.sleep(15)

def generate_srt_from_words(words):
    """Convert ElevenLabs STT word timestamps to SRT subtitle format."""
    if not words:
        return ""

    # Filter only actual words (skip spacing and audio events)
    word_items = [w for w in words if w.get('type') == 'word' and w.get('text', '').strip()]
    if not word_items:
        return ""

    srt_lines = []
    counter = 1

    def fmt_time(t):
        h = int(t // 3600)
        m = int((t % 3600) // 60)
        s = int(t % 60)
        ms = int((t % 1) * 1000)
        return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"

    # Group words into segments (~10 words per subtitle, or split at sentence boundaries)
    segment = []
    segment_start = 0
    segment_end = 0

    for w in word_items:
        text = w['text']
        start = float(w.get('start', 0))
        end = float(w.get('end', start))

        if not segment:
            segment_start = start

        segment.append(text)
        segment_end = end

        # Break at sentence endings or every 10 words
        is_sentence_end = text.rstrip().endswith(('.', '!', '?', '。', '！', '？'))
        if is_sentence_end or len(segment) >= 10:
            line_text = strip_audio_tags(' '.join(segment))
            if line_text:
                srt_lines.append(f"{counter}")
                srt_lines.append(f"{fmt_time(segment_start)} --> {fmt_time(segment_end)}")
                srt_lines.append(line_text)
                srt_lines.append("")
                counter += 1
            segment = []

    # Remaining words
    if segment:
        line_text = strip_audio_tags(' '.join(segment))
        if line_text:
            srt_lines.append(f"{counter}")
            srt_lines.append(f"{fmt_time(segment_start)} --> {fmt_time(segment_end)}")
            srt_lines.append(line_text)
            srt_lines.append("")

    return "\n".join(srt_lines)

def stt_worker():
    """Background thread: polls stt_jobs, transcribes audio using ElevenLabs Speech-to-Text API."""
    os.makedirs('temp_stt', exist_ok=True)
    print("🎤 STT Worker: Started")
    while True:
        job_id = None
        try:
            # 1. Reset stuck jobs
            try: requests.post(f"{PHP_BACKEND_URL}/api/stt/progress.php",
                               json={'action': 'reset_stuck', 'secret': UPDATE_SECRET},
                               timeout=10, verify=False)
            except: pass

            # 2. Get pending jobs
            res = requests.post(f"{PHP_BACKEND_URL}/api/stt/progress.php",
                                json={'action': 'get_pending', 'stt_version': 'v1', 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)
            jobs = res.json().get('jobs', [])
            if not jobs:
                time.sleep(15)
                continue

            for job in jobs:
                job_id = job['id']
                source_file = job['source_file']
                logger.info(f"🎤 STT Job {job_id}: {source_file}")

                # 3. Acquire job
                acq = requests.post(f"{PHP_BACKEND_URL}/api/stt/progress.php",
                                    json={'action': 'start', 'job_id': job_id, 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                if not acq.json().get('acquired'):
                    continue

                log_to_backend(f"Bắt đầu Job Chuyển giọng nói thành văn bản {job_id}", job_id=job_id)

                # 4. Download audio file
                file_url = f"{PHP_BACKEND_URL}/api/results/stt/uploads/{source_file}"
                local_path = f"temp_stt/{source_file}"
                with requests.get(file_url, stream=True, verify=False) as r:
                    r.raise_for_status()
                    with open(local_path, 'wb') as f:
                        for chunk in r.iter_content(chunk_size=8192): f.write(chunk)
                logger.info(f"📥 Downloaded {source_file} ({os.path.getsize(local_path)} bytes)")

                # 5. Get API Keys
                keys_res = requests.post(f"{PHP_BACKEND_URL}/api/stt/progress.php",
                                         json={'action': 'get_keys', 'secret': UPDATE_SECRET},
                                         timeout=10, verify=False)
                keys = keys_res.json().get('keys', [])
                if not keys:
                    raise Exception("No active API keys available")

                # 6. Try keys until success — dùng email:password (Bearer token)
                success = False
                last_error = "Hết key khả dụng"

                for selected_key in keys:
                    try:
                        raw_key = decrypt_key(selected_key['key'])
                        if ':' in raw_key and '@' in raw_key:
                            pts = raw_key.split(':', 1)
                            tk = login_with_firebase(pts[0].strip(), pts[1].strip())
                            if not tk:
                                logger.info(f"⏭️ STT: Firebase login failed for key {selected_key['id']}, skipping")
                                continue
                            raw_key = tk
                        elif raw_key.startswith('sk_'):
                            # Skip sk_ keys — chỉ dùng email:password
                            continue

                        logger.info(f"🔑 Trying STT with key {selected_key['id']}")
                        result = call_api_stt(local_path, raw_key)

                        transcript_text = result.get('text', '')
                        language_code = result.get('language_code', '')
                        words = result.get('words', [])

                        # Generate SRT from word timestamps
                        srt_content = generate_srt_from_words(words)
                        logger.info(f"✅ STT Result: lang={language_code}, {len(transcript_text)} chars, {len(words)} words, SRT: {len(srt_content)} bytes")

                        # 7. Complete job — send transcript text + SRT to backend
                        requests.post(f"{PHP_BACKEND_URL}/api/stt/progress.php",
                                      json={'action': 'complete', 'job_id': job_id,
                                            'result_text': transcript_text,
                                            'result_srt': srt_content,
                                            'language_code': language_code,
                                            'api_key_id': selected_key['id'],
                                            'secret': UPDATE_SECRET},
                                      timeout=30, verify=False)
                        log_to_backend(f"Hoàn thành Job STT {job_id}: {len(transcript_text)} ký tự", job_id=job_id)
                        logger.info(f"✅ STT Job {job_id} Completed")
                        success = True
                        break

                    except Exception as e:
                        err_str = str(e)
                        logger.error(f"❌ STT Key {selected_key['id']} failed: {err_str}")
                        last_error = err_str
                        continue

                if not success:
                    raise Exception(last_error)

                # Cleanup
                if os.path.exists(local_path): os.remove(local_path)

        except Exception as e:
            logger.error(f"💥 STT Worker Error: {e}")
            if job_id:
                user_error = 'Lỗi hệ thống, vui lòng liên hệ admin'
                try: requests.post(f"{PHP_BACKEND_URL}/api/stt/progress.php",
                                    json={'action': 'fail', 'job_id': job_id, 'error': user_error, 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                except: pass

        time.sleep(15)

def voice_changer_worker():
    """Background thread: polls voice_changer_jobs, process audio using ElevenLabs Speech-to-Speech API."""
    os.makedirs('temp_vc', exist_ok=True)
    print("🎙️ Voice Changer Worker: Started")
    while True:
        job_id = None
        try:
            # 1. Get pending jobs
            res = requests.post(f"{PHP_BACKEND_URL}/api/voice_changer/progress.php",
                                json={'action': 'get_pending', 'secret': UPDATE_SECRET},
                                timeout=10, verify=False)
            jobs = res.json().get('jobs', [])
            if not jobs:
                time.sleep(15)
                continue

            for job in jobs:
                job_id = job['id']
                source_file = job['source_file']
                voice_id = job.get('voice_id')
                logger.info(f"🎙️ VC Job {job_id}: {source_file} (Voice: {voice_id})")

                # 2. Acquire job
                acq = requests.post(f"{PHP_BACKEND_URL}/api/voice_changer/progress.php",
                                    json={'action': 'start', 'job_id': job_id, 'worker_uuid': WORKER_UUID, 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                if not acq.json().get('claimed'):
                    continue

                log_to_backend(f"Bắt đầu Job Thay đổi giọng nói {job_id}", job_id=job_id)

                # 3. Download audio file
                file_url = f"{PHP_BACKEND_URL}/api/results/voice_changer/uploads/{source_file}"
                local_path = f"temp_vc/{source_file}"
                with requests.get(file_url, stream=True, verify=False) as r:
                    r.raise_for_status()
                    with open(local_path, 'wb') as f:
                        for chunk in r.iter_content(chunk_size=8192): f.write(chunk)
                logger.info(f"📥 Downloaded {source_file} ({os.path.getsize(local_path)} bytes)")

                # 4. Get duration
                seg = AudioSegment.from_file(local_path)
                duration_sec = len(seg) / 1000.0

                # 5. Charge points
                charge = requests.post(f"{PHP_BACKEND_URL}/api/voice_changer/progress.php",
                                       json={'action': 'charge', 'job_id': job_id, 'duration': duration_sec, 'secret': UPDATE_SECRET},
                                       timeout=10, verify=False)
                if charge.status_code != 200 or charge.json().get('status') != 'success':
                    err_msg = charge.json().get('error', 'Lỗi không rõ')
                    raise Exception(f"Không đủ tín dụng hoặc lỗi trừ điểm: {err_msg}")

                # 6. Get API Keys
                keys_res = requests.post(f"{PHP_BACKEND_URL}/api/voice_changer/progress.php",
                                         json={'action': 'get_keys', 'secret': UPDATE_SECRET},
                                         timeout=10, verify=False)
                keys = keys_res.json().get('keys', [])
                if not keys:
                    raise Exception("No active API keys available")

                success = False
                last_error = "Hết key khả dụng"

                for selected_key in keys:
                    try:
                        raw_key = decrypt_key(selected_key['key'])
                        if ':' in raw_key and '@' in raw_key:
                            pts = raw_key.split(':', 1)
                            tk = login_with_firebase(pts[0].strip(), pts[1].strip())
                            if not tk:
                                logger.info(f"⏭️ VC: Firebase login failed for key {selected_key['id']}, skipping")
                                continue
                            raw_key = tk
                        elif raw_key.startswith('sk_'):
                            continue

                        logger.info(f"🔑 Trying VC with key {selected_key['id']}")
                        result_audio = call_api_voice_changer(local_path, voice_id, raw_key)
                        
                        # 7. Upload Result
                        files = {'file': ('result.mp3', result_audio, 'audio/mpeg')}
                        data = {'secret': UPDATE_SECRET, 'job_id': job_id}
                        ur_res = requests.post(f"{PHP_BACKEND_URL}/api/voice_changer/upload_result.php",
                                             data=data, files=files, timeout=60, verify=False)
                        
                        if ur_res.status_code == 200 and ur_res.json().get('status') == 'success':
                            final_file = ur_res.json().get('result_file')
                            requests.post(f"{PHP_BACKEND_URL}/api/voice_changer/progress.php",
                                          json={'action': 'complete', 'job_id': job_id, 'result_file': final_file, 'api_key_ids': selected_key['id'], 'secret': UPDATE_SECRET},
                                          timeout=10, verify=False)
                            log_to_backend(f"Hoàn thành Job Thay đổi giọng nói {job_id}", job_id=job_id)
                            logger.info(f"✅ VC Job {job_id} Completed")
                            success = True
                            break
                        else:
                            raise Exception(f"Upload failed: {ur_res.text}")

                    except Exception as e:
                        err_str = str(e)
                        logger.error(f"❌ VC Key {selected_key['id']} failed: {err_str}")
                        last_error = err_str
                        continue

                if not success:
                    raise Exception(last_error)

                # Cleanup
                if os.path.exists(local_path): os.remove(local_path)

        except Exception as e:
            logger.error(f"💥 VC Worker Error: {e}")
            if job_id:
                try: requests.post(f"{PHP_BACKEND_URL}/api/voice_changer/progress.php",
                                    json={'action': 'fail', 'job_id': job_id, 'error': str(e), 'secret': UPDATE_SECRET},
                                    timeout=10, verify=False)
                except: pass

        time.sleep(15)

def register_now():
    try:
        # Lấy IP thực của worker
        try:
            my_ip = requests.get('https://api.ipify.org?format=json', timeout=5, verify=False).json().get('ip', '')
        except:
            my_ip = ''

        active_threads = threading.active_count()
        res = requests.post(f"{PHP_BACKEND_URL}/api/register_worker.php",
                            json={
                                'url': public_url,
                                'worker_uuid': WORKER_UUID,
                                'worker_name': WORKER_NAME,
                                'secret': UPDATE_SECRET,
                                'threads': active_threads,
                                'worker_ip': my_ip
                            },
                            timeout=10, verify=False)
        if res.ok:
            print(f"✅ Registered (Threads: {active_threads}, IP: {my_ip}): {res.json().get('message')}")
        else:
            print(f"❌ Registration failed: {res.status_code} - {res.text}")
    except Exception as e:
        print(f"⚠️ Heartbeat network error: {e}")

def heartbeat():
    print(f"💓 Heartbeat Thread: Started for Worker {WORKER_UUID}")
    while True:
        register_now()
        time.sleep(20)

# Register after a small delay to ensure Flask is starting up and Ngrok is stable
def delayed_registration():
    time.sleep(3)
    register_now()
    threading.Thread(target=dubbing_worker, daemon=True).start()
    threading.Thread(target=isolation_worker, daemon=True).start()
    threading.Thread(target=music_worker, daemon=True).start()
    threading.Thread(target=sfx_worker, daemon=True).start()
    threading.Thread(target=stt_worker, daemon=True).start()
    threading.Thread(target=voice_changer_worker, daemon=True).start()
    heartbeat()

threading.Thread(target=delayed_registration, daemon=True).start()
app.run(port=5000, threaded=True)
