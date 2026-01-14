import traceback
import sys
import os
import asyncio
import json
import time
import logging
import random
from threading import Lock, Thread
from flask import Flask, request, render_template_string, jsonify

# ==================================================================================
# [SETUP] PATH CONFIGURATION
# ==================================================================================
current_dir = os.path.dirname(os.path.abspath(__file__))
gemini_src_path = os.path.join(current_dir, 'Gemini-API', 'src')

if os.path.exists(gemini_src_path):
    sys.path.append(gemini_src_path)
else:
    print(f"[WARNING] Missing folder: {gemini_src_path}")

try:
    from gemini_webapi import GeminiClient
    import gemini_webapi.utils
    # Prevent the lib from trying to load browser cookies automatically
    gemini_webapi.utils.load_browser_cookies = lambda: {}
except ImportError as e:
    print(f"[CRITICAL] Import failed: {e}")
    sys.exit(1)

# ==================================================================================
# [CORE] PERSISTENT MANAGER (MULTI-ACCOUNT & ANTI-BOT)
# ==================================================================================
class GeminiManager:
    def __init__(self):
        self.client = None
        self.loop = asyncio.new_event_loop()
        self.async_lock = asyncio.Lock()

        # --- [CONFIG] ACCOUNT MANAGEMENT ---
        # List your cookie files here. If you have multiple, the system will rotate them on 429s.
        self.cookie_files = ["gemini_cookies.json"]
        # Example for multiple: ["gemini_cookies_1.json", "gemini_cookies_2.json"]

        self.current_account_index = 0

        # Logging / ID helpers
        self.query_counter = 0
        self.log_lock = Lock()

        # Timeouts
        self.generation_timeout = 100
        self.total_timeout = 300

        # --- CIRCUIT BREAKER ---
        self.is_rate_limited = False
        self.rate_limit_resume_time = 0

        # Start the background event loop
        self.thread = Thread(target=self._run_event_loop, daemon=True)
        self.thread.start()

    def _run_event_loop(self):
        """Runs the asyncio loop in a separate thread forever."""
        asyncio.set_event_loop(self.loop)
        self.loop.run_forever()

    def _get_current_cookie_file(self):
        return self.cookie_files[self.current_account_index]

    def _rotate_account(self):
        """Switch to the next available account index."""
        if len(self.cookie_files) > 1:
            prev = self.current_account_index
            self.current_account_index = (self.current_account_index + 1) % len(self.cookie_files)
            print(f"[SYSTEM] 🔄 Rotating Account: {prev} -> {self.current_account_index}")
            return True
        return False

    async def _ensure_client(self):
        """Initializes the client if needed, respecting rate limits."""
        if self.client:
            return

        async with self.async_lock:
            if self.client: return

            # [CHECK] If rate limited and we can't rotate accounts, we must wait
            if self.is_rate_limited and time.time() < self.rate_limit_resume_time:
                wait_time = int(self.rate_limit_resume_time - time.time())
                raise Exception(f"System cooling down. Waiting {wait_time}s.")

            target_cookie_file = self._get_current_cookie_file()
            print(f"[SYSTEM] Initializing Client with: {target_cookie_file} ...")

            if not os.path.exists(target_cookie_file):
                print(f"[CRITICAL] Cookie file NOT FOUND: {target_cookie_file}")
                # Try to fall back to index 0 if specific file missing
                if self.current_account_index != 0:
                     self.current_account_index = 0
                     print("[SYSTEM] Fallback to index 0")
                     return await self._ensure_client()
                raise FileNotFoundError(f"Missing {target_cookie_file}")

            try:
                with open(target_cookie_file, 'r') as f:
                    raw = json.load(f)

                cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw

                self.client = GeminiClient(
                    secure_1psid=cookies.get("__Secure-1PSID"),
                    secure_1psidts=cookies.get("__Secure-1PSIDTS")
                )

                # [STRATEGY] User-Agent Rotation
                # We try to inject a random UA if possible to avoid fingerprinting
                user_agents = [
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36",
                    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36"
                ]
                # Note: gemini_webapi might overwrite this, but we try anyway
                if hasattr(self.client, "session"):
                    self.client.session.headers["User-Agent"] = random.choice(user_agents)

                for k, v in cookies.items():
                    if k not in self.client.cookies:
                        self.client.cookies[k] = v

                await self.client.init(timeout=40)
                print("[SYSTEM] Gemini Client Successfully Initialized ✅")

                # Clear rate limit flag on success
                self.is_rate_limited = False

            except Exception as e:
                print(f"[INIT ERROR] Failed to initialize: {e}")
                self.client = None
                raise

    async def _execute_with_retry(self, prompt, q_id):
        # 1. Circuit Breaker Check
        if self.is_rate_limited:
            remaining = self.rate_limit_resume_time - time.time()
            if remaining > 0:
                # If we have multiple accounts, we shouldn't be blocked here unless ALL failed.
                # But for simplicity, if flag is raised, we block.
                return f"Error: System cooling down ({int(remaining)}s remaining)."
            else:
                self.is_rate_limited = False
                print(f"[SYSTEM #{q_id}] Cooldown expired. Resuming.")

        # [STRATEGY] Human Jitter
        # Random sleep 3-7 seconds to act like a human
        jitter = random.uniform(3, 7)
        print(f"[SYSTEM #{q_id}] ⏳ Human Jitter: Sleeping {jitter:.2f}s...")
        await asyncio.sleep(jitter)

        attempts = 0
        max_attempts = 2

        while attempts < max_attempts:
            try:
                await self._ensure_client()

                response = await asyncio.wait_for(
                    self.client.generate_content(prompt),
                    timeout=self.generation_timeout
                )
                return response.text

            except asyncio.TimeoutError:
                print(f"[WARN #{q_id}] Timeout (Attempt {attempts+1})")
                attempts += 1

            except Exception as e:
                error_str = str(e).lower()
                print(f"[ERROR #{q_id}] {e}")

                # --- ERROR STRATEGY ---

                # CASE A: Rate Limit (429)
                if "429" in error_str or "too many requests" in error_str:
                    print(f"[ALERT #{q_id}] 🛑 429 Rate Limit!")

                    # Try to rotate account
                    rotated = self._rotate_account()
                    if rotated:
                        print(f"[SYSTEM #{q_id}] ♻️ Switched Account. Retrying immediately...")
                        async with self.async_lock:
                            self.client = None # Reset so next loop picks up new file
                        attempts += 1
                        continue # Retry loop immediately
                    else:
                        # No other accounts? Pause.
                        self.is_rate_limited = True
                        self.rate_limit_resume_time = time.time() + 180 # 3 Mins
                        return "Error: Rate limit reached. Pausing for 3 minutes."

                # CASE B: Server Error (500/503) - DO NOT RESET CLIENT
                elif "500" in error_str or "503" in error_str or "overloaded" in error_str:
                    print(f"[WARN #{q_id}] Google Server Error. Waiting 10s...")
                    await asyncio.sleep(10)
                    attempts += 1
                    # Do NOT set self.client = None. Keep session.
                    continue

                # CASE C: Auth Error - Reset Client
                elif "auth" in error_str or "login" in error_str or "cookie" in error_str:
                    print(f"[WARN #{q_id}] Auth invalid. Resetting client...")
                    async with self.async_lock:
                        self.client = None

                attempts += 1
                await asyncio.sleep(3)

        return "Error: Failed to generate response after retries."

    def query(self, prompt):
        """Thread-safe entry point for Flask to call."""
        with self.log_lock:
            self.query_counter += 1
            q_id = self.query_counter

        clean_prompt = prompt.strip()
        print(f"\n[QUERY #{q_id}] Processing...")

        future = asyncio.run_coroutine_threadsafe(
            self._execute_with_retry(clean_prompt, q_id),
            self.loop
        )

        try:
            result = future.result(timeout=self.total_timeout)
            if result.startswith("Error"):
                print(f"[STATUS #{q_id}] Failed ❌")
            else:
                print(f"[STATUS #{q_id}] Success ✅")
            return result
        except Exception as e:
            print(f"[STATUS #{q_id}] CRITICAL TIMEOUT/FAIL: {e} ❌")
            return f"Error: Request processing failed ({str(e)})"

# ==================================================================================
# [FLASK] APP SETUP
# ==================================================================================
app = Flask(__name__)
log = logging.getLogger('werkzeug')
log.setLevel(logging.ERROR)

# Initialize Global Manager
bot_manager = GeminiManager()

# --- SERVER-SIDE API LIMITS (Protection for your own server) ---
QUOTA_LOCK = Lock()
SESSION_COUNTER = 0
BLOCK_EXPIRATION = 0
MAX_REQUESTS = 50
COOLDOWN_SECONDS = 3600

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask():
    global SESSION_COUNTER, BLOCK_EXPIRATION

    # --- QUOTA CHECK ---
    with QUOTA_LOCK:
        current_time = time.time()

        if current_time < BLOCK_EXPIRATION:
            return jsonify({}), 200

        if BLOCK_EXPIRATION > 0 and current_time >= BLOCK_EXPIRATION:
            SESSION_COUNTER = 0
            BLOCK_EXPIRATION = 0
            print("[SYSTEM] Server Block Expired. Counter reset.")

        SESSION_COUNTER += 1

        if SESSION_COUNTER >= MAX_REQUESTS:
            BLOCK_EXPIRATION = current_time + COOLDOWN_SECONDS
            print(f"[SYSTEM] Limit reached. Blocking for 1 hour.")
            return jsonify({}), 200

    # --- PROCESS ---
    try:
        data = request.get_json(silent=True) or {}
        prompt = data.get('prompt') or data.get('question')

        if not prompt:
            return jsonify({"error": "Missing prompt"}), 400

        result = bot_manager.query(prompt)

        if result.startswith("Error"):
            return jsonify({"status": "error", "message": result}), 500

        return jsonify({"status": "success", "answer": result})
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/', methods=['GET', 'POST'])
def web_index():
    answer, error, question = "", "", ""
    if request.method == 'POST':
        question = request.form.get('question', '')
        answer = bot_manager.query(question)
        if answer.startswith("Error"):
            error, answer = answer, ""

    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

HTML_TEMPLATE = """
<!DOCTYPE html>
<html>
<head><title>Gemini API (Safe Mode)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-5 bg-light">
    <div class="container" style="max-width: 800px;">
        <h2 class="mb-4">🤖 Gemini API (Safe Mode)</h2>
        <div class="alert alert-info">
            <small>Features Active: Human Jitter (3-7s), Auto-429 Pause, Session Persistence.</small>
        </div>
        <form method="post">
            <textarea name="question" class="form-control mb-3" rows="5" placeholder="Enter prompt...">{{question}}</textarea>
            <button class="btn btn-primary w-100">Submit</button>
        </form>
        {% if answer %}<div class="card mt-4 shadow-sm"><div class="card-body"><pre style="white-space: pre-wrap;">{{answer}}</pre></div></div>{% endif %}
        {% if error %}<div class="alert alert-danger mt-4">{{error}}</div>{% endif %}
    </div>
</body>
</html>
"""

if __name__ == '__main__':
    print("[SYS] Server starting at http://localhost:5000")
    app.run(host='0.0.0.0', port=5000, debug=False)